<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NotFound;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Contributions\PaymentService;
use App\Modules\Finance\Ledger;
use DateTimeImmutable;
use DomainException;
use PDOException;

/**
 * Circuit de décaissement à double validation (A5.3). Toutes les règles sont ici, jamais dans les vues ni les contrôleurs.
 *
 * Chaque action : run() -> Database::atomic (rejoint une transaction ouverte), verrou FOR UPDATE de la demande AVANT toute lecture
 * de son état, contrôles (prédicats partagés avec allowedActions), écritures, puis Audit::log EN DERNIER.
 *
 * Ordre des verrous (un interblocage serait un défaut) :
 *   demande (FOR UPDATE) -> décompte des approbations (lecture verrouillante ; approbations et pièces écrites seulement sous le verrou de la demande)
 *   -> compte (FOR UPDATE, puis Ledger::record) -> ligne de paiement insérée -> séquences -> audit_chain.
 * Audit::log verrouille la ligne unique audit_chain jusqu'au commit : c'est TOUJOURS le dernier verrou pris. Dans pay(),
 * Ledger::record audite lui-même ; après lui, seule la ligne de paiement déjà insérée (donc déjà verrouillée) par cette
 * transaction est mise à jour. Épinglé par DisbursementCircuitTest::test_audit_is_the_last_lock.
 *
 * Le décompte des approbations et le solde sont lus de façon verrouillante (dernier état validé, même si l'appelant a figé sa
 * vue cohérente avant le verrou). Les tests d'existence suivis d'un INSERT (décision déjà prise, clé d'idempotence, pièces)
 * sont des lectures SIMPLES : une lecture verrouillante d'une clé absente pose un verrou d'intervalle qui interbloque deux
 * demandes différentes (1213). Ces lignes ne changent que sous le verrou de la demande ; l'UNIQUE reste le filet (1062 traduit).
 * run() rejoue une fois l'action victime d'un interblocage (transaction propre) ; sinon (ou si l'interblocage se répète), ou en cas d'attente trop longue (1205),
 * DisbursementBusy quand la transaction est la nôtre ; dans celle d'un appelant, l'exception SQL brute est relancée (jamais d'état partiel validé).
 *
 * Le paramètre $ip (facultatif, en dernier) est porté dans l'audit.
 */
final class DisbursementService
{
    /** Champs dont la modification crée une révision (avec l'ensemble des justificatifs) ; category_id et internal_note ne le sont pas. */
    public const SUBSTANTIAL = ['amount', 'beneficiary_name', 'beneficiary_member_id', 'account_id', 'purpose'];
    public const ACTIONS = ['revise', 'submit', 'approve', 'reject', 'cancel', 'pay'];
    private const EDITABLE = [...self::SUBSTANTIAL, 'category_id', 'internal_note'];
    private const ATTACHABLE = 'disbursement_request'; // absent d'AttachmentService::TARGETS : les pièces ne s'ajoutent que par revise()
    private const MAX_CENTS = 999_999_999_999_999;
    public const STALE = 'La demande a été modifiée depuis votre lecture, relisez-la avant de continuer.';
    private const LABELS = ['brouillon' => 'au brouillon', 'soumis' => 'soumise', 'approuve_1' => 'en cours d\'approbation', 'approuve_2' => 'approuvée',
        'rejete' => 'rejetée', 'annule' => 'annulée', 'paye' => 'déjà payée'];

    private const VERBS = ['soumettre' => 'de soumettre', 'approuver' => 'd\'approuver', 'rejeter' => 'de rejeter', 'modifier' => 'de modifier',
        'annuler' => 'd\'annuler', 'payer' => 'de payer'];

    private ApprovalRules $rules;
    private DisbursementGate $operational;

    public function __construct(private Database $db, private Audit $audit, private Ledger $ledger, private Sequence $sequence,
        private Settings $settings, private Gate $gate)
    {
        $this->rules = new ApprovalRules($db);
        $this->operational = new DisbursementGate($db);
    }

