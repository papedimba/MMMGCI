<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Money;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Core\View;
use App\Modules\AuditLog\SensitiveAccess;
use App\Modules\Contributions\PaymentService;
use App\Modules\Finance\Ledger;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

/**
 * Écrans du circuit de décaissement. Aucune règle ici : DisbursementService décide de tout (boutons = allowedActions, POST = service).
 * Le service est appelé hors de toute transaction (son rejeu sur interblocage s'applique). Lecture d'une demande et de ses pièces :
 * finance.view, finance.control, disbursement.approve, disbursement.pay, ou l'initiateur de la demande (sinon 404).
 */
final class DisbursementController
{
    public const STATES = ['brouillon' => 'Brouillon', 'soumis' => 'Soumise', 'approuve_1' => 'En cours d\'approbation', 'approuve_2' => 'Approuvée, à payer',
        'paye' => 'Payée', 'rejete' => 'Rejetée', 'annule' => 'Annulée'];
    private const READ_ALL = ['finance.view', 'finance.control', 'disbursement.approve', 'disbursement.pay'];
    private const PER_PAGE = 25;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private DisbursementService $service, private DisbursementGate $circuit, private Uploads $uploads, private SensitiveAccess $sensitive)
    {
    }

    public function index(Request $r): Response
    {
        $uid = $this->actor($r);
        $etat = (string) $r->input('etat', '');
        $mine = $r->input('vue') === 'a_approuver';
        $where = [];
        $params = [];
        if (!$this->readsAll($uid)) {
            [$where[], $params[]] = ['d.initiated_by = ?', $uid];
        }
        if (isset(self::STATES[$etat])) {
            [$where[], $params[]] = ['d.status = ?', $etat];
        }
        if ($mine) {
            $where[] = "d.status IN ('soumis', 'approuve_1')";
        }
        $cond = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $select = "SELECT d.id, d.reference, d.beneficiary_name, d.amount, d.status, d.revision, d.created_at, u.name AS initiator_name
            FROM disbursement_requests d LEFT JOIN users u ON u.id = d.initiated_by $cond ORDER BY d.id DESC";
        if ($mine) {
            // shortcut: filtre en PHP sur les demandes en cours d'approbation (sans pagination), à paginer si elles dépassent quelques centaines.
            $rows = array_values(array_filter($this->db->fetchAll($select, $params), fn (array $d): bool => in_array('approve', $this->service->allowedActions((int) $d['id'], $uid), true)));
            [$total, $page, $pages] = [count($rows), 1, 1];
        } else {
            $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM disbursement_requests d $cond", $params);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min($pages, max(1, (int) $r->input('page', 1)));
            $rows = $this->db->fetchAll($select . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        }
        return $this->page($r, 'admin/disbursements/index', 'Décaissements', ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'values' => ['etat' => isset(self::STATES[$etat]) ? $etat : '', 'vue' => $mine ? 'a_approuver' : ''],
            'query' => array_filter(['etat' => isset(self::STATES[$etat]) ? $etat : null, 'vue' => $mine ? 'a_approuver' : null]), 'operational' => $this->circuit->isOperational()]);
    }

    public function notOperational(Request $r): Response
    {
        return $this->page($r, 'admin/disbursements/not-operational', 'Circuit de décaissement inopérant', [], $this->circuit->isOperational() ? 200 : 409);
    }

    public function show(Request $r): Response
    {
        return $this->showPage($r, $this->load($r), null);
    }

    public function createForm(Request $r): Response
    {
        return $this->formPage($r, null, ['amount' => '', 'beneficiary_name' => '', 'account_id' => '', 'category_id' => '', 'purpose' => '', 'internal_note' => '', 'reason' => '', 'label' => ''], null);
    }

    public function store(Request $r): Response
    {
        $v = $this->texts($r, ['amount', 'beneficiary_name', 'account_id', 'category_id', 'purpose', 'internal_note', 'reason', 'label']);
        $uid = $this->actor($r);
        try {
            $id = $this->service->create($this->fields($v), $uid, $r->ip());
        } catch (DomainException $e) {
            return $this->formPage($r, null, $v, $e->getMessage(), $this->status($e));
        }
        try {
            $add = $this->upload($r, 'justificatif', $uid, $v['label']);
            if ($add !== []) {
                $this->service->revise($id, ['add_files' => $add], '', $uid, $r->ip());
            }
        } catch (DomainException | UploadError $e) {
            $this->session->flash('error', 'Brouillon enregistré, mais le justificatif n\'a pas été joint : ' . $e->getMessage());
            return Response::redirect("/admin/decaissements/$id/modifier");
        }
        $this->session->flash('status', 'Brouillon enregistré. Joignez au moins un justificatif, puis soumettez la demande.');
        return Response::redirect("/admin/decaissements/$id");
    }

    public function editForm(Request $r): Response
    {
        $d = $this->load($r);
        return $this->guarded($r, $d, 'revise', fn (): Response => $this->formPage($r, $d, [
            'amount' => str_replace('.', ',', preg_replace('/\.00$/', '', (string) $d['amount'])),'beneficiary_name' => (string) $d['beneficiary_name'], 'account_id' => (string) $d['account_id'],
            'category_id' => (string) $d['category_id'], 'purpose' => (string) $d['purpose'], 'internal_note' => (string) $d['internal_note'], 'reason' => '', 'label' => ''], null));
    }

    public function update(Request $r): Response
    {
        $d = $this->load($r);
        $v = $this->texts($r, ['amount', 'beneficiary_name', 'account_id', 'category_id', 'purpose', 'internal_note', 'reason', 'label']);
        $uid = $this->actor($r);
        $remove = $r->post('remove');
        try {
            $changes = $this->fields($v) + ['remove_attachments' => array_values(array_map('intval', is_array($remove) ? $remove : [])),
                'add_files' => $this->upload($r, 'justificatif', $uid, $v['label'])];
            $this->service->revise((int) $d['id'], $changes, $v['reason'], $uid, $r->ip());
        } catch (DomainException | UploadError $e) {
            return $this->formPage($r, $this->load($r), $v, $e->getMessage(), $this->status($e));
        }
        $this->session->flash('status', 'Demande modifiée.');
        return Response::redirect('/admin/decaissements/' . $d['id']);
    }

    public function submit(Request $r): Response
    {
        $d = $this->load($r);
        return $this->act($r, $d, fn () => $this->service->submit((int) $d['id'], $this->actor($r), $r->ip()), 'Demande soumise aux approbateurs.',
            fn (string $m, int $s): Response => $this->showPage($r, $this->load($r), $m, $s));
    }

    public function approve(Request $r): Response
    {
        $d = $this->load($r);
        return $this->act($r, $d, fn () => $this->service->approve((int) $d['id'], $this->actor($r), (int) $r->post('seen_revision', 0), null, $r->ip()), 'Approbation enregistrée.',
            fn (string $m, int $s): Response => $this->showPage($r, $this->load($r), $m, $s));
    }

    public function rejectForm(Request $r): Response
    {
        $d = $this->load($r);
        return $this->guarded($r, $d, 'reject', fn (): Response => $this->reasonPage($r, $d, 'reject', '', null));
    }

    public function reject(Request $r): Response
    {
        $d = $this->load($r);
        $reason = $this->texts($r, ['reason'])['reason'];
        return $this->act($r, $d, fn () => $this->service->reject((int) $d['id'], $this->actor($r), (int) $r->post('seen_revision', 0), $reason, $r->ip()), 'Demande rejetée.',
            fn (string $m, int $s): Response => $this->reasonPage($r, $this->load($r), 'reject', $reason, $m, $s));
    }

    public function cancelForm(Request $r): Response
    {
        $d = $this->load($r);
        return $this->guarded($r, $d, 'cancel', fn (): Response => $this->reasonPage($r, $d, 'cancel', '', null));
    }

    public function cancel(Request $r): Response
    {
        $d = $this->load($r);
        return $this->act($r, $d, fn () => $this->service->cancel((int) $d['id'], $this->actor($r), $r->ip()), 'Demande annulée.',
            fn (string $m, int $s): Response => $this->reasonPage($r, $this->load($r), 'cancel', '', $m, $s));
    }

    public function payForm(Request $r): Response
    {
        $d = $this->load($r);
        return $this->guarded($r, $d, 'pay', fn (): Response => $this->payPage($r, $d, ['method' => 'virement', 'paid_on' => Ledger::now()->format('Y-m-d'), 'external_ref' => ''], null));
    }

    public function pay(Request $r): Response
    {
        $d = $this->load($r);
        $id = (int) $d['id'];
        $uid = $this->actor($r);
        $v = $this->texts($r, ['method', 'paid_on', 'external_ref', 'key', 'seen_revision', 'seen_amount']);
        if ($this->paidWithKey($id, $v['key'])) { // double envoi du même formulaire : déjà payé, rien à refaire (ni fichier à stocker)
            return $this->paid($id);
        }
        try {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $v['paid_on'], new DateTimeZone('Africa/Abidjan'));
            if ($day === false || $day->format('Y-m-d') !== $v['paid_on']) {
                throw new DisbursementRuleViolation('Date invalide : choisissez une date.');
            }
            $now = Ledger::now();
            $proof = $this->upload($r, 'preuve', $uid, '');
            if ($proof === []) {
                throw new UploadError('Joignez la pièce justificative du paiement (reçu signé, bordereau, capture du transfert).');
            }
            // shortcut: un fichier stocké reste orphelin si le service refuse ensuite le paiement ; à purger par une tâche si le volume le justifie.
            $this->service->pay($id, $uid, (int) $v['seen_revision'], (int) $v['seen_amount'], ['method' => $v['method'],
                'paid_at' => $day->format('Y-m-d') === $now->format('Y-m-d') ? $now : $day->setTime(12, 0), 'external_ref' => $v['external_ref']],
                (int) array_key_first($proof), $v['key'], $r->ip());
        } catch (DomainException | UploadError $e) {
            if ($this->paidWithKey($id, $v['key'])) { // envoi concurrent du même formulaire : l'autre a payé
                return $this->paid($id);
            }
            return $this->payPage($r, $this->load($r), $v, $e->getMessage(), $this->status($e));
        }
        return $this->paid($id);
    }

    /** Justificatif de la demande ou preuve de son paiement : le fichier doit appartenir à CETTE demande (sinon 404) ; consultation journalisée. */
    public function file(Request $r): Response
    {
        $d = $this->load($r);
        $fid = (int) $r->attribute('fid');
        $file = $this->db->fetch("SELECT f.id, f.mime, f.original_name, 'justificatif' AS kind FROM financial_attachments a JOIN files f ON f.id = a.file_id
                WHERE a.attachable_type = 'disbursement_request' AND a.attachable_id = ? AND a.file_id = ?
            UNION SELECT f.id, f.mime, f.original_name, 'preuve_paiement' FROM disbursement_payments p JOIN files f ON f.id = p.proof_file_id
                WHERE p.disbursement_request_id = ? AND p.proof_file_id = ? LIMIT 1", [$d['id'], $fid, $d['id'], $fid]) ?? throw new NotFound('Pièce introuvable.');
        try {
            $body = (string) file_get_contents($this->uploads->path((int) $file['id']));
        } catch (InvalidArgumentException) {
            throw new NotFound('Fichier introuvable.');
        }
        $this->sensitive->record($this->actor($r), 'disbursement_request', (int) $d['id'], (string) $file['kind'], $r->ip());
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_name']) ?: 'piece';
        return new Response($body, 200, ['Content-Type' => (string) $file['mime'], 'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    // --- aides ---

    /** @param array<string,mixed> $d */
    private function act(Request $r, array $d, callable $call, string $done, callable $onError): Response
    {
        try {
            $call();
        } catch (DomainException $e) {
            return $onError($e->getMessage(), $this->status($e));
        }
        $this->session->flash('status', $done);
        return Response::redirect('/admin/decaissements/' . $d['id']);
    }

    /** Page d'action affichée seulement si le service autoriserait l'action ; circuit inopérant : page d'explication. @param array<string,mixed> $d */
    private function guarded(Request $r, array $d, string $action, callable $render): Response
    {
        if (in_array($action, $this->service->allowedActions((int) $d['id'], $this->actor($r)), true)) {
            return $render();
        }
        if (!$this->circuit->isOperational()) {
            return $this->notOperational($r);
        }
        $this->session->flash('error', 'Cette action n\'est pas possible pour vous dans l\'état actuel de la demande.');
        return Response::redirect('/admin/decaissements/' . $d['id']);
    }

    private function status(\Throwable $e): int
    {
        return $e instanceof DisbursementBusy ? 409 : 422;
    }

    private function paidWithKey(int $id, string $key): bool
    {
        return $key !== '' && $this->db->fetchValue('SELECT 1 FROM disbursement_payments WHERE disbursement_request_id = ? AND idempotency_key = ?', [$id, $key]) !== null;
    }

    private function paid(int $id): Response
    {
        $this->session->flash('status', 'Paiement enregistré.');
        return Response::redirect("/admin/decaissements/$id");
    }

    /** Téléverse (disque privé, auteur = l'acteur) le fichier du champ, s'il y en a un. @return array<int,string> file_id => libellé */
    private function upload(Request $r, string $field, int $uid, string $label): array
    {
        $file = $r->file($field);
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$this->uploads->store($file, 'document', false, $uid) => $label];
    }

    /** @param array<string,string> $v @return array<string,mixed> */
    private function fields(array $v): array
    {
        try {
            $amount = Money::parse($v['amount']);
        } catch (InvalidArgumentException $e) {
            throw new DisbursementRuleViolation($e->getMessage(), 0, $e);
        }
        return ['amount' => $amount, 'beneficiary_name' => $v['beneficiary_name'], 'account_id' => (int) $v['account_id'], 'category_id' => (int) $v['category_id'],
            'purpose' => $v['purpose'], 'internal_note' => $v['internal_note']];
    }

    private function readsAll(int $uid): bool
    {
        foreach (self::READ_ALL as $p) {
            if ($this->gate->allows($uid, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> demande visible par l'acteur, sinon 404 */
    private function load(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $d = $this->db->fetch('SELECT d.*, a.name AS account_name, c.name AS category_name, ui.name AS initiator_name, ur.name AS rejected_by_name
            FROM disbursement_requests d JOIN financial_accounts a ON a.id = d.account_id JOIN financial_categories c ON c.id = d.category_id
            LEFT JOIN users ui ON ui.id = d.initiated_by LEFT JOIN users ur ON ur.id = d.rejected_by WHERE d.id = ?', [$id]);
        $uid = $this->actor($r);
        if ($d === null || ((int) $d['initiated_by'] !== $uid && !$this->readsAll($uid))) {
            throw new NotFound("Demande $id introuvable.");
        }
        return $d;
    }

    /** @return list<array<string,mixed>> */
    private function attachments(int $id): array
    {
        return $this->db->fetchAll("SELECT a.id, a.file_id, a.label, f.original_name FROM financial_attachments a JOIN files f ON f.id = a.file_id
            WHERE a.attachable_type = 'disbursement_request' AND a.attachable_id = ? ORDER BY a.id", [$id]);
    }

    /** @param array<string,mixed> $d */
    private function showPage(Request $r, array $d, ?string $error, int $status = 200): Response
    {
        $id = (int) $d['id'];
        return $this->page($r, 'admin/disbursements/show', 'Demande ' . $d['reference'], [
            'd' => $d, 'error' => $error, 'attachments' => $this->attachments($id),
            'approvals' => $this->db->fetchAll('SELECT a.revision, a.approver_id, a.decision, a.comment, a.decided_at, a.invalidated_at, u.name AS approver_name
                FROM disbursement_approvals a LEFT JOIN users u ON u.id = a.approver_id WHERE a.disbursement_request_id = ? ORDER BY a.id', [$id]),
            'revisions' => $this->db->fetchAll('SELECT r.revision, r.reason, r.snapshot, r.created_at, u.name AS changed_by_name
                FROM disbursement_revisions r LEFT JOIN users u ON u.id = r.changed_by WHERE r.disbursement_request_id = ? ORDER BY r.revision', [$id]),
            'payment' => $this->db->fetch('SELECT p.paid_at, p.method, p.external_ref, p.proof_file_id, u.name AS payer_name, f.original_name AS proof_name
                FROM disbursement_payments p LEFT JOIN users u ON u.id = p.paid_by JOIN files f ON f.id = p.proof_file_id WHERE p.disbursement_request_id = ?', [$id]),
            'actions' => $this->service->allowedActions($id, $this->actor($r)), 'circuit' => $this->service->circuitStatus($id),
            'operational' => $this->circuit->isOperational(), 'methods' => PaymentService::METHODS,
        ], $status);
    }

    /** @param ?array<string,mixed> $d @param array<string,string> $values */
    private function formPage(Request $r, ?array $d, array $values, ?string $error, int $status = 200): Response
    {
        $opts = static fn (array $rows): array => ['' => 'Choisir'] + array_column($rows, 'name', 'id');
        return $this->page($r, 'admin/disbursements/form', $d === null ? 'Nouvelle demande de décaissement' : 'Modifier la demande ' . $d['reference'], [
            'd' => $d, 'values' => $values, 'error' => $error, 'attachments' => $d === null ? [] : $this->attachments((int) $d['id']),
            'accountOptions' => $opts($this->db->fetchAll('SELECT id, name FROM financial_accounts WHERE is_active = 1 ORDER BY name')),
            'categoryOptions' => $opts($this->db->fetchAll("SELECT id, name FROM financial_categories WHERE direction = 'depense' AND is_active = 1 ORDER BY name")),
        ], $status);
    }

    /** Rejet (motif) ou annulation : page dédiée ; la révision affichée part en champ caché. @param array<string,mixed> $d */
    private function reasonPage(Request $r, array $d, string $kind, string $reason, ?string $error, int $status = 200): Response
    {
        return $this->page($r, 'admin/disbursements/decision', $kind === 'reject' ? 'Rejeter la demande ' . $d['reference'] : 'Annuler la demande ' . $d['reference'],
            ['d' => $d, 'kind' => $kind, 'reason' => $reason, 'error' => $error], $status);
    }

    /** @param array<string,mixed> $d @param array<string,string> $values */
    private function payPage(Request $r, array $d, array $values, ?string $error, int $status = 200): Response
    {
        // Révision, montant et clé d'envoi : ceux de la page affichée maintenant (jamais relus au moment du POST).
        return $this->page($r, 'admin/disbursements/pay', 'Payer la demande ' . $d['reference'], ['d' => $d, 'values' => $values, 'error' => $error,
            'key' => bin2hex(random_bytes(16)), 'methods' => PaymentService::METHODS, 'today' => Ledger::now()->format('Y-m-d')], $status);
    }

    /** @param list<string> $keys @return array<string,string> */
    private function texts(Request $r, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $r->post($k);
            $out[$k] = is_string($v) ? $v : '';
        }
        return $out;
    }

    private function actor(Request $r): int
    {
        return (int) $r->attribute('user')['id'];
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        $error = $this->session->pullFlash('error');
        return new Response($this->view->render($view, [
            'title' => $title, 'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
