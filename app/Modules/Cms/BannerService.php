<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DateTimeImmutable;
use PDOException;

/** Bannières : texte court (colonne title), lien, période. Au plus 3 bannières publiées dont la période n'est pas terminée. Lecture publique (tâche 8) : activeNow(). */
final class BannerService extends SimpleContent
{
    protected const TABLE = 'cms_banners';
    protected const NOUN = 'banner';
    protected const THE = 'Cette bannière';
    public const MAX_ACTIVE = 3;

    /** @param array<string,mixed> $data */
    public function create(array $data, int $actor): int
    {
        return $this->db->atomic(function () use ($data, $actor): int {
            $id = $this->db->insert(static::TABLE, $this->clean($data) + ['status' => 'brouillon']);
            $this->audit->log($actor, 'cms.banner_created', static::TABLE, $id, null, ['status' => 'brouillon']);
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $this->db->atomic(function () use ($id, $data, $actor): void {
            $this->lockSet();
            $old = $this->lock($id);
            $this->assertMayEdit($old, $actor);
            $row = $this->clean($data);
            if ($old['status'] === 'publie') {
                $this->assertRoom($id, $row['ends_at']);
            }
            $this->db->update(static::TABLE, $row, ['id' => $id]);
            $this->audit->log($actor, 'cms.banner_updated', static::TABLE, $id, ['status' => $old['status']], ['status' => $old['status']]);
        });
    }

    public function transition(int $id, string $to, int $actor): void
    {
        try {
            $this->db->atomic(function () use ($id, $to, $actor): void {
                if ($to === 'publie') {
                    $this->lockSet(); // même ordre de verrous que update() : l'ensemble, puis la ligne
                }
                parent::transition($id, $to, $actor);
            });
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new ContentTransitionInvalid('Le serveur est occupé : réessayez dans quelques instants.');
            }
            throw $e;
        }
    }

    protected function beforePublish(array $row): void
    {
        $this->assertRoom((int) $row['id'], $row['ends_at']);
    }

    /** Verrou sur toutes les bannières : deux publications simultanées se suivent au lieu de dépasser la limite. */
    private function lockSet(): void
    {
        $this->db->query('SELECT id FROM cms_banners ORDER BY id FOR UPDATE');
    }

    private function assertRoom(int $self, ?string $endsAt): void
    {
        if ($endsAt !== null && $endsAt < $this->now()->format('Y-m-d H:i:s')) {
            return; // période terminée : ne compte pas
        }
        $n = (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_banners WHERE status = 'publie' AND id <> ? AND (ends_at IS NULL OR ends_at >= ?)",
            [$self, $this->now()->format('Y-m-d H:i:s')]);
        if ($n >= self::MAX_ACTIVE) {
            throw new ContentTransitionInvalid('Trois bannières sont déjà publiées (période en cours ou à venir) : archivez ou dépubliez-en une avant d\'en publier une autre.');
        }
    }

    /** Bannières à afficher à cet instant, au plus 3. @return list<array<string,mixed>> */
    public function activeNow(DateTimeImmutable $now): array
    {
        $t = $now->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i:s');
        return $this->db->fetchAll("SELECT id, title AS text, link_url, starts_at, ends_at FROM cms_banners WHERE status = 'publie'
            AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY sort_order, id LIMIT " . self::MAX_ACTIVE, [$t, $t]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clean(array $data): array
    {
        $errors = [];
        $text = self::limited($data, 'title', 160, 'Le texte', $errors, true);
        $link = self::limited($data, 'link_url', 255, 'Le lien', $errors);
        if ($link !== '' && !isset($errors['link_url']) && !Blocks::isSafeUrl($link)) {
            $errors['link_url'] = 'Lien non accepté : adresse https://…, adresse du site (/page), mailto: ou tel:.';
        }
        $from = self::dateTime($data['starts_at'] ?? null);
        $to = self::dateTime($data['ends_at'] ?? null);
        foreach (['starts_at' => $from, 'ends_at' => $to] as $k => $v) {
            if ($v === false) {
                $errors[$k] = 'Date et heure invalides.';
            }
        }
        if (is_string($from) && is_string($to) && $to < $from) {
            $errors['ends_at'] = 'La fin ne peut pas précéder le début.';
        }
        if ($errors !== []) {
            throw new PageInvalid($errors);
        }
        return ['title' => $text, 'link_url' => $link ?: null, 'starts_at' => $from, 'ends_at' => $to];
    }
}
