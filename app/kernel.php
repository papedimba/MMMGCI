<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http\ForceHttps;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Http\SecurityHeaders;
use App\Core\Http\StartSession;
use App\Core\Http\VerifyCsrf;
use App\Core\Session;
use App\Core\View;

/** Assemble l'application ; $httpSession = false pour la construire sans session PHP (tests, CLI). */
return static function (Config $config, bool $httpSession): App {
    $view = new View(__DIR__ . '/views');
    $store = [];
    $session = new Session($store, (int) $config->get('SESSION_IDLE_MINUTES', 30));
    $csrf = new Csrf($session);

    // Connexion paresseuse : une base en panne ne casse ni les autres routes ni les pages d'erreur.
    $database = null;
    $db = static function () use (&$database, $config): Database {
        return $database ??= Database::connect($config);
    };

    $titles = [400 => 'Requête invalide', 403 => 'Accès refusé', 404 => 'Page introuvable', 405 => 'Méthode non autorisée', 419 => 'Session expirée'];
    $errors = static function (int $status, Request $r) use ($view, $titles): Response {
        $status = isset($titles[$status]) ? $status : 404;
        return new Response($view->render("errors/$status", ['title' => $titles[$status]]), $status);
    };

    // Contrôleurs et middleware nommés par classe : dépendances résolues par type parmi les services du socle.
    $services = [Config::class => $config, View::class => $view, Session::class => $session, Csrf::class => $csrf];
    $make = static function (string $class) use ($services, $db): object {
        $args = array_map(
            static fn(ReflectionParameter $p): object => match ($type = (string) $p->getType()) {
                Database::class => $db(),
                default => $services[$type] ?? throw new LogicException("Dépendance « $type » non résolue pour $class."),
            },
            (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
        return new $class(...$args);
    };

    $router = new Router($make, $errors);
    (require dirname(__DIR__) . '/routes/web.php')($router, $view, $csrf, $db);

    return new App($config, $router, [
        new ForceHttps((string) $config->get('APP_ENV', 'production')),
        new SecurityHeaders(),
        new StartSession($session, $httpSession),
        new VerifyCsrf($csrf, $view),
    ]);
};