    /** @param array<string,mixed> $data amount (centimes int), beneficiary_name, beneficiary_member_id?, account_id, category_id, purpose, internal_note? */
    public function create(array $data, int $initiator, ?string $ip = null): int
    {
        return $this->run(function () use ($data, $initiator, $ip): int {
            $this->deny($this->permission($initiator, 'disbursement.initiate'));
            if (($data['social_decision_id'] ?? null) !== null) {
                throw new DisbursementRuleViolation('Le lien avec une décision sociale n\'est pas encore disponible.');
            }
            unset($data['social_decision_id']);
            if (array_diff(['amount', 'beneficiary_name', 'account_id', 'category_id', 'purpose'], array_keys($data)) !== []) {
                throw new DisbursementRuleViolation('Montant, bénéficiaire, compte, catégorie et motif sont obligatoires.');
            }
            $row = $this->clean($data); // verrou partagé du compte avant la séquence
            $reference = $this->sequence->next('disbursement', 'DEC', 5);
            $id = $this->db->insert('disbursement_requests', $row + ['reference' => $reference, 'initiated_by' => $initiator, 'status' => 'brouillon', 'revision' => 1]);
            $this->audit->log($initiator, 'disbursement.created', 'disbursement_request', $id, null, ['status' => 'brouillon', 'revision' => 1, 'amount' => $row['amount']], $reference, $ip);
            return $id;
        });
    }

