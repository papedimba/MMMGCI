<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NotFound;
use App\Modules\Disbursements\DisbursementService;
use DomainException;
use PDOException;

/**
 * Lien décision sociale -> circuit de décaissement L6 (T7.4, CDC art. 10 et 14). Une aide financière accordée ne prépare qu'un BROUILLON par
 * DisbursementService::create, qui suit ensuite le circuit L6 inchangé (soumission, deux approbateurs, payeur tiers). Rien ici ne soumet,
 * n'approuve, ne paie ni n'écrit au grand livre ; aucune pièce sociale n'est copiée (elle peut être médicale).
 *
 * Même structure que SocialRequestService : run() (rejeu sur 1213), verrou FOR UPDATE de la demande (périmètre : 404) puis de la décision,
 * contrôles, écritures sociales (statut, événement), appel au circuit L6, puis Audit::log du module social EN DERNIER.
 * Ordre des verrous : social_requests -> social_decisions -> (DisbursementService::create : compte en lecture partagée -> sequences) -> audit_chain.
 */
final class SocialDisbursementLink
{
    private const CHOICE = ['account_id', 'category_id', 'beneficiary_name'];
    private const LIVE = "status NOT IN ('rejete', 'annule')";

    private SocialAccess $access;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate, private DisbursementService $disbursements)
    {
        $this->access = new SocialAccess($db, $gate, $audit);
    }

    /**
     * Prépare le décaissement au brouillon (accordée -> en paiement). Montant = montant décidé, bénéficiaire = adhérent de la demande,
     * objet neutre (référence AS- et référence de la décision : jamais le type ni une donnée de santé).
     * @param array<string,mixed> $choice account_id, category_id (choisis par l'agent), beneficiary_name (nom de l'adhérent ou d'un ayant droit)
     * @return int id du décaissement
     */
    public function prepare(int $requestId, array $choice, int $actor, ?string $ip = null): int
    {
        if (array_diff(array_keys($choice), self::CHOICE) !== [] || array_diff(self::CHOICE, array_keys($choice)) !== []) {
            throw new SocialRuleViolation('Choisissez le compte, la catégorie de dépense et le bénéficiaire.');
        }
        return $this->run(function () use ($requestId, $choice, $actor, $ip): int {
            $r = $this->lock($requestId, $actor);
            $d = $this->decision($requestId);
            $this->deny($this->canPrepare($r, $d, $actor));
            if ($this->db->fetchValue("SELECT 1 FROM financial_categories WHERE direction = 'depense' AND is_active = 1 LIMIT 1") === null) {
                throw new SocialRuleViolation('Créez d\'abord une catégorie de dépense (finance.manage).');
            }
            $this->db->update('social_requests', ['status' => 'en_paiement'], ['id' => $requestId]);
            $this->event($requestId, $r['status'], 'en_paiement', 'prepareDisbursement', $actor);
            $disbursementId = $this->disbursements->create(['amount' => Money::fromDecimal((string) $d['amount']), 'beneficiary_member_id' => (int) $r['member_id'],
                'purpose' => 'Aide sociale ' . $r['reference'] . ' — décision ' . $d['decision_ref'], 'social_decision_id' => (int) $d['id']] + $choice, $actor, $ip);
            $this->auditTransition($actor, 'disbursement_prepared', $r, 'en_paiement', $ip, ['decision_id' => (int) $d['id'], 'disbursement_id' => $disbursementId, 'amount' => $d['amount']]);
            return $disbursementId;
        });
    }

    /** Décaissement lié rejeté ou annulé : retour à « accordée » (en paiement -> accordée) ; une nouvelle préparation refait tout le circuit. */
    public function paymentFailed(int $requestId, int $actor, ?string $ip = null): void
    {
        $this->run(function () use ($requestId, $actor, $ip): void {
            $r = $this->lock($requestId, $actor);
            $d = $this->decision($requestId);
            $this->deny($this->canFail($r, $d, $actor));
            $this->db->update('social_requests', ['status' => 'accordee'], ['id' => $requestId]);
            $this->event($requestId, $r['status'], 'accordee', 'paymentFailed', $actor);
            $this->auditTransition($actor, 'disbursement_failed', $r, 'accordee', $ip, ['decision_id' => $d === null ? null : (int) $d['id']]);
        });
    }

    /** Actions possibles maintenant (lecture seule, mêmes prédicats) : prepare, paymentFailed. @return list<string> */
    public function allowedActions(int $requestId, int $userId): array
    {
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ?', [$requestId]);
        if ($r === null || !$this->access->visible($userId, (int) $r['member_id'])) {
            return [];
        }
        $d = $this->db->fetch('SELECT * FROM social_decisions WHERE social_request_id = ?', [$requestId]);
        return array_keys(array_filter(['prepare' => $this->canPrepare($r, $d, $userId), 'paymentFailed' => $this->canFail($r, $d, $userId)], fn (?DomainException $e): bool => $e === null));
    }

    /** @param array<string,mixed> $r @param ?array<string,mixed> $d */
    private function canPrepare(array $r, ?array $d, int $actor): ?DomainException
    {
        return $this->permission($actor, 'social.manage') ?? $this->permission($actor, 'disbursement.initiate') ?? $this->step($r, 'prepareDisbursement')
            ?? ($d === null || $d['decision'] !== 'accordee' || $d['aid_kind'] !== 'financiere' || $d['amount'] === null
                ? new SocialRuleViolation('Seule une aide financière accordée prépare un décaissement.') : null)
            ?? ($this->live((int) $d['id']) ? new SocialRuleViolation('Un décaissement est déjà en cours pour cette décision.') : null);
    }

    /** @param array<string,mixed> $r @param ?array<string,mixed> $d */
    private function canFail(array $r, ?array $d, int $actor): ?DomainException
    {
        return $this->permission($actor, 'social.manage') ?? $this->step($r, 'paymentFailed')
            ?? ($d === null || $this->live((int) $d['id']) || $this->db->fetchValue('SELECT 1 FROM disbursement_requests WHERE social_decision_id = ? LIMIT 1', [(int) $d['id']]) === null
                ? new SocialRuleViolation('Le décaissement lié n\'est ni rejeté ni annulé : la demande reste en paiement.') : null);
    }

    private function live(int $decisionId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM disbursement_requests WHERE social_decision_id = ? AND ' . self::LIVE . ' LIMIT 1', [$decisionId]) !== null;
    }

    /** @param array<string,mixed> $r */
    private function step(array $r, string $action): ?SocialTransitionInvalid
    {
        return SocialWorkflow::permits($r['status'], $action) ? null
            : new SocialTransitionInvalid('Impossible ' . ($action === 'paymentFailed' ? 'de rouvrir' : 'de préparer le décaissement de') . ' cette demande : elle est '
                . (SocialWorkflow::LABELS[$r['status']] ?? 'dans un état inconnu') . '.');
    }

    private function permission(int $actor, string $permission): ?DomainException
    {
        return $this->access->allows($actor, $permission) ? null : SocialAccess::refused();
    }

    private function deny(?DomainException $e): void
    {
        if ($e !== null) {
            throw $e;
        }
    }

    /** Verrou de la demande avant toute lecture de son état ; hors périmètre ou compte désactivé : introuvable. @return array<string,mixed> */
    private function lock(int $id, int $actor): array
    {
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null || !$this->access->allows($actor, 'social.view') || !$this->access->visible($actor, (int) $r['member_id'])) { // sans social.view : introuvable
            throw new NotFound('Demande sociale introuvable.');
        }
        return $r;
    }

    /** Décision verrouillée, toujours après la demande. @return ?array<string,mixed> */
    private function decision(int $requestId): ?array
    {
        return $this->db->fetch('SELECT * FROM social_decisions WHERE social_request_id = ? FOR UPDATE', [$requestId]);
    }

    private function event(int $id, string $from, string $to, string $action, int $actor): void
    {
        $this->db->insert('social_request_events', ['social_request_id' => $id, 'from_status' => $from, 'to_status' => $to, 'action' => $action, 'user_id' => $actor]);
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $extra */
    private function auditTransition(int $actor, string $action, array $r, string $to, ?string $ip, array $extra): void
    {
        $this->audit->log($actor, 'social.' . $action, 'social_request', (int) $r['id'], ['status' => $r['status']], ['status' => $to] + $extra, $r['reference'], $ip);
    }

    /** Même reprise que SocialRequestService::run : un rejeu sur interblocage si la transaction est la nôtre, sinon « réessayez » (ou SQL brut dans celle d'un appelant). */
    private function run(callable $fn): mixed
    {
        $owned = !$this->db->pdo()->inTransaction();
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->atomic($fn);
            } catch (PDOException $e) {
                $errno = $e->errorInfo[1] ?? 0;
                if ($errno === 1213 && $owned && $attempt === 1) {
                    continue;
                }
                throw $owned && in_array($errno, [1213, 1205], true) ? new SocialRuleViolation(SocialRuleViolation::BUSY, 0, $e) : $e;
            }
        }
    }
}
