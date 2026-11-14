<?php
declare(strict_types=1);

use App\Core\Http\PermissionGuard;
use App\Core\Http\Request;
use App\Core\Http\RequireAuth;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\Auth\LoginController;
use App\Modules\Auth\LoginThrottle;
use App\Modules\Auth\PasswordController;
use App\Modules\Auth\PasswordReset;

/** @param array<string,mixed> $deps services de app/kernel.php ; db, gate, auth et audit sont des fabriques paresseuses */
return static function (Router $r, array $deps): void {
    ['view' => $view, 'csrf' => $csrf, 'session' => $session, 'db' => $db, 'gate' => $gate, 'auth' => $auth, 'audit' => $audit, 'mailer' => $mailer, 'defer' => $defer, 'clock' => $clock, 'config' => $config] = $deps;
    $login = static fn (): LoginController => new LoginController($view, $csrf, $db(), $auth(), $audit(), new LoginThrottle($db()), Closure::fromCallable($clock), $session);
    $password = static fn (): PasswordController => new PasswordController($view, $csrf, $session, $db(), $auth(), $gate(), $audit(),
        new LoginThrottle($db()), new PasswordReset($db()), $mailer, Closure::fromCallable($defer), (string) $config->get('APP_URL', ''),
        !in_array($config->get('APP_ENV', 'production'), ['local', 'testing'], true), Closure::fromCallable($clock));
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
