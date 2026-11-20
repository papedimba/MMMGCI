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
 * Pages à blocs et leur circuit de publication.
 *
 * Modèle d'état (colonnes de cms_pages) :
 * - Copie de travail : title, blocks, excerpt, seo_title/seo_description, featured/og_file_id, canonical_url. Version en ligne conservée : published_blocks
 *   et published_title, _excerpt, _seo_title, _seo_description, _featured/og_file_id, _canonical_url, _version_at (0017). La version conservée n'existe (published_blocks NOT NULL)
 *   que pendant la reprise d'une page déjà en ligne.
 * - publie : la copie de travail EST la version en ligne. La modifier (contenu versionné) copie d'abord l'actuelle vers published_*,
 *   puis passe la copie de travail en brouillon : le public continue de voir l'ancienne version jusqu'à republication.
 * - brouillon / en_relecture avec published_blocks : le public voit published_* ; l'auteur soumet, le publicateur republie.
 * - publie (transition) : la copie de travail devient la version en ligne, published_* est vidé.
 * - programme (date future, publicateur) : rien ne change pour le public avant l'échéance (version conservée s'il y en a une) ;
 *   à l'échéance la copie de travail est en ligne (liveVersion) et le cron (tâche 8) passe la page en publie. Modifier une page
 *   programmée annule la programmation (retour en brouillon, version conservée gardée).
 * - publie/programme -> brouillon (dépublier) : plus rien en ligne ; programme -> brouillon garde la version conservée.
 *   unpublish() d'un brouillon qui a une version conservée la retire. archive : plus rien en ligne, version conservée vidée.
 * - Adresse, parent, gabarit et ordre ne sont pas versionnés : ils s'appliquent tout de suite. « A été en ligne » = published_by NOT NULL
 *   (publiée ou programmée au moins une fois) : suppression refusée, ancien slug conservé en redirection.
 * Lecture publique (tâche 8) : liveVersion() uniquement.
 */
