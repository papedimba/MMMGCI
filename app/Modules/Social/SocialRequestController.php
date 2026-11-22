<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
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
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use App\Modules\Members\MemberSearch;
use App\Modules\Solidarity\SolidarityService;
use DomainException;
use InvalidArgumentException;

/**
 * Écrans de gestion d'une demande sociale. Aucune règle ici : SocialRequestService et SocialDisbursementLink décident (boutons = allowedActions,
 * POST = service, appelé hors transaction pour que son rejeu sur interblocage s'applique). Visibilité : SocialAccess, lue AVANT toute autre lecture
 * de la demande (hors périmètre, compte inactif ou demande inconnue : 404). Les sections sensibles ne sont lues que si elles sont affichées :
 * pièces (la liste d'un détenteur de social.medical.view est une consultation journalisée) et données de santé (bouton explicite, journalisé).
 */
final class SocialRequestController
{
    private const TABS = ['suivi' => 'Suivi', 'examen' => 'Examen', 'pieces' => 'Pièces', 'sante' => 'Données de santé'];
    private const DISB_READ = ['finance.view', 'finance.control', 'disbursement.initiate', 'disbursement.approve', 'disbursement.pay'];

    private SocialAccess $access;
    private SocialDocumentService $documents;
    private SocialNoteService $notes;
    private SocialMedicalService $medical;
    private SocialTypeService $types;
    private MemberScope $scope;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate, private Audit $audit,
        Uploads $uploads, private SocialRequestService $service, private SocialDisbursementLink $link, private SolidarityService $solidarity)
    {
        $this->access = new SocialAccess($db, $gate, $audit);
        $this->documents = new SocialDocumentService($db, $audit, $gate, $uploads);
        $this->notes = new SocialNoteService($db, $audit, $gate);
        $this->medical = new SocialMedicalService($db, $audit, $gate);
        $this->types = new SocialTypeService($db, $audit, $gate);
        $this->scope = new MemberScope($db, $gate);
    }

    // --- enregistrement ---

    public function createForm(Request $r): Response
    {
        $member = $r->input('membre');
        return $this->createPage($r, is_string($member) && ctype_digit($member) ? (int) $member : null, [], null);
    }

    public function store(Request $r): Response
    {
        $uid = $this->actor($r);
        $v = $this->texts($r, ['member_id', 'beneficiary_id', 'type_id', 'received_at', 'channel', 'priority', 'description', 'medical_details', 'label', 'contains_medical_data']);
        $memberId = ctype_digit($v['member_id']) ? (int) $v['member_id'] : 0;
        $medicalText = $this->access->canWriteMedical($uid) ? trim($v['medical_details']) : '';
        try {
            $id = $this->service->register(['member_id' => $memberId, 'beneficiary_id' => ctype_digit($v['beneficiary_id']) ? (int) $v['beneficiary_id'] : null,
                'type_id' => (int) $v['type_id'], 'received_at' => $v['received_at'], 'channel' => $v['channel'], 'priority' => $v['priority'] === '' ? 'normale' : $v['priority'],
                'description' => $v['description'], 'contains_medical_data' => $r->post('contains_medical_data') === '1' || $medicalText !== ''], $uid, $r->ip());
        } catch (DomainException $e) {
            return $this->createPage($r, $memberId, $v, $e->getMessage(), 422);
        }
        try {
            if ($medicalText !== '') {
                $this->medical->write($id, $medicalText, $uid, $r->ip());
            }
            $this->upload($r, $id, $uid, $v['label']);
        } catch (DomainException | UploadError $e) {
            $this->session->flash('error', 'Demande enregistrée, mais un complément n\'a pas été enregistré : ' . $e->getMessage());
            return Response::redirect("/admin/demandes-sociales/$id");
        }
        $this->session->flash('status', 'Demande enregistrée.');
        return Response::redirect("/admin/demandes-sociales/$id");
    }

    // --- page d'une demande ---

    public function show(Request $r): Response
    {
        return $this->showPage($r, $this->load($r));
    }

    /** Afficher les données de santé : clic explicite, chaque lecture journalisée par le service. */
    public function revealMedical(Request $r): Response
    {
        $req = $this->load($r);
        try {
            $details = $this->medical->read((int) $req['id'], $this->actor($r), $r->ip());
        } catch (SocialAccessDenied $e) {
            return $this->showPage($r, $req, $e->getMessage(), 403);
        }
        return $this->showPage($r, $req, null, 200, 'sante', ['medicalDetails' => $details ?? '', 'medicalRevealed' => true])->withHeader('Cache-Control', 'private, no-store');
    }

    public function writeMedical(Request $r): Response
    {
        $req = $this->load($r);
        $text = $this->texts($r, ['details'])['details'];
        return $this->act($r, $req, fn () => $this->medical->write((int) $req['id'], $text, $this->actor($r), $r->ip()), 'Données de santé enregistrées.', 'sante');
    }

    public function assign(Request $r): Response
    {
        $req = $this->load($r);
        $to = (int) $r->post('assignee', 0);
        return $this->act($r, $req, fn () => $this->service->assign((int) $req['id'], $to, $this->actor($r), $r->ip()), 'Demande affectée ; la personne est prévenue par e-mail.');
    }

    public function requestDocuments(Request $r): Response
    {
        $req = $this->load($r);
        $text = $this->texts($r, ['documents'])['documents'];
        return $this->act($r, $req, fn () => $this->service->requestDocuments((int) $req['id'], $text, $this->actor($r), $r->ip()), 'Pièces demandées à l\'adhérent.');
    }

    public function resume(Request $r): Response
    {
        $req = $this->load($r);
        $comment = $this->texts($r, ['comment'])['comment'];
        return $this->act($r, $req, fn () => $this->service->resumeInstruction((int) $req['id'], $this->actor($r), $comment, $r->ip()), 'Instruction reprise.');
    }

    public function review(Request $r): Response
    {
        $req = $this->load($r);
        $v = $this->texts($r, ['opinion', 'recommended_amount', 'comment']);
        return $this->act($r, $req, function () use ($req, $v, $r): void {
            $this->service->addReview((int) $req['id'], $v['opinion'], $this->cents($v['recommended_amount']), $v['comment'], $this->actor($r), $r->ip());
        }, 'Avis enregistré.', 'examen');
    }

    public function submit(Request $r): Response
    {
        $req = $this->load($r);
        return $this->act($r, $req, fn () => $this->service->submitForDecision((int) $req['id'], $this->actor($r), $r->ip()), 'Demande soumise à décision.');
    }

    public function close(Request $r): Response
    {
        $req = $this->load($r);
        $note = $this->texts($r, ['closure_note'])['closure_note'];
        return $this->act($r, $req, fn () => $this->service->close((int) $req['id'], $note, $this->actor($r), $r->ip()), 'Demande clôturée.');
    }

    public function paymentFailed(Request $r): Response
    {
        $req = $this->load($r);
        return $this->act($r, $req, fn () => $this->link->paymentFailed((int) $req['id'], $this->actor($r), $r->ip()), 'Demande revenue à « accordée » : un nouveau décaissement peut être préparé.');
    }

    public function note(Request $r): Response
    {
        $req = $this->load($r);
        $body = $this->texts($r, ['body'])['body'];
        return $this->act($r, $req, fn () => $this->notes->add((int) $req['id'], $body, $this->actor($r), $r->ip()), 'Note ajoutée.', 'examen');
    }

    public function addDocument(Request $r): Response
    {
        $req = $this->load($r);
        $label = $this->texts($r, ['label'])['label'];
        try {
            if (!$this->upload($r, (int) $req['id'], $this->actor($r), $label)) {
                throw new UploadError('Choisissez un fichier à déposer.');
            }
        } catch (DomainException | UploadError $e) {
            return $this->showPage($r, $this->load($r), $e->getMessage(), $e instanceof SocialAccessDenied ? 403 : 422, 'pieces');
        }
        $this->session->flash('status', 'Pièce déposée.');
        return Response::redirect('/admin/demandes-sociales/' . $req['id'] . '?onglet=pieces');
    }

    /** Téléchargement contrôlé : 404 (périmètre, pièce d'une autre demande), 403 journalisé, chaque pièce médicale journalisée (service). */
    public function document(Request $r): Response
    {
        $req = $this->load($r);
        $docId = (int) $r->attribute('docId');
        try {
            $path = $this->documents->stream((int) $req['id'], $docId, $this->actor($r), $r->ip());
        } catch (SocialAccessDenied $e) {
            return $this->showPage($r, $req, $e->getMessage(), 403);
        }
        $f = $this->db->fetch('SELECT f.mime, f.original_name FROM social_request_documents d JOIN files f ON f.id = d.file_id WHERE d.id = ?', [$docId]) ?? throw new NotFound('Pièce introuvable.');
        $body = (string) file_get_contents($path);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $f['original_name']) ?: 'piece';
        return new Response($body, 200, ['Content-Type' => (string) $f['mime'], 'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    // --- décision ---

    public function decisionForm(Request $r): Response
    {
        $req = $this->load($r);
        if (!in_array('decide', $this->service->allowedActions((int) $req['id'], $this->actor($r)), true)) {
            return $this->notNow($req);
        }
        return $this->decisionPage($r, $req, ['decided_at' => Ledger::now()->format('Y-m-d')], null);
    }

    public function decide(Request $r): Response
    {
        $req = $this->load($r);
        $v = $this->texts($r, ['decision', 'aid_kind', 'amount', 'reason', 'member_message', 'decision_ref', 'deciding_body', 'decided_at']);
        try {
            $this->service->decide((int) $req['id'], ['decision' => $v['decision'], 'aid_kind' => $v['aid_kind'] === '' ? null : $v['aid_kind'],
                'amount' => $this->cents($v['amount']), 'reason' => $v['reason'], 'member_message' => $v['member_message'], 'decision_ref' => $v['decision_ref'],
                'deciding_body' => $v['deciding_body'], 'decided_at' => $v['decided_at'] === '' ? null : $v['decided_at']], $this->actor($r), $r->ip());
        } catch (DomainException $e) {
            if ($this->db->fetchValue('SELECT 1 FROM social_decisions WHERE social_request_id = ?', [(int) $req['id']]) !== null) {
                $this->session->flash('error', 'Une décision est déjà enregistrée pour cette demande : elle ne peut pas être modifiée.');
                return Response::redirect('/admin/demandes-sociales/' . $req['id']);
            }
            return $this->decisionPage($r, $req, $v, $e->getMessage(), 422);
        }
        $this->session->flash('status', 'Décision enregistrée.');
        return Response::redirect('/admin/demandes-sociales/' . $req['id']);
    }

    // --- préparation du décaissement ---

    public function disbursementForm(Request $r): Response
    {
        $req = $this->load($r);
        if (!in_array('prepare', $this->link->allowedActions((int) $req['id'], $this->actor($r)), true)) {
            return $this->notNow($req);
        }
        return $this->disbursementPage($r, $req, ['account_id' => '', 'category_id' => '', 'beneficiary_name' => trim($req['last_name'] . ' ' . $req['first_names'])], null);
    }

    public function prepare(Request $r): Response
    {
        $req = $this->load($r);
        $v = $this->texts($r, ['account_id', 'category_id', 'beneficiary_name']);
        try {
            $did = $this->link->prepare((int) $req['id'], ['account_id' => (int) $v['account_id'], 'category_id' => (int) $v['category_id'],
                'beneficiary_name' => $v['beneficiary_name']], $this->actor($r), $r->ip());
        } catch (DomainException $e) {
            return $this->disbursementPage($r, $req, $v, $e->getMessage(), 422);
        }
        $ref = (string) $this->db->fetchValue('SELECT reference FROM disbursement_requests WHERE id = ?', [$did]);
        $this->session->flash('status', "Brouillon de décaissement $ref préparé. Joignez-y un justificatif non médical, puis soumettez-le aux approbateurs.");
        return Response::redirect('/admin/demandes-sociales/' . $req['id']);
    }

    // --- aides ---

    /** @param array<string,mixed> $req */
    private function act(Request $r, array $req, callable $call, string $done, ?string $tab = null): Response
    {
        try {
            $call();
        } catch (DomainException $e) {
            return $this->showPage($r, $this->load($r), $e->getMessage(), $e instanceof SocialAccessDenied ? 403 : ($e->getMessage() === SocialRuleViolation::BUSY ? 409 : 422), $tab);
        }
        $this->session->flash('status', $done);
        return Response::redirect('/admin/demandes-sociales/' . $req['id'] . ($tab !== null ? '?onglet=' . $tab : ''));
    }

    /** @param array<string,mixed> $req */
    private function notNow(array $req): Response
    {
        $this->session->flash('error', 'Cette action n\'est pas possible pour vous dans l\'état actuel de la demande.');
        return Response::redirect('/admin/demandes-sociales/' . $req['id']);
    }

    /** Pièce du formulaire (champ « piece »), s'il y en a une : déposée par le service (contrôles d'abord, puis stockage privé). */
    private function upload(Request $r, int $id, int $uid, string $label): bool
    {
        $file = $r->file('piece');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return false;
        }
        $kind = $r->post('kind');
        $this->documents->add($id, $file, is_string($kind) && $kind !== '' ? $kind : 'piece', $label, $r->post('is_medical') === '1', $uid, $r->ip());
        return true;
    }

    private function cents(string $v): ?int
    {
        if (trim($v) === '') {
            return null;
        }
        try {
            return Money::parse($v);
        } catch (InvalidArgumentException $e) {
            throw new SocialRuleViolation('Montant : ' . $e->getMessage(), 0, $e);
        }
    }

    /** Demande visible par l'acteur (social.view, périmètre, compte actif), sinon 404 : contrôle fait AVANT toute lecture (context() compris). @return array<string,mixed> */
    private function load(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $this->access->assertCanSeeRequest($this->actor($r), $id);
        return $this->db->fetch('SELECT r.*, m.member_number, m.last_name, m.first_names, t.name AS type_name, u.name AS assignee_name,
                CONCAT_WS(CHAR(32 USING utf8mb4), b.last_name, b.first_names) AS beneficiary_name, b.relationship AS beneficiary_relationship
            FROM social_requests r JOIN members m ON m.id = r.member_id JOIN social_request_types t ON t.id = r.type_id
            LEFT JOIN users u ON u.id = r.assigned_to LEFT JOIN member_beneficiaries b ON b.id = r.beneficiary_id WHERE r.id = ?', [$id])
            ?? throw new NotFound('Demande sociale introuvable.');
    }

    /** @param array<string,mixed> $req @param array<string,mixed> $extra */
    private function showPage(Request $r, array $req, ?string $error = null, int $status = 200, ?string $forceTab = null, array $extra = []): Response
    {
        $uid = $this->actor($r);
        $id = (int) $req['id'];
        $instruction = $this->access->canSeeInstruction($uid);
        $medical = $this->access->canSeeMedical($uid);
        $tabs = array_filter(self::TABS, static fn (string $k): bool => match ($k) {
            'examen' => $instruction, 'pieces' => $instruction || $medical, 'sante' => $medical, default => true,
        }, ARRAY_FILTER_USE_KEY);
        $tab = $forceTab ?? $r->input('onglet');
        $tab = is_string($tab) && isset($tabs[$tab]) ? $tab : 'suivi';
        $decision = $this->db->fetch('SELECT d.*, u.name AS recorded_by_name FROM social_decisions d LEFT JOIN users u ON u.id = d.recorded_by WHERE d.social_request_id = ?', [$id]);
        $data = $extra + ['req' => $req, 'tab' => $tab, 'tabs' => $tabs, 'error' => $error, 'instruction' => $instruction, 'medical' => $medical,
            'actions' => $this->service->allowedActions($id, $uid), 'linkActions' => $this->link->allowedActions($id, $uid), 'decision' => $decision,
            'events' => $this->db->fetchAll('SELECT e.from_status, e.to_status, e.action, e.comment, e.created_at, u.name AS author FROM social_request_events e
                LEFT JOIN users u ON u.id = e.user_id WHERE e.social_request_id = ? ORDER BY e.id', [$id]),
            'disbursements' => $this->db->fetchAll('SELECT dr.id, dr.reference, dr.status, dr.amount FROM disbursement_requests dr JOIN social_decisions d ON d.id = dr.social_decision_id
                WHERE d.social_request_id = ? ORDER BY dr.id DESC', [$id]),
            'readsDisbursements' => array_filter(self::DISB_READ, fn (string $p): bool => $this->gate->allows($uid, $p)) !== [],
            'assignees' => [], 'medicalDetails' => '', 'medicalRevealed' => false, 'canWriteMedical' => $this->access->canWriteMedical($uid)];
        if (in_array('assign', $data['actions'], true)) {
            $data['assignees'] = $this->assignees((int) $req['member_id'], $req['assigned_to'] === null ? 0 : (int) $req['assigned_to']);
        }
        if ($tab === 'examen') { // l'appelant a vérifié le périmètre (load) : context() n'en fait aucun
            $data['context'] = $this->service->context($id);
            $data['fund'] = $this->fund();
            $data['reviews'] = $this->notes->reviews($id, $uid);
            $data['notes'] = $this->notes->list($id, $uid);
        } elseif ($tab === 'pieces') { // seule lecture des pièces : une liste aux libellés médicaux est une consultation journalisée
            $data['documents'] = $this->documents->list($id, $uid, $r->ip());
            $data['canUpload'] = $req['status'] !== 'cloturee' && ($this->access->allows($uid, 'social.manage') || ($req['status'] === 'recue' && $this->access->allows($uid, 'social.create')));
        }
        return $this->page($r, 'admin/social/show', 'Demande ' . $req['reference'], $data, $status);
    }

    /** Comptes actifs pouvant recevoir l'affectation (mêmes conditions que le service). @return array<string,string> */
    private function assignees(int $memberId, int $current): array
    {
        // shortcut: filtre en PHP sur tous les comptes actifs ; à remplacer par une requête sur les rôles si les comptes dépassent quelques centaines.
        $out = ['' => 'Choisir'];
        foreach ($this->db->fetchAll('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name') as $u) {
            if ((int) $u['id'] !== $current && $this->access->allows((int) $u['id'], 'social.manage') && $this->access->visible((int) $u['id'], $memberId)) {
                $out[(string) $u['id']] = (string) $u['name'];
            }
        }
        return $out;
    }

    /** Solde du fonds de solidarité, information d'examen (« sous réserve des ressources disponibles »). @return ?array{name:string, balance:int} */
    private function fund(): ?array
    {
        try {
            $s = $this->solidarity->summary(); // lecture seule du grand livre
        } catch (DomainException) {
            return null;
        }
        return ['name' => (string) $s['account']['name'], 'balance' => (int) $s['balance']];
    }

    /** @param array<string,string> $values */
    private function createPage(Request $r, ?int $memberId, array $values, ?string $error, int $status = 200): Response
    {
        $uid = $this->actor($r);
        $q = $r->input('q');
        $q = is_string($q) ? trim($q) : '';
        $member = null;
        $beneficiaries = [];
        if ($memberId !== null && $memberId > 0) {
            $this->scope->assertMayAccess($uid, $memberId); // hors périmètre : 404
            $member = $this->db->fetch('SELECT id, member_number, last_name, first_names, status FROM members WHERE id = ?', [$memberId]);
            $beneficiaries = ['' => 'Aucun : la demande concerne l\'adhérent'];
            foreach ($this->db->fetchAll('SELECT id, last_name, first_names, relationship FROM member_beneficiaries WHERE member_id = ? ORDER BY last_name, first_names, id', [$memberId]) as $b) {
                $beneficiaries[(string) $b['id']] = trim($b['last_name'] . ' ' . $b['first_names']) . ($b['relationship'] ? ' (' . $b['relationship'] . ')' : '');
            }
        }
        $found = $member === null && $q !== '' ? (new MemberSearch($this->db, $this->scope))->run($uid, ['q' => $q], 'name', 1)['rows'] : [];
        return $this->page($r, 'admin/social/create', 'Nouvelle demande d\'assistance', [
            'q' => $q, 'found' => $found, 'member' => $member, 'beneficiaries' => $beneficiaries, 'error' => $error,
            'values' => $values + ['beneficiary_id' => '', 'type_id' => '', 'received_at' => Ledger::now()->format('Y-m-d'), 'channel' => '', 'priority' => 'normale',
                'description' => '', 'medical_details' => '', 'label' => ''],
            'typeOptions' => ['' => 'Choisir'] + array_column($this->types->all(true), 'name', 'id'),
            'canWriteMedical' => $this->access->canWriteMedical($uid),
            'referencePending' => $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'social' AND `key` = 'reference_format'") !== 'valide',
            'today' => Ledger::now()->format('Y-m-d'),
        ], $status);
    }

    /** @param array<string,mixed> $req @param array<string,string> $values */
    private function decisionPage(Request $r, array $req, array $values, ?string $error, int $status = 200): Response
    {
        $type = (int) $req['type_id'];
        $kinds = $this->types->allowedAidKinds($type);
        return $this->page($r, 'admin/social/decision', 'Décision sur la demande ' . $req['reference'], [
            'req' => $req, 'error' => $error, 'values' => $values + array_fill_keys(['decision', 'aid_kind', 'amount', 'reason', 'member_message', 'decision_ref', 'deciding_body', 'decided_at'], ''),
            'ceiling' => $this->types->ceilingFor($type), 'body' => $this->types->decidingBodyFor($type),
            'kindOptions' => ['' => 'Aucune (refus)'] + ($kinds === null ? SocialTypeService::AID_KINDS : array_intersect_key(SocialTypeService::AID_KINDS, array_flip($kinds))),
            'today' => Ledger::now()->format('Y-m-d'),
        ], $status);
    }

    /** @param array<string,mixed> $req @param array<string,string> $values */
    private function disbursementPage(Request $r, array $req, array $values, ?string $error, int $status = 200): Response
    {
        $opts = static fn (array $rows): array => ['' => 'Choisir'] + array_column($rows, 'name', 'id');
        return $this->page($r, 'admin/social/prepare-disbursement', 'Préparer le décaissement de ' . $req['reference'], [
            'req' => $req, 'values' => $values, 'error' => $error,
            'amount' => (string) $this->db->fetchValue('SELECT amount FROM social_decisions WHERE social_request_id = ?', [(int) $req['id']]),
            // fonds de solidarité en premier, jamais présélectionné
            'accountOptions' => $opts($this->db->fetchAll("SELECT id, name FROM financial_accounts WHERE is_active = 1 ORDER BY type = 'solidarity_fund' DESC, name")),
            'categoryOptions' => $opts($this->db->fetchAll("SELECT id, name FROM financial_categories WHERE direction = 'depense' AND is_active = 1 ORDER BY name")),
        ], $status);
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
