<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Auth\LinkMailer;
use App\Modules\Members\MemberScope;
use Closure;
use DomainException;

/**
 * Écrans du personnel pour les accès à l'Espace adhérent : onglet de la fiche, délivrance des codes (la lettre est la réponse
 * du POST, le code n'est jamais relu ni réimprimé), liste des accès et paramètres. Les règles sont dans MemberAccountService.
 */
final class AccountAdminController
{
    public const STATES = ['sans_acces' => 'Aucun accès', 'en_attente' => "En attente d'activation", 'code_expire' => 'Code expiré', 'actif' => 'Actif', 'desactive' => 'Désactivé'];
    public const REPLAYED = 'Code déjà délivré : délivrez-en un nouveau si la lettre est perdue.';
    private const PER_PAGE = 50;
    /** Code ouvert (activation ou réinitialisation) le plus tardif d'un compte : seule sa date est lue, jamais le haché. */
    private const OPEN_CODE = "SELECT MAX(c.expires_at) FROM member_activation_codes c WHERE c.member_account_id = a.id AND c.used_at IS NULL AND c.purpose IN ('activation', 'reinitialisation')";

    /** @param Closure(): MemberAccountService $accounts */
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private MemberScope $scope, private Closure $accounts, private EspaceSettingsService $settings, private LinkMailer $links, private string $appUrl)
    {
    }

    /** État affiché d'un accès (onglet et liste) ; un mot de passe absent veut dire « code à saisir ». */
    public static function state(?array $account, ?string $expires, int $now): string
    {
        return match (true) {
            $account === null => 'sans_acces',
            $account['status'] === 'desactive' => 'desactive',
            $account['password'] !== null => 'actif',
            $expires !== null && strtotime($expires . ' UTC') > $now => 'en_attente',
            default => 'code_expire',
        };
    }

    /** Données de l'onglet « Espace adhérent » (périmètre déjà vérifié par la fiche). @return array<string,mixed> */
    public function tab(int $memberId, int $userId): array
    {
        $m = $this->db->fetch('SELECT id, member_number, email, phone, status, birth_date, anonymized_at FROM members WHERE id = ?', [$memberId]);
        $account = $this->db->fetch('SELECT a.id, a.status, a.password, a.last_login_at, (' . self::OPEN_CODE . ') AS expires FROM member_accounts a WHERE a.member_id = ?', [$memberId]);
        $access = new MemberAccess(new Settings($this->db));
        $level = $access->level((string) $m['status']);
        $state = self::state($account, $account['expires'] ?? null, time());
        $can = fn (string $p): bool => $this->gate->allows($userId, $p);
        $blocker = match (true) {
            $m['anonymized_at'] !== null || $level === 'desactive' => "Le statut de cet adhérent ne permet pas l'accès à l'Espace adhérent.",
            $m['birth_date'] === null => MemberAccountService::NO_BIRTH_DATE,
            default => null,
        };
        // Formulaire qui délivre un code, selon l'état : ouvrir, nouveau code d'activation, ou réinitialisation (accès actif).
        $form = match (true) {
            $blocker !== null => null,
            $state === 'sans_acces' && $can('espace.accounts.manage') => 'open',
            $account !== null && $account['status'] === 'en_attente' && $can('espace.accounts.manage') => 'code',
            $account !== null && $account['status'] === 'actif' && $can('espace.accounts.reset') => 'reset',
            default => null,
        };
        $emailOff = match (true) {
            $form === null => null,
            (string) $m['email'] === '' => 'Aucune adresse e-mail dans la fiche : seule la lettre imprimée est possible.',
            !$this->links->ready() => "L'envoi d'e-mails n'est pas configuré : seule la lettre imprimée est possible.",
            default => null,
        };
        $keys = [];
        foreach (array_filter([$m['member_number'], $m['phone'], $m['email']], static fn ($v): bool => (string) $v !== '') as $identifier) {
            $keys[] = mb_strtolower(MemberAuth::throttleKey('espace:', (string) $identifier));
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        return ['espace' => [
            'state' => $state, 'account' => $account === null ? null : ['status' => $account['status'], 'reset_pending' => $account['status'] === 'actif' && $account['password'] === null,
                'last_login_at' => $account['last_login_at'], 'expires' => $account['expires']],
            'level' => $level, 'pending' => $access->pending(), 'blocker' => $blocker, 'form' => $form, 'emailOff' => $emailOff,
            'once' => $form === null ? null : $this->csrf->oneTimeToken("espace-$form-$memberId"),
            'canManage' => $can('espace.accounts.manage'),
            'lastBlocked' => $this->db->fetchValue("SELECT MAX(created_at) FROM login_attempts WHERE succeeded = 2 AND email IN ($marks)", $keys),
            'correction' => $this->db->fetchValue("SELECT id FROM member_change_requests WHERE member_id = ? AND status = 'soumise' ORDER BY id LIMIT 1", [$memberId]),
        ]];
    }

    public function open(Request $r): Response
    {
        return $this->issue($r, 'open', fn (int $id, string $delivery, int $actor, ?string $ip): ?string => ($this->accounts)()->create($id, $delivery, $actor, $ip)['code'] ?? null);
    }

    public function reissue(Request $r): Response
    {
        return $this->issue($r, 'code', fn (int $id, string $delivery, int $actor, ?string $ip): ?string => ($this->accounts)()->reissueActivation($id, $delivery, $actor, $ip));
    }

    public function reset(Request $r): Response
    {
        $checked = $r->post('identite_verifiee') === '1'; // case cochée : seule preuve déclarative, exigée aussi par le service
        return $this->issue($r, 'reset', fn (int $id, string $delivery, int $actor, ?string $ip): ?string => ($this->accounts)()->resetByManager($id, $delivery, $checked, $actor, $ip));
    }

    public function disable(Request $r): Response
    {
        return $this->toggle($r, true, 'Accès désactivé : les sessions et les codes ouverts sont fermés.');
    }

    public function enable(Request $r): Response
    {
        return $this->toggle($r, false, 'Accès réactivé.');
    }

    /** Liste des accès, limitée au périmètre ; ni code, ni mot de passe, ni autre donnée de la fiche. */
    public function index(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        [$scopeSql, $params] = $this->scope->where($userId);
        $state = $r->input('etat');
        $state = is_string($state) && isset(self::STATES[$state]) ? $state : '';
        $scopeAll = $this->scope->forUser($userId) === null;
        $zone = $r->input('zone');
        $zone = $scopeAll && is_string($zone) && ctype_digit($zone) ? (int) $zone : 0;
        $now = gmdate('Y-m-d H:i:s');
        $open = 'EXISTS (SELECT 1 FROM member_activation_codes c WHERE c.member_account_id = a.id AND c.used_at IS NULL'
            . " AND c.purpose IN ('activation', 'reinitialisation') AND c.expires_at > ?)";
        [$stateSql, $stateParams] = match ($state) {
            'sans_acces' => [' AND a.id IS NULL', []],
            'en_attente' => [" AND a.status <> 'desactive' AND a.password IS NULL AND $open", [$now]],
            'code_expire' => [" AND a.status <> 'desactive' AND a.password IS NULL AND NOT $open", [$now]],
            'actif' => [" AND a.status <> 'desactive' AND a.password IS NOT NULL", []],
            'desactive' => [" AND a.status = 'desactive'", []],
            default => ['', []],
        };
        $where = "$scopeSql$stateSql" . ($zone > 0 ? ' AND m.zone_id = ?' : '');
        $args = [...$params, ...$stateParams, ...($zone > 0 ? [$zone] : [])];
        $from = 'FROM members m LEFT JOIN member_accounts a ON a.member_id = m.id LEFT JOIN zones z ON z.id = m.zone_id WHERE ' . $where;
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) $from", $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $r->input('page');
        $page = min($pages, is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1);
        $rows = $this->db->fetchAll('SELECT m.id, m.member_number, m.last_name, m.first_names, z.name AS zone_name, a.id AS account_id, a.status, a.last_login_at,
            (a.password IS NOT NULL) AS has_password, (' . self::OPEN_CODE . ") AS expires $from ORDER BY m.last_name, m.first_names, m.id LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $args);
        $time = time();
        foreach ($rows as &$row) {
            $row['state'] = self::state($row['account_id'] === null ? null : ['status' => $row['status'], 'password' => (int) $row['has_password'] === 1 ? '' : null], $row['expires'], $time);
            unset($row['has_password'], $row['account_id'], $row['status']);
        }
        unset($row);
        return $this->page($r, 'admin/espace/accounts', 'Accès à l\'Espace adhérent', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'state' => $state, 'zone' => $zone, 'scopeAll' => $scopeAll,
            'zones' => $scopeAll ? ['' => 'Toutes'] + array_column($this->db->fetchAll('SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name'), 'name', 'id') : [],
            'query' => array_filter(['etat' => $state, 'zone' => $zone > 0 ? (string) $zone : ''], static fn (string $v): bool => $v !== ''),
        ]);
    }

    public function settings(Request $r): Response
    {
        return $this->settingsPage($r, '', []);
    }

    public function validateSetting(Request $r): Response
    {
        $key = (string) $r->attribute('key');
        $ref = $r->post('decision_ref_' . $key);
        if ($key === 'status_access') {
            $map = $r->post('status_access');
            $entered = is_array($map) ? array_filter($map, 'is_string') : [];
            $raw = $entered === [] ? '' : (string) json_encode($entered);
        } else {
            $value = $r->post('value_' . $key);
            $raw = is_string($value) ? $value : '';
            $entered = $raw;
        }
        try {
            $this->settings->validate($key, $raw, is_string($ref) ? $ref : '', (int) $r->attribute('user')['id']);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
            return $this->settingsPage($r, $key, $entered, 422);
        }
        $this->session->flash('status', 'Paramètre validé avec la référence de la décision.');
        return Response::redirect('/admin/espace/parametres');
    }

    /** @param string|array<string,string> $entered valeur saisie à reproposer après un refus */
    private function settingsPage(Request $r, string $errorKey, string|array $entered, int $status = 200): Response
    {
        return $this->page($r, 'admin/espace/settings', "Paramètres de l'Espace adhérent",
            ['rows' => $this->settings->all(), 'statuses' => $this->settings->statuses(), 'errorKey' => $errorKey, 'entered' => $entered], $status);
    }

    /**
     * Délivrance d'un code : jeton à usage unique consommé d'abord (un rechargement ne délivre rien), puis le service.
     * Lettre : la réponse est la lettre, non mise en cache ; e-mail : retour à l'onglet, le code n'est jamais montré.
     *
     * @param Closure(int, string, int, ?string): ?string $call code formaté pour une lettre, sinon null
     */
    private function issue(Request $r, string $form, Closure $call): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id); // 404 hors périmètre, avant tout autre message
        $tab = "/admin/adherents/$id?onglet=espace";
        $once = $r->post('_once');
        if (!$this->csrf->consumeOneTime("espace-$form-$id", is_string($once) ? $once : null)) {
            $this->session->flash('error', self::REPLAYED);
            return Response::redirect($tab);
        }
        $delivery = $r->post('remise');
        try {
            $code = $call($id, is_string($delivery) ? $delivery : '', $userId, $r->ip());
        } catch (AccountRuleViolation $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect($tab);
        }
        if ($code === null) {
            $this->session->flash('status', "Code envoyé à l'adresse de la fiche.");
            return Response::redirect($tab);
        }
        $m = $this->db->fetch('SELECT m.last_name, m.first_names, m.member_number, (' . self::OPEN_CODE . ') AS expires
            FROM members m JOIN member_accounts a ON a.member_id = m.id WHERE m.id = ?', [$id]);
        return new Response($this->view->render('admin/espace/letter', ['title' => "Accès à l'Espace adhérent", 'm' => $m, 'code' => $code,
            'reset' => $form === 'reset', 'url' => rtrim($this->appUrl, '/') . '/espace/activer'], 'layouts/print'), 200,
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function toggle(Request $r, bool $disabled, string $done): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id);
        try {
            ($this->accounts)()->setDisabled($id, $disabled, $userId, $r->ip());
            $this->session->flash('status', $done);
        } catch (AccountRuleViolation $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect("/admin/adherents/$id?onglet=espace");
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        $error = $this->session->pullFlash('error');
        return new Response($this->view->render($view, [
            'title' => $title,
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $error ?? $this->session->pullFlash('status'),
            'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
