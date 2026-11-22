<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use App\Modules\Members\MemberScope;

/**
 * Politique unique de visibilité des demandes sociales (CDC art. 8 : accès limité aux personnes habilitées), pour les services ET les vues.
 * social.view seul : référence, type, date, statut, priorité, responsable, décision (sorte, montant, organe, référence), état du décaissement lié.
 * Instruction (avis, notes, commentaires des événements, pièces ordinaires) : social.manage ou social.decide. Santé : social.medical.view.
 * Hors périmètre, demande inconnue ou compte désactivé : introuvable (404, on ne révèle pas l'existence).
 */
final class SocialAccess
{
    private MemberScope $scope;

    public function __construct(private Database $db, private Gate $gate, private Audit $audit)
    {
        $this->scope = new MemberScope($db, $gate);
    }

    public function canSeeRequest(int $userId, int $requestId): bool
    {
        $member = $this->db->fetchValue('SELECT member_id FROM social_requests WHERE id = ?', [$requestId]);
        return $member !== null && $this->allows($userId, 'social.view') && $this->visible($userId, (int) $member);
    }

    public function canSeeInstruction(int $userId): bool
    {
        return $this->allows($userId, 'social.manage') || $this->allows($userId, 'social.decide');
    }

    public function canSeeMedical(int $userId): bool
    {
        return $this->allows($userId, 'social.medical.view');
    }

    public function canWriteMedical(int $userId): bool
    {
        return $this->allows($userId, 'social.manage') && $this->canSeeMedical($userId);
    }

    /** @throws NotFound */
    public function assertCanSeeRequest(int $userId, int $requestId): void
    {
        if (!$this->canSeeRequest($userId, $requestId)) {
            throw new NotFound('Demande sociale introuvable.');
        }
    }

    /** Adhérent dans le périmètre d'un compte ACTIF (MemberScope ne regarde pas is_active : un compte désactivé avec zone y verrait encore sa zone). */
    public function visible(int $userId, int $memberId): bool
    {
        if ((int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$userId]) !== 1) {
            return false;
        }
        [$sql, $params] = $this->scope->where($userId);
        return $this->db->fetchValue("SELECT 1 FROM members m WHERE m.id = ? AND $sql", [$memberId, ...$params]) !== null;
    }

    /**
     * Écriture annexe (pièce, note, données de santé) : verrou de la demande AVANT la lecture de son état, social.view et périmètre (404), une des permissions,
     * demande non clôturée. À appeler en premier dans la transaction. @return array<string,mixed>
     */
    public function lockForWrite(int $id, int $actor, string ...$anyOf): array
    {
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ? FOR UPDATE', [$id]);
        // sans social.view : introuvable aussi (pas d'oracle d'existence pour un détenteur de finance.view avec tout le périmètre)
        if ($r === null || !$this->allows($actor, 'social.view') || !$this->visible($actor, (int) $r['member_id'])) {
            throw new NotFound('Demande sociale introuvable.');
        }
        if (array_filter($anyOf, fn (string $p): bool => $this->allows($actor, $p)) === []) {
            throw self::refused();
        }
        if ($r['status'] === 'cloturee') {
            throw new SocialTransitionInvalid('Cette demande est clôturée : on ne peut plus y ajouter de pièce, de note ni de donnée de santé.');
        }
        return $r;
    }

    public static function refused(): SocialRuleViolation
    {
        return new SocialRuleViolation('Action refusée : votre compte doit être actif et autorisé pour cette étape de la demande sociale.');
    }

    /** Refus de lecture (403) journalisé comme les autres refus : identifiants seulement. @param array<string,int> $extra */
    public function deny(int $userId, int $requestId, string $permission, ?string $ip, array $extra = []): never
    {
        $this->audit->log($userId, 'access.denied', 'social_request', $requestId, null, ['permission' => $permission] + $extra, null, $ip);
        throw new SocialAccessDenied('Accès refusé : vous n\'êtes pas habilité à consulter cette information.');
    }

    public function allows(int $userId, string $permission): bool
    {
        $this->gate->forget($userId); // jamais une permission mise en cache avant un retrait de rôle ou une désactivation
        return $this->gate->allows($userId, $permission);
    }
}
