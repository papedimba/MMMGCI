<?php
declare(strict_types=1);

namespace App\Modules\Finance;

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
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Écrans finances : lecture = finance.view, écriture = finance.manage (permissions posées par routes/admin.php). Aucune dépense n'est saisissable ici. */
final class FinanceController
{
    private const PER_PAGE = 25;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private Ledger $ledger, private AccountService $accounts, private CategoryService $categories, private AttachmentService $attachments, private Uploads $uploads, private SensitiveAccess $sensitive)
    {
    }

    public function index(Request $r): Response
    {
        $rows = $this->db->fetchAll('SELECT id, name, type, is_active FROM financial_accounts ORDER BY is_active DESC, name');
        foreach ($rows as &$a) {
            $a['balance'] = $this->ledger->balance((int) $a['id']);
        }
        return $this->page($r, 'admin/finance/accounts', 'Finances', ['rows' => $rows, 'types' => AccountService::TYPES]);
    }

    public function accountForm(Request $r): Response
    {
        return $this->accountFormPage($r, ['name' => '', 'type' => 'general', 'opening' => ''], null);
    }

    public function createAccount(Request $r): Response
    {
        $v = $this->texts($r, ['name', 'type', 'opening']);
        try {
            $id = $this->accounts->create($v['name'], $v['type'], $v['opening'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->accountFormPage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Compte créé.');
        return Response::redirect("/admin/finances/comptes/$id");
    }

    public function account(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $acc = $this->db->fetch('SELECT id, name, type, is_active, opening_balance FROM financial_accounts WHERE id = ?', [$id]) ?? throw new NotFound("Compte $id introuvable.");
        $from = $this->date($r->input('du'));
        $to = $this->date($r->input('au'));
        $cat = (int) $r->input('categorie', 0);
        $where = ['t.account_id = ?'];
        $params = [$id];
        if ($from !== null) {
            $where[] = 't.occurred_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 't.occurred_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        if ($cat > 0) {
            $where[] = 't.category_id = ?';
            $params[] = $cat;
        }
        $cond = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions t WHERE $cond", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $r->input('page', 1)));
        $rows = $this->db->fetchAll(
            "SELECT t.id, t.reference, t.direction, t.amount, t.occurred_at, t.description, t.authorization_ref, t.reversal_of_id, t.source_type, c.name AS category_name,
                    (SELECT x.reference FROM financial_transactions x WHERE x.id = t.reversal_of_id) AS reversal_of_reference,
                    (SELECT COUNT(*) FROM financial_transactions x WHERE x.reversal_of_id = t.id) AS reversed
             FROM financial_transactions t JOIN financial_categories c ON c.id = t.category_id
             WHERE $cond ORDER BY t.occurred_at DESC, t.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $ids = array_map(static fn (array $t): int => (int) $t['id'], $rows);
        $files = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->fetchAll("SELECT a.attachable_id, a.file_id, f.original_name FROM financial_attachments a JOIN files f ON f.id = a.file_id
                WHERE a.attachable_type = 'financial_transaction' AND a.attachable_id IN ($in) ORDER BY a.id", $ids) as $f) {
                $files[(int) $f['attachable_id']][] = $f;
            }
        }
        $query = array_filter(['du' => $from, 'au' => $to, 'categorie' => $cat > 0 ? (string) $cat : null], static fn ($v): bool => $v !== null);
        return $this->page($r, 'admin/finance/account', (string) $acc['name'], [
            'acc' => $acc, 'types' => AccountService::TYPES, 'balance' => $this->ledger->balance($id), 'rows' => $rows, 'files' => $files,
            'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $query, 'from' => $from ?? '', 'to' => $to ?? '', 'cat' => $cat,
            'categoryOptions' => ['' => 'Toutes'] + array_column($this->db->fetchAll('SELECT id, name FROM financial_categories ORDER BY direction, name'), 'name', 'id'),
        ]);
    }

    public function accountActivation(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        try {
            $this->accounts->setActive($id, $r->post('active') === '1', $this->actor($r));
        } catch (DomainException) {
            throw new NotFound("Compte $id introuvable.");
        }
        $this->session->flash('status', $r->post('active') === '1' ? 'Compte réactivé.' : 'Compte désactivé.');
        return Response::redirect("/admin/finances/comptes/$id");
    }

    public function categoriesPage(Request $r, array $values = ['name' => '', 'direction' => 'recette'], ?string $error = null): Response
    {
        $rows = $this->db->fetchAll('SELECT id, name, direction, is_active FROM financial_categories ORDER BY direction, name');
        return $this->page($r, 'admin/finance/categories', 'Catégories financières', ['rows' => $rows, 'values' => $values, 'error' => $error], $error === null ? 200 : 422);
    }

    public function createCategory(Request $r): Response
    {
        $v = $this->texts($r, ['name', 'direction']);
        try {
            $this->categories->create($v['name'], $v['direction'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->categoriesPage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Catégorie créée.');
        return Response::redirect('/admin/finances/categories');
    }

    public function categoryActivation(Request $r): Response
    {
        try {
            $this->categories->setActive((int) $r->attribute('id'), $r->post('active') === '1', $this->actor($r));
        } catch (DomainException) {
            throw new NotFound('Catégorie introuvable.');
        }
        $this->session->flash('status', $r->post('active') === '1' ? 'Catégorie réactivée.' : 'Catégorie désactivée.');
        return Response::redirect('/admin/finances/categories');
    }

    public function resourceForm(Request $r): Response
    {
        return $this->resourcePage($r, ['account_id' => (string) (int) $r->input('compte', 0), 'category_id' => '', 'amount' => '',
            'occurred_on' => $this->today(), 'description' => '', 'authorization_ref' => ''], null);
    }

    /** Recettes uniquement : le sens est imposé ici, aucune saisie de dépense n'existe dans ce module. */
    public function recordResource(Request $r): Response
    {
        $v = $this->texts($r, ['account_id', 'category_id', 'amount', 'occurred_on', 'description', 'authorization_ref']);
        try {
            try {
                $cents = Money::parse($v['amount']);
            } catch (\InvalidArgumentException $e) {
                throw new DomainException($e->getMessage());
            }
            $day = $this->date($v['occurred_on']) ?? throw new DomainException('Date invalide : choisissez une date.');
            $at = new DateTimeImmutable($day . ' ' . Ledger::now()->format('H:i:s'), new DateTimeZone('Africa/Abidjan'));
            $file = $r->file('justificatif');
            $actor = $this->actor($r);
            $this->db->transaction(function () use ($v, $cents, $at, $file, $actor): void {
                $id = $this->ledger->record((int) $v['account_id'], (int) $v['category_id'], 'recette', $cents, $at,
                    ['source_type' => 'ressource', 'description' => $v['description'], 'authorization_ref' => $v['authorization_ref']], $actor);
                if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $this->attachments->attach('financial_transaction', $id, $this->uploads->store($file, 'document', false, $actor), 'Justificatif', $actor);
                }
            });
        } catch (DomainException | UploadError $e) {
            return $this->resourcePage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Ressource enregistrée.');
        return Response::redirect('/admin/finances/comptes/' . (int) $v['account_id']);
    }

    public function reverseForm(Request $r): Response
    {
        $t = $this->transaction($r);
        // Même refus (422) qu'au POST pour une cible non contre-passable : source de circuit, contre-passation, ou déjà contre-passée.
        $error = !Ledger::isManual($t['source_type']) ? Ledger::WORKFLOW_REVERSAL_MESSAGE
            : ($t['reversal_of_id'] !== null ? 'Cette écriture est elle-même une contre-passation : elle ne peut pas être contre-passée.'
            : ($this->db->fetchValue('SELECT 1 FROM financial_transactions WHERE reversal_of_id = ? LIMIT 1', [$t['id']]) !== null ? 'Cette écriture a déjà été contre-passée.' : null));
        return $this->reversePage($r, '', $error);
    }

    public function reverse(Request $r): Response
    {
        $t = $this->transaction($r);
        $v = $this->texts($r, ['reason']);
        try {
            $this->ledger->reverseResource((int) $t['id'], $v['reason'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->reversePage($r, $v['reason'], $e->getMessage());
        }
        $this->session->flash('status', 'Écriture contre-passée.');
        return Response::redirect('/admin/finances/comptes/' . (int) $t['account_id']);
    }

    /** Justificatif d'une écriture, servi par le contrôleur depuis le disque privé. */
    public function attachment(Request $r): Response
    {
        $file = $this->db->fetch("SELECT f.id, f.mime, f.original_name FROM financial_attachments a JOIN files f ON f.id = a.file_id
            WHERE a.attachable_type = 'financial_transaction' AND a.attachable_id = ? AND a.file_id = ?", [(int) $r->attribute('id'), (int) $r->attribute('fid')])
            ?? throw new NotFound('Justificatif introuvable.');
        try {
            $body = (string) file_get_contents($this->uploads->path((int) $file['id']));
        } catch (\InvalidArgumentException) {
            throw new NotFound('Fichier introuvable.');
        }
        $this->sensitive->consulted((int) $r->attribute('user')['id'], 'financial_transaction', (int) $r->attribute('id'), 'justificatif', $r->ip());
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_name']) ?: 'justificatif';
        return new Response($body, 200, ['Content-Type' => (string) $file['mime'], 'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,string> $values */
    private function accountFormPage(Request $r, array $values, ?string $error): Response
    {
        return $this->page($r, 'admin/finance/account-form', 'Nouveau compte', ['values' => $values, 'error' => $error, 'types' => AccountService::TYPES], $error === null ? 200 : 422);
    }

    /** @param array<string,string> $values */
    private function resourcePage(Request $r, array $values, ?string $error): Response
    {
        $opts = static fn (array $rows): array => ['' => 'Choisir'] + array_column($rows, 'name', 'id');
        return $this->page($r, 'admin/finance/other-resources', 'Enregistrer une ressource', [
            'values' => $values, 'error' => $error, 'exceptional' => Ledger::EXCEPTIONAL,
            'accountOptions' => $opts($this->db->fetchAll('SELECT id, name FROM financial_accounts WHERE is_active = 1 ORDER BY name')),
            'categoryOptions' => $opts($this->db->fetchAll("SELECT id, name FROM financial_categories WHERE direction = 'recette' AND is_active = 1 ORDER BY name")),
        ], $error === null ? 200 : 422);
    }

    private function reversePage(Request $r, string $reason, ?string $error): Response
    {
        $t = $this->transaction($r);
        return $this->page($r, 'admin/finance/reversal', 'Contre-passer une écriture', ['t' => $t, 'reason' => $reason, 'error' => $error], $error === null ? 200 : 422);
    }

    /** @return array<string,mixed> */
    private function transaction(Request $r): array
    {
        $id = (int) $r->attribute('id');
        return $this->db->fetch('SELECT t.id, t.reference, t.account_id, t.direction, t.amount, t.occurred_at, t.description, t.reversal_of_id, t.source_type, a.name AS account_name, c.name AS category_name
            FROM financial_transactions t JOIN financial_accounts a ON a.id = t.account_id JOIN financial_categories c ON c.id = t.category_id WHERE t.id = ?', [$id])
            ?? throw new NotFound("Écriture $id introuvable.");
    }

    private function today(): string
    {
        return Ledger::now()->format('Y-m-d');
    }

    private function date(mixed $v): ?string
    {
        $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
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
