<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;

/**
 * Articles : même circuit et même modèle d'état que PageService (voir son commentaire), avec published_body comme marqueur de version conservée.
 * Contenu versionné : titre, corps, extrait, SEO, images, adresse canonique. Non versionnés (appliqués tout de suite, donc réservés aux
 * publicateurs une fois l'article passé en ligne) : adresse, catégorie, étiquettes, auteur affiché. Pas de redirection d'ancienne adresse
 * (shortcut : à ajouter en L4 si les adresses d'articles doivent survivre à un changement).
 * Lecture publique (L4) : liveVersion() / liveList() uniquement.
 */
final class ArticleService implements ScheduledContent
{
    public const STATUS_LABELS = PageService::STATUS_LABELS;
    private const VERSIONED = ['title', 'body', 'excerpt', 'seo_title', 'seo_description', 'featured_file_id', 'og_file_id', 'canonical_url'];
    private const SNAPSHOT_CLEARED = ['published_body' => null, 'published_title' => null, 'published_excerpt' => null, 'published_seo_title' => null,
        'published_seo_description' => null, 'published_featured_file_id' => null, 'published_og_file_id' => null, 'published_canonical_url' => null, 'published_version_at' => null];
    private const TZ = 'Africa/Abidjan';

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->guardSlugRace(fn (): int => $this->db->atomic(function () use ($data, $actor): int {
            $row = $this->clean($data, null, $actor);
            $tags = $row['tag_ids'];
            unset($row['tag_ids']);
            $id = $this->db->insert('cms_articles', $row + ['status' => 'brouillon', 'author_id' => $actor]);
            $this->setTags($id, $tags);
            $this->audit->log($actor, 'cms.article_created', 'cms_articles', $id, null, ['status' => 'brouillon', 'slug' => $row['slug']]);
            return $id;
        }));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->guardSlugRace(fn () => $this->db->atomic(function () use ($id, $data, $actor): void {
            $old = $this->lock($id);
            $row = $this->clean($data, $id, $actor);
            $tags = $row['tag_ids'];
            unset($row['tag_ids']);
            $changed = array_values(array_filter(self::VERSIONED, static fn (string $k): bool => (string) ($old[$k] ?? '') !== (string) ($row[$k] ?? '')));
            $structural = [];
            foreach (['slug', 'category_id', 'author_label'] as $k) {
                if ((string) ($old[$k] ?? '') !== (string) ($row[$k] ?? '')) {
                    $structural[] = $k;
                }
            }
            $oldTags = $this->tagIds($id);
            if ($oldTags !== $tags) {
                $structural[] = 'tags';
            }
            if ($structural !== [] && $old['published_by'] !== null && !$this->gate->allows($actor, 'cms.publish')) {
                $labels = ['slug' => 'son adresse', 'category_id' => 'sa catégorie', 'author_label' => 'son auteur affiché', 'tags' => 'ses étiquettes'];
                $errors = [];
                foreach ($structural as $f) {
                    $errors[$f] = 'Cet article a déjà été en ligne : seul un publicateur peut changer ' . $labels[$f] . '.';
                }
                throw new PageInvalid($errors);
            }
            $status = (string) $old['status'];
            if ($changed !== [] && $status === 'publie') {
                $row += ['published_body' => (string) ($old['body'] ?? ''), 'published_title' => $old['title'], 'published_excerpt' => $old['excerpt'],
                    'published_seo_title' => $old['seo_title'], 'published_seo_description' => $old['seo_description'],
                    'published_featured_file_id' => $old['featured_file_id'], 'published_og_file_id' => $old['og_file_id'],
                    'published_canonical_url' => $old['canonical_url'], 'published_version_at' => $old['published_at']];
                $status = 'brouillon';
            } elseif ($changed !== [] && in_array($status, ['en_relecture', 'programme'], true)) {
                $status = 'brouillon'; // contenu versionné seulement : un champ structurel (publicateur) ne défait ni la relecture ni la programmation
            }
            if ($status !== $old['status']) {
                $row += ['status' => $status, 'published_at' => null];
            }
            $changed = [...$changed, ...$structural];
            if ($changed !== []) {
                $row['content_version'] = (int) $old['content_version'] + 1;
            }
            $this->db->update('cms_articles', $row, ['id' => $id]);
            $this->setTags($id, $tags);
            $this->audit->log($actor, 'cms.article_updated', 'cms_articles', $id, ['status' => $old['status'], 'slug' => $old['slug']],
                ['status' => $status, 'slug' => $row['slug'], 'fields' => $changed]);
        }));
    }

    /** Point d'entrée unique des transitions (même contrat que PageService::transition). */
    public function transition(int $id, string $to, int $actor, ?DateTimeImmutable $at = null, ?int $seenVersion = null): void
    {
        $this->db->atomic(function () use ($id, $to, $actor, $at, $seenVersion): void {
            $a = $this->lock($id);
            $from = (string) $a['status'];
            ContentWorkflow::assertCan($from, $to, $this->gate->allows($actor, 'cms.publish'));
            if (in_array($to, ['publie', 'programme'], true) && $seenVersion !== null && $seenVersion !== (int) $a['content_version']) {
                throw new ContentTransitionInvalid('L\'article a été modifié depuis votre lecture, relisez-le.');
            }
            $set = ['status' => $to];
            $extra = [];
            if ($to === 'en_relecture') {
                $set['submitted_by'] = $actor;
            } elseif ($to === 'publie') {
                $set += ['published_at' => $this->now()->format('Y-m-d H:i:s'), 'published_by' => $actor, 'archived_at' => null] + self::SNAPSHOT_CLEARED;
            } elseif ($to === 'programme') {
                ContentWorkflow::assertScheduledDate($at, $this->now());
                $set += ['published_at' => $at->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i:s'), 'published_by' => $actor, 'archived_at' => null];
                $extra['at'] = $set['published_at'];
            } elseif ($to === 'archive') {
                $set += ['archived_at' => $this->now()->format('Y-m-d H:i:s')] + self::SNAPSHOT_CLEARED;
            } elseif ($to === 'brouillon') {
                $set['archived_at'] = null;
                if (in_array($from, ['publie', 'programme'], true)) {
                    $set['published_at'] = null;
                }
            }
            $this->db->update('cms_articles', $set, ['id' => $id]);
            $action = match (true) {
                $to === 'en_relecture' => 'cms.article_submitted',
                $to === 'publie' => 'cms.article_published',
                $to === 'programme' => 'cms.article_scheduled',
                $to === 'archive' => 'cms.article_archived',
                $from === 'en_relecture' => 'cms.article_withdrawn',
                $from === 'archive' => 'cms.article_restored',
                default => 'cms.article_unpublished',
            };
            $this->audit->log($actor, $action, 'cms_articles', $id, ['status' => $from], ['status' => $to, 'slug' => $a['slug']] + $extra);
        });
    }

    /** Retire du site en une action : publié/programmé -> brouillon, et la version conservée est retirée. */
    public function unpublish(int $id, int $actor): void
    {
        $this->db->atomic(function () use ($id, $actor): void {
            $a = $this->lock($id);
            $scheduled = in_array($a['status'], ['publie', 'programme'], true);
            if ($scheduled) {
                $this->transition($id, 'brouillon', $actor);
            } elseif (!$this->gate->allows($actor, 'cms.publish')) {
                throw new ContentTransitionInvalid('Retirer un article du site exige le droit de publication.');
            } elseif ($a['published_body'] === null) {
                throw new ContentTransitionInvalid('Cet article n\'a pas de version en ligne.');
            }
            if ($a['published_body'] !== null) {
                $this->db->update('cms_articles', self::SNAPSHOT_CLEARED, ['id' => $id]);
                if (!$scheduled) {
                    $this->audit->log($actor, 'cms.article_unpublished', 'cms_articles', $id, ['status' => $a['status'], 'live' => true], ['status' => $a['status'], 'slug' => $a['slug'], 'live' => false]);
                }
            }
        });
    }

    public function scheduledTable(): string
    {
        return 'cms_articles';
    }

    public function dueIds(DateTimeImmutable $now): array
    {
        return array_map('intval', array_column($this->db->fetchAll("SELECT id FROM cms_articles WHERE status = 'programme' AND published_at <= ? ORDER BY id", [$now->format('Y-m-d H:i:s')]), 'id'));
    }

    public function publishDue(int $id, DateTimeImmutable $now): void
    {
        $this->db->atomic(function () use ($id, $now): void {
            $a = $this->lock($id);
            if ($a['status'] !== 'programme' || $a['published_at'] === null || $a['published_at'] > $now->format('Y-m-d H:i:s')) {
                return;
            }
            $this->db->update('cms_articles', ['status' => 'publie'] + self::SNAPSHOT_CLEARED, ['id' => $id]);
            $this->audit->log(null, 'cms.scheduled_published', 'cms_articles', $id, ['status' => 'programme'], ['status' => 'publie', 'slug' => $a['slug']]);
        });
    }

    /** Seul un brouillon jamais publié ni programmé peut être supprimé ; sinon : archiver. */
    public function delete(int $id, int $actor): void
    {
        $this->db->atomic(function () use ($id, $actor): void {
            $a = $this->lock($id);
            if ($a['published_by'] !== null || !in_array($a['status'], ['brouillon', 'en_relecture'], true)) {
                throw new DomainException('Cet article a déjà été publié ou programmé : il ne peut pas être supprimé. Archivez-le.');
            }
            $this->db->query('DELETE FROM cms_articles WHERE id = ?', [$id]);
            $this->audit->log($actor, 'cms.article_deleted', 'cms_articles', $id, ['status' => $a['status'], 'slug' => $a['slug']], null);
        });
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $a = $this->db->fetch('SELECT * FROM cms_articles WHERE id = ?', [$id]) ?? throw new NotFound('Article introuvable.');
        foreach (['featured', 'og'] as $k) {
            $fid = $a[$k . '_file_id'];
            $a[$k . '_media_id'] = $fid === null ? null : $this->db->fetchValue('SELECT id FROM cms_media WHERE file_id = ? ORDER BY id LIMIT 1', [$fid]);
        }
        $a['tags'] = implode(', ', array_column($this->db->fetchAll('SELECT t.name FROM cms_article_tag x JOIN cms_tags t ON t.id = x.tag_id WHERE x.article_id = ? ORDER BY t.name', [$id]), 'name'));
        $a['category_name'] = $a['category_id'] === null ? null : $this->db->fetchValue('SELECT name FROM cms_categories WHERE id = ?', [$a['category_id']]);
        $a['author_name'] = $a['author_label'] ?: $this->db->fetchValue('SELECT name FROM users WHERE id = ?', [$a['author_id']]);
        return $a;
    }

    /**
     * Version que le public peut voir, ou null (mêmes règles que PageService::liveVersion).
     * @return array<string,mixed>|null
     */
    public function liveVersion(int $id): ?array
    {
        $a = $this->db->fetch('SELECT a.*, COALESCE(a.author_label, u.name) AS author_name FROM cms_articles a LEFT JOIN users u ON u.id = a.author_id WHERE a.id = ?', [$id]);
        if ($a === null) {
            return null;
        }
        $base = ['id' => (int) $a['id'], 'slug' => $a['slug'], 'category_id' => $a['category_id'] === null ? null : (int) $a['category_id'], 'author_name' => $a['author_name']];
        $due = $a['status'] === 'programme' && $a['published_at'] !== null && $a['published_at'] <= $this->now()->format('Y-m-d H:i:s');
        if ($a['status'] === 'publie' || $due) {
            return $base + ['title' => $a['title'], 'body' => (string) $a['body'], 'excerpt' => $a['excerpt'], 'seo_title' => $a['seo_title'], 'seo_description' => $a['seo_description'],
                'featured_file_id' => $a['featured_file_id'], 'og_file_id' => $a['og_file_id'], 'canonical_url' => $a['canonical_url'], 'published_at' => $a['published_at']];
        }
        if (in_array($a['status'], ['brouillon', 'en_relecture', 'programme'], true) && $a['published_body'] !== null) {
            return $base + ['title' => $a['published_title'], 'body' => (string) $a['published_body'], 'excerpt' => $a['published_excerpt'], 'seo_title' => $a['published_seo_title'],
                'seo_description' => $a['published_seo_description'], 'featured_file_id' => $a['published_featured_file_id'], 'og_file_id' => $a['published_og_file_id'],
                'canonical_url' => $a['published_canonical_url'], 'published_at' => $a['published_version_at']];
        }
        return null;
    }

    /** Articles visibles du public, du plus récent au plus ancien (L4 : listes). @return list<array<string,mixed>> */
    public function liveList(int $limit = 20, ?int $categoryId = null): array
    {
        $now = $this->now()->format('Y-m-d H:i:s');
        $out = [];
        // Date affichée : celle de la copie de travail si elle est en ligne, sinon celle de la version conservée.
        foreach ($this->db->fetchAll("SELECT id FROM cms_articles WHERE (status = 'publie' OR (status = 'programme' AND published_at <= ?)
            OR (status IN ('brouillon', 'en_relecture', 'programme') AND published_body IS NOT NULL)) AND (? IS NULL OR category_id = ?)
            ORDER BY CASE WHEN status = 'publie' OR (status = 'programme' AND published_at <= ?) THEN published_at ELSE published_version_at END DESC, id DESC LIMIT " . max(1, $limit),
            [$now, $categoryId, $categoryId, $now]) as $r) {
            $v = $this->liveVersion((int) $r['id']);
            if ($v !== null) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Liste d'administration filtrée (état, catégorie, texte) et paginée.
     * @param array{status?:string,category?:string,q?:string} $f @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $f, int $page, int $per = 20): array
    {
        $where = ['1=1'];
        $args = [];
        if (isset(self::STATUS_LABELS[$f['status'] ?? ''])) {
            $where[] = 'a.status = ?';
            $args[] = $f['status'];
        }
        if (ctype_digit($f['category'] ?? '') && $f['category'] !== '') {
            $where[] = 'a.category_id = ?';
            $args[] = (int) $f['category'];
        }
        $q = trim($f['q'] ?? '');
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '\\%_') . '%';
            $where[] = '(a.title LIKE ? OR a.slug LIKE ?)';
            array_push($args, $like, $like);
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_articles a WHERE $w", $args);
        $rows = $this->db->fetchAll("SELECT a.id, a.title, a.slug, a.status, a.published_at, a.updated_at, a.published_body IS NOT NULL AS has_copy, c.name AS category,
            COALESCE(a.author_label, u.name) AS author FROM cms_articles a LEFT JOIN cms_categories c ON c.id = a.category_id LEFT JOIN users u ON u.id = a.author_id
            WHERE $w ORDER BY a.updated_at DESC, a.id DESC LIMIT " . (int) $per . ' OFFSET ' . (max(0, $page - 1) * $per), $args);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function history(int $id): array
    {
        return $this->db->fetchAll("SELECT a.created_at, a.action, a.old_values, a.new_values, u.name AS user_name FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id WHERE a.auditable_type = 'cms_articles' AND a.auditable_id = ? ORDER BY a.id DESC LIMIT 100", [$id]);
    }

    /** @return array<int,string> id => nom */
    public function categoryChoices(): array
    {
        return array_column($this->db->fetchAll('SELECT id, name FROM cms_categories ORDER BY name'), 'name', 'id');
    }

    /** Images de la médiathèque (id => titre). @return array<int,string> */
    public function imageChoices(): array
    {
        return (new PageService($this->db, $this->audit, $this->gate))->imageChoices();
    }

    /** Médias (url, alt) de l'aperçu : image à la une. @return array{url:string,alt:string}|null */
    public function mediaOf(?int $fileId): ?array
    {
        $r = $fileId === null ? null : $this->db->fetch('SELECT m.alt, f.path FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.file_id = ? LIMIT 1', [$fileId]);
        return $r === null ? null : ['url' => '/media/' . $r['path'], 'alt' => (string) $r['alt']];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> champs de la ligne + tag_ids */
    private function clean(array $data, ?int $id, int $actor): array
    {
        $errors = [];
        $str = static fn (string $k, bool $multi = false): string => Blocks::text(is_scalar($data[$k] ?? null) ? (string) $data[$k] : '', $multi);
        $title = $str('title');
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = 'Le titre compte 200 caractères au maximum.';
        }
        $slugs = new Slugs($this->db);
        $slug = $str('slug');
        try {
            $keep = $slug === '' && $id !== null ? $this->db->fetchValue('SELECT slug FROM cms_articles WHERE id = ?', [$id]) : null; // adresse laissée vide en modification : conservée
            $slug = $keep !== null ? (string) $keep : ($slug === '' ? $this->freeSlug(Slugs::fromTitle($title), $id, $slugs) : $slugs->validate($slug, $id, 'cms_articles'));
        } catch (DomainException $e) {
            $errors['slug'] = $e->getMessage();
        }
        $body = '';
        try {
            $body = RichText::fromRequestField($data['body'] ?? '');
        } catch (DomainException $e) {
            $errors['body'] = $e->getMessage();
        }
        $limits = ['excerpt' => [300, 'L\'extrait'], 'seo_title' => [70, 'Le titre SEO'], 'seo_description' => [160, 'La description SEO'],
            'canonical_url' => [255, 'L\'adresse canonique'], 'author_label' => [80, 'L\'auteur affiché']];
        $texts = [];
        foreach ($limits as $k => [$max, $label]) {
            $texts[$k] = $str($k, $k === 'excerpt');
            if (mb_strlen($texts[$k]) > $max) {
                $errors[$k] = "$label compte $max caractères au maximum (" . mb_strlen($texts[$k]) . ' saisis).';
            }
        }
        if ($texts['canonical_url'] !== '' && preg_match('~^https?://[^\s/?#]+[^\s]*$~iD', $texts['canonical_url']) !== 1) {
            $errors['canonical_url'] = 'L\'adresse canonique doit être une adresse complète en https://…';
        }
        $category = $this->optionalId($data['category_id'] ?? null);
        if ($category === false || ($category !== null && $this->db->fetchValue('SELECT id FROM cms_categories WHERE id = ?', [$category]) === null)) {
            $errors['category_id'] = 'Catégorie inconnue.';
        }
        $mediaRefs = [];
        foreach (['featured_media_id', 'og_media_id'] as $k) {
            $m = $this->optionalId($data[$k] ?? null);
            if ($m === false) {
                $errors[$k] = 'Image invalide.';
            } elseif ($m !== null) {
                $mediaRefs[$k] = $m;
            }
        }
        $files = [];
        if ($mediaRefs !== []) {
            $ids = array_values(array_unique($mediaRefs));
            $found = [];
            foreach ($this->db->fetchAll('SELECT m.id, m.file_id, f.mime FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') LOCK IN SHARE MODE', $ids) as $r) {
                $found[(int) $r['id']] = $r;
            }
            foreach ($mediaRefs as $path => $mid) {
                if (!isset($found[$mid])) {
                    $errors[$path] = "Le média n° $mid n'existe pas (supprimé ?) : choisissez-en un autre.";
                } elseif (!str_starts_with((string) $found[$mid]['mime'], 'image/')) {
                    $errors[$path] = 'Choisissez une image, pas un document.';
                } else {
                    $files[$path] = (int) $found[$mid]['file_id'];
                }
            }
        }
        $tagIds = [];
        try {
            $tagIds = $this->resolveTags($str('tags'), $actor);
        } catch (DomainException $e) {
            $errors['tags'] = $e->getMessage();
        }
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $title, 'slug' => $slug, 'body' => $body, 'excerpt' => $texts['excerpt'] ?: null, 'seo_title' => $texts['seo_title'] ?: null,
            'seo_description' => $texts['seo_description'] ?: null, 'canonical_url' => $texts['canonical_url'] ?: null, 'author_label' => $texts['author_label'] ?: null,
            'category_id' => $category, 'featured_file_id' => $files['featured_media_id'] ?? null, 'og_file_id' => $files['og_media_id'] ?? null, 'tag_ids' => $tagIds];
    }

    /** Étiquettes saisies en liste séparée par des virgules ; une nouvelle étiquette exige cms.pages.manage. @return list<int> triés */
    private function resolveTags(string $csv, int $actor): array
    {
        $names = [];
        foreach (explode(',', $csv) as $n) {
            $n = Blocks::text($n, false);
            if ($n !== '') {
                $names[mb_strtolower($n)] = $n;
            }
        }
        if (count($names) > 10) {
            throw new DomainException('10 étiquettes au maximum par article.');
        }
        $slugs = new Slugs($this->db);
        $ids = [];
        foreach ($names as $name) {
            if (mb_strlen($name) > 120) {
                throw new DomainException('Une étiquette compte 120 caractères au maximum.');
            }
            $found = $this->db->fetchValue('SELECT id FROM cms_tags WHERE name = ?', [$name]);
            if ($found === null) {
                if (!$this->gate->allows($actor, 'cms.pages.manage')) {
                    throw new DomainException("L'étiquette « $name » n'existe pas : seul un gestionnaire des pages peut en créer.");
                }
                $found = $this->db->insert('cms_tags', ['name' => $name, 'slug' => $this->freeSlug(Slugs::fromTitle($name), null, $slugs, 'cms_tags')]);
            }
            $ids[] = (int) $found;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /** @return list<int> */
    private function tagIds(int $id): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT tag_id FROM cms_article_tag WHERE article_id = ? ORDER BY tag_id', [$id]), 'tag_id'));
    }

    /** @param list<int> $ids */
    private function setTags(int $id, array $ids): void
    {
        $this->db->query('DELETE FROM cms_article_tag WHERE article_id = ?', [$id]);
        foreach ($ids as $t) {
            $this->db->insert('cms_article_tag', ['article_id' => $id, 'tag_id' => $t]);
        }
    }

    private function optionalId(mixed $raw): int|null|false
    {
        $s = is_scalar($raw) ? trim((string) $raw) : '';
        if ($s === '') {
            return null;
        }
        return ctype_digit($s) && strlen($s) < 19 && (int) $s > 0 ? (int) $s : false;
    }

    private function freeSlug(string $base, ?int $id, Slugs $slugs, string $table = 'cms_articles'): string
    {
        for ($n = 1; $n <= 50; $n++) {
            $candidate = $n === 1 ? $base : rtrim(substr($base, 0, 80 - strlen("-$n")), '-') . "-$n";
            try {
                return $slugs->validate($candidate, $id, $table);
            } catch (DomainException) {
            }
        }
        throw new DomainException('Aucune adresse libre : saisissez-en une.');
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->fetch('SELECT * FROM cms_articles WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Article introuvable.');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    private function guardSlugRace(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new PageInvalid(['slug' => 'Cette adresse vient d\'être prise : choisissez-en une autre.']);
            }
            throw $e;
        }
    }
}
