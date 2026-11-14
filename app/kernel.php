<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\ForceHttps;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Http\SecurityHeaders;
use App\Core\Http\StartSession;
use App\Core\Http\VerifyCsrf;
use App\Core\Mailer;
use App\Core\Session;
use App\Core\View;
use App\Modules\Auth\Auth;

/**
 * Assemble l'application ; $httpSession = false pour la construire sans session PHP (tests, CLI).
 * $overrides : 'db' (Database), 'mailer' (Mailer), 'clock' (callable(): int). $services reçoit les services construits (tests) ;
 * db, gate, auth, audit et mailer y sont des fabriques paresseuses (callable(): objet), partagées avec les routes.
 *
 * @param array<string,mixed> $overrides
 * @param array<string,mixed>|null $services
 */
return static function (Config $config, bool $httpSession, array $overrides = [], ?array &$services = null): App {
    $view = new View(__DIR__ . '/views');
    $store = [];
    $session = new Session($store, (int) $config->get('SESSION_IDLE_MINUTES', 30));
    $csrf = new Csrf($session);

    // Connexion paresseuse : une base en panne ne casse ni les autres routes ni les pages d'erreur.
    $database = $overrides['db'] ?? null;
    $db = static function () use (&$database, $config): Database {
        return $database ??= Database::connect($config);
    };
    $once = static function (callable $make): Closure {
        $made = null;
        return static function () use (&$made, $make): object {
            return $made ??= $make();
        };
    };
    $gate = $once(static fn (): Gate => new Gate($db()));
    $auth = $once(static fn (): Auth => new Auth($session, $db(), $gate()));
    $audit = $once(static fn (): Audit => new Audit($db(), (string) $config->get('APP_KEY')));
    $mailer = $once(static fn (): Mailer => $overrides['mailer'] ?? Mailer::fromConfig($config)); // paresseux : MAIL_FROM vide ne casse que l'envoi
    $clock = $overrides['clock'] ?? time(...);
    $services = compact('config', 'session', 'csrf', 'db', 'gate', 'auth', 'audit', 'mailer', 'clock', 'view');

    $titles = [400 => 'Requête invalide', 403 => 'Accès refusé', 404 => 'Page introuvable', 405 => 'Méthode non autorisée', 419 => 'Session expirée'];
    $errors = static function (int $status, Request $r) use ($view, $titles): Response {
        $status = isset($titles[$status]) ? $status : 404;
        return new Response($view->render("errors/$status", ['title' => $titles[$status]]), $status);
    };

    $router = new Router(null, $errors);
    $services['router'] = $router;
    (require dirname(__DIR__) . '/routes/web.php')($router, $view, $csrf, $db);
    (require dirname(__DIR__) . '/routes/admin.php')($router, $services);

    return new App($config, $router, [
        new ForceHttps((string) $config->get('APP_ENV', 'production')),
        new SecurityHeaders(),
        new StartSession($session, $httpSession, ['/sante']),
        new VerifyCsrf($csrf, $view),
    ]);
};