final class PageService
{
    public const TEMPLATES = ['standard' => 'Standard', 'accueil' => 'Accueil', 'liste' => 'Liste'];
    public const STATUS_LABELS = ['brouillon' => 'Brouillon', 'en_relecture' => 'En relecture', 'publie' => 'Publiée', 'programme' => 'Programmée', 'archive' => 'Archivée'];
    /** Contenu versionné (copié dans published_* lors d'une reprise). */
    private const VERSIONED = ['title', 'excerpt', 'seo_title', 'seo_description', 'featured_file_id', 'og_file_id', 'canonical_url'];
    private const TZ = 'Africa/Abidjan';

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->guardSlugRace(fn (): int => $this->db->atomic(function () use ($data, $actor): int {
            $row = $this->clean($data, null);
            $row['sort_order'] = $this->nextOrder($row['parent_id']);
            $id = $this->db->insert('cms_pages', $row + ['status' => 'brouillon', 'author_id' => $actor]);
            $this->audit->log($actor, 'cms.page_created', 'cms_pages', $id, null, ['status' => 'brouillon', 'slug' => $row['slug']]);
            return $id;
        }));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->guardSlugRace(fn () => $this->db->atomic(function () use ($id, $data, $actor): void {
            $old = $this->lock($id);
            $row = $this->clean($data, $id);
            $changed = array_values(array_filter(self::VERSIONED, static fn (string $k): bool => (string) ($old[$k] ?? '') !== (string) ($row[$k] ?? '')));
            if (json_decode((string) ($old['blocks'] ?? '[]'), true) != json_decode($row['blocks'], true)) {
                $changed[] = 'blocks';
            }
            $status = $old['status'];
            if ($changed !== [] && $status === 'publie') {
                // Reprise d'une page en ligne : la version actuelle reste servie au public.
                $row += ['published_blocks' => $old['blocks'] ?? '[]', 'published_title' => $old['title'], 'published_excerpt' => $old['excerpt'],
                    'published_seo_title' => $old['seo_title'], 'published_seo_description' => $old['seo_description'],
                    'published_featured_file_id' => $old['featured_file_id'], 'published_og_file_id' => $old['og_file_id'],
                    'published_canonical_url' => $old['canonical_url'], 'published_version_at' => $old['published_at']];
                $status = 'brouillon';
            } elseif ($changed !== [] && $status === 'programme') {
                $status = 'brouillon'; // contenu modifié après relecture : la programmation est annulée
            }
            if ($status !== $old['status']) {
                $row += ['status' => $status, 'published_at' => null];
            }
            if ($row['parent_id'] !== ($old['parent_id'] === null ? null : (int) $old['parent_id'])) {
                $row['sort_order'] = $this->nextOrder($row['parent_id']);
                $changed[] = 'parent_id';
            }
            if ($row['slug'] !== $old['slug']) {
                $changed[] = 'slug';
                if ($old['published_by'] !== null) {
                    $this->db->query('DELETE FROM cms_page_redirects WHERE old_slug = ? AND page_id = ?', [$row['slug'], $id]);
                    $this->db->insert('cms_page_redirects', ['old_slug' => $old['slug'], 'page_id' => $id]);
                }
            }
            if ($row['template'] !== $old['template']) {
                $changed[] = 'template';
            }
            $this->db->update('cms_pages', $row, ['id' => $id]);
            // Jamais de contenu dans le journal : noms des champs modifiés, statut et adresse seulement.
            $this->audit->log($actor, 'cms.page_updated', 'cms_pages', $id, ['status' => $old['status'], 'slug' => $old['slug']],
                ['status' => $status, 'slug' => $row['slug'], 'fields' => $changed]);
        }));
    }

    /** Point d'entrée unique des transitions de ContentWorkflow ; $at : date de publication programmée (heure d'Abidjan). */
    public function transition(int $id, string $to, int $actor, ?DateTimeImmutable $at = null): void
    {
        $this->db->atomic(function () use ($id, $to, $actor, $at): void {
            $p = $this->lock($id);
            $from = (string) $p['status'];
            ContentWorkflow::assertCan($from, $to, $this->gate->allows($actor, 'cms.publish'));
            $set = ['status' => $to];
            $snapshotCleared = ['published_blocks' => null, 'published_title' => null, 'published_excerpt' => null, 'published_seo_title' => null,
                'published_seo_description' => null, 'published_featured_file_id' => null, 'published_og_file_id' => null,
                'published_canonical_url' => null, 'published_version_at' => null];
            $extra = [];
            if ($to === 'en_relecture') {
                $set['submitted_by'] = $actor;
            } elseif ($to === 'publie') {
                $set += ['published_at' => $this->now()->format('Y-m-d H:i:s'), 'published_by' => $actor] + $snapshotCleared;
            } elseif ($to === 'programme') {
                ContentWorkflow::assertScheduledDate($at, $this->now());
                $set += ['published_at' => $at->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i:s'), 'published_by' => $actor];
                $extra['at'] = $set['published_at'];
            } elseif ($to === 'archive') {
                $set += $snapshotCleared;
            } elseif ($to === 'brouillon' && in_array($from, ['publie', 'programme'], true)) {
                $set['published_at'] = null; // dépublication ou annulation ; une version conservée (programme) reste en ligne
            }
            $this->db->update('cms_pages', $set, ['id' => $id]);
            $action = match (true) {
                $to === 'en_relecture' => 'cms.page_submitted',
                $to === 'publie' => 'cms.page_published',
                $to === 'programme' => 'cms.page_scheduled',
                $to === 'archive' => 'cms.page_archived',
                $from === 'en_relecture' => 'cms.page_withdrawn',
                $from === 'archive' => 'cms.page_restored',
                default => 'cms.page_unpublished',
            };
            $this->audit->log($actor, $action, 'cms_pages', $id, ['status' => $from], ['status' => $to, 'slug' => $p['slug']] + $extra);
        });
    }

    public function submit(int $id, int $actor): void
    {
        $this->transition($id, 'en_relecture', $actor);
    }

    public function withdraw(int $id, int $actor): void
    {
        $this->transition($id, 'brouillon', $actor);
    }

    /** Sans $at : publication immédiate ; avec : programmation. */
    public function publish(int $id, int $actor, ?DateTimeImmutable $at = null): void
    {
        $this->transition($id, $at === null ? 'publie' : 'programme', $actor, $at);
    }

    public function archive(int $id, int $actor): void
    {
        $this->transition($id, 'archive', $actor);
    }

    public function restore(int $id, int $actor): void
    {
        $this->transition($id, 'brouillon', $actor);
    }

    /** Retire du site : page publiée/programmée -> brouillon ; brouillon en reprise -> sa version conservée est retirée. */
    public function unpublish(int $id, int $actor): void
    {
        $this->db->atomic(function () use ($id, $actor): void {
            $p = $this->lock($id);
            if (in_array($p['status'], ['publie', 'programme'], true)) {
                $this->transition($id, 'brouillon', $actor);
                return;
            }
            if (!$this->gate->allows($actor, 'cms.publish')) {
                throw new ContentTransitionInvalid('Retirer une page du site exige le droit de publication.');
            }
            if ($p['published_blocks'] === null) {
                throw new ContentTransitionInvalid('Cette page n\'a pas de version en ligne.');
            }
            $this->db->update('cms_pages', ['published_blocks' => null, 'published_title' => null, 'published_excerpt' => null, 'published_seo_title' => null,
                'published_seo_description' => null, 'published_featured_file_id' => null, 'published_og_file_id' => null,
                'published_canonical_url' => null, 'published_version_at' => null], ['id' => $id]);
            $this->audit->log($actor, 'cms.page_unpublished', 'cms_pages', $id, ['status' => $p['status'], 'live' => true], ['status' => $p['status'], 'slug' => $p['slug'], 'live' => false]);
        });
    }

    /** Déplace la page d'un rang parmi ses sœurs ($delta -1 = monter, +1 = descendre). */
    public function reorder(int $id, int $delta, int $actor): void
    {
        $this->db->atomic(function () use ($id, $delta, $actor): void {
            $p = $this->lock($id);
            $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM cms_pages WHERE parent_id <=> ? ORDER BY sort_order, title, id FOR UPDATE', [$p['parent_id']]), 'id'));
            $i = (int) array_search($id, $ids, true);
            $j = $i + ($delta < 0 ? -1 : 1);
            if ($j < 0 || $j >= count($ids)) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $order => $pid) {
                $this->db->update('cms_pages', ['sort_order' => $order], ['id' => $pid]);
            }
            $this->audit->log($actor, 'cms.page_reordered', 'cms_pages', $id, null, ['parent_id' => $p['parent_id'], 'order' => $ids]);
        });
    }

    public function move(int $id, ?int $newParentId, int $actor): void
    {
        $this->db->atomic(function () use ($id, $newParentId, $actor): void {
            $p = $this->lock($id);
            $error = $this->parentError($newParentId, $id);
            if ($error !== null) {
                throw new PageInvalid(['parent_id' => $error]);
            }
            $this->db->update('cms_pages', ['parent_id' => $newParentId, 'sort_order' => $this->nextOrder($newParentId)], ['id' => $id]);
            $this->audit->log($actor, 'cms.page_moved', 'cms_pages', $id, ['parent_id' => $p['parent_id']], ['parent_id' => $newParentId]);
        });
    }

    /** Seul un brouillon jamais publié ni programmé, sans sous-page, peut être supprimé ; sinon : archiver. */
    public function delete(int $id, int $actor): void
    {
        try {
            $this->db->atomic(function () use ($id, $actor): void {
                $p = $this->lock($id);
                if ($p['published_by'] !== null || !in_array($p['status'], ['brouillon', 'en_relecture'], true)) {
                    throw new DomainException('Cette page a déjà été publiée ou programmée : elle ne peut pas être supprimée. Archivez-la.');
                }
                if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages WHERE parent_id = ?', [$id]) > 0) {
                    throw new DomainException('Cette page a des sous-pages : déplacez-les ou supprimez-les d\'abord.');
                }
                $this->db->query('DELETE FROM cms_pages WHERE id = ?', [$id]);
                $this->audit->log($actor, 'cms.page_deleted', 'cms_pages', $id, ['status' => $p['status'], 'slug' => $p['slug']], null);
            });
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                throw $e;
            }
            throw new DomainException('Cette page est encore référencée (menu…) : retirez-la d\'abord.');
        }
    }

    /** @return array<string,mixed> ligne avec blocks décodé, featured/og_media_id */
    public function get(int $id): array
    {
        $p = $this->db->fetch('SELECT * FROM cms_pages WHERE id = ?', [$id]) ?? throw new NotFound('Page introuvable.');
        $p['blocks'] = json_decode((string) ($p['blocks'] ?? '[]'), true) ?: [];
        foreach (['featured', 'og'] as $k) {
            $fid = $p[$k . '_file_id'];
            $p[$k . '_media_id'] = $fid === null ? null : $this->db->fetchValue('SELECT id FROM cms_media WHERE file_id = ? ORDER BY id LIMIT 1', [$fid]);
        }
        return $p;
    }

    /**
     * Version que le public peut voir, ou null : publie (ou programme échu) -> copie de travail ; brouillon / en_relecture /
     * programme non échu avec version conservée -> la version conservée ; sinon rien.
     * @return array<string,mixed>|null
     */
    public function liveVersion(int $id): ?array
    {
        $p = $this->db->fetch('SELECT * FROM cms_pages WHERE id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $base = ['id' => (int) $p['id'], 'parent_id' => $p['parent_id'] === null ? null : (int) $p['parent_id'], 'slug' => $p['slug'], 'template' => $p['template']];
        $due = $p['status'] === 'programme' && $p['published_at'] !== null && $p['published_at'] <= $this->now()->format('Y-m-d H:i:s');
        if ($p['status'] === 'publie' || $due) {
            return $base + ['title' => $p['title'], 'blocks' => json_decode((string) ($p['blocks'] ?? '[]'), true) ?: [], 'excerpt' => $p['excerpt'],
                'seo_title' => $p['seo_title'], 'seo_description' => $p['seo_description'], 'featured_file_id' => $p['featured_file_id'],
                'og_file_id' => $p['og_file_id'], 'canonical_url' => $p['canonical_url'], 'published_at' => $p['published_at']];
        }
        if (in_array($p['status'], ['brouillon', 'en_relecture', 'programme'], true) && $p['published_blocks'] !== null) {
            return $base + ['title' => $p['published_title'], 'blocks' => json_decode((string) $p['published_blocks'], true) ?: [], 'excerpt' => $p['published_excerpt'],
                'seo_title' => $p['published_seo_title'], 'seo_description' => $p['published_seo_description'], 'featured_file_id' => $p['published_featured_file_id'],
                'og_file_id' => $p['published_og_file_id'], 'canonical_url' => $p['published_canonical_url'], 'published_at' => $p['published_version_at']];
        }
        return null;
    }

    /** Arbre aplati (ordre, profondeur) pour la liste et les sélecteurs. @return list<array<string,mixed>> */
    public function tree(): array
    {
        $rows = $this->db->fetchAll('SELECT p.id, p.parent_id, p.title, p.slug, p.status, p.updated_at, p.published_at, p.published_blocks IS NOT NULL AS has_copy,
            u.name AS author FROM cms_pages p LEFT JOIN users u ON u.id = p.author_id ORDER BY p.sort_order, p.title, p.id');
        $children = [];
        foreach ($rows as $r) {
            $children[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $now = $this->now()->format('Y-m-d H:i:s');
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $children, $now): void {
            foreach ($children[$parent] ?? [] as $r) {
                $r['depth'] = $depth;
                $r['live'] = $r['status'] === 'publie' || (bool) $r['has_copy'] || ($r['status'] === 'programme' && $r['published_at'] <= $now);
                $out[] = $r;
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** Ids de la page et de ses descendants (exclus des parents possibles). @return list<int> */
    public function subtree(int $id): array
    {
        $ids = [$id];
        for ($frontier = [$id]; $frontier !== [];) {
            $frontier = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM cms_pages WHERE parent_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')', $frontier), 'id'));
            $frontier = array_values(array_diff($frontier, $ids));
            $ids = [...$ids, ...$frontier];
        }
        return $ids;
    }

    /**
     * Images de la médiathèque pour les sélecteurs (id => titre).
     * shortcut: liste complète dans un <select>, suffisant pour quelques centaines d'images ; passer au sélecteur paginé au-delà.
     * @return array<int,string>
     */
    public function imageChoices(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT m.id, m.title FROM cms_media m JOIN files f ON f.id = m.file_id WHERE f.mime LIKE 'image/%' ORDER BY m.title, m.id") as $r) {
            $out[(int) $r['id']] = $r['title'] . ' (n° ' . $r['id'] . ')';
        }
        return $out;
    }

    /** Médias (url, alt) et adresses des pages cités par des blocs, pour l'aperçu. @param list<array<string,mixed>> $blocks @return array{media:array<int,array{url:string,alt:string}>,pages:array<int,string>} */
    public function resolve(array $blocks): array
    {
        $refs = Blocks::references($blocks);
        $out = ['media' => [], 'pages' => []];
        foreach (array_unique($refs['media']) as $mid) {
            $r = $this->db->fetch('SELECT m.alt, f.path FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$mid]);
            if ($r !== null) {
                $out['media'][$mid] = ['url' => '/media/' . $r['path'], 'alt' => (string) $r['alt']];
            }
        }
        foreach (array_unique($refs['pages']) as $pid) {
            $slug = $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$pid]);
            if ($slug !== null) {
                $out['pages'][$pid] = (string) $slug;
            }
        }
        return $out;
    }

    /** Historique (journal d'audit) de la page. @return list<array<string,mixed>> */
    public function history(int $id): array
    {
        return $this->db->fetchAll("SELECT a.created_at, a.action, a.old_values, a.new_values, u.name AS user_name FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id WHERE a.auditable_type = 'cms_pages' AND a.auditable_id = ? ORDER BY a.id DESC LIMIT 100", [$id]);
    }

    /**
     * Valide et normalise les champs ; pose un verrou partagé sur les médias cités (la suppression d'un média verrouille sa ligne
     * FOR UPDATE puis vérifie ses usages : elle attend cette transaction). À appeler dans la transaction d'écriture.
     * @param array<string,mixed> $data @return array<string,mixed>
     */
    private function clean(array $data, ?int $id): array
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
            $slug = $slug === '' ? $this->freeSlug(Slugs::fromTitle($title), $id, $slugs) : $slugs->validate($slug, $id, 'cms_pages');
        } catch (DomainException $e) {
            $errors['slug'] = $e->getMessage();
        }
        $template = $str('template') ?: 'standard';
        if (!isset(self::TEMPLATES[$template])) {
            $errors['template'] = 'Gabarit inconnu.';
        }
        $parent = $this->optionalId($data['parent_id'] ?? null);
        if ($parent === false || ($parent !== null && ($error = $this->parentError($parent, $id)) !== null)) {
            $errors['parent_id'] = $error ?? 'Page parente invalide.';
        }
        $limits = ['excerpt' => [500, 'L\'extrait'], 'seo_title' => [70, 'Le titre SEO'], 'seo_description' => [160, 'La description SEO'], 'canonical_url' => [255, 'L\'adresse canonique']];
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
        $blocks = [];
        $rawBlocks = $data['blocks'] ?? [];
        try {
            $blocks = Blocks::validate(is_array($rawBlocks) ? $rawBlocks : throw new BlockInvalid('blocs', 'Blocs invalides.'));
        } catch (BlockInvalid $e) {
            $errors[$e->path] = $e->getMessage();
        }
        // Médias : blocs + image à la une + image Open Graph ; tous verrouillés en partage.
        $refs = Blocks::references($blocks);
        $mediaRefs = $refs['media'];
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
        foreach ($refs['pages'] as $path => $pid) {
            if ($this->db->fetchValue('SELECT id FROM cms_pages WHERE id = ?', [$pid]) === null) {
                $errors[$path] = "La page n° $pid n'existe pas : choisissez-en une autre.";
            }
        }
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $title, 'slug' => $slug, 'template' => $template, 'parent_id' => $parent,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'excerpt' => $texts['excerpt'] ?: null, 'seo_title' => $texts['seo_title'] ?: null, 'seo_description' => $texts['seo_description'] ?: null,
            'canonical_url' => $texts['canonical_url'] ?: null,
            'featured_file_id' => $files['featured_media_id'] ?? null, 'og_file_id' => $files['og_media_id'] ?? null];
    }

    /** null (vide), un id, ou false (illisible). */
    private function optionalId(mixed $raw): int|null|false
    {
        $s = is_scalar($raw) ? trim((string) $raw) : '';
        if ($s === '') {
            return null;
        }
        return ctype_digit($s) && strlen($s) < 19 && (int) $s > 0 ? (int) $s : false;
    }

    private function parentError(?int $parent, ?int $id): ?string
    {
        if ($parent === null) {
            return null;
        }
        if ($this->db->fetchValue('SELECT id FROM cms_pages WHERE id = ?', [$parent]) === null) {
            return 'La page parente n\'existe pas.';
        }
        if ($id !== null && in_array($parent, $this->subtree($id), true)) {
            return 'Une page ne peut pas être rangée sous elle-même ni sous une de ses sous-pages.';
        }
        return null;
    }

    private function freeSlug(string $base, ?int $id, Slugs $slugs): string
    {
        for ($n = 1; $n <= 50; $n++) {
            $candidate = $n === 1 ? $base : rtrim(substr($base, 0, 80 - strlen("-$n")), '-') . "-$n";
            try {
                return $slugs->validate($candidate, $id, 'cms_pages');
            } catch (DomainException) {
            }
        }
        throw new DomainException('Aucune adresse libre : saisissez-en une.');
    }

    private function nextOrder(?int $parent): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM cms_pages WHERE parent_id <=> ?', [$parent]);
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->fetch('SELECT * FROM cms_pages WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Page introuvable.');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    /** Course sur l'adresse (deux enregistrements simultanés) : l'index unique tranche, message clair. */
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
