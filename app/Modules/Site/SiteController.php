<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\SiteSettings;
use Closure;
use Throwable;

/**
 * Site public : seul du contenu publié (PublishedContent), aucune session ni cookie, adresses normalisées avant toute lecture.
 * Pages en cache public 5 min avec ETag ; jamais pour une 404 ni une redirection.
 */
final class SiteController
{
    private const MAX_SEGMENTS = 5;
    private ?PublishedContent $content = null;
    private ?SiteSettings $settings = null;
    private ?Request $request = null;

    /**
     * @param Closure(): PublishedContent $content @param Closure(): SiteSettings $settings fabriques paresseuses (base jointe à la demande)
     * @param string $appUrl APP_URL : adresses canoniques absolues ; vide = chemins seuls
     */
    public function __construct(private View $view, private Closure $makeContent, private Closure $makeSettings, private string $appUrl = '')
    {
    }

    /** Adresse absolue depuis APP_URL (http(s)://hôte), sinon le chemin tel quel : jamais un hôte inventé. */
    public function absolute(string $path): string
    {
        return preg_match('#^https?://[^/\s"<>]+#i', $this->appUrl) === 1 ? rtrim($this->appUrl, '/') . $path : $path;
    }

    /**
     * Rendu dans le gabarit public avec les balises SEO : canonique = champ canonical_url sûr de l'élément, sinon l'adresse propre
     * (sans paramètre) de la requête. @param array<string,mixed> $data
     */
    public function layout(string $view, array $data, ?string $canonicalField = null, ?string $path = null): string
    {
        $field = (string) $canonicalField;
        $own = filter_var($field, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://[^/\s"<>]+#i', $field) === 1 ? $field : null;
        return $this->view->render($view, $data + ['canonical' => $own ?? $this->absolute($path ?? ($this->request === null ? '/' : '/' . trim($this->request->rawPath(), '/'))),
            'appUrl' => $this->absolute('')] + (isset($data['site']) ? [] : ['site' => $this->safeSite()]), 'layouts/public');
    }

    public function home(Request $r): Response
    {
        return $this->guarded($r, function (): Response {
            $page = $this->content()->page('/accueil');
            if ($page === null || isset($page['redirect'])) {
                return $this->html($this->layout('home', ['title' => 'MMMGCI', 'site' => $this->site()]));
            }
            return $this->renderPage($page);
        }, fn (): Response => new Response($this->view->render('home', ['title' => 'MMMGCI'], 'layouts/public'))); // base injoignable : page d'attente, sans cache
    }

    /** Route attrape-tout : pages à l'adresse plate /{slug}. */
    public function show(Request $r): Response
    {
        return $this->guarded($r, function () use ($r): Response {
            $path = self::normalized($r);
            $page = $this->content()->page($path);
            if ($page === null) {
                return $this->notFound();
            }
            if (isset($page['redirect'])) {
                return self::moved((string) $page['redirect']);
            }
            return $page['slug'] === 'accueil' ? self::moved('/') : $this->renderPage($page);
        }, null, true);
    }

    public function articles(Request $r): Response
    {
        return $this->guarded($r, fn (): Response => $this->listPage('Actualités', 'articles', $this->content()->articles(['limit' => 50])));
    }

    public function article(Request $r): Response
    {
        return $this->guarded($r, function () use ($r): Response {
            $a = $this->content()->article(self::slug($r));
            if ($a === null) {
                return $this->notFound();
            }
            $category = $a['category_id'] === null ? null : $this->content()->category((int) $a['category_id']);
            $image = $a['featured_file_id'] === null ? null : $this->content()->fileUrl((int) $a['featured_file_id']);
            $og = $a['og_file_id'] === null ? null : $this->content()->fileUrl((int) $a['og_file_id']);
            return $this->html($this->layout('public/pages/article', ['title' => (string) ($a['seo_title'] ?: $a['title']), 'description' => $a['seo_description'] ?: $a['excerpt'],
                'article' => $a, 'category' => $category, 'image' => $image, 'ogImage' => $og ?? $image, 'ogType' => 'article', 'site' => $this->site()], $a['canonical_url'] ?? null));
        });
    }

    public function events(Request $r): Response
    {
        return $this->guarded($r, fn (): Response => $this->html($this->layout('public/pages/liste', ['title' => 'Évènements', 'kind' => 'evenements',
            'items' => $this->content()->events(['when' => 'upcoming', 'limit' => 50]), 'past' => $this->content()->events(['when' => 'past', 'limit' => 50]),
            'site' => $this->site()])));
    }

    public function event(Request $r): Response
    {
        return $this->guarded($r, function () use ($r): Response {
            $e = $this->content()->events(['slug' => self::slug($r), 'limit' => 1])[0] ?? null;
            if ($e === null) {
                return $this->notFound();
            }
            $image = $e['featured_file_id'] === null ? null : $this->content()->fileUrl((int) $e['featured_file_id']);
            return $this->html($this->layout('public/pages/evenement', ['title' => (string) $e['title'], 'event' => $e, 'image' => $image, 'ogImage' => $image, 'site' => $this->site()]));
        });
    }

    public function documents(Request $r): Response
    {
        return $this->guarded($r, fn (): Response => $this->listPage('Documents', 'documents', $this->content()->documents()));
    }

    public function galleries(Request $r): Response
    {
        return $this->guarded($r, fn (): Response => $this->listPage('Galeries', 'galeries', $this->content()->galleries()));
    }

    public function gallery(Request $r): Response
    {
        return $this->guarded($r, function () use ($r): Response {
            $slug = self::slug($r);
            $album = array_values(array_filter($this->content()->galleries(), static fn (array $g): bool => $g['slug'] === $slug))[0] ?? null;
            if ($album === null) {
                return $this->notFound();
            }
            return $this->html($this->layout('public/pages/galerie', ['title' => (string) $album['title'], 'album' => $album,
                'items' => $this->content()->galleryItems((int) $album['id']), 'site' => $this->site()]));
        });
    }

    /**
     * Chemin brut normalisé, ou réponse : 404 pour //, \, ., %, caractères hors [A-Za-z0-9/-] ou plus de 5 segments ;
     * 301 (même site) vers la forme sans barre finale et en minuscules. Le chemin n'est jamais renvoyé tel quel au visiteur.
     */
    public static function check(Request $r): ?Response
    {
        $raw = $r->rawPath();
        if ($raw === '' || $raw[0] !== '/' || str_contains($raw, '//') || preg_match('~^[A-Za-z0-9/-]+$~D', $raw) !== 1
            || count(explode('/', trim($raw, '/'))) > self::MAX_SEGMENTS) {
            return new Response('', 404);
        }
        $clean = $raw === '/' ? '/' : '/' . strtolower(trim($raw, '/'));
        return $clean === $raw ? null : self::moved($clean);
    }

    private static function normalized(Request $r): string
    {
        return '/' . trim($r->rawPath(), '/'); // déjà vérifié par check()
    }

    private static function slug(Request $r): string
    {
        return (string) $r->attribute('slug');
    }

    /** @param string $to chemin du site : seule une forme /a-b/c est acceptée, jamais //hôte ni adresse absolue */
    private static function moved(string $to): Response
    {
        if ($to !== '/' && preg_match('~^(/[a-z0-9]+(?:-[a-z0-9]+)*)+$~D', $to) !== 1) {
            return new Response('', 404);
        }
        return new Response('', 301, ['Location' => $to, 'Cache-Control' => 'no-store']);
    }

    /**
     * @param Closure(): Response $handle @param (Closure(): Response)|null $onFailure
     * @param bool $follow adresse de page : une ancienne adresse non normalisée (/Ancien/) va en une seule 301 vers la nouvelle
     */
    private function guarded(Request $r, Closure $handle, ?Closure $onFailure = null, bool $follow = false): Response
    {
        $this->request = $r;
        $bad = self::check($r);
        if ($bad !== null && $bad->status() !== 404 && $follow) {
            try {
                $page = $this->content()->page((string) $bad->headers()['Location']);
                if (isset($page['redirect'])) {
                    $bad = self::moved((string) $page['redirect']); // cible altérée : 404 ci-dessous
                }
            } catch (Throwable) {
                // base injoignable : la 301 de normalisation suffit
            }
        }
        if ($bad !== null) {
            return $bad->status() === 404 ? $this->notFound() : $bad;
        }
        try {
            $res = $handle();
        } catch (Throwable $e) {
            error_log('Site public : ' . get_class($e)); // sans message ni chemin
            return $onFailure !== null ? $onFailure() : $this->notFound();
        }
        return $res->status() === 404 && $res->body() === '' ? $this->notFound() : $res;
    }

    /** @param array<string,mixed> $page */
    private function renderPage(array $page): Response
    {
        $image = $page['og_file_id'] === null ? null : $this->content()->fileUrl((int) $page['og_file_id']);
        $home = $page['template'] === 'accueil';
        $extra = [];
        if ($home) {
            // Héro : image à la une réelle (image publique avec texte alternatif) sinon dessin. Bouton principal : « Demander mon adhésion »
            // vers le formulaire si l'adhésion en ligne est ouverte, sinon « Comment adhérer » vers la page « adhesion » si elle est en ligne.
            $hero = $page['featured_file_id'] === null ? null : $this->content()->fileUrl((int) $page['featured_file_id']);
            $adhesion = $this->content()->page('/adhesion');
            $open = ApplyFlow::isOpen($this->settings ??= ($this->makeSettings)());
            $extra = ['hero' => $hero !== null && plain($hero['alt'] ?? '') !== '' && preg_match('~\.(?:jpe?g|png|webp|gif)$~i', $hero['url']) === 1 ? $hero : null,
                'adhesion' => $open ? '/adhesion-en-ligne' : ($adhesion !== null && !isset($adhesion['redirect']) ? '/adhesion' : null), 'adhesionOpen' => $open,
                'aides' => array_filter(is_array($page['blocks'] ?? null) ? $page['blocks'] : [], static fn ($b): bool => is_array($b) && ($b['type'] ?? '') === 'domaines' && !empty($b['items'])) !== []];
        }
        $forms = array_filter(is_array($page['blocks'] ?? null) ? $page['blocks'] : [], static fn ($b): bool => is_array($b) && ($b['type'] ?? '') === 'formulaire') !== [];
        return $this->html($this->layout($home ? 'public/pages/accueil' : 'public/pages/standard', ['title' => (string) ($page['seo_title'] ?: $page['title']), 'description' => $page['seo_description'] ?: $page['excerpt'],
            'page' => $page, 'body' => (new PageRenderer($this->view, $this->content(), ApplyFlow::isOpen($this->settings ??= ($this->makeSettings)())))->render($page), 'ogImage' => $image, 'site' => $this->site()] + $extra,
            $page['canonical_url'] ?? null, $home ? '/' : null), $forms || $home); // accueil : bouton selon l'ouverture, revalidé
    }

    /** @param list<array<string,mixed>> $items */
    private function listPage(string $title, string $kind, array $items): Response
    {
        return $this->html($this->layout('public/pages/liste', ['title' => $title, 'kind' => $kind, 'items' => $items, 'site' => $this->site()]));
    }

    /**
     * Réponse publique en cache 5 min, ETag fort du HTML ; 304 si le navigateur a déjà cette version (comparaison faible : « W/ » accepté, « * » aussi ; suffixe « -gzip » ajouté par mod_deflate retiré).
     * Le HTML ne dépend pas des paramètres d'adresse (canonique = adresse propre) : un même ETag pour /p et /p?x=1 désigne bien le même contenu.
     * @param bool $revalidate page à état changeant (bloc formulaire : bouton d'adhésion ouvert/fermé) : no-cache, revalidée par ETag à chaque visite
     */
    private function html(string $html, bool $revalidate = false): Response
    {
        $etag = '"' . substr(hash('sha256', $html), 0, 32) . '"';
        $headers = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => $revalidate ? 'no-cache' : 'public, max-age=300', 'ETag' => $etag];
        $sent = $this->request?->header('If-None-Match');
        $tags = $sent === null ? [] : array_map(static fn (string $t): string => (string) preg_replace(['~^W/~', '~-gzip"$~'], ['', '"'], trim($t)), explode(',', $sent));
        if (in_array($etag, $tags, true) || in_array('*', $tags, true)) {
            return new Response('', 304, $headers);
        }
        return new Response($html, 200, $headers);
    }

