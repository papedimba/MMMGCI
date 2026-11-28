<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\ForceHttps;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Http\ShareLayoutData;
use App\Core\Http\SecurityHeaders;
use App\Core\Http\StartSession;
use App\Core\Http\VerifyCsrf;
use App\Core\Mailer;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Uploads;
use App\Core\View;
use App\Modules\Auth\Auth;
use App\Modules\Espace\MemberAccess;
use App\Modules\Espace\MemberAuth;
use App\Modules\Espace\RequireMember;
use App\Modules\Espace\RequireMemberWrite;

/**
 * Assemble l'application ; $httpSession = false pour la construire sans session PHP (tests, CLI).
 * $overrides : 'db' (Database), 'mailer' (Mailer), 'clock' (callable(): int), 'defer' (callable(callable $job): void, travail après la réponse). $services reçoit les services construits (tests) ;
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
    // Espace adhérent : second monde de session (cookie mmmgci_esp au chemin /espace, jamais envoyé à /admin), jeton CSRF propre.
    $memberStore = [];
    $memberSession = new Session($memberStore, (int) $config->get('SESSION_IDLE_MINUTES', 30), 'mmmgci_esp', '/espace');
    $memberCsrf = new Csrf($memberSession);

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
    // Pièces et photos : disque privé (hors racine web) ; 'uploads' est surchargeable pour les tests.
    $uploads = $once(static fn (): Uploads => $overrides['uploads'] ?? new Uploads($db(), __DIR__ . '/../storage/uploads/private',
        __DIR__ . '/../public/media', max(1, (int) $config->get('UPLOAD_MAX_MB', 5)) * 1048576));
    $clock = $overrides['clock'] ?? time(...);
    // shortcut: aucun gain sous mod_php ou php -S (le travail s'exécute avant que le client soit libéré) ; efficace sous PHP-FPM/LiteSpeed.
    $defer = $overrides['defer'] ?? static function (callable $job): void {
        register_shutdown_function(static function () use ($job): void {
            // PHP n'enregistre la session qu'après les fonctions d'arrêt et *_finish_request() ne la libère pas : sans cette
            // fermeture, le fichier de session reste verrouillé pendant le travail et la requête suivante du même cookie l'attend.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            // PHP exécute les fonctions d'arrêt avant de vider la sortie : c'est *_finish_request() qui libère le client.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            try {
                $job();
            } catch (\Throwable $e) {
                error_log('Travail différé en échec : ' . get_class($e)); // sans message : il pourrait contenir un jeton
            }
        });
    };
    $memberAuth = $once(static fn (): MemberAuth => new MemberAuth($memberSession, $db(), new MemberAccess(new Settings($db()))));
    $services = compact('config', 'session', 'csrf', 'db', 'gate', 'auth', 'audit', 'mailer', 'defer', 'clock', 'view', 'uploads') + [
        'member_session' => $memberSession, 'member_csrf' => $memberCsrf, 'member_auth' => $memberAuth,
        'require_member' => new RequireMember($memberAuth), 'require_member_write' => new RequireMemberWrite($view, $memberCsrf),
    ];

    $titles = [400 => 'Requête invalide', 403 => 'Accès refusé', 404 => 'Page introuvable', 405 => 'Méthode non autorisée', 419 => 'Session expirée'];
    $errors = static function (int $status, Request $r) use ($view, $titles): Response {
        $status = isset($titles[$status]) ? $status : 404;
        if (in_array($status, [404, 405], true) && !VerifyCsrf::isAdmin($r->path())) { // site public : son propre gabarit, sans menu (aucune lecture de base)
            $public = $status === 404 ? [] : ['heading' => $titles[405], 'message' => 'Cette page ne peut pas être ouverte de cette façon. Utilisez le lien ou le formulaire prévu.'];
            return new Response($view->render('public/pages/not-found', ['title' => $titles[$status], 'noindex' => true] + $public, 'layouts/public'), $status,
                ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
        }
        return new Response($view->render("errors/$status", ['title' => $titles[$status]]), $status);
    };

    $router = new Router(null, $errors);
    $services['router'] = $router;
    // Administration d'abord : le site public se termine par une route attrape-tout (GET /{chemin}) qui doit rester la dernière.
    (require dirname(__DIR__) . '/routes/admin.php')($router, $services);
    (require dirname(__DIR__) . '/routes/espace.php')($router, $services);
    (require dirname(__DIR__) . '/routes/web.php')($router, $view, $csrf, $db, $services);

    return new App($config, $router, [
        new ForceHttps((string) $config->get('APP_ENV', 'production')),
        new SecurityHeaders(),
        // Toute réponse sous /espace (redirections et erreurs comprises) : jamais en cache partagé, jamais indexée.
        new class implements Middleware {
            public function handle(Request $r, callable $next): Response
            {
                $res = $next($r);
                return VerifyCsrf::isEspace($r->path()) ? $res->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex') : $res;
            }
        },
        // Session (donc cookie) seulement pour l'administration et les futurs formulaires publics (adhésion, contact, partenariat) : tout le reste du site est sans cookie.
        // Adhésion en ligne fermée : aucune session ni cookie (la garde n'est qu'une lecture des réglages ; base injoignable = fermée).
        new StartSession($session, $httpSession, [], ['/admin', '/admin/', '/adhesion-en-ligne', '/adhesion-en-ligne/', '/contact', '/partenariat'],
            static function (Request $r) use ($db, $audit, $gate): bool {
                // Formulaires publics : session seulement à l'adresse exacte (Request retire la barre finale : « /contact/ » redirige, sans session)
                // et si la page hôte est en ligne (sinon 404 sans cookie).
                if (in_array($r->path(), ['/contact', '/partenariat'], true)) {
                    if ($r->rawPath() !== $r->path()) {
                        return true;
                    }
                    try {
                        return !\App\Modules\Site\ContactController::hostLive($db(), new \App\Modules\Cms\PageService($db(), $audit(), $gate()), $r->path());
                    } catch (\Throwable) {
                        return true;
                    }
                }
                if ($r->path() !== '/adhesion-en-ligne' && !str_starts_with($r->path(), '/adhesion-en-ligne/')) {
                    return false;
                }
                try {
                    return !\App\Modules\Site\ApplyFlow::isOpen(new \App\Modules\Cms\SiteSettings($db(), $audit(), $gate()));
                } catch (\Throwable) {
                    return true;
                }
            }),
        // Espace adhérent : sa propre session, seulement sous /espace (la liste ci-dessus reste inchangée).
        new StartSession($memberSession, $httpSession, [], ['/espace', '/espace/']),
        new VerifyCsrf($csrf, $view, $router, $memberCsrf),
        new ShareLayoutData($view, $router),
    ]);
};
