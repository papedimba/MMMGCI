<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/**
 * Albums et éléments (image de la médiathèque + légende + crédit). Le texte alternatif vient du média et est obligatoire : on refuse tout média non image
 * ou sans alt. Un album publié n'est modifiable (album et éléments) que par un publicateur. Lecture publique (tâche 8) : publishedAlbums(), publishedItems().
 */
final class GalleryService extends SimpleContent
{
    protected const TABLE = 'cms_galleries';
    protected const NOUN = 'gallery';
    protected const THE = 'Cet album';
    public const MAX_ITEMS = 200;
    public const IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->guardSlugRace(fn (): int => $this->db->atomic(function () use ($data, $actor): int {
            $id = $this->db->insert(static::TABLE, $this->clean($data, null) + ['status' => 'brouillon']);
            $this->audit->log($actor, 'cms.gallery_created', static::TABLE, $id, null, ['status' => 'brouillon']);
            return $id;
        }));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->guardSlugRace(fn () => $this->db->atomic(function () use ($id, $data, $actor): void {
            $old = $this->lock($id);
            $this->assertMayEdit($old, $actor);
            $this->db->update(static::TABLE, $this->clean($data, $id), ['id' => $id]);
            $this->audit->log($actor, 'cms.gallery_updated', static::TABLE, $id, ['status' => $old['status']], ['status' => $old['status']]);
        }));
    }

    /** @return list<array<string,mixed>> éléments dans l'ordre, avec le média (url, alt, titre) */
    public function items(int $galleryId): array
    {
        return $this->db->fetchAll("SELECT i.id, i.media_id, i.caption, i.credit, i.sort_order, m.title, m.alt, CONCAT('/media/', f.path) AS url
            FROM cms_gallery_items i JOIN cms_media m ON m.id = i.media_id JOIN files f ON f.id = m.file_id WHERE i.gallery_id = ? ORDER BY i.sort_order, i.id", [$galleryId]);
    }

    /** @param array<string,mixed> $data media_id, caption, credit */
    public function addItem(int $galleryId, array $data, int $actor): int
    {
        return $this->db->atomic(function () use ($galleryId, $data, $actor): int {
            $g = $this->lock($galleryId);
            $this->assertMayEdit($g, $actor);
            $errors = [];
            $this->mediaFile($data['media_id'] ?? null, 'media_id', self::IMAGES, true, $errors);
            $mid = self::optionalId($data['media_id'] ?? null);
            if ($mid === null || $mid === false) {
                $errors['media_id'] ??= 'Choisissez une image de la médiathèque.';
            }
            [$caption, $credit] = $this->texts($data, $errors);
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_gallery_items WHERE gallery_id = ?', [$galleryId]) >= self::MAX_ITEMS) {
                $errors['media_id'] = 'Un album compte ' . self::MAX_ITEMS . ' images au maximum.';
            }
            if ($errors !== []) {
                throw new PageInvalid($errors);
            }
            $id = $this->db->insert('cms_gallery_items', ['gallery_id' => $galleryId, 'media_id' => $mid, 'caption' => $caption ?: null, 'credit' => $credit ?: null,
                'sort_order' => (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cms_gallery_items WHERE gallery_id = ?', [$galleryId])]);
            $this->audit->log($actor, 'cms.gallery_item_added', static::TABLE, $galleryId, null, ['item_id' => $id, 'media_id' => $mid]);
            return $id;
        });
    }

    /** @param array<string,mixed> $data caption, credit */
    public function updateItem(int $galleryId, int $itemId, array $data, int $actor): void
    {
        $this->db->atomic(function () use ($galleryId, $itemId, $data, $actor): void {
            $this->assertMayEdit($this->lock($galleryId), $actor);
            $this->itemRow($galleryId, $itemId);
            $errors = [];
            [$caption, $credit] = $this->texts($data, $errors);
            if ($errors !== []) {
                throw new PageInvalid($errors);
            }
            $this->db->update('cms_gallery_items', ['caption' => $caption ?: null, 'credit' => $credit ?: null], ['id' => $itemId]);
            $this->audit->log($actor, 'cms.gallery_item_updated', static::TABLE, $galleryId, null, ['item_id' => $itemId]);
        });
    }

    public function removeItem(int $galleryId, int $itemId, int $actor): void
    {
        $this->db->atomic(function () use ($galleryId, $itemId, $actor): void {
            $this->assertMayEdit($this->lock($galleryId), $actor);
            $this->itemRow($galleryId, $itemId);
            $this->db->query('DELETE FROM cms_gallery_items WHERE id = ?', [$itemId]);
            $this->audit->log($actor, 'cms.gallery_item_removed', static::TABLE, $galleryId, null, ['item_id' => $itemId]);
        });
    }

    /** Échange l'élément avec son voisin ($direction -1 = monter, +1 = descendre) puis renumérote 1..n. */
    public function moveItem(int $galleryId, int $itemId, int $direction, int $actor): void
    {
        $this->db->atomic(function () use ($galleryId, $itemId, $direction, $actor): void {
            $this->assertMayEdit($this->lock($galleryId), $actor);
            $this->itemRow($galleryId, $itemId);
            $ids = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM cms_gallery_items WHERE gallery_id = ? ORDER BY sort_order, id', [$galleryId]), 'id'));
            $i = (int) array_search($itemId, $ids, true);
            $j = $i + ($direction < 0 ? -1 : 1);
            if (isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
            foreach ($ids as $n => $id) {
                $this->db->update('cms_gallery_items', ['sort_order' => $n + 1], ['id' => $id]);
            }
            $this->audit->log($actor, 'cms.gallery_item_moved', static::TABLE, $galleryId, null, ['item_id' => $itemId]);
        });
    }

    /** Albums publiés (jamais brouillon ni archivé), avec le nombre d'images publiables. @return list<array<string,mixed>> */
    public function publishedAlbums(): array
    {
        return $this->db->fetchAll("SELECT g.id, g.title, g.slug, g.description, g.published_at,
            (SELECT COUNT(*) FROM cms_gallery_items i JOIN cms_media m ON m.id = i.media_id WHERE i.gallery_id = g.id AND m.alt IS NOT NULL AND m.alt <> '') AS item_count
            FROM cms_galleries g WHERE g.status = 'publie' ORDER BY g.published_at DESC, g.id DESC");
    }

    /** Images d'un album publié (vide si l'album n'est pas publié), seulement celles qui ont un texte alternatif. @return list<array<string,mixed>> */
    public function publishedItems(int $albumId): array
    {
        return $this->db->fetchAll("SELECT i.id, i.caption, i.credit, m.alt, CONCAT('/media/', f.path) AS url
            FROM cms_gallery_items i JOIN cms_galleries g ON g.id = i.gallery_id JOIN cms_media m ON m.id = i.media_id JOIN files f ON f.id = m.file_id
            WHERE g.id = ? AND g.status = 'publie' AND f.disk = 'public' AND f.mime LIKE 'image/%' AND m.alt IS NOT NULL AND m.alt <> '' ORDER BY i.sort_order, i.id", [$albumId]);
    }

    /** @return array<string,mixed> */
    private function itemRow(int $galleryId, int $itemId): array
    {
        return $this->db->fetch('SELECT * FROM cms_gallery_items WHERE id = ? AND gallery_id = ?', [$itemId, $galleryId]) ?? throw new DomainException('Cet élément n\'existe plus.');
    }

    /** @param array<string,mixed> $data @param array<string,string> $errors @return array{0:string,1:string} */
    private function texts(array $data, array &$errors): array
    {
        return [self::limited($data, 'caption', 200, 'La légende', $errors), self::limited($data, 'credit', 120, 'Le crédit', $errors)];
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
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $title, 'slug' => $slug, 'description' => $description ?: null];
    }
}
