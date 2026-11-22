<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Database;
use App\Core\Money;
use DomainException;

/**
 * Ce que l'adhérent voit de ses demandes (contrat L15, A8) : liste blanche de champs, épinglée par SocialMemberViewTest.
 * Jamais : notes, avis, noms des instructeurs ou décideurs, motif interne, organe, circuit de décaissement, données d'un autre membre.
 */
final class SocialMemberView
{
    public const STATUS = ['recue' => 'Reçue', 'en_instruction' => 'En cours d\'examen', 'en_attente_pieces' => 'Pièces demandées', 'soumise_decision' => 'En cours d\'examen',
        'accordee' => 'Décision rendue', 'refusee' => 'Décision rendue', 'en_paiement' => 'Décision rendue', 'cloturee' => 'Clôturée'];
    public const DECISION = ['accordee' => 'Accordée', 'refusee' => 'Refusée'];

    public function __construct(private Database $db)
    {
    }

    public static function statusOf(string $internal): string
    {
        return self::STATUS[$internal] ?? throw new DomainException('Statut de demande inconnu.');
    }

    /** @return list<array{reference: string, type: string, received_at: string, status: string, documents_requested?: ?string, decision?: string, message?: string, amount_granted?: int}> */
    public function requestsOf(int $memberId): array
    {
        $rows = $this->db->fetchAll('SELECT r.reference, t.name AS type, r.received_at, r.status, r.documents_requested_text, d.decision, d.member_message, d.aid_kind, d.amount
            FROM social_requests r JOIN social_request_types t ON t.id = r.type_id LEFT JOIN social_decisions d ON d.social_request_id = r.id
            WHERE r.member_id = ? ORDER BY r.received_at DESC, r.id DESC', [$memberId]);
        $out = [];
        foreach ($rows as $r) {
            $v = ['reference' => $r['reference'], 'type' => $r['type'], 'received_at' => $r['received_at'], 'status' => self::statusOf($r['status'])];
            if ($r['status'] === 'en_attente_pieces') {
                $v['documents_requested'] = $r['documents_requested_text'];
            }
            if ($r['decision'] !== null) {
                $v['decision'] = self::DECISION[$r['decision']];
                $v['message'] = $r['member_message'];
                if ($r['decision'] === 'accordee' && $r['aid_kind'] === 'financiere') {
                    $v['amount_granted'] = Money::fromDecimal((string) $r['amount']);
                }
            }
            $out[] = $v;
        }
        return $out;
    }
}
