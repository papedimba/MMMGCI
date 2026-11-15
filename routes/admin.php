<?php
declare(strict_types=1);

use App\Core\Http\PermissionGuard;
use App\Core\Http\Request;
use App\Core\Http\RequireAuth;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\AuditLog\AuditLogController;
use App\Modules\AuditLog\AuditQuery;
use App\Modules\AuditLog\SensitiveAccess;
use App\Modules\Auth\LinkMailer;
use App\Modules\Auth\LoginController;
use App\Modules\Auth\LoginThrottle;
use App\Modules\Auth\PasswordController;
use App\Modules\Auth\PasswordReset;
use App\Modules\Members\ApplicationController;
use App\Modules\Members\ApplicationService;
use App\Modules\Members\CardController;
use App\Modules\Members\CardService;
use App\Modules\Members\DocumentController;
use App\Modules\Members\MemberController;
use App\Modules\Members\MemberScope;
use App\Modules\Members\MemberSearch;
use App\Modules\Members\MemberService;
use App\Modules\Members\ReferentialController;
use App\Modules\Members\Referentials;
use App\Modules\Members\StatusService;
use App\Modules\Roles\RoleController;
use App\Modules\Roles\RoleService;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Users\UserController;
use App\Modules\Users\UserService;

/** @param array<string,mixed> $deps services de app/kernel.php ; db, gate, auth et audit sont des fabriques paresseuses */
return static function (Router $r, array $deps): void {
    ['view' => $view, 'csrf' => $csrf, 'session' => $session, 'db' => $db, 'gate' => $gate, 'auth' => $auth, 'audit' => $audit, 'mailer' => $mailer, 'defer' => $defer, 'clock' => $clock, 'config' => $config, 'uploads' => $uploads] = $deps;
    $login = static fn (): LoginController => new LoginController($view, $csrf, $db(), $auth(), $audit(), new LoginThrottle($db()), Closure::fromCallable($clock), $session);
    $links = static fn (): LinkMailer => new LinkMailer($mailer, (string) $config->get('APP_URL', ''),
        !in_array($config->get('APP_ENV', 'production'), ['local', 'testing'], true));
    $password = static fn (): PasswordController => new PasswordController($view, $csrf, $session, $db(), $auth(), $gate(), $audit(),
        new LoginThrottle($db()), new PasswordReset($db()), $links(), Closure::fromCallable($defer), Closure::fromCallable($clock));
    $users = static fn (): UserController => new UserController($view, $csrf, $session, $db(), $gate(), new UserService($db(), $audit(), $gate()),
        new PasswordReset($db()), $links(), Closure::fromCallable($defer), Closure::fromCallable($clock));
    $roles = static fn (): RoleController => new RoleController($view, $csrf, $session, $db(), $gate(), new RoleService($db(), $audit(), $gate()));
    $journal = static fn (): AuditLogController => new AuditLogController($view, $csrf, $session, $db(), $gate(), $audit(),
        new AuditQuery($db()), new SensitiveAccess($db()));
    $referentials = static fn (): ReferentialController => new ReferentialController($view, $csrf, $session, $db(), $gate(), new Referentials($db(), $audit()));
    $members = static fn (): MemberController => new MemberController($view, $csrf, $session, $db(), $gate(), new MemberSearch($db(), new MemberScope($db(), $gate())),
        new MemberScope($db(), $gate()), new SensitiveAccess($db()),
        new MemberService($db(), $audit(), new Settings($db()), new Sequence($db()), new MemberScope($db(), $gate())), $uploads(), $audit(),
        new StatusService($db(), $audit(), new MemberScope($db(), $gate())));
    $documents = static fn (): DocumentController => new DocumentController($db(), $session, new MemberScope($db(), $gate()), new SensitiveAccess($db()), $uploads(), $audit());
    $cards = static fn (): CardController => new CardController($view, $csrf, $session, $db(), $gate(), new MemberScope($db(), $gate()),
        new CardService($db(), $audit(), new Settings($db()), new Sequence($db()), new MemberScope($db(), $gate())), (string) $config->get('APP_URL', ''));
    $applications = static fn (): ApplicationController => new ApplicationController($view, $csrf, $session, $db(), $gate(), new MemberScope($db(), $gate()), new SensitiveAccess($db()), $uploads(), $audit(),
        new ApplicationService($db(), $audit(), new Sequence($db()), new MemberService($db(), $audit(), new Settings($db()), new Sequence($db()), new MemberScope($db(), $gate()))));
    $requireAuth = static fn (): RequireAuth => new RequireAuth($auth());

    /** Middleware d'autorisation d'une route : $r->get(..., [$requireAuth, $permission('users.manage')]) */
    $permission = static fn (string $name): PermissionGuard => new PermissionGuard($name, $auth, $view, $audit);

    $r->get('/admin/connexion', static fn (Request $q): Response => $login()->show($q));
    $r->post('/admin/connexion', static fn (Request $q): Response => $login()->login($q));
    // Ouvertes (sans session) : mot de passe oublié et réinitialisation par jeton.
    $r->get('/admin/mot-de-passe-oublie', static fn (Request $q): Response => $password()->forgotForm($q));
    $r->post('/admin/mot-de-passe-oublie', static fn (Request $q): Response => $password()->forgot($q));
    $r->get('/admin/reinitialiser/{token:[a-f0-9]{64}}', static fn (Request $q): Response => $password()->resetForm($q));
    $r->post('/admin/reinitialiser/{token:[a-f0-9]{64}}', static fn (Request $q): Response => $password()->reset($q));
    $r->get('/admin/mon-compte/mot-de-passe', static fn (Request $q): Response => $password()->changeForm($q), [$requireAuth]);
    $r->post('/admin/mon-compte/mot-de-passe', static fn (Request $q): Response => $password()->change($q), [$requireAuth]);
    $r->post('/admin/deconnexion', static fn (Request $q): Response => $login()->logout($q), [$requireAuth]);

    $manageUsers = [$requireAuth, $permission('users.manage')];
    $r->get('/admin/utilisateurs', static fn (Request $q): Response => $users()->index($q), $manageUsers);
    $r->get('/admin/utilisateurs/nouveau', static fn (Request $q): Response => $users()->createForm($q), $manageUsers);
    $r->post('/admin/utilisateurs/nouveau', static fn (Request $q): Response => $users()->create($q), $manageUsers);
    $r->get('/admin/utilisateurs/{id:\d+}', static fn (Request $q): Response => $users()->editForm($q), $manageUsers);
    $r->post('/admin/utilisateurs/{id:\d+}', static fn (Request $q): Response => $users()->edit($q), $manageUsers);
    $r->post('/admin/utilisateurs/{id:\d+}/activation', static fn (Request $q): Response => $users()->activation($q), $manageUsers);

    $manageRoles = [$requireAuth, $permission('roles.manage')];
    $r->get('/admin/roles', static fn (Request $q): Response => $roles()->index($q), $manageRoles);
    $r->get('/admin/roles/nouveau', static fn (Request $q): Response => $roles()->createForm($q), $manageRoles);
    $r->post('/admin/roles/nouveau', static fn (Request $q): Response => $roles()->create($q), $manageRoles);
    $r->get('/admin/roles/{id:\d+}', static fn (Request $q): Response => $roles()->editForm($q), $manageRoles);
    $r->post('/admin/roles/{id:\d+}', static fn (Request $q): Response => $roles()->edit($q), $manageRoles);
    $r->post('/admin/roles/{id:\d+}/suppression', static fn (Request $q): Response => $roles()->delete($q), $manageRoles);

    $manageSettings = [$requireAuth, $permission('settings.manage')];
    $r->get('/admin/adherents/referentiels', static fn (Request $q): Response => $referentials()->index($q), $manageSettings);
    $r->post('/admin/adherents/referentiels', static fn (Request $q): Response => $referentials()->save($q), $manageSettings);
    $r->post('/admin/adherents/referentiels/activation', static fn (Request $q): Response => $referentials()->activation($q), $manageSettings);

    $viewMembers = [$requireAuth, $permission('members.view')];
    $r->get('/admin/adherents/demandes', static fn (Request $q): Response => $applications()->index($q), $viewMembers);
    $r->get('/admin/adherents/demandes/{id:\d+}', static fn (Request $q): Response => $applications()->show($q), $viewMembers);
    $r->get('/admin/adherents/demandes/nouvelle', static fn (Request $q): Response => $applications()->createForm($q), [$requireAuth, $permission('members.create')]);
    $r->post('/admin/adherents/demandes/nouvelle', static fn (Request $q): Response => $applications()->store($q), [$requireAuth, $permission('members.create')]);
    $validateMembers = [$requireAuth, $permission('members.validate')];
    $r->post('/admin/adherents/demandes/{id:\d+}/prise-en-charge', static fn (Request $q): Response => $applications()->verify($q), $validateMembers);
    $r->post('/admin/adherents/demandes/{id:\d+}/complements', static fn (Request $q): Response => $applications()->complements($q), $validateMembers);
    $r->post('/admin/adherents/demandes/{id:\d+}/rejeter', static fn (Request $q): Response => $applications()->reject($q), $validateMembers);
    $r->post('/admin/adherents/demandes/{id:\d+}/valider', static fn (Request $q): Response => $applications()->validate($q), $validateMembers);
    $exportMembers = [$requireAuth, $permission('members.export')];
    $r->get('/admin/adherents/export.csv', static fn (Request $q): Response => $members()->export($q), $exportMembers);
    $r->get('/admin/adherents/export.xlsx', static fn (Request $q): Response => $members()->export($q), $exportMembers);
    $r->get('/admin/adherents/imprimer', static fn (Request $q): Response => $members()->printList($q), $exportMembers);
    $r->get('/admin/adherents', static fn (Request $q): Response => $members()->index($q), $viewMembers);
    $r->get('/admin/adherents/{id:\d+}', static fn (Request $q): Response => $members()->show($q), $viewMembers);
    $r->get('/admin/adherents/{id:\d+}/photo', static fn (Request $q): Response => $documents()->photo($q), $viewMembers);

    $createMembers = [$requireAuth, $permission('members.create')];
    $r->get('/admin/adherents/nouveau', static fn (Request $q): Response => $members()->createForm($q), $createMembers);
    $r->post('/admin/adherents/nouveau', static fn (Request $q): Response => $members()->store($q), $createMembers);
    $updateMembers = [$requireAuth, $permission('members.update')];
    $r->get('/admin/adherents/{id:\d+}/modifier', static fn (Request $q): Response => $members()->editForm($q), $updateMembers);
    $r->post('/admin/adherents/{id:\d+}/modifier', static fn (Request $q): Response => $members()->update($q), $updateMembers);
    $changeStatus = [$requireAuth, $permission('members.status')];
    $r->get('/admin/adherents/{id:\d+}/statut', static fn (Request $q): Response => $members()->statusForm($q), $changeStatus);
    $r->post('/admin/adherents/{id:\d+}/statut', static fn (Request $q): Response => $members()->changeStatus($q), $changeStatus);
    $r->get('/admin/adherents/{id:\d+}/carte', static fn (Request $q): Response => $cards()->show($q), $updateMembers);
    $r->get('/admin/adherents/{id:\d+}/carte/imprimer', static fn (Request $q): Response => $cards()->printCard($q), $updateMembers);
    $r->post('/admin/adherents/{id:\d+}/carte', static fn (Request $q): Response => $cards()->issue($q), $updateMembers);
    $r->post('/admin/adherents/{id:\d+}/carte/{cardId:\d+}/revoquer', static fn (Request $q): Response => $cards()->revoke($q), $updateMembers);
    $r->post('/admin/adherents/{id:\d+}/ayants-droit', static fn (Request $q): Response => $members()->addBeneficiary($q), $updateMembers);
    $r->post('/admin/adherents/{id:\d+}/ayants-droit/{bid:\d+}/supprimer', static fn (Request $q): Response => $members()->deleteBeneficiary($q), $updateMembers);
    $documentsView = [$requireAuth, $permission('members.documents.view')];
    $r->post('/admin/adherents/{id:\d+}/pieces', static fn (Request $q): Response => $documents()->upload($q), $documentsView);
    $r->post('/admin/adherents/demandes/{id:\d+}/pieces', static fn (Request $q): Response => $applications()->upload($q), $documentsView);
    $r->get('/admin/adherents/demandes/{id:\d+}/pieces/{fid:\d+}', static fn (Request $q): Response => $applications()->download($q), $documentsView);
    $r->get('/admin/adherents/{id:\d+}/pieces/{fid:\d+}', static fn (Request $q): Response => $documents()->download($q), $documentsView);

    // Lecture seule : aucune route ne modifie ni ne supprime une ligne du journal.
    $viewAudit = [$requireAuth, $permission('audit.view')];
    $r->get('/admin/journal', static fn (Request $q): Response => $journal()->index($q), $viewAudit);
    $r->post('/admin/journal/verification', static fn (Request $q): Response => $journal()->verify($q), $viewAudit);

    // Galerie du kit et page d'exemple d'impression : outils de développement, routes non enregistrées en production (404).
    if ($config->get('APP_ENV', 'production') !== 'production') {
        $r->get('/admin/composants', static function (Request $q) use ($view, $csrf, $session, $gate): Response {
            $user = $q->attribute('user');
            return new Response($view->render('admin/design/index', [
                'title' => 'Composants', 'user' => $user, 'csrfToken' => $csrf->token(), 'flash' => $session->pullFlash('status'),
                'can' => static fn (string $p): bool => $gate()->allows((int) $user['id'], $p),
            ], 'layouts/admin'));
        }, [$requireAuth, $permission('users.manage')]);

        // Page d'exemple du gabarit d'impression (contrôle PDF) : mêmes règles que la galerie.
        $r->get('/admin/composants/document', static function (Request $q) use ($view): Response {
            return new Response($view->render('admin/design/document', ['title' => 'Document d\'exemple', 'reference' => 'EXEMPLE-0001'], 'layouts/print'));
        }, [$requireAuth, $permission('users.manage')]);
    }

    $r->get('/admin', static function (Request $q) use ($view, $csrf, $session, $gate): Response {
        $user = $q->attribute('user');
        $can = static fn (string $p): bool => $gate()->allows((int) $user['id'], $p); // Gate met en cache : une requête par page
        return new Response($view->render('admin/dashboard', [
            'title' => 'Tableau de bord',
            'user' => $user,
            'can' => $can,
            'csrfToken' => $csrf->token(),
            'flash' => $session->pullFlash('status'),
        ], 'layouts/admin'));
    }, [$requireAuth]);
};
