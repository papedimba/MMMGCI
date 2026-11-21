<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\View;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\BannerService;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\GalleryService;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\SiteSettings;
use App\Modules\Cms\TestimonialService;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Members\ApplicationService;
use App\Modules\Members\MemberScope;
use App\Modules\Members\MemberService;
use App\Modules\Members\VerifyController;
use App\Modules\Site\ApplyController;
use App\Modules\Site\ApplyFlow;
use App\Modules\Site\SiteController;

/** Chargé APRÈS routes/admin.php : la route attrape-tout du site public doit rester la dernière enregistrée. */
return static function (Router $router, View $view, Csrf $csrf, callable $db, array $services): void {
    ['audit' => $audit, 'gate' => $gate, 'uploads' => $uploads, 'clock' => $clock] = $services;
    $content = static function () use ($db, $audit, $gate, $uploads, $clock): PublishedContent {
        $pages = new PageService($db(), $audit(), $gate());
        return new PublishedContent($db(), $pages, new MenuService($db(), $audit(), $gate(), $pages), new ArticleService($db(), $audit(), $gate()),
            new EventService($db(), $audit(), $gate()), new DocumentService($db(), $audit(), $gate()), new BannerService($db(), $audit(), $gate()),
            new GalleryService($db(), $audit(), $gate()), new TestimonialService($db(), $audit(), $gate(), new \App\Core\Settings($db()), $uploads()),
            Closure::fromCallable($clock), $uploads());
    };
    $siteSettings = static fn (): SiteSettings => new SiteSettings($db(), $audit(), $gate());
    $site = static fn (): SiteController => new SiteController($view, $content, $siteSettings);

    // Site public : sans session ni cookie (StartSession, app/kernel.php) ; accueil = page publiée « accueil », sinon page d'attente.
    $router->get('/', static fn (Request $r): Response => $site()->home($r));

    // Publique, sans session ni cookie (exemptée dans app/kernel.php). Le jeton n'est pas contraint par la route : tout jeton invalide rend la page neutre.
    $router->get('/verifier/{jeton}', static fn(Request $r): Response => (new VerifyController($view, $db()))->show($r));

    $router->get('/sante', static function (Request $r) use ($db): Response {
        try {
            $ok = (string) $db()->fetchValue('SELECT 1') === '1';
        } catch (Throwable) {
            $ok = false; // aucun détail exposé
        }
        return new Response($ok ? 'ok' : 'indisponible', $ok ? 200 : 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    });

    // Adhésion en ligne (slug réservé) : session et cookie seulement ici (StartSession, app/kernel.php) ; garde d'ouverture à chaque requête.
    $apply = static function () use ($view, $csrf, $db, $services, $content, $siteSettings, $site): ApplyController {
        ['session' => $session, 'config' => $config, 'mailer' => $mailer, 'defer' => $defer, 'clock' => $clock, 'audit' => $audit, 'gate' => $gate, 'uploads' => $uploads] = $services;
        $flow = static function () use ($db, $session, $config, $mailer, $defer, $clock, $audit, $gate, $uploads): ApplyFlow {
            $seq = new Sequence($db());
            $members = new MemberService($db(), $audit(), new Settings($db()), $seq, new MemberScope($db(), $gate()));
            return new ApplyFlow($db(), $session, $members, new ApplicationService($db(), $audit(), $seq, $members), $uploads(), (string) $config->get('APP_KEY'),
                Closure::fromCallable($clock), Closure::fromCallable($mailer), Closure::fromCallable($defer));
        };
        return new ApplyController($view, $csrf, $flow, $siteSettings, $content, static fn (): array => $site()->safeSite());
    };
    foreach (['get', 'post'] as $verb) {
        $router->$verb('/adhesion-en-ligne', static fn (Request $r): Response => $apply()->entry($r));
        $router->$verb('/adhesion-en-ligne/etape/{n}', static fn (Request $r): Response => $apply()->step($r));
        $router->$verb('/adhesion-en-ligne/envoye', static fn (Request $r): Response => $apply()->done($r));
    }

    $router->get('/actualites', static fn (Request $r): Response => $site()->articles($r));
    $router->get('/actualites/{slug}', static fn (Request $r): Response => $site()->article($r));
    $router->get('/evenements', static fn (Request $r): Response => $site()->events($r));
    $router->get('/evenements/{slug}', static fn (Request $r): Response => $site()->event($r));
    $router->get('/documents', static fn (Request $r): Response => $site()->documents($r));
    $router->get('/galeries', static fn (Request $r): Response => $site()->galleries($r));
    $router->get('/galeries/{slug}', static fn (Request $r): Response => $site()->gallery($r));

    // Attrape-tout : pages à l'adresse plate /{slug} (et anciennes adresses → 301). TOUJOURS la dernière route.
    // Jamais sous /admin : une adresse d'administration inconnue garde la 404 standard, et un GET sur une route POST seule, sa 405.
    $router->get('/{chemin:(?!admin(?:/|$)).+}', static fn (Request $r): Response => $site()->show($r));
};
