<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use App\Core\NotFound;
use App\Core\Sequence;
use App\Core\Settings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Carte de membre : une seule carte active par adhérent (la nouvelle révoque l'ancienne, sous verrou de la ligne adhérent).
 * Le jeton de vérification n'est jamais écrit dans l'audit. members.update est exigé par la route ; le périmètre est vérifié ici.
 */
final class CardService
{
    public const REASON_MAX = 500;

    public function __construct(private Database $db, private Audit $audit, private Settings $settings, private Sequence $sequence, private MemberScope $scope)
    {
    }

    /** Texte des conditions de délivrance si les instances l'ont validé (non évaluable automatiquement : coche manuelle), sinon null. */
    public function conditions(): ?string
    {
        if (!$this->settings->isValidated('members', 'card_conditions')) {
            return null;
        }
        $v = $this->settings->get('members', 'card_conditions');
        return is_string($v) ? trim($v) : json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,mixed> la carte créée (id, card_number, verify_token, expires_at)
     * @throws NotFound hors périmètre @throws DomainException conditions de délivrance non confirmées
     */
    public function issue(int $memberId, int $actor, bool $conditionsChecked = false): array
    {
        $this->scope->assertMayAccess($actor, $memberId);
        $conditions = $this->conditions();
        if ($conditions !== null && !$conditionsChecked) {
            throw new DomainException('Conditions de délivrance à vérifier : ' . $conditions);
        }
        return $this->db->atomic(function () use ($memberId, $actor): array {
            if ($this->db->fetchValue('SELECT id FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$memberId]) === null) {
                throw new NotFound("Adhérent $memberId introuvable.");
            }
            foreach ($this->db->fetchAll('SELECT id, card_number FROM membership_cards WHERE member_id = ? AND revoked_at IS NULL', [$memberId]) as $old) {
                $this->db->query('UPDATE membership_cards SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$old['id']]);
                $this->audit->log($actor, 'member_card.revoked', 'member_card', (int) $old['id'], ['card_number' => $old['card_number']],
                    ['reason' => 'Remplacée par une nouvelle carte']);
            }
            $expires = null;
            if ($this->settings->isValidated('members', 'card_validity_months')) {
                $months = (int) $this->settings->get('members', 'card_validity_months');
                if ($months > 0) {
                    $expires = (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify("+$months months")->format('Y-m-d');
                }
            }
            $number = $this->sequence->next('member_card', 'CARTE', 5);
            $token = bin2hex(random_bytes(32));
            $id = $this->db->insert('membership_cards', ['member_id' => $memberId, 'card_number' => $number, 'verify_token' => $token,
                'expires_at' => $expires, 'printed_by' => $actor]);
            $this->db->query('UPDATE membership_cards SET issued_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            $this->audit->log($actor, 'member_card.issued', 'member_card', $id, null, ['member_id' => $memberId, 'card_number' => $number, 'expires_at' => $expires]);
            return ['id' => $id, 'card_number' => $number, 'verify_token' => $token, 'expires_at' => $expires];
        });
    }

    /** @throws NotFound carte inconnue ou hors périmètre @throws DomainException motif absent ou carte déjà révoquée */
    public function revoke(int $cardId, string $reason, int $actor): void
    {
        $card = $this->db->fetch('SELECT id, member_id, card_number FROM membership_cards WHERE id = ?', [$cardId]) ?? throw new NotFound("Carte $cardId introuvable.");
        $this->scope->assertMayAccess($actor, (int) $card['member_id']);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new DomainException('Le motif est obligatoire (' . self::REASON_MAX . ' caractères au plus).');
        }
        $this->db->atomic(function () use ($card, $reason, $actor): void {
            $row = $this->db->fetch('SELECT revoked_at FROM membership_cards WHERE id = ? FOR UPDATE', [$card['id']]);
            if ($row === null || $row['revoked_at'] !== null) {
                throw new DomainException('Cette carte est déjà révoquée.');
            }
            $this->db->query('UPDATE membership_cards SET revoked_at = UTC_TIMESTAMP() WHERE id = ?', [$card['id']]);
            $this->audit->log($actor, 'member_card.revoked', 'member_card', (int) $card['id'], ['card_number' => $card['card_number']], ['reason' => $reason]);
        });
    }
}
