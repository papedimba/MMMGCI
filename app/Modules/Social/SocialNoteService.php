<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;

/** Notes internes (ajout seul : ni modification ni suppression) et lecture des avis : jamais montrées à l'adhérent ni à un détenteur de social.view seul. */
final class SocialNoteService
{
    public const WARNING = 'Note interne, jamais montrée à l\'adhérent. Ne saisissez pas de donnée de santé ici.';
    private SocialAccess $access;

    public function __construct(private Database $db, private Audit $audit, Gate $gate)
    {
        $this->access = new SocialAccess($db, $gate, $audit);
    }

    public function add(int $requestId, string $body, int $actor, ?string $ip = null): int
    {
        $body = SocialRequestService::text($body, 5000, 'Note') ?? throw new SocialRuleViolation('La note est vide (5 000 caractères au maximum).');
        return $this->db->atomic(function () use ($requestId, $body, $actor, $ip): int {
            $r = $this->access->lockForWrite($requestId, $actor, 'social.manage', 'social.decide');
            $noteId = $this->db->insert('social_request_notes', ['social_request_id' => $requestId, 'author_id' => $actor, 'body' => $body]);
            $this->audit->log($actor, 'social.note_added', 'social_request', $requestId, null, ['note_id' => $noteId], $r['reference'], $ip);
            return $noteId;
        });
    }

    /** @return list<array<string,mixed>> vide sans droit d'instruction */
    public function list(int $requestId, int $userId): array
    {
        $this->access->assertCanSeeRequest($userId, $requestId);
        return $this->access->canSeeInstruction($userId) ? $this->db->fetchAll('SELECT n.id, n.body, n.created_at, u.name AS author FROM social_request_notes n
            JOIN users u ON u.id = n.author_id WHERE n.social_request_id = ? ORDER BY n.id', [$requestId]) : [];
    }

    /** Avis d'instruction (montant proposé en DECIMAL). @return list<array<string,mixed>> vide sans droit d'instruction */
    public function reviews(int $requestId, int $userId): array
    {
        $this->access->assertCanSeeRequest($userId, $requestId);
        return $this->access->canSeeInstruction($userId) ? $this->db->fetchAll('SELECT v.id, v.opinion, v.recommended_amount, v.comment, v.created_at, u.name AS reviewer
            FROM social_request_reviews v JOIN users u ON u.id = v.reviewer_id WHERE v.social_request_id = ? ORDER BY v.id', [$requestId]) : [];
    }
}
