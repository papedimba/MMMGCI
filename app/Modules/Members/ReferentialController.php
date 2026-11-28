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
use DomainException;

/** Écran des référentiels des adhérents (permission settings.manage, posée par routes/admin.php). */
final class ReferentialController
{
    public const TABS = ['categories' => ['member_categories', 'Catégories'], 'professions' => ['maritime_professions', 'Professions'],
        'specialites' => ['maritime_specialties', 'Spécialités'], 'zones' => ['zones', 'Zones'], 'statuts' => [null, 'Statuts']];
    private const BASE = '/admin/adherents/referentiels';

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private Referentials $refs)
    {
    }

    public function index(Request $r): Response
    {
        $edit = $r->input('modifier');
        return $this->page($r, $this->tab($r->input('onglet')), is_string($edit) && ctype_digit($edit) ? (int) $edit : null);
    }

    public function save(Request $r): Response
    {
        $tab = $this->tab($r->post('onglet'));
        $id = $r->post('id');
        $id = is_string($id) && ctype_digit($id) ? (int) $id : null;
        $data = ['name' => $r->post('name'), 'parent_id' => $r->post('parent_id'), 'type' => $r->post('type')];
        try {
            $this->refs->save(self::TABS[$tab][0] ?? '', $id, $data, (int) $r->attribute('user')['id']);
        } catch (DomainException $e) {
            return $this->page($r, $tab, $id, $e->getMessage(), $data, 422);
        }
        $this->session->flash('status', 'Référentiel enregistré.');
        return Response::redirect(self::BASE . '?onglet=' . $tab);
    }

    public function activation(Request $r): Response
    {
        $tab = $this->tab($r->post('onglet'));
        $id = $r->post('id');
        $active = $r->post('active');
        if (!is_string($id) || !ctype_digit($id) || ($active !== '0' && $active !== '1')) {
            return $this->page($r, $tab, null, 'Demande invalide : utilisez les boutons de la liste.', [], 400);
        }
        try {
            $this->refs->setActive(self::TABS[$tab][0] ?? '', (int) $id, $active === '1', (int) $r->attribute('user')['id']);
        } catch (DomainException $e) {
            return $this->page($r, $tab, null, $e->getMessage(), [], 422);
        }
        $this->session->flash('status', $active === '1' ? 'Élément réactivé.' : 'Élément désactivé : il reste sur les fiches existantes mais n\'est plus proposé.');
        return Response::redirect(self::BASE . '?onglet=' . $tab);
    }

    private function tab(mixed $v): string
    {
        return is_string($v) && isset(self::TABS[$v]) ? $v : 'categories';
    }

    /** @param array<string,mixed> $values */
    private function page(Request $r, string $tab, ?int $edit, ?string $error = null, array $values = [], int $status = 200): Response
    {
        $table = self::TABS[$tab][0];
        $rows = $table === null ? $this->db->fetchAll('SELECT code, label, is_proposed FROM member_statuses ORDER BY label') : $this->refs->list($table);
        if ($edit !== null && $values === []) {
            $row = array_values(array_filter($rows, static fn (array $x): bool => (int) $x['id'] === $edit))[0] ?? null;
            $values = $row ?? [];
            $edit = $row === null ? null : $edit;
        }
        $parentTable = ['professions' => 'maritime_professions', 'specialites' => 'maritime_professions', 'zones' => 'zones'][$tab] ?? null;
        $user = $r->attribute('user');
        return new Response($this->view->render('admin/members/referentials', [
            'title' => 'Référentiels des adhérents', 'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $this->session->pullFlash('status'),
            'tab' => $tab, 'tabs' => self::TABS, 'rows' => $rows, 'edit' => $edit, 'error' => $error,
            'values' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $values),
            'parents' => $parentTable === null ? null : $this->db->fetchAll("SELECT id, name FROM `$parentTable` WHERE is_active = 1 ORDER BY name"),
            'zoneTypes' => Referentials::ZONE_TYPES,
        ], 'layouts/admin'), $status);
    }
}
