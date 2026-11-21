<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Database;
use Closure;
use DateTimeImmutable;

/**
 * Lectures publiques du CMS (consommées par L4). Ne renvoie JAMAIS de brouillon, de contenu archivé ou de programmation non échue :
 * chaque méthode délègue au service du type, qui ne lit que « publié » (ou « programmé échu »), et une page en cours de relecture
 * renvoie sa version publiée conservée. Résultats mémorisés pour la durée de l'objet (une requête) seulement.
 *
 * Adresses publiques (L4 les route) :
 *   page ............ /{slug}                     (adresse plate : les slugs de pages sont uniques ; la hiérarchie sert à l'administration ;
 *                                                  /parent/enfant redirige vers /{slug} si la chaîne de parents est exacte)
 *   ancienne adresse  /{ancien-slug}              -> page() renvoie ['redirect' => '/{slug}'] : L4 répond 301
 *   article ......... /actualites/{slug}          (liste : /actualites)
 *   évènement ....... /evenements/{slug}          (liste : /evenements)
 *   documents ....... /documents ; galerie : /galeries et /galeries/{slug}
 * Les fichiers publics sont sous /media/… (url fournie par documents() et galleryItems()) et ne figurent pas au plan du site.
 */
final class PublishedContent
{
    public const ARTICLE_PATH = '/actualites/%s';
    public const EVENT_PATH = '/evenements/%s';

    /** @var array<string,mixed> */
    private array $memo = [];

    /** @param Closure(): int $clock horloge du noyau (secondes Unix) */
    public function __construct(private Database $db, private PageService $pages, private MenuService $menus, private ArticleService $articles, private EventService $events,
        private DocumentService $documents, private BannerService $banners, private GalleryService $galleries, private TestimonialService $testimonials, private Closure $clock)
    {
    }

    /** @return array<string,mixed>|null page en ligne (+ 'path'), ['redirect' => chemin] pour une ancienne adresse, null si introuvable ou hors ligne */
    public function page(string $path): ?array
    {
        return $this->memo('page:' . $path, function () use ($path): ?array {
            $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));
            if ($segments === [] || count($segments) > 5 || array_filter($segments, static fn (string $s): bool => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) !== 1) !== []) {
                return null;
            }
            $slug = end($segments);
            $id = $this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
            if ($id === null || $id === false) {
                if (count($segments) > 1) {
                    return null;
                }
                $id = $this->db->fetchValue('SELECT page_id FROM cms_page_redirects WHERE old_slug = ?', [$slug]);
                $live = $id === null || $id === false ? null : $this->pages->liveVersion((int) $id);
                return $live === null ? null : ['redirect' => '/' . $live['slug']];
            }
            $live = $this->pages->liveVersion((int) $id);
            if ($live === null) {
                return null;
            }
            if (count($segments) > 1 && !$this->chainMatches((int) $id, array_slice($segments, 0, -1))) {
                return null;
            }
            return count($segments) > 1 ? ['redirect' => '/' . $live['slug']] : $live + ['path' => '/' . $live['slug']];
        });
    }

    /** @param list<string> $ancestors slugs attendus, du plus haut au parent direct */
    private function chainMatches(int $pageId, array $ancestors): bool
    {
        $parent = $this->db->fetchValue('SELECT parent_id FROM cms_pages WHERE id = ?', [$pageId]);
        foreach (array_reverse($ancestors) as $slug) {
            $row = $parent === null || $parent === false ? null : $this->db->fetch('SELECT slug, parent_id FROM cms_pages WHERE id = ?', [(int) $parent]);
            if ($row === null || $row['slug'] !== $slug) {
                return false;
            }
            $parent = $row['parent_id'];
        }
        return $parent === null;
    }

    /** @return list<array<string,mixed>> */
    public function menu(string $location): array
    {
        return $this->memo('menu:' . $location, fn (): array => $this->menus->publicItems($location));
    }

    /** @param array{limit?:int,category?:int} $criteria @return list<array<string,mixed>> */
    public function articles(array $criteria = []): array
    {
        return $this->memo('articles:' . json_encode($criteria), fn (): array => $this->articles->liveList((int) ($criteria['limit'] ?? 20), isset($criteria['category']) ? (int) $criteria['category'] : null));
    }

    /** @return array<string,mixed>|null */
    public function article(string $slug): ?array
    {
        $id = $this->db->fetchValue('SELECT id FROM cms_articles WHERE slug = ?', [$slug]);
        return $id === null || $id === false ? null : $this->memo('article:' . $slug, fn (): ?array => $this->articles->liveVersion((int) $id));
    }

    /** @param array{when?:string,limit?:int,slug?:string} $criteria @return list<array<string,mixed>> */
    public function events(array $criteria = []): array
    {
        return $this->memo('events:' . json_encode($criteria), fn (): array => $this->events->liveList($criteria));
    }

    /** @return list<array<string,mixed>> */
    public function documents(?string $category = null): array
    {
        return $this->memo('documents:' . $category, fn (): array => $this->documents->publishedList($category));
    }

    /** @return list<array<string,mixed>> */
    public function banners(): array
    {
        return $this->memo('banners', fn (): array => $this->banners->activeNow(new DateTimeImmutable('@' . ($this->clock)())));
    }

    /** @return list<array<string,mixed>> */
    public function galleries(): array
    {
        return $this->memo('galleries', fn (): array => $this->galleries->publishedAlbums());
    }

    /** @return list<array<string,mixed>> */
    public function galleryItems(int $albumId): array
    {
        return $this->memo('gallery:' . $albumId, fn (): array => $this->galleries->publishedItems($albumId));
    }

    /** @return list<array<string,mixed>> */
    public function testimonials(): array
    {
        return $this->memo('testimonials', fn (): array => $this->testimonials->publishable());
    }

    /**
     * Chemins du plan du site : pages en ligne, articles et évènements publiés (pas les listes ni les fichiers /media).
     * shortcut: 5000 articles et 5000 évènements au plus (une requête par article), passer à une requête en lot au-delà.
     * @return list<string>
     */
    public function sitemapUrls(): array
    {
        return $this->memo('sitemap', function (): array {
            $urls = [];
            $slugs = $this->pages->liveSlugs(array_map('intval', array_column($this->db->fetchAll('SELECT id FROM cms_pages ORDER BY sort_order, id'), 'id')));
            foreach ($slugs as $slug) {
                $urls[] = '/' . $slug;
            }
            foreach ($this->articles->liveList(5000) as $a) {
                $urls[] = sprintf(self::ARTICLE_PATH, $a['slug']);
            }
            foreach ($this->events->liveList(['limit' => 5000]) as $e) {
                $urls[] = sprintf(self::EVENT_PATH, $e['slug']);
            }
            return $urls;
        });
    }

    private function memo(string $key, callable $load): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $load();
    }
}
