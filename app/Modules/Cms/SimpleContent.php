<?php
declare(strict_types=1);

namespace App\Modules\Cms;

/**
 * Circuit simple des documents, galeries, bannières et témoignages : brouillon / publié / archivé, sans relecture ni programmation.
 * cms.pages.manage rédige les brouillons ; seul cms.publish publie, archive ou dépublie. Un contenu publié n'est modifiable que par un publicateur
 * (la modification est visible tout de suite : pas de version conservée). Un contenu jamais publié (published_at vide) peut être supprimé.
 */
abstract class SimpleContent extends CmsContent
{
    public const STATUS_LABELS = ['brouillon' => 'Brouillon', 'publie' => 'Publié', 'archive' => 'Archivé'];
    private const TRANSITIONS = ['brouillon' => ['publie'], 'publie' => ['archive', 'brouillon'], 'archive' => ['brouillon']];

    /** @return list<string> */
    public static function transitionsFor(string $from, bool $mayPublish): array
    {
        return $mayPublish ? self::TRANSITIONS[$from] ?? [] : [];
    }

    /** Contrôles propres au type avant publication (verrou déjà posé) ; lève ContentTransitionInvalid. @param array<string,mixed> $row */
    protected function beforePublish(array $row): void
    {
    }

    /** Version de la copie de travail : à appeler à chaque changement de contenu (texte, fichier, éléments), verrou déjà posé. */
    protected function bump(int $id): void
    {
        $this->db->query('UPDATE ' . static::TABLE . ' SET content_version = content_version + 1 WHERE id = ?', [$id]);
    }

    /** $seenVersion : version affichée au publicateur ; exigée pour publier (absente ou différente : refus). */
    public function transition(int $id, string $to, int $actor, ?int $seenVersion = null): void
    {
        $this->db->atomic(function () use ($id, $to, $actor, $seenVersion): void {
            $row = $this->lock($id);
            $from = (string) $row['status'];
            if (!in_array($to, self::transitionsFor($from, $this->gate->allows($actor, 'cms.publish')), true)) {
                throw new ContentTransitionInvalid("Transition impossible : « $from » vers « $to » (droit de publication requis).");
            }
            $set = ['status' => $to];
            if ($to === 'publie') {
                if ($seenVersion !== (int) $row['content_version']) {
                    throw new ContentTransitionInvalid('Le contenu a été modifié depuis votre lecture, relisez-le.');
                }
                $this->beforePublish($row);
                $set['published_at'] = $this->now()->format('Y-m-d H:i:s');
            }
            $this->db->update(static::TABLE, $set, ['id' => $id]);
            $action = match (true) {
                $to === 'publie' => '_published',
                $to === 'archive' => '_archived',
                $from === 'archive' => '_restored',
                default => '_unpublished',
            };
            $this->audit->log($actor, 'cms.' . static::NOUN . $action, static::TABLE, $id, ['status' => $from], ['status' => $to]);
        });
    }

    public function delete(int $id, int $actor): void
    {
        $this->db->atomic(function () use ($id, $actor): void {
            $row = $this->lock($id);
            if ($row['published_at'] !== null || $row['status'] !== 'brouillon') {
                throw new ContentTransitionInvalid(static::THE . ' a déjà été publié : il ne peut pas être supprimé. Archivez-le.');
            }
            $this->db->query('DELETE FROM ' . static::TABLE . ' WHERE id = ?', [$id]);
            $this->audit->log($actor, 'cms.' . static::NOUN . '_deleted', static::TABLE, $id, ['status' => $row['status']], null);
        });
    }

    /** Un contenu publié change le site dès l'enregistrement : réservé aux publicateurs. @param array<string,mixed> $row */
    protected function assertMayEdit(array $row, int $actor): void
    {
        if ($row['status'] === 'publie' && !$this->gate->allows($actor, 'cms.publish')) {
            throw new ContentTransitionInvalid(static::THE . ' est en ligne : seul un publicateur peut le modifier.');
        }
    }

    /** @return list<array<string,mixed>> les plus récemment modifiés d'abord (shortcut : 200 lignes, sans pagination ; paginer au-delà) */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM ' . static::TABLE . ' ORDER BY updated_at DESC, id DESC LIMIT 200');
    }
}
