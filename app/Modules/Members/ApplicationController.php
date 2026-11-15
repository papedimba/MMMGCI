<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Core\View;
use App\Modules\AuditLog\SensitiveAccess;
use DomainException;

/**
 * Demandes d'adhésion. Périmètre : sans members.scope.all, les demandes dont la zone saisie est celle du compte (le schéma n'a pas de colonne
 * « créée par » ; un délégué voit donc les demandes de sa zone, pas seulement les siennes). Hors périmètre ou inconnue : 404.
 */
final class ApplicationController
{
    private const PAGE = 25;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private MemberScope $scope, private SensitiveAccess $sensitive, private Uploads $uploads, private Audit $audit, private ApplicationService $service)
    {
    }

    public function index(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $status = $r->input('etat');
        $status = is_string($status) && isset(ApplicationService::STATES[$status]) ? $status : '';
        [$sql, $params] = $this->visible($userId);
        $where = $sql . ($status !== '' ? ' AND a.status = ?' : '');
        $params = $status !== '' ? [...$params, $status] : $params;
        $page = $r->input('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM membership_applications a WHERE $where", $params);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll("SELECT a.id, a.reference, a.status, a.source, a.created_at, a.member_id,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload, '$.last_name')) AS last_name, JSON_UNQUOTE(JSON_EXTRACT(a.payload, '$.first_names')) AS first_names
            FROM membership_applications a WHERE $where ORDER BY a.id DESC LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), $params);
        return $this->page($r, 'admin/members/applications/index', 'Demandes d\'adhésion', ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'etat' => $status]);
    }

    public function createForm(Request $r): Response
    {
        if ($this->scope->forUser((int) $r->attribute('user')['id']) === 0) {
            return new Response($this->view->render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        return $this->form($r, array_fill_keys(ApplicationService::FIELDS, ''));
    }

    public function store(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $zone = $this->scope->forUser($userId);
        if ($zone === 0) {
            return new Response($this->view->render('errors/403', ['title' => 'Accès refusé']), 403);
        }
        $data = [];
        foreach (ApplicationService::FIELDS as $k) {
            $v = $r->post($k);
            $data[$k] = is_string($v) ? $v : '';
        }
        if (!$this->gate->allows($userId, 'members.documents.view')) {
            $data = array_merge($data, array_fill_keys(['id_document_type', 'id_document_number', 'seaman_book_number', 'seaman_book_expiry'], ''));
        }
        if ($zone !== null) {
            $data['zone_id'] = (string) $zone; // zone imposée par le serveur
        }
        try {
            $id = $this->service->submit($data, $zone === null ? 'saisie' : 'delegue', $userId, $r->ip());
        } catch (DomainException $e) {
            return $this->form($r, $data, $e->getMessage());
        }
        $this->session->flash('status', 'Demande enregistrée : ' . $this->db->fetchValue('SELECT reference FROM membership_applications WHERE id = ?', [$id]) . '.');
        return Response::redirect("/admin/adherents/demandes/$id");
    }

    public function show(Request $r): Response
    {
        return $this->showPage($r);
    }

    /** @param array{visible: list<array<string,mixed>>, hidden_count: int}|null $dup */
    private function showPage(Request $r, ?array $dup = null, int $status = 200): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $app = $this->find($r);
        $payload = json_decode((string) $app['payload'], true) ?: [];
        $docs = $this->gate->allows($userId, 'members.documents.view');
        $revealed = [];
        if ($docs) {
            foreach (['id_document_number', 'seaman_book_number'] as $k) {
                if ((string) ($payload[$k] ?? '') !== '') {
                    $this->sensitive->record($userId, 'membership_application', (int) $app['id'], $k, $r->ip());
                    $revealed[$k] = true;
                }
            }
        }
        $label = fn (string $table, string $col, mixed $id): string => $id === null || $id === '' ? '' : (string) $this->db->fetchValue("SELECT $col FROM $table WHERE id = ?", [(int) $id]);
        return $this->page($r, 'admin/members/applications/show', 'Demande ' . $app['reference'], [
            'app' => $app, 'payload' => $payload, 'canDocuments' => $docs, 'revealed' => $revealed, 'dup' => $dup,
            'zoneName' => $label('zones', 'name', $payload['zone_id'] ?? null), 'categoryName' => $label('member_categories', 'name', $payload['category_id'] ?? null),
            'professionName' => $label('maritime_professions', 'name', $payload['profession_id'] ?? null),
            'files' => $docs ? $this->db->fetchAll('SELECT f.file_id, t.label AS type_label, x.original_name FROM membership_application_files f
                JOIN member_document_types t ON t.code = f.type JOIN files x ON x.id = f.file_id WHERE f.application_id = ? ORDER BY f.id', [(int) $app['id']]) : [],
            'docTypes' => array_column($this->db->fetchAll('SELECT code, label FROM member_document_types ORDER BY label'), 'label', 'code'),
            'reviewer' => $app['reviewer_id'] === null ? '' : (string) $this->db->fetchValue('SELECT name FROM users WHERE id = ?', [(int) $app['reviewer_id']]),
        ], $status);
    }

    public function verify(Request $r): Response
    {
        return $this->act($r, fn (int $id, int $u) => $this->service->markVerifying($id, $u), 'Demande prise en charge.');
    }

    public function complements(Request $r): Response
    {
        $note = $r->post('note');
        return $this->act($r, fn (int $id, int $u) => $this->service->requestComplements($id, is_string($note) ? $note : '', $u), 'Compléments demandés.');
    }

    public function reject(Request $r): Response
    {
        if ($r->post('confirm') !== '1') {
            $this->session->flash('error', 'Cochez « Je confirme le rejet » : cette décision est définitive.');
            return Response::redirect('/admin/adherents/demandes/' . (int) $r->attribute('id'));
        }
        $reason = $r->post('reason');
        return $this->act($r, fn (int $id, int $u) => $this->service->reject($id, is_string($reason) ? $reason : '', $u), 'Demande rejetée.');
    }

    public function validate(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $app = $this->find($r);
        try {
            $memberId = $this->service->validate((int) $app['id'], $userId, $r->post('confirm_duplicate') === '1');
        } catch (DuplicatesFound $d) {
            return $this->showPage($r, $d->found);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/admin/adherents/demandes/' . $app['id']);
        }
        $this->session->flash('status', 'Demande validée : n° ' . $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$memberId]) . '.');
        return Response::redirect("/admin/adherents/$memberId");
    }

    /** Pièce jointe à une demande encore ouverte (members.documents.view, comme les pièces d'adhérent). */
    public function upload(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $app = $this->find($r);
        $back = Response::redirect('/admin/adherents/demandes/' . $app['id']);
        $type = $r->post('type');
        if (in_array($app['status'], ['validee', 'rejetee'], true)) {
            $this->session->flash('error', 'Cette demande est clôturée : aucune pièce ne peut être ajoutée.');
            return $back;
        }
        if (!is_string($type) || $this->db->fetchValue('SELECT 1 FROM member_document_types WHERE code = ?', [$type]) === null) {
            $this->session->flash('error', 'Type de pièce invalide : choisissez-le dans la liste proposée.');
            return $back;
        }
        $file = $r->file('piece');
        try {
            if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new UploadError('Choisissez un fichier à déposer.');
            }
            $this->db->transaction(function () use ($file, $type, $app, $userId): void {
                $fileId = $this->uploads->store($file, 'document', false, $userId);
                $rid = $this->db->insert('membership_application_files', ['application_id' => $app['id'], 'type' => $type, 'file_id' => $fileId]);
                $this->audit->log($userId, 'application.document_added', 'membership_application', (int) $app['id'], null, ['row_id' => $rid, 'type' => $type, 'file_id' => $fileId]);
            });
        } catch (UploadError $e) {
            $this->session->flash('error', $e->getMessage());
            return $back;
        }
        $this->session->flash('status', 'Pièce enregistrée.');
        return $back;
    }

    public function download(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $app = $this->find($r);
        $file = $this->db->fetch('SELECT x.id, x.mime, x.original_name FROM membership_application_files f JOIN files x ON x.id = f.file_id WHERE f.application_id = ? AND f.file_id = ?',
            [$app['id'], (int) $r->attribute('fid')]) ?? throw new NotFound('Pièce introuvable.');
        try {
            $body = (string) file_get_contents($this->uploads->path((int) $file['id']));
        } catch (\InvalidArgumentException) {
            throw new NotFound('Fichier introuvable.');
        }
        $this->sensitive->record($userId, 'membership_application', (int) $app['id'], 'document', $r->ip());
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_name']) ?: 'piece';
        return new Response($body, 200, ['Content-Type' => (string) $file['mime'], 'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** @param callable(int,int):void $do */
    private function act(Request $r, callable $do, string $ok): Response
    {
        $app = $this->find($r);
        try {
            $do((int) $app['id'], (int) $r->attribute('user')['id']);
            $this->session->flash('status', $ok);
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/adherents/demandes/' . $app['id']);
    }

    /** @return array{string, list<int>} */
    private function visible(int $userId): array
    {
        $zone = $this->scope->forUser($userId);
        return match (true) {
            $zone === null => ['1 = 1', []],
            $zone === 0 => ['1 = 0', []],
            default => ["CAST(JSON_UNQUOTE(JSON_EXTRACT(a.payload, '$.zone_id')) AS UNSIGNED) = ?", [$zone]],
        };
    }

    /** @return array<string,mixed> @throws NotFound */
    private function find(Request $r): array
    {
        [$sql, $params] = $this->visible((int) $r->attribute('user')['id']);
        return $this->db->fetch("SELECT a.* FROM membership_applications a WHERE a.id = ? AND $sql", [(int) $r->attribute('id'), ...$params])
            ?? throw new NotFound('Demande introuvable.');
    }

    /** @param array<string,mixed> $values */
    private function form(Request $r, array $values, ?string $error = null): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $db = $this->db;
        $opts = static fn (string $table, mixed $current, string $first) => ['' => $first] + array_column(
            $db->fetchAll("SELECT id, name FROM $table WHERE is_active = 1 OR id = ? ORDER BY name", [(int) $current]), 'name', 'id');
        return $this->page($r, 'admin/members/form', 'Nouvelle demande d\'adhésion', [
            'application' => true, 'member' => null, 'values' => array_map(static fn ($v) => is_string($v) ? $v : '', $values), 'error' => $error, 'errors' => [],
            'docs' => $this->gate->allows($userId, 'members.documents.view'), 'scopeAll' => $this->scope->forUser($userId) === null,
            'zones' => $opts('zones', $values['zone_id'] ?? 0, 'Aucune'),
            'zoneName' => (string) $db->fetchValue('SELECT name FROM zones WHERE id = ?', [(int) ($values['zone_id'] ?? 0)]),
            'categories' => $opts('member_categories', $values['category_id'] ?? 0, 'Aucune'),
            'professions' => $opts('maritime_professions', $values['profession_id'] ?? 0, 'Aucune'),
            'specialties' => $opts('maritime_specialties', $values['specialty_id'] ?? 0, 'Aucune'),
            'idTypes' => ['' => 'Non renseigné'] + array_column($db->fetchAll('SELECT code, label FROM member_document_types ORDER BY label'), 'label', 'code'),
        ], $error === null ? 200 : 422);
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
            'flash' => $error ?? $this->session->pullFlash('status'),
            'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
