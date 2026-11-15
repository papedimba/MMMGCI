<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use App\Core\NotFound;

/**
 * Changement de statut d'un adhérent : on enregistre, sans règle de transition ni effet de bord (carte, cotisations, accès :
 * à définir par les instances). members.status est exigé par la route ; le périmètre est vérifié ici.
 * L'historique est en ajout seul : aucun UPDATE ni DELETE sur member_status_history.
 */
final class StatusService
{
    public const REASON_MAX = 2000;
    public const REF_MAX = 100;

    public function __construct(private Database $db, private Audit $audit, private MemberScope $scope)
    {
    }

    /** @throws NotFound hors périmètre, inconnu ou supprimé @throws StatusInvalid */
    public function change(int $memberId, string $toStatus, string $reason, string $decisionRef, int $actor): void
    {
        $this->scope->assertMayAccess($actor, $memberId);
        $reason = trim($reason);
        $decisionRef = trim($decisionRef);
        $this->db->atomic(function () use ($memberId, $toStatus, $reason, $decisionRef, $actor): void {
            // Verrou avant lecture : deux changements simultanés ne peuvent pas enregistrer le même statut d'origine.
            $from = $this->db->fetchValue('SELECT status FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$memberId]);
            if ($from === null) {
                throw new NotFound("Adhérent $memberId introuvable.");
            }
            $errors = [];
            $active = $this->db->fetchValue('SELECT is_active FROM member_statuses WHERE code = ?', [$toStatus]);
            if ($active === null || (int) $active !== 1) {
                $errors['to'] = 'Choisissez un statut valide.';
            } elseif ($toStatus === $from) {
                $errors['to'] = 'L\'adhérent a déjà ce statut.';
            }
            if ($reason === '') {
                $errors['reason'] = 'Le motif est obligatoire.';
            } elseif (mb_strlen($reason) > self::REASON_MAX) {
                $errors['reason'] = 'Le motif ne doit pas dépasser ' . self::REASON_MAX . ' caractères.';
            }
            if ($decisionRef === '') {
                $errors['decision_ref'] = 'La référence de la décision est obligatoire.';
            } elseif (mb_strlen($decisionRef) > self::REF_MAX) {
                $errors['decision_ref'] = 'La référence ne doit pas dépasser ' . self::REF_MAX . ' caractères.';
            }
            if ($errors !== []) {
                throw new StatusInvalid($errors);
            }
            $this->db->query('UPDATE members SET status = ?, status_changed_at = UTC_TIMESTAMP() WHERE id = ?', [$toStatus, $memberId]);
            $this->db->insert('member_status_history', ['member_id' => $memberId, 'from_status' => $from, 'to_status' => $toStatus,
                'reason' => $reason, 'decision_ref' => $decisionRef, 'changed_by' => $actor]);
            $this->audit->log($actor, 'member.status_changed', 'member', $memberId, ['status' => $from],
                ['status' => $toStatus, 'reason' => $reason, 'decision_ref' => $decisionRef]);
        });
    }
}
