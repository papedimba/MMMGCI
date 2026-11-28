<?php
declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\Auth\LoginThrottle;
use App\Modules\Espace\AccountController;
use App\Modules\Espace\LoginController;

/**
 * Espace adhérent : chargé entre routes/admin.php et routes/web.php (avant l'attrape-tout public, qui exclut /espace).
 * Session et jeton propres (member_session, member_csrf) ; aucune route n'accepte une session d'administration.
 * Toute route protégée porte $requireMember ; les écritures métier aussi $requireMemberWrite.
 *
 * @param array<string,mixed> $deps services de app/kernel.php
 */
return static function (Router $r, array $deps): void {
    ['view' => $view, 'db' => $db, 'audit' => $audit, 'clock' => $clock, 'member_csrf' => $csrf, 'member_auth' => $auth, 'require_member' => $requireMember,
        'member_session' => $session, 'member_accounts' => $accounts] = $deps;
    $login = static fn (): LoginController => new LoginController($view, $csrf, $db(), $auth(), $audit(), new LoginThrottle($db()), Closure::fromCallable($clock));

    $r->get('/espace/connexion', static fn (Request $q): Response => $login()->show($q));
    $r->post('/espace/connexion', static fn (Request $q): Response => $login()->login($q));
    $r->post('/espace/deconnexion', static fn (Request $q): Response => $login()->logout($q), [$requireMember]);
    $account = static fn (): AccountController => new AccountController($view, $csrf, $session, $db(), $auth(), $accounts(), Closure::fromCallable($clock));
    // Sans session : activation (code d'activation ou de réinitialisation remis par un gestionnaire), oubli, lien de réinitialisation.
    $r->get('/espace/activer', static fn (Request $q): Response => $account()->activateForm($q));
    $r->post('/espace/activer', static fn (Request $q): Response => $account()->activate($q));
    $r->get('/espace/mot-de-passe-oublie', static fn (Request $q): Response => $account()->forgotForm($q));
    $r->post('/espace/mot-de-passe-oublie', static fn (Request $q): Response => $account()->forgot($q));
    $r->get('/espace/reinitialiser/{token:[a-f0-9]{64}}', static fn (Request $q): Response => $account()->resetForm($q));
    $r->post('/espace/reinitialiser/{token:[a-f0-9]{64}}', static fn (Request $q): Response => $account()->reset($q));
    $r->get('/espace/compte', static fn (Request $q): Response => $account()->account($q), [$requireMember]);
    $r->post('/espace/compte', static fn (Request $q): Response => $account()->changePassword($q), [$requireMember]);
    $r->get('/espace', static fn (Request $q): Response => $login()->home($q), [$requireMember]);
};
