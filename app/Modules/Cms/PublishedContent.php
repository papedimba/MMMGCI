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
    /** Chemin d'un fichier public sous /media/ : jamais de « .. », d'hôte ni de caractère spécial (défense en profondeur). */
    private const MEDIA_PATH = '~^[A-Za-z0-9][A-Za-z0-9/_-]*\.[A-Za-z0-9]+$~D';

    /** @var array<string,mixed> */
    private array $memo = [];

    /** @param Closure(): int $clock horloge du noyau (secondes Unix) */
    public function __construct(private Database $db, private PageService $pages, private MenuService $menus, private ArticleService $articles, private EventService $events,
        private DocumentService $documents, private BannerService $banners, private GalleryService $galleries, private TestimonialService $testimonials, private Closure $clock,
        private ?\App\Core\Uploads $uploads = null)
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
            $live = $row === null ? null : $this->pages->liveVersion((int) $parent); // un parent hors ligne ne confirme jamais son adresse
            if ($row === null || $live === null || $live['slug'] !== $slug) {
                return false;
            }
            $parent = $row['parent_id'];
        }
        return $parent === null;
    }

    /**
     * Média de la médiathèque (id lu dans un bloc ou un champ) : image ou PDF du disque public seulement ; les dimensions exigent $uploads.
     * Texte brut à échapper par le rendu.
     * @return array{url:string,alt:?string,width:?int,height:?int}|null
     */
    public function mediaUrl(int $mediaId): ?array
    {
        return $this->memo('media:' . $mediaId, function () use ($mediaId): ?array {
            $r = $this->db->fetch("SELECT f.id, f.path, f.mime, m.alt FROM cms_media m JOIN files f ON f.id = m.file_id
                WHERE m.id = ? AND f.disk = 'public' AND (f.mime LIKE 'image/%' OR f.mime = 'application/pdf')", [$mediaId]);
            return $r === null || preg_match(self::MEDIA_PATH, (string) $r['path']) !== 1 ? null : $this->fileRow($r);
        });
    }

    /**
     * Fichier (ex. featured_file_id, og_file_id) : disque public seulement ; le texte alternatif vient de la médiathèque quand le fichier y figure.
     * @return array{url:string,alt?:?string,width?:?int,height?:?int}|null
     */
    public function fileUrl(int $fileId): ?array
    {
        return $this->memo('file:' . $fileId, function () use ($fileId): ?array {
            $r = $this->db->fetch("SELECT f.id, f.path, f.mime, m.alt FROM files f LEFT JOIN cms_media m ON m.file_id = f.id WHERE f.id = ? AND f.disk = 'public'", [$fileId]);
            return $r === null || preg_match(self::MEDIA_PATH, (string) $r['path']) !== 1 ? null : $this->fileRow($r);
        });
    }

    /** Chemin public d'une page EN LIGNE (jamais l'adresse d'un brouillon). */
    public function pagePath(int $pageId): ?string
    {
        $slug = $this->pages->liveSlugs([$pageId])[$pageId] ?? null;
        return $slug === null ? null : '/' . $slug;
    }

    /** @return array{name:string,slug:string}|null */
    public function category(int $id): ?array
    {
        $r = $this->db->fetch('SELECT name, slug FROM cms_categories WHERE id = ?', [$id]);
        return $r === null ? null : ['name' => (string) $r['name'], 'slug' => (string) $r['slug']];
    }

    /**
     * Dimensions et variantes responsives (créées à la demande si absentes, MediaVariants) quand $uploads est fourni.
     * @param array<string,mixed> $r @return array{url:string,alt:?string,width:?int,height:?int,variants:list<array{url:string,width:int,height:int}>}
     */
    private function fileRow(array $r): array
    {
        $w = $h = null;
        $variants = [];
        if ($this->uploads !== null && str_starts_with((string) $r['mime'], 'image/')) {
            try {
                $info = @getimagesize($this->uploads->path((int) $r['id']));
                [$w, $h] = $info === false ? [null, null] : [$info[0], $info[1]];
                $variants = (new MediaVariants($this->db, $this->uploads))->forFile((int) $r['id']);
            } catch (\InvalidArgumentException) {
            }
        }
        return ['url' => '/media/' . $r['path'], 'alt' => $r['alt'] === null ? null : (string) $r['alt'], 'width' => $w, 'height' => $h, 'variants' => $variants];
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
        return $this->memo('documents:' . $category, fn (): array => array_values(array_filter($this->documents->publishedList($category),
            static fn (array $d): bool => preg_match(self::MEDIA_PATH, substr((string) $d['url'], 7)) === 1 && str_starts_with((string) $d['url'], '/media/'))));
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
        return $this->memo('gallery:' . $albumId, function () use ($albumId): array {
            $out = [];
            foreach ($this->galleries->publishedItems($albumId) as $it) {
                if (preg_match(self::MEDIA_PATH, (string) $it['path']) === 1) {
                    $out[] = $this->fileRow(['id' => $it['file_id'], 'path' => $it['path'], 'mime' => $it['mime'], 'alt' => $it['alt']]) + $it;
                }
            }
            return $out;
        });
    }

    /** @return list<array<string,mixed>> */
    public function testimonials(): array
    {
        return $this->memo('testimonials', fn (): array => $this->testimonials->publishable());
    }

    /**
     * Chemins du plan du site : pages en ligne, articles et évènements publiés (pas les listes ni les fichiers /media).
     * @return list<string>
     */
    public function sitemapUrls(): array
    {
        return array_column($this->sitemapEntries(), 'path');
    }

    /**
     * Comme sitemapUrls(), avec la date de la version en ligne (heure d'Abidjan, null si inconnue).
     * shortcut: 5000 articles et 5000 évènements au plus (une requête par page et par article), passer à une requête en lot au-delà.
     * @return list<array{path:string,lastmod:?string}>
     */
    public function sitemapEntries(): array
    {
        return $this->memo('sitemap', function (): array {
            $out = [];
            $slugs = $this->pages->liveSlugs(array_map('intval', array_column($this->db->fetchAll('SELECT id FROM cms_pages ORDER BY sort_order, id'), 'id')));
            foreach ($slugs as $id => $slug) {
                if (in_array($slug, [...Slugs::RESERVED, ...Slugs::LIST_ROUTES], true)) {
                    continue; // page antérieure à la réservation : masquée par une route fixe, jamais au plan du site
                }
                $out[] = ['path' => '/' . $slug, 'lastmod' => $this->pages->liveVersion($id)['published_at'] ?? null];
            }
            foreach ($this->articles->liveList(5000) as $a) {
                $out[] = ['path' => sprintf(self::ARTICLE_PATH, $a['slug']), 'lastmod' => $a['published_at'] ?? null];
            }
            foreach ($this->events->liveList(['limit' => 5000]) as $e) {
                $out[] = ['path' => sprintf(self::EVENT_PATH, $e['slug']), 'lastmod' => $e['published_at'] ?? null];
            }
            return $out;
        });
    }

    private function memo(string $key, callable $load): mixed
    {
        return array_key_exists($key, $this->memo) ? $this->memo[$key] : $this->memo[$key] = $load();
    }
}
