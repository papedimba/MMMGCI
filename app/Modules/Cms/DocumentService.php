<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DateTimeImmutable;

/** Documents officiels : fichier de la médiathèque (PDF, JPG, PNG), jamais public avant publication par un publicateur. Lecture publique (tâche 8) : publishedList(). */
final class DocumentService extends SimpleContent
{
    protected const TABLE = 'cms_documents';
    protected const NOUN = 'document';
    protected const THE = 'Ce document';
    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->db->atomic(function () use ($data, $actor): int {
            $row = $this->clean($data);
            $id = $this->db->insert(static::TABLE, $row + ['status' => 'brouillon', 'created_by' => $actor]);
            $this->audit->log($actor, 'cms.document_created', static::TABLE, $id, null, ['status' => 'brouillon']);
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->db->atomic(function () use ($id, $data, $actor): void {
            $old = $this->lock($id);
            $this->assertMayEdit($old, $actor);
            $this->db->update(static::TABLE, $this->clean($data), ['id' => $id]);
            $this->bump($id);
            $this->audit->log($actor, 'cms.document_updated', static::TABLE, $id, ['status' => $old['status']], ['status' => $old['status']]);
        });
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $d = parent::get($id);
        $d['media_id'] = $this->mediaIdOfFile((int) $d['file_id']);
        return $d;
    }

    /** @return list<string> catégories déjà utilisées */
    public function categories(): array
    {
        return array_column($this->db->fetchAll("SELECT DISTINCT category FROM cms_documents WHERE category IS NOT NULL AND category <> '' ORDER BY category"), 'category');
    }

    /** Documents publiés (jamais brouillon ni archivé), du plus récent au plus ancien. @return list<array<string,mixed>> */
    public function publishedList(?string $category = null): array
    {
        return $this->db->fetchAll("SELECT d.id, d.title, d.category, d.document_date, d.published_at, f.mime, f.size, CONCAT('/media/', f.path) AS url
            FROM cms_documents d JOIN files f ON f.id = d.file_id WHERE d.status = 'publie' AND f.disk = 'public' AND (? IS NULL OR d.category = ?)
            ORDER BY d.document_date DESC, d.id DESC", [$category, $category]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clean(array $data): array
    {
        $errors = [];
        $title = self::limited($data, 'title', 200, 'Le titre', $errors, true);
        $category = self::limited($data, 'category', 80, 'La catégorie', $errors);
        $raw = self::str($data, 'document_date');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($raw === '') {
            $errors['document_date'] = 'La date du document est obligatoire.';
        } elseif ($d === false || $d->format('Y-m-d') !== $raw) {
            $errors['document_date'] = 'Date invalide.';
        }
        $file = $this->mediaFile($data['media_id'] ?? null, 'media_id', self::MIMES, false, $errors);
        if ($file === null && !isset($errors['media_id'])) {
            $errors['media_id'] = 'Choisissez le fichier du document (PDF, JPG ou PNG) dans la médiathèque.';
        }
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $title, 'category' => $category ?: null, 'document_date' => $raw, 'file_id' => $file];
    }
}