    public function notFound(): Response
    {
        $html = $this->view->render('public/pages/not-found', ['title' => 'Page introuvable', 'noindex' => true, 'site' => $this->safeSite()], 'layouts/public');
        return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /**
     * Données du gabarit : menus publiés, lien « Adhésion » de secours, coordonnées validées seulement.
     * @return array<string,mixed>
     */
    private function site(): array
    {
        $c = $this->content();
        $s = $this->settings ??= ($this->makeSettings)();
        $header = $c->menu('header');
        $contacts = [];
        foreach (['contact_phone', 'contact_email', 'address', 'social_facebook', 'social_whatsapp'] as $k) {
            $v = $s->publicValue($k);
            if (is_string($v) && $v !== '') {
                $contacts[$k] = $v;
            }
        }
        $adhesion = $header === [] ? $c->page('/adhesion') : null;
        return ['header' => $header, 'footer' => $c->menu('footer'), 'contacts' => $contacts,
            'adhesion' => $adhesion !== null && !isset($adhesion['redirect']) ? '/adhesion' : null];
    }

    /** Données du gabarit public (aussi pour l'adhésion en ligne) ; [] si la base est injoignable. @return array<string,mixed> */
    public function safeSite(): array
    {
        try {
            return $this->site();
        } catch (Throwable) {
            return [];
        }
    }

    private function content(): PublishedContent
    {
        return $this->content ??= ($this->makeContent)();
    }
}
