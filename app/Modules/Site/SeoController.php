<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Cms\PublishedContent;
use Closure;
use Throwable;

/**
 * /sitemap.xml (contenu publié seulement : PublishedContent::sitemapEntries, plus /documents s'il existe un document publié) et /robots.txt.
 * Adresses absolues depuis APP_URL ; sans APP_URL, chemins seuls et aucune ligne « Sitemap: » (jamais d'hôte deviné). Sans session ni cookie.
 */
final class SeoController
{
    /** @param Closure(): PublishedContent $content @param Closure(string): string $absolute SiteController::absolute */
    public function __construct(private Closure $content, private Closure $absolute, private bool $hasAppUrl)
    {
    }

    public function sitemap(Request $r): Response
    {
        try {
            $c = ($this->content)();
            $entries = [];
            foreach ($c->sitemapEntries() as $e) {
                $entries[] = [$e['path'] === '/accueil' ? '/' : $e['path'], $e['lastmod']];
            }
            $docs = $c->documents();
            if ($docs !== []) {
                $entries[] = ['/documents', max(array_map(static fn (array $d): string => (string) ($d['published_at'] ?? ''), $docs)) ?: null];
            }
        } catch (Throwable $e) {
            error_log('Plan du site : ' . get_class($e));
            return new Response('', 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'Retry-After' => '300']);
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as [$path, $lastmod]) {
            $xml .= '<url><loc>' . htmlspecialchars(($this->absolute)($path), ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . (is_string($lastmod) && preg_match('~^\d{4}-\d{2}-\d{2}~', $lastmod) === 1 ? '<lastmod>' . substr($lastmod, 0, 10) . '</lastmod>' : '') . "</url>\n";
        }
        return new Response($xml . "</urlset>\n", 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(Request $r): Response
    {
        $txt = "User-agent: *\nDisallow: /admin\nDisallow: /verifier/\nDisallow: /adhesion-en-ligne\n"
            . ($this->hasAppUrl ? 'Sitemap: ' . ($this->absolute)('/sitemap.xml') . "\n" : '');
        return new Response($txt, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
