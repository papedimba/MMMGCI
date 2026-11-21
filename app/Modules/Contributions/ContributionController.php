<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Money;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DomainException;

/** Types et appels de cotisation : lecture = contributions.view, écriture = contributions.manage (routes/admin.php). */
final class ContributionController
{
    private const PER_PAGE = 25;
    public const STATUSES = ['appelee' => 'Appelée', 'partielle' => 'Partiellement payée', 'payee' => 'Payée', 'annulee' => 'Annulée'];

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private ContributionTypeService $types, private CallService $calls, private MemberScope $scope)
    {
    }

    public function types(Request $r): Response
    {
        $rows = $this->db->fetchAll('SELECT t.id, t.name, t.kind, t.amount, t.periodicity, t.authorization_ref, t.status, a.name AS account_name
            FROM contribution_types t LEFT JOIN financial_accounts a ON a.id = t.affects_account_id ORDER BY t.status, t.name');
        return $this->page($r, 'admin/contributions/types', 'Types de cotisation', ['rows' => $rows]);
    }

    public function typeForm(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $values = ['name' => '', 'kind' => 'periodique', 'amount' => '', 'periodicity' => '', 'authorization_ref' => '', 'affects_account_id' => '', 'status' => 'non_configure'];
        if ($id > 0) {
            $t = $this->db->fetch('SELECT * FROM contribution_types WHERE id = ?', [$id]) ?? throw new NotFound("Type $id introuvable.");
            $values = ['name' => $t['name'], 'kind' => $t['kind'], 'amount' => $t['amount'] === null ? '' : Money::toDecimal(Money::fromDecimal($t['amount'])),
                'periodicity' => (string) $t['periodicity'], 'authorization_ref' => (string) $t['authorization_ref'], 'affects_account_id' => (string) $t['affects_account_id'], 'status' => $t['status']];
        }
        return $this->typePage($r, $id, $values, null);
    }

    public function saveType(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $v = $this->texts($r, ['name', 'kind', 'amount', 'periodicity', 'authorization_ref', 'affects_account_id', 'status']);
        if ($id > 0 && $this->db->fetchValue('SELECT 1 FROM contribution_types WHERE id = ?', [$id]) === null) {
            throw new NotFound("Type $id introuvable.");
        }
        try {
            $this->types->save($id > 0 ? $id : null, $v, $this->actor($r));
        } catch (DomainException $e) {
            return $this->typePage($r, $id, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Type de cotisation enregistré.');
        return Response::redirect('/admin/cotisations/types');
    }

    public function calls(Request $r): Response
    {
        $user = $this->actor($r);
        [$scopeSql, $params] = $this->scope->where($user);
        $where = [$scopeSql];
        $query = [];
        foreach (['statut' => 'c.status', 'type' => 'c.contribution_type_id', 'periode' => 'c.period_start'] as $key => $col) {
            $v = $r->input($key);
            if (is_string($v) && $v !== '') {
                $where[] = "$col = ?";
                $params[] = $v;
                $query[$key] = $v;
            }
        }
        $cond = implode(' AND ', $where);
        $from = "FROM contribution_calls c JOIN members m ON m.id = c.member_id JOIN contribution_types t ON t.id = c.contribution_type_id WHERE $cond";
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) $from", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $r->input('page', 1)));
        $rows = $this->db->fetchAll("SELECT c.id, c.period_label, c.amount_due, c.due_date, c.status, c.cancel_reason, m.id AS member_id, m.last_name, m.first_names, m.member_number, t.name AS type_name,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.contribution_call_id = c.id AND p.cancelled_at IS NULL), 0) AS paid
            $from ORDER BY c.period_start DESC, m.last_name, c.id LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $periods = $this->db->fetchAll('SELECT DISTINCT period_start, period_label FROM contribution_calls ORDER BY period_start DESC LIMIT 60');
        return $this->page($r, 'admin/contributions/calls', 'Appels de cotisation', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $query, 'statuses' => self::STATUSES,
            'statusOptions' => ['' => 'Tous'] + self::STATUSES,
            'typeOptions' => ['' => 'Tous'] + array_column($this->db->fetchAll('SELECT id, name FROM contribution_types ORDER BY name'), 'name', 'id'),
            'periodOptions' => ['' => 'Toutes'] + array_column($periods, 'period_label', 'period_start'),
            'values' => $query + ['statut' => '', 'type' => '', 'periode' => ''],
        ]);
    }

    public function individualForm(Request $r): Response
    {
        $number = '';
        $mid = (int) $r->input('adherent', 0);
        if ($mid > 0) {
            try {
                $this->scope->assertMayAccess($this->actor($r), $mid);
                $number = (string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$mid]);
            } catch (NotFound) {
            }
        }
        return $this->individualPage($r, ['member_number' => $number, 'type_id' => '', 'start' => $this->today(), 'due_date' => ''], null);
    }

    public function createIndividual(Request $r): Response
    {
        $v = $this->texts($r, ['member_number', 'type_id', 'start', 'due_date']);
        try {
            $mid = (int) $this->db->fetchValue('SELECT id FROM members WHERE member_number = ?', [trim($v['member_number'])]);
            try {
                $callId = $this->calls->createForMember($mid, (int) $v['type_id'], ['start' => $v['start'], 'due_date' => $v['due_date']], $this->actor($r));
            } catch (NotFound) {
                throw new DomainException('Aucun adhérent de votre périmètre ne porte ce numéro.');
            }
        } catch (DomainException $e) {
            return $this->individualPage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Appel créé.');
        return Response::redirect('/admin/adherents/' . $mid . '?onglet=cotisations');
    }

    public function batchForm(Request $r): Response
    {
        return $this->batchPage($r, ['type_id' => '', 'start' => $this->today()], null);
    }

    /** Étape de confirmation : compte les adhérents concernés SANS rien créer. */
    public function batchConfirm(Request $r): Response
    {
        $v = $this->texts($r, ['type_id', 'start']);
        try {
            $preview = $this->calls->previewBatch((int) $v['type_id'], $this->day($v['start']), $this->actor($r));
        } catch (DomainException $e) {
            return $this->batchPage($r, $v, $e->getMessage());
        }
        $type = $this->db->fetch('SELECT name, amount FROM contribution_types WHERE id = ?', [(int) $v['type_id']]);
        return $this->page($r, 'admin/contributions/batch-confirm', 'Confirmer le lot', ['values' => $v, 'preview' => $preview, 'type' => $type, 'banner' => CallService::BATCH_BANNER]);
    }

    public function batchRun(Request $r): Response
    {
        $v = $this->texts($r, ['type_id', 'start']);
        try {
            $res = $this->calls->generateBatch((int) $v['type_id'], $this->day($v['start']), $this->actor($r));
        } catch (DomainException $e) {
            return $this->batchPage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', $res['created'] . ' appel(s) créé(s), ' . $res['skipped'] . ' adhérent(s) déjà appelé(s).');
        return Response::redirect('/admin/cotisations/appels');
    }

    public function cancelForm(Request $r): Response
    {
        return $this->cancelPage($r, '', null);
    }

    public function cancel(Request $r): Response
    {
        $v = $this->texts($r, ['reason']);
        $call = $this->call($r);
        try {
            $this->calls->cancel((int) $call['id'], $v['reason'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->cancelPage($r, $v['reason'], $e->getMessage());
        }
        $this->session->flash('status', 'Appel annulé.');
        return Response::redirect('/admin/cotisations/appels');
    }

    /** @return array<string,mixed> appel visible par l'acteur, sinon 404 */
    private function call(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $c = $this->db->fetch('SELECT c.id, c.member_id, c.period_label, c.amount_due, c.status, m.last_name, m.first_names, t.name AS type_name
            FROM contribution_calls c JOIN members m ON m.id = c.member_id JOIN contribution_types t ON t.id = c.contribution_type_id WHERE c.id = ?', [$id]) ?? throw new NotFound("Appel $id introuvable.");
        $this->scope->assertMayAccess($this->actor($r), (int) $c['member_id']);
        return $c;
    }

    private function cancelPage(Request $r, string $reason, ?string $error): Response
    {
        return $this->page($r, 'admin/contributions/cancel', 'Annuler un appel', ['c' => $this->call($r), 'reason' => $reason, 'error' => $error], $error === null ? 200 : 422);
    }

    /** @param array<string,string> $values */
    private function typePage(Request $r, int $id, array $values, ?string $error): Response
    {
        return $this->page($r, 'admin/contributions/type-form', $id > 0 ? 'Modifier le type' : 'Nouveau type de cotisation', [
            'id' => $id, 'values' => $values, 'error' => $error, 'kinds' => ContributionTypeService::KINDS,
            'periodicities' => ['' => 'Non définie'] + Periods::LABELS,
            'accountOptions' => ['' => 'Choisir'] + array_column($this->db->fetchAll('SELECT id, name FROM financial_accounts WHERE is_active = 1 ORDER BY name'), 'name', 'id'),
        ], $error === null ? 200 : 422);
    }

    /** @param array<string,string> $values */
    private function individualPage(Request $r, array $values, ?string $error): Response
    {
        return $this->page($r, 'admin/contributions/individual', 'Appel individuel', ['values' => $values, 'error' => $error, 'typeOptions' => $this->activeTypes()], $error === null ? 200 : 422);
    }

    /** @param array<string,string> $values */
    private function batchPage(Request $r, array $values, ?string $error): Response
    {
        return $this->page($r, 'admin/contributions/batch', 'Générer un lot d\'appels', ['values' => $values, 'error' => $error, 'typeOptions' => $this->activeTypes(), 'banner' => CallService::BATCH_BANNER], $error === null ? 200 : 422);
    }

    /** @return array<int|string,string> */
    private function activeTypes(): array
    {
        $out = ['' => 'Choisir'];
        foreach ($this->db->fetchAll("SELECT id, name, amount FROM contribution_types WHERE status = 'actif' AND amount IS NOT NULL ORDER BY name") as $t) {
            $out[$t['id']] = $t['name'] . ' (' . Money::format(Money::fromDecimal($t['amount'])) . ')';
        }
        return $out;
    }

    private function day(string $v): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('Africa/Abidjan'));
        return $d !== false && $d->format('Y-m-d') === $v ? $d : throw new DomainException('Date invalide : choisissez une date.');
    }

    private function today(): string
    {
        return Ledger::now()->format('Y-m-d');
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
            'csrfToken' => $this->csrf->token(), 'autoBanner' => CallService::autoBanner($this->db),
            'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