    public function submit(int $id, int $actor, ?string $ip = null): void
    {
        $this->run(function () use ($id, $actor, $ip): void {
            $r = $this->lock($id);
            $this->deny($this->canSubmit($r, $actor));
            $this->clean(['account_id' => (int) $r['account_id'], 'category_id' => (int) $r['category_id']]); // toujours actifs ?
            $this->db->query('UPDATE disbursement_requests SET status = \'soumis\', submitted_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            $this->snapshot($id, (int) $r['revision'], $actor, 'Soumission');
            $this->auditTransition($actor, 'submitted', $r, 'soumis', $ip);
        });
    }

    /** $seenRevision : révision affichée à l'approbateur (obligatoire) ; si elle n'est plus la courante, refus (jamais utilisée pour choisir la révision). */
    public function approve(int $id, int $approver, int $seenRevision, ?string $comment, ?string $ip = null): void
    {
        $comment = $this->text($comment, 500, 'Commentaire');
        $this->run(function () use ($id, $approver, $comment, $seenRevision, $ip): void {
            $r = $this->lock($id);
            $this->deny($this->canApprove($r, $approver));
            $this->assertSeen($r, $seenRevision);
            try {
                $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $id, 'revision' => (int) $r['revision'], 'approver_id' => $approver,
                    'decision' => 'approve', 'comment' => $comment]);
            } catch (PDOException $e) {
                throw $this->integrity($e, 'Vous vous êtes déjà prononcé sur cette révision de la demande.');
            }
            $count = $this->validApprovals($r);
            $required = $this->rules->requiredFor(Money::fromDecimal($r['amount']));
            $to = $count >= $required ? 'approuve_2' : 'approuve_1';
            $this->db->update('disbursement_requests', ['status' => $to], ['id' => $id]);
            $this->auditTransition($approver, 'approved', $r, $to, $ip, ['comment' => $comment, 'approvals' => $count, 'required' => $required]);
        });
    }

    /** Rejet motivé, définitif. Un approbateur qui avait déjà approuvé peut encore rejeter (frein de sécurité) : sa décision est alors portée par la demande. */
    public function reject(int $id, int $actor, int $seenRevision, string $reason, ?string $ip = null): void
    {
        $reason = $this->text($reason, 500, 'Motif du rejet') ?? throw new DisbursementRuleViolation('Le motif du rejet est obligatoire.');
        $this->run(function () use ($id, $actor, $seenRevision, $reason, $ip): void {
            $r = $this->lock($id);
            $this->deny($this->canReject($r, $actor));
            $this->assertSeen($r, $seenRevision);
            if (!$this->decided($r, $actor)) {
                try {
                    $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $id, 'revision' => (int) $r['revision'], 'approver_id' => $actor,
                        'decision' => 'reject', 'comment' => $reason]);
                } catch (PDOException $e) {
                    throw $this->integrity($e, 'Vous vous êtes déjà prononcé sur cette révision de la demande.');
                }
            }
            $this->db->update('disbursement_requests', ['status' => 'rejete', 'rejected_by' => $actor, 'rejection_reason' => $reason], ['id' => $id]);
            $this->auditTransition($actor, 'rejected', $r, 'rejete', $ip, ['reason' => $reason]);
        });
    }

    /**
     * Modifie la demande (initiateur). Au brouillon : sans révision. Soumise ou approuvée : un changement substantiel (SUBSTANTIAL ou
     * ensemble des justificatifs) crée la révision + 1 (instantané), rend caduques les approbations antérieures (lignes conservées) et
     * renvoie la demande à « soumis » ; un changement non substantiel (catégorie, note interne) garde les approbations.
     *
     * @param array<string,mixed> $changes champs EDITABLE, plus add_files (array<int,string> file_id => libellé) et remove_attachments (list<int>)
     */
    public function revise(int $id, array $changes, string $reason, int $actor, ?string $ip = null): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) > 500) {
            throw new DisbursementRuleViolation('Motif de la modification : 500 caractères au maximum.');
        }
        $this->run(function () use ($id, $changes, $reason, $actor, $ip): void {
            $r = $this->lock($id);
            $this->deny($this->canRevise($r, $actor));
            $adds = $changes['add_files'] ?? [];
            $removes = $changes['remove_attachments'] ?? [];
            unset($changes['add_files'], $changes['remove_attachments']);
            if (!is_array($adds) || !is_array($removes)) {
                throw new DisbursementRuleViolation('Justificatifs : format invalide.');
            }
            $fields = $this->clean($changes);
            $changed = array_keys(array_filter($fields, fn ($v, string $k): bool => !self::same($v, $r[$k]), ARRAY_FILTER_USE_BOTH));
            $current = array_column($this->attachments($id), null, 'id');
            foreach ($removes as $aid) {
                if (!is_int($aid) || !isset($current[$aid])) {
                    throw new DisbursementRuleViolation('Ce justificatif n\'appartient pas à la demande.');
                }
            }
            foreach ($adds as $fileId => $label) {
                $this->assertOwnFile($fileId, $actor, 'Fichier justificatif');
                if (mb_strlen(trim((string) $label)) > 160) {
                    throw new DisbursementRuleViolation('Libellé du justificatif : 160 caractères au maximum.');
                }
            }
            if ($changed === [] && $adds === [] && $removes === []) {
                throw new DisbursementRuleViolation('Aucune modification à enregistrer.');
            }
            $substantial = array_intersect($changed, self::SUBSTANTIAL) !== [] || $adds !== [] || $removes !== [];
            $bump = $substantial && $r['status'] !== 'brouillon';
            if ($bump && $reason === '') {
                throw new DisbursementRuleViolation('Le motif est obligatoire pour une modification substantielle d\'une demande soumise.');
            }
            if ($r['status'] !== 'brouillon' && count($current) - count(array_unique($removes)) + count($adds) < 1) {
                throw new DisbursementRuleViolation('Une demande soumise doit garder au moins un justificatif.');
            }
            $set = array_intersect_key($fields, array_flip($changed));
            $revision = (int) $r['revision'];
            $invalidated = 0;
            if ($bump) {
                $revision++;
                $set += ['revision' => $revision, 'status' => 'soumis'];
            }
            if ($set !== []) {
                $this->db->update('disbursement_requests', $set, ['id' => $id]);
            }
            foreach (array_unique($removes) as $aid) {
                $this->db->query('DELETE FROM financial_attachments WHERE id = ? AND attachable_type = ? AND attachable_id = ?', [$aid, self::ATTACHABLE, $id]);
            }
            foreach ($adds as $fileId => $label) {
                $label = trim((string) $label);
                $this->db->insert('financial_attachments', ['attachable_type' => self::ATTACHABLE, 'attachable_id' => $id, 'file_id' => $fileId,
                    'label' => $label === '' ? null : $label, 'uploaded_by' => $actor]);
            }
            if ($bump) {
                $invalidated = $this->db->query('UPDATE disbursement_approvals SET invalidated_at = UTC_TIMESTAMP()
                    WHERE disbursement_request_id = ? AND revision < ? AND invalidated_at IS NULL', [$id, $revision])->rowCount();
                $this->snapshot($id, $revision, $actor, $reason);
            }
            $this->auditTransition($actor, 'revised', $r, $bump ? 'soumis' : $r['status'], $ip, ['changed' => $changed, 'files_added' => array_keys($adds),
                'attachments_removed' => array_values(array_unique($removes)), 'approvals_invalidated' => $invalidated, 'reason' => $reason === '' ? null : $reason], $revision);
        });
    }

    public function cancel(int $id, int $initiator, ?string $ip = null): void
    {
        $this->run(function () use ($id, $initiator, $ip): void {
            $r = $this->lock($id);
            $this->deny($this->canCancel($r, $initiator));
            $this->db->query('UPDATE disbursement_requests SET status = \'annule\', cancelled_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            $this->auditTransition($initiator, 'cancelled', $r, 'annule', $ip);
        });
    }

    /**
     * Enregistre l'unique paiement : dépense au grand livre (Ledger::record, source 'disbursement'), ligne disbursement_payments, état « paye ».
     * Rejeu de la même clé avec la même charge utile = même paiement ; autre charge utile = refus.
     * $seenRevision et $seenAmount (centimes) : ce que le payeur a affiché ; s'ils diffèrent de la demande verrouillée, refus.
     *
     * @param array{method: string, paid_at?: ?DateTimeImmutable, external_ref?: ?string} $payment
     */
    public function pay(int $id, int $payer, int $seenRevision, int $seenAmount, array $payment, int $proofFileId, string $idempotencyKey, ?string $ip = null): int
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $idempotencyKey)) {
            throw new DisbursementRuleViolation('Formulaire expiré : rechargez la page.');
        }
        if (array_diff(array_keys($payment), ['method', 'paid_at', 'external_ref']) !== []) {
            throw new DisbursementRuleViolation('Données de paiement invalides.');
        }
        $method = $payment['method'] ?? null;
        if (!is_string($method) || !isset(PaymentService::METHODS[$method])) {
            throw new DisbursementRuleViolation('Mode de paiement invalide.');
        }
        $paidAt = $payment['paid_at'] ?? Ledger::now();
        if (!$paidAt instanceof DateTimeImmutable || $paidAt > Ledger::now()) {
            throw new DisbursementRuleViolation('La date du paiement est invalide ou dans le futur.');
        }
        $externalRef = $this->text($payment['external_ref'] ?? null, 100, 'Référence externe');
        return $this->run(function () use ($id, $payer, $seenRevision, $seenAmount, $method, $paidAt, $externalRef, $proofFileId, $idempotencyKey, $ip): int {
            $r = $this->lock($id);
            $existing = $this->db->fetch('SELECT id, disbursement_request_id, method, external_ref, proof_file_id, paid_by FROM disbursement_payments WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) {
                if ((int) $existing['disbursement_request_id'] !== $id || $existing['method'] !== $method || $existing['external_ref'] !== $externalRef
                    || (int) $existing['proof_file_id'] !== $proofFileId || (int) $existing['paid_by'] !== $payer) {
                    throw new DisbursementRuleViolation('Cette clé d\'envoi a déjà servi pour un autre paiement : rechargez le formulaire.');
                }
                return (int) $existing['id'];
            }
            $this->deny($this->canPay($r, $payer));
            $this->assertSeen($r, $seenRevision, $seenAmount);
            $this->assertOwnFile($proofFileId, $payer, 'Pièce justificative du paiement');
            $amount = Money::fromDecimal($r['amount']);
            $accountId = (int) $r['account_id'];
            $this->db->fetch('SELECT id FROM financial_accounts WHERE id = ? FOR UPDATE', [$accountId]);
            $balance = $this->ledger->balance($accountId, null, locking: true);
            $warning = null;
            if ($balance < $amount) {
                $alert = $this->settings->isValidated('finance', 'insufficient_balance_policy') && $this->settings->get('finance', 'insufficient_balance_policy') === 'alerter';
                if (!$alert) {
                    throw new DisbursementRuleViolation('Solde insuffisant : le compte dispose de ' . Money::format($balance) . ' pour un décaissement de ' . Money::format($amount) . '. Paiement bloqué.');
                }
                $warning = 'Solde insuffisant (' . Money::toDecimal($balance) . ') : paiement autorisé par la politique « alerter » validée.';
            }
            try {
                $paymentId = $this->db->insert('disbursement_payments', ['disbursement_request_id' => $id, 'paid_at' => $paidAt->format('Y-m-d H:i:s'), 'method' => $method,
                    'external_ref' => $externalRef, 'paid_by' => $payer, 'proof_file_id' => $proofFileId, 'idempotency_key' => $idempotencyKey]);
            } catch (PDOException $e) {
                throw $this->integrity($e, 'Cette demande a déjà été payée, ou cette clé d\'envoi a déjà servi : rechargez la page.');
            }
            $this->db->update('disbursement_requests', ['status' => 'paye'], ['id' => $id]);
            $tx = $this->ledger->record($accountId, (int) $r['category_id'], 'depense', $amount, $paidAt,
                ['source_type' => 'disbursement', 'source_id' => $id, 'description' => 'Décaissement ' . $r['reference']], $payer);
            $this->db->update('disbursement_payments', ['financial_transaction_id' => $tx], ['id' => $paymentId]); // ligne insérée plus haut : déjà verrouillée
            $this->auditTransition($payer, 'paid', $r, 'paye', $ip, ['payment_id' => $paymentId, 'transaction_id' => $tx, 'amount' => $r['amount'], 'method' => $method,
                'proof_file_id' => $proofFileId, 'balance_warning' => $warning]);
            return $paymentId;
        });
    }

    /**
     * État du circuit pour l'affichage : approbations requises, valides (révision courante), approbateurs éligibles restants
     * (actifs, autres que l'initiateur, sans décision sur la révision courante) ; stalled = il en reste moins que d'approbations manquantes.
     * @return array{required:int, valid:int, other_eligible_approvers:int, stalled:bool}
     */
    public function circuitStatus(int $id): array
    {
        $r = $this->db->fetch('SELECT * FROM disbursement_requests WHERE id = ?', [$id]) ?? throw new NotFound('Demande de décaissement introuvable.');
        $required = $this->rules->requiredFor(Money::fromDecimal($r['amount']));
        $valid = $this->validApprovals($r);
        $eligible = (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id AND p.name = 'disbursement.approve'
             WHERE u.is_active = 1 AND u.id <> ?
               AND NOT EXISTS (SELECT 1 FROM disbursement_approvals a WHERE a.disbursement_request_id = ? AND a.revision = ? AND a.approver_id = u.id)",
            [(int) $r['initiated_by'], $id, (int) $r['revision']]);
        return ['required' => $required, 'valid' => $valid, 'other_eligible_approvers' => $eligible, 'stalled' => $eligible < max(0, $required - $valid)];
    }

    /** Actions que $userId peut faire maintenant (lecture seule, mêmes prédicats que les actions ; pay hors contrôles de charge utile et de solde). @return list<string> */
    public function allowedActions(int $id, int $userId): array
    {
        $r = $this->db->fetch('SELECT * FROM disbursement_requests WHERE id = ?', [$id]);
        if ($r === null) {
            return [];
        }
        $checks = ['revise' => fn () => $this->canRevise($r, $userId), 'submit' => fn () => $this->canSubmit($r, $userId), 'approve' => fn () => $this->canApprove($r, $userId),
            'reject' => fn () => $this->canReject($r, $userId), 'cancel' => fn () => $this->canCancel($r, $userId), 'pay' => fn () => $this->canPay($r, $userId)];
        return array_keys(array_filter($checks, fn (callable $c): bool => $c() === null));
    }

    // --- prédicats : null = autorisé, sinon l'exception à lever (source unique pour les actions et allowedActions) ---

    /** @param array<string,mixed> $r */
    private function canSubmit(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.initiate')
            ?? ($r['status'] !== 'brouillon' ? $this->invalid($r, 'soumettre') : null)
            ?? ((int) $r['initiated_by'] !== $actor ? new DisbursementRuleViolation('Seul l\'initiateur peut soumettre sa demande.') : null)
            ?? ($this->attachments((int) $r['id']) === [] ? new DisbursementRuleViolation('Joignez au moins un justificatif avant de soumettre la demande.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canApprove(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.approve')
            ?? (!in_array($r['status'], ['soumis', 'approuve_1'], true) ? $this->invalid($r, 'approuver') : null)
            ?? $this->notOperational()
            ?? ((int) $r['initiated_by'] === $actor ? new DisbursementRuleViolation('L\'initiateur d\'une demande ne peut jamais l\'approuver.') : null)
            ?? ($this->decided($r, $actor) ? new DisbursementRuleViolation('Vous vous êtes déjà prononcé sur cette révision de la demande.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canReject(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.approve')
            ?? (!in_array($r['status'], ['soumis', 'approuve_1'], true) ? $this->invalid($r, 'rejeter') : null)
            ?? $this->notOperational()
            ?? ((int) $r['initiated_by'] === $actor ? new DisbursementRuleViolation('L\'initiateur ne peut pas rejeter sa propre demande : il peut l\'annuler avant toute approbation ou la modifier.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canRevise(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.initiate')
            ?? (!in_array($r['status'], ['brouillon', 'soumis', 'approuve_1', 'approuve_2'], true) ? $this->invalid($r, 'modifier') : null)
            ?? ($r['status'] !== 'brouillon' ? $this->notOperational() : null)
            ?? ((int) $r['initiated_by'] !== $actor ? new DisbursementRuleViolation('Seul l\'initiateur peut modifier sa demande.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canCancel(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.initiate')
            ?? ($r['status'] !== 'soumis' ? $this->invalid($r, 'annuler') : null)
            ?? $this->notOperational()
            ?? ((int) $r['initiated_by'] !== $actor ? new DisbursementRuleViolation('Seul l\'initiateur peut annuler sa demande.') : null)
            ?? ($this->validApprovals($r) > 0 ? new DisbursementRuleViolation('Une approbation a déjà été donnée : la demande ne peut plus être annulée.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canPay(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'disbursement.pay')
            ?? ($r['status'] !== 'approuve_2' ? $this->invalid($r, 'payer') : null)
            ?? $this->notOperational()
            ?? ((int) $r['initiated_by'] === $actor && !$this->payerMayBeInitiator() ? new DisbursementRuleViolation('L\'initiateur d\'une demande ne peut pas en enregistrer le paiement.') : null)
            ?? ($this->validApprovals($r) < $this->rules->requiredFor(Money::fromDecimal($r['amount']))
                ? new DisbursementRuleViolation('Les approbations requises pour la révision courante ne sont pas réunies : paiement impossible.') : null);
    }

    private function permission(int $actor, string $permission): ?DomainException
    {
        $this->gate->forget($actor); // jamais une permission mise en cache avant un retrait de rôle ou une désactivation
        return $this->gate->allows($actor, $permission) ? null
            : new DisbursementRuleViolation('Action refusée : votre compte doit être actif et autorisé pour cette étape du décaissement.');
    }

    private function notOperational(): ?DomainException
    {
        return $this->operational->isOperational() ? null : new DisbursementNotOperational(DisbursementGate::MESSAGE);
    }

    /** @param array<string,mixed> $r */
    private function invalid(array $r, string $verb): DisbursementTransitionInvalid
    {
        return new DisbursementTransitionInvalid('Impossible ' . (self::VERBS[$verb] ?? "de $verb") . ' cette demande : elle est ' . (self::LABELS[$r['status']] ?? $r['status']) . '.');
    }

    private function payerMayBeInitiator(): bool
    {
        return $this->settings->isValidated('finance', 'payer_may_be_initiator') && $this->settings->get('finance', 'payer_may_be_initiator') === true;
    }

    // --- lectures (verrouillantes) et écritures annexes ---

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->fetch('SELECT * FROM disbursement_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Demande de décaissement introuvable.');
    }

    private function deny(?DomainException $e): void
    {
        if ($e !== null) {
            throw $e;
        }
    }

    /** Approbations valides et distinctes de la révision courante (jamais celle de l'initiateur, même forgée en base). @param array<string,mixed> $r */
    private function validApprovals(array $r): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ? AND revision = ? AND decision = 'approve'
            AND invalidated_at IS NULL AND approver_id <> ? LOCK IN SHARE MODE", [(int) $r['id'], (int) $r['revision'], (int) $r['initiated_by']]);
    }

    /** @param array<string,mixed> $r */
    private function decided(array $r, int $actor): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM disbursement_approvals WHERE disbursement_request_id = ? AND revision = ? AND approver_id = ?',
            [(int) $r['id'], (int) $r['revision'], $actor]) !== null;
    }

    /** @return list<array{id:int,file_id:int,label:?string}> */
    private function attachments(int $id): array
    {
        return array_map(fn (array $a): array => ['id' => (int) $a['id'], 'file_id' => (int) $a['file_id'], 'label' => $a['label']],
            $this->db->fetchAll('SELECT id, file_id, label FROM financial_attachments WHERE attachable_type = ? AND attachable_id = ? ORDER BY id', [self::ATTACHABLE, $id]));
    }

    /** Instantané d'une révision (champs + identifiants des justificatifs, jamais leur contenu). */
    private function snapshot(int $id, int $revision, int $actor, string $reason): void
    {
        $r = $this->db->fetch('SELECT amount, beneficiary_name, beneficiary_member_id, account_id, category_id, purpose, internal_note FROM disbursement_requests WHERE id = ? FOR UPDATE', [$id]);
        $this->db->insert('disbursement_revisions', ['disbursement_request_id' => $id, 'revision' => $revision, 'changed_by' => $actor, 'reason' => mb_substr($reason, 0, 500),
            'snapshot' => json_encode($r + ['attachments' => $this->attachments($id)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $extra */
    private function auditTransition(int $actor, string $action, array $r, string $to, ?string $ip, array $extra = [], ?int $revision = null): void
    {
        $this->audit->log($actor, 'disbursement.' . $action, 'disbursement_request', (int) $r['id'], ['status' => $r['status'], 'revision' => (int) $r['revision']],
            ['status' => $to, 'revision' => $revision ?? (int) $r['revision']] + $extra, $r['reference'], $ip);
    }

    /** @param array<string,mixed> $r */
    private function assertSeen(array $r, int $revision, ?int $amount = null): void
    {
        if ($revision !== (int) $r['revision'] || ($amount !== null && $amount !== Money::fromDecimal($r['amount']))) {
            throw new DisbursementRuleViolation(self::STALE);
        }
    }

    /** Fichier téléversé par l'acteur lui-même, et jamais une pièce d'un dossier d'adhérent ou de demande d'adhésion. */
    private function assertOwnFile(mixed $fileId, int $actor, string $label): void
    {
        $ok = is_int($fileId) && $this->db->fetchValue('SELECT 1 FROM files f WHERE f.id = ? AND f.uploaded_by = ?
            AND NOT EXISTS (SELECT 1 FROM member_documents d WHERE d.file_id = f.id)
            AND NOT EXISTS (SELECT 1 FROM membership_application_files m WHERE m.file_id = f.id)', [$fileId, $actor]) !== null;
        if (!$ok) {
            throw new DisbursementRuleViolation("$label : fichier introuvable ou non téléversé par vous pour ce décaissement.");
        }
    }

    /** Transaction de l'action ; interblocage (1213) : un rejeu si la transaction est la nôtre ; attente trop longue (1205) ou second interblocage : DisbursementBusy seulement si la transaction est la nôtre, sinon l'exception SQL brute. */
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
                // Transaction de l'appelant : SQL brut (un DomainException rattrapé dans sa closure pourrait faire valider un état partiel).
                throw $owned && in_array($errno, [1213, 1205], true) ? new DisbursementBusy(DisbursementBusy::MESSAGE, 0, $e) : $e;
            }
        }
    }

    /**
     * Valide et normalise les champs modifiables (le compte est lu avec un verrou partagé : ordre compte -> séquences).
     * @param array<string,mixed> $data @return array<string,mixed>
     */
    private function clean(array $data): array
    {
        $unknown = array_diff(array_keys($data), self::EDITABLE);
        if ($unknown !== []) {
            throw new DisbursementRuleViolation('Champ non modifiable : ' . implode(', ', $unknown) . '.');
        }
        $out = [];
        if (array_key_exists('amount', $data)) {
            if (!is_int($data['amount']) || $data['amount'] <= 0 || $data['amount'] > self::MAX_CENTS) {
                throw new DisbursementRuleViolation(Money::MESSAGE);
            }
            $out['amount'] = Money::toDecimal($data['amount']);
        }
        foreach (['beneficiary_name' => [160, 'Bénéficiaire'], 'purpose' => [2000, 'Motif']] as $k => [$max, $label]) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $this->text(is_string($data[$k]) ? $data[$k] : null, $max, $label) ?? throw new DisbursementRuleViolation("$label : obligatoire.");
            }
        }
        if (array_key_exists('internal_note', $data)) {
            $out['internal_note'] = $this->text(is_string($data['internal_note']) ? $data['internal_note'] : null, 2000, 'Note interne');
        }
        if (array_key_exists('beneficiary_member_id', $data)) {
            $m = $data['beneficiary_member_id'];
            if ($m !== null && (!is_int($m) || $this->db->fetchValue('SELECT 1 FROM members WHERE id = ? AND deleted_at IS NULL', [$m]) === null)) {
                throw new DisbursementRuleViolation('Adhérent bénéficiaire introuvable.');
            }
            $out['beneficiary_member_id'] = $m;
        }
        if (array_key_exists('account_id', $data)) {
            $a = is_int($data['account_id']) ? $this->db->fetch('SELECT is_active FROM financial_accounts WHERE id = ? LOCK IN SHARE MODE', [$data['account_id']]) : null;
            if ($a === null || (int) $a['is_active'] !== 1) {
                throw new DisbursementRuleViolation('Compte inconnu ou désactivé.');
            }
            $out['account_id'] = $data['account_id'];
        }
        if (array_key_exists('category_id', $data)) {
            $c = is_int($data['category_id']) ? $this->db->fetch('SELECT direction, is_active FROM financial_categories WHERE id = ?', [$data['category_id']]) : null;
            if ($c === null || $c['direction'] !== 'depense' || (int) $c['is_active'] !== 1) {
                throw new DisbursementRuleViolation('Choisissez une catégorie de dépense active.');
            }
            $out['category_id'] = $data['category_id'];
        }
        return $out;
    }

    private function text(?string $v, int $max, string $label): ?string
    {
        $v = $v === null ? null : trim($v);
        if ($v !== null && mb_strlen($v) > $max) {
            throw new DisbursementRuleViolation("$label : $max caractères au maximum.");
        }
        return $v === '' ? null : $v;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null ? $a === $b : (string) $a === (string) $b;
    }

    /** Contraintes de la base (UNIQUE 1062, CHECK 3819/4025) : filet de sécurité traduit en erreur métier. */
    private function integrity(PDOException $e, string $message): DomainException|PDOException
    {
        return in_array($e->errorInfo[1] ?? 0, [1062, 3819, 4025], true) ? new DisbursementRuleViolation($message, 0, $e) : $e;
    }
}
