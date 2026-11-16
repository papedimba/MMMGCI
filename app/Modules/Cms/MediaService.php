<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Uploads;
use DomainException;

/** Médiathèque : fichiers publics (disque public, /media/…) enveloppés dans cms_media avec leurs métadonnées. */
final class MediaService
{
    public const PER_PAGE = 24;
    private const TYPE_LABELS = ['page' => 'page', 'article' => 'article', 'evenement' => 'évènement', 'galerie' => 'galerie', 'banniere' => 'bannière', 'document' => 'document', 'menu' => 'menu'];

    /** @var array<string, callable(int): list<array{type:string,id:int,label:string}>> */
    private array $providers = [];

    public function __construct(private Database $db, private Audit $audit, private Uploads $uploads)
    {
        $this->registerBuiltinProviders();
    }

    /** Les modules ajoutent ici leurs références aux médias ; usages() fusionne tous les fournisseurs. */
    public function registerUsageProvider(string $type, callable $provider): void
    {
        $this->providers[$type] = $provider;
    }

    /** @return list<array{type:string,id:int,label:string}> */
    public function usages(int $id): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            foreach ($provider($id) as $u) {
                $out[$u['type'] . ':' . $u['id']] = $u; // une page citée deux fois n'est listée qu'une
            }
        }
        return array_values($out);
    }

    /** @param array<string,mixed> $meta title, alt (obligatoire pour une image), caption, credit, folder */
    public function add(array $phpFile, array $meta, int $actor): int
    {
        $ext = strtolower(pathinfo((string) ($phpFile['name'] ?? ''), PATHINFO_EXTENSION));
        $isDocument = $ext === 'pdf';
        $clean = $this->clean($meta, !$isDocument);
        // Profil « image » pour tout sauf le PDF ; Uploads refuse SVG, HTML, PHP, doubles extensions et MIME menteurs.
        $fileId = $this->uploads->store($phpFile, $isDocument ? 'document' : 'image', true, $actor);
        try {
            return $this->db->atomic(function () use ($fileId, $clean, $actor): int {
                $id = $this->db->insert('cms_media', ['file_id' => $fileId, 'uploaded_by' => $actor] + $clean);
                $this->audit->log($actor, 'cms.media_added', 'cms_media', $id, null, ['title' => $clean['title'], 'file_id' => $fileId]);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->discard($fileId);
            throw $e;
        }
    }

    /** @param array<string,mixed> $meta */
    public function update(int $id, array $meta, int $actor): void
    {
        $old = $this->get($id);
        $clean = $this->clean($meta, str_starts_with((string) $old['mime'], 'image/'));
        $this->db->atomic(function () use ($id, $clean, $old, $actor): void {
            $this->db->update('cms_media', $clean, ['id' => $id]);
            $this->audit->log($actor, 'cms.media_updated', 'cms_media', $id, array_intersect_key($old, $clean), $clean);
        });
    }

    public function delete(int $id, int $actor): void
    {
        $m = $this->get($id);
        $usages = $this->usages($id);
        if ($usages !== []) {
            throw new DomainException('Ce média est utilisé et ne peut pas être supprimé. Retirez-le d\'abord de : '
                . implode(' ; ', array_map(static fn (array $u): string => (self::TYPE_LABELS[$u['type']] ?? $u['type']) . ' « ' . $u['label'] . ' »', $usages)) . '.');
        }
        try {
            $this->db->atomic(function () use ($id, $m, $actor): void {
                $this->db->query('DELETE FROM cms_media WHERE id = ?', [$id]);
                $this->audit->log($actor, 'cms.media_deleted', 'cms_media', $id, ['title' => $m['title'], 'file_id' => (int) $m['file_id']], null);
                $path = $this->uploads->path((int) $m['file_id']);
                $this->db->query('DELETE FROM files WHERE id = ?', [(int) $m['file_id']]);
                @unlink($path); // après les écritures : un échec de base annule la transaction avant d'arriver ici ; au pire un fichier orphelin
            });
        } catch (\PDOException) {
            throw new DomainException('Ce média est encore référencé ailleurs : suppression impossible.'); // filet : contrainte FK
        }
    }

    /**
     * @param array<string,mixed> $criteria type (image|document), folder, q (titre, alt, légende)
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function search(array $criteria, int $page): array
    {
        $where = ['1=1'];
        $args = [];
        $type = $criteria['type'] ?? '';
        if ($type === 'image') {
            $where[] = "f.mime LIKE 'image/%'";
        } elseif ($type === 'document') {
            $where[] = "f.mime NOT LIKE 'image/%'";
        }
        $folder = (string) ($criteria['folder'] ?? '');
        if ($folder !== '') {
            $where[] = 'm.folder = ?';
            $args[] = $folder;
        }
        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(m.title LIKE ? OR m.alt LIKE ? OR m.caption LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $sql = ' FROM cms_media m JOIN files f ON f.id = m.file_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $args);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll('SELECT m.*, f.path, f.mime, f.size' . $sql . ' ORDER BY m.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $args);
        return ['rows' => array_map($this->decorate(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<string> */
    public function folders(): array
    {
        return array_column($this->db->fetchAll("SELECT DISTINCT folder FROM cms_media WHERE folder IS NOT NULL AND folder <> '' ORDER BY folder"), 'folder');
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = $this->db->fetch('SELECT m.*, f.path, f.mime, f.size FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$id]);
        if ($row === null) {
            throw new \App\Core\NotFound('Média introuvable.');
        }
        return $this->decorate($row);
    }

    /** Ajoute url publique, est-image et dimensions (lues sur le fichier stocké). @param array<string,mixed> $row @return array<string,mixed> */
    private function decorate(array $row): array
    {
        $row['url'] = '/media/' . $row['path'];
        $row['is_image'] = str_starts_with((string) $row['mime'], 'image/');
        $row['width'] = $row['height'] = null;
        if ($row['is_image']) {
            try {
                $info = @getimagesize($this->uploads->path((int) $row['file_id']));
                [$row['width'], $row['height']] = $info === false ? [null, null] : [$info[0], $info[1]];
            } catch (\InvalidArgumentException) {
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $meta @return array{title:string,alt:?string,caption:?string,credit:?string,folder:?string} */
    private function clean(array $meta, bool $altRequired): array
    {
        $str = static fn (string $k, int $max): ?string => ($v = mb_substr(trim(mb_scrub((string) ($meta[$k] ?? ''))), 0, $max)) === '' ? null : $v;
        $title = $str('title', 160);
        if ($title === null) {
            throw new DomainException('Le titre est obligatoire.');
        }
        $alt = $str('alt', 255);
        if ($altRequired && $alt === null) {
            throw new DomainException('Le texte alternatif est obligatoire pour une image : décrivez ce qu\'elle montre (lu par les lecteurs d\'écran).');
        }
        return ['title' => $title, 'alt' => $alt, 'caption' => $str('caption', 255), 'credit' => $str('credit', 160), 'folder' => $this->folder((string) ($meta['folder'] ?? ''))];
    }

    private function folder(string $raw): ?string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $raw) !== 1) {
            return null;
        }
        $s = rtrim(substr(Slugs::fromTitle($raw), 0, 40), '-');
        return $s === '' ? null : $s;
    }

    private function discard(int $fileId): void
    {
        try {
            $path = $this->uploads->path($fileId);
            $this->db->query('DELETE FROM files WHERE id = ?', [$fileId]);
            @unlink($path);
        } catch (\Throwable) {
        }
    }

    /** @return array{file_id:int,path:string}|null */
    private function ref(int $mediaId): ?array
    {
        $r = $this->db->fetch('SELECT m.file_id, f.path FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$mediaId]);
        return $r === null ? null : ['file_id' => (int) $r['file_id'], 'path' => (string) $r['path']];
    }

    /** Couvre toutes les tables de la migration 0016 qui référencent un fichier : pages, articles, évènements, galeries, bannières, documents, menus. */
    private function registerBuiltinProviders(): void
    {
        // Colonnes file_id : on remonte du média à son fichier.
        $byFile = fn (string $type, string $sql): callable => function (int $id) use ($type, $sql): array {
            $ref = $this->ref($id);
            if ($ref === null) {
                return [];
            }
            return array_map(static fn (array $r): array => ['type' => $type, 'id' => (int) $r['id'], 'label' => (string) $r['label']],
                $this->db->fetchAll($sql, array_fill(0, substr_count($sql, '?'), $ref['file_id'])));
        };
        $this->registerUsageProvider('page_fichier', $byFile('page', 'SELECT id, title AS label FROM cms_pages WHERE featured_file_id = ? OR og_file_id = ?'));
        $this->registerUsageProvider('article', $byFile('article', 'SELECT id, title AS label FROM cms_articles WHERE featured_file_id = ? OR og_file_id = ?'));
        $this->registerUsageProvider('evenement', $byFile('evenement', 'SELECT id, title AS label FROM cms_events WHERE featured_file_id = ?'));
        $this->registerUsageProvider('banniere', $byFile('banniere', 'SELECT id, title AS label FROM cms_banners WHERE file_id = ?'));
        $this->registerUsageProvider('document', $byFile('document', 'SELECT id, title AS label FROM cms_documents WHERE file_id = ?'));
        $this->registerUsageProvider('galerie', function (int $id): array {
            return array_map(static fn (array $r): array => ['type' => 'galerie', 'id' => (int) $r['id'], 'label' => (string) $r['label']],
                $this->db->fetchAll('SELECT DISTINCT g.id, g.title AS label FROM cms_gallery_items i JOIN cms_galleries g ON g.id = i.gallery_id WHERE i.media_id = ?', [$id]));
        });
        $this->registerUsageProvider('menu', function (int $id): array {
            $ref = $this->ref($id);
            return $ref === null ? [] : array_map(static fn (array $r): array => ['type' => 'menu', 'id' => (int) $r['id'], 'label' => (string) $r['label']],
                $this->db->fetchAll('SELECT id, label FROM cms_menu_items WHERE url LIKE ?', ['%/media/' . addcslashes($ref['path'], '\\%_') . '%']));
        });
        // shortcut: les blocs et le HTML riche référencent un média par "media_id" ou par /media/<chemin> ; on cherche dans le texte JSON
        // (blocks et published_blocks). Tâche 4 : remplacer par une table de références normalisée écrite à l'enregistrement.
        $this->registerUsageProvider('page_blocs', function (int $id): array {
            $ref = $this->ref($id);
            if ($ref === null) {
                return [];
            }
            $re = '"media_id"[[:space:]]*:[[:space:]]*"?' . $id . '([^0-9]|$)';
            // Le JSON encodé par PHP échappe les « / » : on cherche les deux écritures.
            $plain = '/media/' . $ref['path'];
            $like = '%' . addcslashes($plain, '\\%_') . '%';
            $likeEscaped = '%' . addcslashes(str_replace('/', '\\/', $plain), '\\%_') . '%';
            return array_map(static fn (array $r): array => ['type' => 'page', 'id' => (int) $r['id'], 'label' => (string) $r['label']],
                $this->db->fetchAll('SELECT id, title AS label FROM cms_pages WHERE blocks REGEXP ? OR published_blocks REGEXP ? OR blocks LIKE ? OR published_blocks LIKE ? OR blocks LIKE ? OR published_blocks LIKE ?', [$re, $re, $like, $like, $likeEscaped, $likeEscaped]));
        });
    }
}
