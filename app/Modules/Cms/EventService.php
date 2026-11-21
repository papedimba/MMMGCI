<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Évènements : même circuit que les articles (ContentWorkflow, un seul point d'entrée transition(), version vue exigée à la publication, relecture
 * modifiée -> brouillon, publication programmée par CmsScheduler), mais SANS version conservée : un évènement déjà en ligne est modifié en direct.
 * Règle sûre qui en découle : un évènement qui a déjà été publié ou programmé (published_by) n'est modifiable que par un publicateur ; l'auteur
 * ne modifie que ce qui n'a jamais été en ligne. Pas de champs SEO (la table n'en a pas). Lecture publique (tâche 8) : liveList().
 */
final class EventService extends CmsContent implements ScheduledContent
{
    protected const TABLE = 'cms_events';
    protected const NOUN = 'event';
    protected const THE = 'Cet évènement';
    public const STATUS_LABELS = PageService::STATUS_LABELS;
    private const VERSIONED = ['title', 'slug', 'description', 'starts_at', 'ends_at', 'location', 'featured_file_id'];

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->guardSlugRace(fn (): int => $this->db->atomic(function () use ($data, $actor): int {
            $row = $this->clean($data, null);
            $id = $this->db->insert(static::TABLE, $row + ['status' => 'brouillon', 'created_by' => $actor]);
            $this->audit->log($actor, 'cms.event_created', static::TABLE, $id, null, ['status' => 'brouillon', 'slug' => $row['slug']]);
            return $id;
        }));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->guardSlugRace(fn () => $this->db->atomic(function () use ($id, $data, $actor): void {
            $old = $this->lock($id);
            if ($old['published_by'] !== null && !$this->gate->allows($actor, 'cms.publish')) {
                throw new ContentTransitionInvalid('Cet évènement a déjà été en ligne : seul un publicateur peut le modifier.');
            }
            $row = $this->clean($data, $id);
            $changed = array_values(array_filter(self::VERSIONED, static fn (string $k): bool => (string) ($old[$k] ?? '') !== (string) ($row[$k] ?? '')));
            $status = (string) $old['status'];
            if ($changed !== [] && in_array($status, ['en_relecture', 'programme'], true)) {
                $status = 'brouillon';
                $row += ['status' => $status, 'published_at' => null];
            }
            if ($changed !== []) {
                $row['content_version'] = (int) $old['content_version'] + 1;
            }
            $this->db->update(static::TABLE, $row, ['id' => $id]);
            $this->audit->log($actor, 'cms.event_updated', static::TABLE, $id, ['status' => $old['status'], 'slug' => $old['slug']], ['status' => $status, 'slug' => $row['slug'], 'fields' => $changed]);
        }));
    }

    /** Point d'entrée unique des transitions. */
    public function transition(int $id, string $to, int $actor, ?DateTimeImmutable $at = null, ?int $seenVersion = null): void
    {
        $this->db->atomic(function () use ($id, $to, $actor, $at, $seenVersion): void {
            $e = $this->lock($id);
            $from = (string) $e['status'];
            ContentWorkflow::assertCan($from, $to, $this->gate->allows($actor, 'cms.publish'));
            if (in_array($to, ['publie', 'programme'], true) && $seenVersion !== null && $seenVersion !== (int) $e['content_version']) {
                throw new ContentTransitionInvalid('L\'évènement a été modifié depuis votre lecture, relisez-le.');
            }
            $set = ['status' => $to];
            $extra = [];
            if ($to === 'en_relecture') {
                $set['submitted_by'] = $actor;
            } elseif ($to === 'publie') {
                $set += ['published_at' => $this->now()->format('Y-m-d H:i:s'), 'published_by' => $actor, 'archived_at' => null];
            } elseif ($to === 'programme') {
                ContentWorkflow::assertScheduledDate($at, $this->now());
                $set += ['published_at' => $at->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d H:i:s'), 'published_by' => $actor, 'archived_at' => null];
                $extra['at'] = $set['published_at'];
            } elseif ($to === 'archive') {
                $set['archived_at'] = $this->now()->format('Y-m-d H:i:s');
            } else {
                $set['archived_at'] = null;
                if (in_array($from, ['publie', 'programme'], true)) {
                    $set['published_at'] = null;
                }
            }
            $this->db->update(static::TABLE, $set, ['id' => $id]);
            $action = match (true) {
                $to === 'en_relecture' => 'cms.event_submitted',
                $to === 'publie' => 'cms.event_published',
                $to === 'programme' => 'cms.event_scheduled',
                $to === 'archive' => 'cms.event_archived',
                $from === 'en_relecture' => 'cms.event_withdrawn',
                $from === 'archive' => 'cms.event_restored',
                default => 'cms.event_unpublished',
            };
            $this->audit->log($actor, $action, static::TABLE, $id, ['status' => $from], ['status' => $to, 'slug' => $e['slug']] + $extra);
        });
    }

    /** Seul un évènement jamais publié ni programmé, en brouillon ou relecture, peut être supprimé ; sinon : archiver. */
    public function delete(int $id, int $actor): void
    {
        $this->db->atomic(function () use ($id, $actor): void {
            $e = $this->lock($id);
            if ($e['published_by'] !== null || !in_array($e['status'], ['brouillon', 'en_relecture'], true)) {
                throw new ContentTransitionInvalid('Cet évènement a déjà été publié ou programmé : il ne peut pas être supprimé. Archivez-le.');
            }
            $this->db->query('DELETE FROM cms_events WHERE id = ?', [$id]);
            $this->audit->log($actor, 'cms.event_deleted', static::TABLE, $id, ['status' => $e['status'], 'slug' => $e['slug']], null);
        });
    }

    public function scheduledTable(): string
    {
        return 'cms_events';
    }

    public function dueIds(DateTimeImmutable $now): array
    {
        return array_map('intval', array_column($this->db->fetchAll("SELECT id FROM cms_events WHERE status = 'programme' AND published_at <= ? ORDER BY id", [$now->format('Y-m-d H:i:s')]), 'id'));
    }

    public function publishDue(int $id, DateTimeImmutable $now): void
    {
        $this->db->atomic(function () use ($id, $now): void {
            $e = $this->lock($id);
            if ($e['status'] !== 'programme' || $e['published_at'] === null || $e['published_at'] > $now->format('Y-m-d H:i:s')) {
                return;
            }
            $this->db->update(static::TABLE, ['status' => 'publie'], ['id' => $id]);
            $this->audit->log(null, 'cms.scheduled_published', static::TABLE, $id, ['status' => 'programme'], ['status' => 'publie', 'slug' => $e['slug']]);
        });
    }

    /**
     * Évènements que le public peut voir (publiés, ou programmés échus).
     * @param array{when?:string,limit?:int,slug?:string} $criteria when : upcoming (en cours ou à venir, du plus proche), past (terminés, du plus récent), sinon tous
     * @return list<array<string,mixed>>
     */
    public function liveList(array $criteria = []): array
    {
        $now = $this->now()->format('Y-m-d H:i:s');
        $where = ["(status = 'publie' OR (status = 'programme' AND published_at <= ?))"];
        $args = [$now];
        $order = 'starts_at DESC, id DESC';
        if (($criteria['when'] ?? '') === 'upcoming') {
            $where[] = 'COALESCE(ends_at, starts_at) >= ?';
            $args[] = $now;
            $order = 'starts_at ASC, id ASC';
        } elseif (($criteria['when'] ?? '') === 'past') {
            $where[] = 'COALESCE(ends_at, starts_at) < ?';
            $args[] = $now;
        }
        if (isset($criteria['slug'])) {
            $where[] = 'slug = ?';
            $args[] = (string) $criteria['slug'];
        }
        $limit = max(1, min(5000, (int) ($criteria['limit'] ?? 20)));
        return $this->db->fetchAll('SELECT id, title, slug, description, starts_at, ends_at, location, featured_file_id, published_at FROM cms_events WHERE '
            . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit", $args);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT id, title, slug, status, starts_at, ends_at, location, updated_at FROM cms_events ORDER BY starts_at DESC, id DESC LIMIT 200');
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $e = parent::get($id);
        $e['featured_media_id'] = $this->mediaIdOfFile($e['featured_file_id'] === null ? null : (int) $e['featured_file_id']);
        return $e;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clean(array $data, ?int $id): array
    {
        $errors = [];
        $title = self::limited($data, 'title', 200, 'Le titre', $errors, true);
        $slug = $this->resolveSlug(self::str($data, 'slug'), $title, $id, $errors);
        $description = '';
        try {
            $description = RichText::fromRequestField($data['description'] ?? '');
        } catch (DomainException $e) {
            $errors['description'] = $e->getMessage();
        }
        $location = self::limited($data, 'location', 160, 'Le lieu', $errors);
        $from = self::dateTime($data['starts_at'] ?? null);
        $to = self::dateTime($data['ends_at'] ?? null);
        if ($from === null) {
            $errors['starts_at'] = 'La date de début est obligatoire.';
        } elseif ($from === false) {
            $errors['starts_at'] = 'Date et heure de début invalides.';
        }
        if ($to === false) {
            $errors['ends_at'] = 'Date et heure de fin invalides.';
        } elseif (is_string($from) && is_string($to) && $to < $from) {
            $errors['ends_at'] = 'La fin ne peut pas précéder le début.';
        }
        $file = $this->mediaFile($data['featured_media_id'] ?? null, 'featured_media_id', GalleryService::IMAGES, false, $errors);
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $title, 'slug' => $slug, 'description' => $description ?: null, 'starts_at' => $from, 'ends_at' => $to,
            'location' => $location ?: null, 'featured_file_id' => $file];
    }
}
