<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\AuditLog\SensitiveAccess;

/** Liste et fiche des adhérents, en lecture (permission members.view, posée par routes/admin.php ; périmètre par MemberScope). */
final class MemberController
{
    public const TABS = ['identite' => 'Identité', 'profession' => 'Profession', 'ayants-droit' => 'Ayants droit', 'pieces' => 'Pièces',
        'cotisations' => 'Cotisations', 'demandes' => 'Demandes', 'historique' => 'Historique'];
    private const SORTS = ['name' => 'Nom (A à Z)', '-name' => 'Nom (Z à A)', 'number' => 'Numéro', '-joined_at' => 'Adhésion (récentes d\'abord)', 'joined_at' => 'Adhésion (anciennes d\'abord)', 'status' => 'Statut'];

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private MemberSearch $search, private MemberScope $scope, private SensitiveAccess $sensitive)
    {
    }

    public function index(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $criteria = [];
        foreach (['q', 'status', 'category_id', 'zone_id', 'profession_id'] as $k) {
            $v = $r->input($k);
            if (is_string($v) && trim($v) !== '') {
                $criteria[$k] = trim($v);
            }
        }
        $sort = $r->input('tri');
        $sort = is_string($sort) && isset(self::SORTS[$sort]) ? $sort : 'name';
        $page = $r->input('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
        $res = $this->search->run($userId, $criteria, $sort, $page);
        $page = min($page, $res['pages']);
        $opts = static fn (array $rows, string $key, string $label): array => ['' => 'Tous'] + array_column($rows, $label, $key);
        $db = $this->db;
        return $this->page($r, 'admin/members/index', 'Adhérents', $res + [
            'page' => $page, 'criteria' => $criteria, 'sort' => $sort, 'sorts' => self::SORTS,
            'query' => $criteria + ($sort !== 'name' ? ['tri' => $sort] : []),
            'statuses' => $opts($db->fetchAll('SELECT code, label FROM member_statuses WHERE is_active = 1 ORDER BY label'), 'code', 'label'),
            'categories' => $opts($db->fetchAll('SELECT id, name FROM member_categories WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'zones' => $opts($db->fetchAll('SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'professions' => $opts($db->fetchAll('SELECT id, name FROM maritime_professions WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'statusLabels' => array_column($db->fetchAll('SELECT code, label FROM member_statuses'), 'label', 'code'),
        ]);
    }

    public function show(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id); // NotFound : 404 central
        $m = $this->db->fetch(
            'SELECT m.*, z.name AS zone_name, c.name AS category_name, p.name AS profession_name, s.name AS specialty_name, st.label AS status_label
             FROM members m LEFT JOIN zones z ON z.id = m.zone_id LEFT JOIN member_categories c ON c.id = m.category_id
             LEFT JOIN maritime_professions p ON p.id = m.profession_id LEFT JOIN maritime_specialties s ON s.id = m.specialty_id
             LEFT JOIN member_statuses st ON st.code = m.status WHERE m.id = ?', [$id]);
        $docs = $this->gate->allows($userId, 'members.documents.view');
        $tabs = array_filter(self::TABS, static fn (string $k): bool => $k !== 'pieces' || $docs, ARRAY_FILTER_USE_KEY);
        $tab = $r->input('onglet');
        $tab = is_string($tab) && isset($tabs[$tab]) ? $tab : 'identite';
        $data = ['m' => $m, 'tab' => $tab, 'tabs' => $tabs, 'canDocuments' => $docs, 'revealed' => []];
        if ($tab === 'ayants-droit') {
            $data['rows'] = $this->db->fetchAll('SELECT last_name, first_names, relationship, birth_date, phone FROM member_beneficiaries WHERE member_id = ? ORDER BY last_name, first_names, id', [$id]);
        } elseif ($tab === 'historique') {
            $data['rows'] = $this->db->fetchAll(
                'SELECT h.from_status, h.to_status, h.reason, h.decision_ref, h.created_at, u.name AS author, f.label AS from_label, t.label AS to_label
                 FROM member_status_history h LEFT JOIN users u ON u.id = h.changed_by LEFT JOIN member_statuses f ON f.code = h.from_status
                 LEFT JOIN member_statuses t ON t.code = h.to_status WHERE h.member_id = ? ORDER BY h.id DESC', [$id]);
        } elseif ($docs && ($tab === 'identite' || $tab === 'profession')) {
            // Une consultation journalisée par affichage effectif du numéro (pièce d'identité ou livret), jamais pour un champ vide.
            $field = $tab === 'identite' ? 'id_document_number' : 'seaman_book_number';
            if ((string) $m[$field] !== '') {
                $this->sensitive->record($userId, 'member', $id, $field, $r->ip());
                $data['revealed'][$field] = true;
            }
        }
        return $this->page($r, 'admin/members/show', $m['last_name'] . ' ' . $m['first_names'], $data);
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data): Response
    {
        $user = $r->attribute('user');
        return new Response($this->view->render($view, [
            'title' => $title,
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $this->session->pullFlash('status'),
        ] + $data, 'layouts/admin'));
    }
}
