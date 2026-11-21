<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Settings;
use App\Core\UploadError;
use App\Core\Uploads;
use DomainException;

/**
 * Témoignages, désactivés par défaut. Publication : cms.publish ET réglage site.testimonials_enabled validé à la valeur true ET fichier de consentement.
 * Le consentement est un document PRIVÉ (disque privé, jamais /media), lisible par les seuls publicateurs via TestimonialController (consultation journalisée).
 * Supprimer un témoignage (jamais publié) ne supprime que sa ligne : la pièce de consentement est conservée comme preuve.
 * Lecture publique (tâche 8) : publishable().
 */
final class TestimonialService extends SimpleContent
{
    protected const TABLE = 'cms_testimonials';
    protected const NOUN = 'testimonial';
    protected const THE = 'Ce témoignage';

    public function __construct(Database $db, Audit $audit, Gate $gate, private Settings $settings, private Uploads $uploads)
    {
        parent::__construct($db, $audit, $gate);
    }

    public function enabled(): bool
    {
        return $this->settings->isValidated('site', 'testimonials_enabled') && $this->settings->get('site', 'testimonials_enabled') === true;
    }

    /** @param array<string,mixed> $data @param array<string,mixed>|null $consent entrée $_FILES */
    public function create(array $data, ?array $consent, int $actor): int
    {
        $row = $this->clean($data);
        $fileId = $this->store($consent, $actor);
        try {
            return $this->db->atomic(function () use ($row, $fileId, $actor): int {
                $id = $this->db->insert(static::TABLE, $row + ['status' => 'brouillon', 'consent_file_id' => $fileId]);
                $this->audit->log($actor, 'cms.testimonial_created', static::TABLE, $id, null, ['status' => 'brouillon', 'consent' => $fileId !== null]);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->discard($fileId);
            throw $e;
        }
    }

    /** @param array<string,mixed> $data @param array<string,mixed>|null $consent un nouveau fichier remplace l'ancien */
    public function update(int $id, array $data, ?array $consent, int $actor): void
    {
        $row = $this->clean($data);
        $new = $this->store($consent, $actor);
        try {
            $oldFile = $this->db->atomic(function () use ($id, $row, $new, $actor): ?int {
                $old = $this->lock($id);
                $this->assertMayEdit($old, $actor);
                $this->db->update(static::TABLE, $row + ($new === null ? [] : ['consent_file_id' => $new]), ['id' => $id]);
                $this->bump($id);
                $this->audit->log($actor, 'cms.testimonial_updated', static::TABLE, $id, ['status' => $old['status']], ['status' => $old['status'], 'consent_replaced' => $new !== null]);
                return $new !== null && $old['consent_file_id'] !== null ? (int) $old['consent_file_id'] : null;
            });
        } catch (\Throwable $e) {
            $this->discard($new);
            throw $e;
        }
        $this->discard($oldFile); // après le COMMIT : l'ancienne pièce n'est plus référencée
    }

    protected function beforePublish(array $row): void
    {
        if (!$this->enabled()) {
            throw new ContentTransitionInvalid('Les témoignages sont désactivés : le réglage « site.testimonials_enabled » n\'est pas validé par les instances.');
        }
        if ($row['consent_file_id'] === null) {
            throw new ContentTransitionInvalid('Publication refusée : le fichier de consentement signé est obligatoire.');
        }
    }

    /** Témoignages que le public peut voir : publiés, réglage validé à true, consentement présent. @return list<array<string,mixed>> */
    public function publishable(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        return $this->db->fetchAll("SELECT id, body AS quote, author_name, author_role, published_at FROM cms_testimonials
            WHERE status = 'publie' AND consent_file_id IS NOT NULL ORDER BY published_at DESC, id DESC");
    }

    /** Pièce de consentement (disque privé) pour le contrôleur de téléchargement. @return array{id:int,mime:string,original_name:string} */
    public function consentFile(int $id): array
    {
        $f = $this->db->fetch("SELECT f.id, f.mime, f.original_name FROM cms_testimonials t JOIN files f ON f.id = t.consent_file_id WHERE t.id = ? AND f.disk = 'private'", [$id]);
        return $f ?? throw new \App\Core\NotFound('Pièce introuvable.');
    }

    public function path(int $fileId): string
    {
        return $this->uploads->path($fileId);
    }

    /** @param array<string,mixed>|null $file */
    private function store(?array $file, int $actor): ?int
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        try {
            return $this->uploads->store($file, 'document', false, $actor);
        } catch (UploadError $e) {
            throw new PageInvalid(['consent' => $e->getMessage()]);
        }
    }

    private function discard(?int $fileId): void
    {
        if ($fileId === null) {
            return;
        }
        try {
            $path = $this->uploads->path($fileId);
            $this->db->query('DELETE FROM files WHERE id = ?', [$fileId]);
            @unlink($path);
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clean(array $data): array
    {
        $errors = [];
        $quote = self::limited($data, 'body', 600, 'Le témoignage', $errors, true, true);
        $name = self::limited($data, 'author_name', 80, 'Le nom', $errors, true);
        $role = self::limited($data, 'author_role', 80, 'La fonction', $errors);
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['body' => $quote, 'author_name' => $name, 'author_role' => $role ?: null];
    }
}
