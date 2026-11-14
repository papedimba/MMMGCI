<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\LinkMailer;
use App\Modules\Auth\PasswordReset;
use Closure;
use DomainException;

/** Écrans d'administration des utilisateurs (permission users.manage, posée par routes/admin.php). */
final class UserController
{
    private const PER_PAGE = 50;
    private const INVITATION_TTL = 72 * 3600;
    private const SUBJECT = "Invitation à l'administration de la plateforme MMMGCI";

    /** @param Closure(callable): void $defer @param Closure(): int $clock */
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private UserService $users, private PasswordReset $resets, private LinkMailer $links,
        private Closure $defer, private Closure $clock)
    {
    }

    public function index(Request $r): Response
    {
        $q = $r->input('q');
        $q = is_string($q) ? trim($q) : '';
        $page = $r->input('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
        [$where, $params] = ['', []];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%'; // % et _ saisis cherchés littéralement (échappement par \ par défaut)
            [$where, $params] = ['WHERE u.name LIKE ? OR u.email LIKE ?', [$like, $like]];
        }
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM users u $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ') AS roles
             FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
             $where GROUP BY u.id, u.name, u.email, u.is_active, u.last_login_at ORDER BY u.name, u.id
             LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        return $this->page($r, 'admin/users/index', 'Utilisateurs', ['rows' => $rows, 'q' => $q, 'page' => $page, 'pages' => $pages, 'total' => $total]);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, ['name' => '', 'email' => '', 'phone' => '', 'roles' => []]);
    }

    public function create(Request $r): Response
    {
        $values = $this->posted($r);
        $now = ($this->clock)();
        $canSend = $this->links->ready(); // sinon : compte créé sans jeton, l'utilisateur passera par « Mot de passe oublié »
        try {
            // Le jeton d'invitation est émis dans la transaction de la création (et de son audit user.created).
            [$id, $token] = $this->db->transaction(function () use ($values, $r, $now, $canSend): array {
                $id = $this->users->create($values, (int) $r->attribute('user')['id']);
                return [$id, $canSend ? $this->resets->issue($id, $now, self::INVITATION_TTL) : null];
            });
        } catch (DomainException $e) {
            return $this->form($r, null, $values, $e->getMessage());
        }
        $user = $this->db->fetch('SELECT id, name, email FROM users WHERE id = ?', [$id]);
        if ($token !== null) {
            ($this->defer)(fn () => $this->links->send($user, $token, self::SUBJECT, static fn (string $link): string => "Bonjour {$user['name']},\n\n"
                . "Un compte d'administration de la plateforme MMMGCI vient d'être créé pour vous. "
                . "Pour l'activer, choisissez votre mot de passe en ouvrant ce lien :\n\n$link\n\n"
                . "Ce lien est valable 72 heures et ne peut servir qu'une fois. Passé ce délai, utilisez « Mot de passe oublié » sur la page de connexion.\n"));
        }
        $this->session->flash('status', $token !== null
            ? "L'invitation est en cours d'envoi ; si elle n'arrive pas, la personne peut utiliser « Mot de passe oublié »."
            : "Compte créé, mais l'invitation n'a pas pu être envoyée (adresse du site non configurée) : utilisez « Mot de passe oublié » une fois la configuration corrigée.");
        return Response::redirect("/admin/utilisateurs/$id");
    }

    public function editForm(Request $r): Response
    {
        $target = $this->target($r);
        if ($target === null) {
            return $this->notFound();
        }
        return $this->form($r, $target, $target + ['roles' => $this->users->roleNames((int) $target['id'])]);
    }

    public function edit(Request $r): Response
    {
        $target = $this->target($r);
        if ($target === null) {
            return $this->notFound();
        }
        $id = (int) $target['id'];
        $values = $this->posted($r);
        $actor = (int) $r->attribute('user')['id'];
        $current = $this->users->roleNames($id);
        // Rôles appliqués seulement si le formulaire les porte (absents pour son propre compte) et s'ils changent.
        $rolesChange = $r->post('roles_field') !== null && $values['roles'] !== $current;
        try {
            $this->db->transaction(function () use ($id, $values, $actor, $rolesChange): void {
                $this->users->update($id, $values, $actor);
                if ($rolesChange) {
                    $this->users->setRoles($id, $values['roles'], $actor);
                }
            });
        } catch (DomainException $e) {
            return $this->form($r, $target, $values + ($rolesChange ? [] : ['roles' => $current]), $e->getMessage());
        }
        $this->session->flash('status', 'Modifications enregistrées.');
        return Response::redirect("/admin/utilisateurs/$id");
    }

    public function activation(Request $r): Response
    {
        $target = $this->target($r);
        if ($target === null) {
            return $this->notFound();
        }
        $id = (int) $target['id'];
        $posted = $r->post('active');
        if ($posted !== '0' && $posted !== '1') {
            return $this->form($r, $target, $target + ['roles' => $this->users->roleNames($id)],
                "Demande d'activation invalide : utilisez le bouton « Désactiver » ou « Réactiver » de la fiche du compte.", 400);
        }
        $active = $posted === '1';
        try {
            $this->users->setActive($id, $active, (int) $r->attribute('user')['id']);
        } catch (DomainException $e) {
            return $this->form($r, $target, $target + ['roles' => $this->users->roleNames($id)], $e->getMessage());
        }
        $this->session->flash('status', $active ? 'Compte activé.' : 'Compte désactivé : ses sessions ouvertes ont été fermées.');
        return Response::redirect("/admin/utilisateurs/$id");
    }

    /** @return array<string,mixed>|null */
    private function target(Request $r): ?array
    {
        return $this->db->fetch('SELECT id, name, email, phone, is_active, last_login_at FROM users WHERE id = ?', [(int) $r->attribute('id')]);
    }

    /** @return array{name: mixed, email: mixed, phone: mixed, roles: list<string>} */
    private function posted(Request $r): array
    {
        $roles = array_values(array_unique(array_filter((array) $r->post('roles', []), 'is_string')));
        sort($roles);
        return ['name' => $r->post('name'), 'email' => $r->post('email'), 'phone' => $r->post('phone'), 'roles' => $roles];
    }

    /** @param array<string,mixed>|null $target null : création @param array<string,mixed> $values */
    private function form(Request $r, ?array $target, array $values, ?string $error = null, int $status = 422): Response
    {
        return $this->page($r, 'admin/users/form', $target === null ? 'Nouvel utilisateur' : 'Utilisateur : ' . $target['name'], [
            'target' => $target,
            'values' => array_map(static fn ($v) => is_string($v) || is_array($v) ? $v : '', $values),
            'roles' => $this->db->fetchAll('SELECT name, label FROM roles ORDER BY label'),
            'self' => $target !== null && (int) $target['id'] === (int) $r->attribute('user')['id'],
            'error' => $error,
        ], $error === null ? 200 : $status);
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
