<?php
declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use DomainException;

/** Écrans d'administration des rôles (permission roles.manage, posée par routes/admin.php). */
final class RoleController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private RoleService $roles)
    {
    }

    public function index(Request $r): Response
    {
        $rows = $this->db->fetchAll(
            'SELECT r.id, r.name, r.label, r.is_system,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permissions
             FROM roles r ORDER BY r.label, r.id');
        return $this->page($r, 'admin/roles/index', 'Rôles', ['rows' => $rows]);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, ['name' => '', 'label' => '', 'description' => '', 'permissions' => []]);
    }

    public function create(Request $r): Response
    {
        $values = $this->posted($r);
        try {
            $id = $this->roles->create($values['name'], $values['label'], $values['description'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->form($r, null, $values, $e->getMessage());
        }
        $this->session->flash('status', 'Rôle créé : cochez maintenant ses permissions.');
        return Response::redirect("/admin/roles/$id");
    }

    public function editForm(Request $r): Response
    {
        $target = $this->target($r);
        return $target === null ? $this->notFound() : $this->form($r, $target, $target);
    }

    public function edit(Request $r): Response
    {
        $target = $this->target($r);
        if ($target === null) {
            return $this->notFound();
        }
        $id = (int) $target['id'];
        $values = $this->posted($r);
        $actor = $this->actor($r);
        $withPermissions = $r->post('permissions_field') !== null; // absent : formulaire sans cases (libellé seul)
        try {
            $this->db->transaction(function () use ($id, $values, $actor, $withPermissions): void {
                $this->roles->update($id, $values['label'], $values['description'], $actor);
                if ($withPermissions) {
                    $this->roles->setPermissions($id, $values['permissions'], $actor);
                }
            });
        } catch (DomainException $e) {
            return $this->form($r, $target, $values + ($withPermissions ? [] : ['permissions' => $target['permissions']]), $e->getMessage());
        }
        $this->session->flash('status', 'Modifications enregistrées.');
        return Response::redirect("/admin/roles/$id");
    }

    public function delete(Request $r): Response
    {
        $target = $this->target($r);
        if ($target === null) {
            return $this->notFound();
        }
        try {
            $this->roles->delete((int) $target['id'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->form($r, $target, $target, $e->getMessage());
        }
        $this->session->flash('status', "Rôle « {$target['label']} » supprimé.");
        return Response::redirect('/admin/roles');
    }

    private function actor(Request $r): int
    {
        return (int) $r->attribute('user')['id'];
    }

    /** @return array<string,mixed>|null avec 'permissions' : noms actuels */
    private function target(Request $r): ?array
    {
        $role = $this->db->fetch('SELECT id, name, label, description, is_system FROM roles WHERE id = ?', [(int) $r->attribute('id')]);
        if ($role === null) {
            return null;
        }
        $role['permissions'] = array_column($this->db->fetchAll(
            'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$role['id']]), 'name');
        return $role;
    }

    /** @return array{name: string, label: string, description: string, permissions: list<string>} */
    private function posted(Request $r): array
    {
        $text = static fn (mixed $v): string => is_string($v) ? $v : '';
        return ['name' => $text($r->post('name')), 'label' => $text($r->post('label')), 'description' => $text($r->post('description')),
            'permissions' => array_values(array_filter((array) $r->post('permissions', []), 'is_string'))];
    }

    /** @param array<string,mixed>|null $target null : création @param array<string,mixed> $values */
    private function form(Request $r, ?array $target, array $values, ?string $error = null): Response
    {
        $modules = [];
        foreach ($this->db->fetchAll('SELECT name, label, module FROM permissions ORDER BY module, label') as $p) {
            $modules[$p['module']][] = $p;
        }
        $grantable = $this->roles->grantable($this->actor($r));
        // Affichage seul (la vue ne propose alors aucun enregistrement) ; l'écriture reste refusée côté serveur par RoleService.
        $readOnly = $target !== null && ((int) $target['is_system'] === 1 || !$this->roles->mayEdit((int) $target['id'], $this->actor($r)));
        return $this->page($r, 'admin/roles/form', $target === null ? 'Nouveau rôle' : 'Rôle : ' . $target['label'], [
            'target' => $target,
            'readOnly' => $readOnly,
            'values' => ['description' => (string) ($values['description'] ?? '')] + $values,
            'modules' => $modules,
            // Case grisée : droit non détenu par l'acteur et absent du rôle (un droit déjà présent reste décochable).
            'locked' => static fn (string $p): bool => $grantable !== null && !in_array($p, $grantable, true) && !in_array($p, $target['permissions'] ?? [], true),
            'error' => $error,
        ], $error === null ? 200 : 422);
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        return new Response($this->view->render($view, [
            'title' => $title,
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $this->session->pullFlash('status'),
        ] + $data, 'layouts/admin'), $status);
    }

    private function notFound(): Response
    {
        return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
    }
}
