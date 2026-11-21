<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Money;
use App\Core\Sequence;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DomainException;
use PDOException;

/**
 * Encaissements des cotisations. Chaque paiement vise un appel (aucun paiement libre : avances non définies par les instances) et crédite le compte du type.
 * Ordre des verrous : appel -> compte (Ledger) -> séquences (écriture, paiement, reçu). Un paiement n'est jamais modifié ni supprimé : on l'annule par contre-passation.
 */
final class PaymentService
{
    public const METHODS = ['especes' => 'Espèces', 'mobile_money' => 'Mobile money', 'virement' => 'Virement', 'cheque' => 'Chèque'];
    private const CATEGORIES = ['droit_adhesion' => 'Droits d\'adhésion', 'periodique' => 'Cotisations périodiques', 'exceptionnelle' => 'Contributions exceptionnelles'];

    public function __construct(private Database $db, private Audit $audit, private Ledger $ledger, private Sequence $sequence, private ReceiptService $receipts, private MemberScope $scope)
    {
    }

    /** @return int id du paiement (celui d'origine en cas de rejeu de la même clé) */
    public function record(int $memberId, ?int $callId, int $amount, string $method, DateTimeImmutable $paidAt, ?string $externalRef, int $accountId, string $idempotencyKey, int $actor): int
    {
        if ($callId === null) {
            throw new DomainException('Un paiement doit viser un appel de cotisation : les paiements sans appel ne sont pas autorisés.');
        }
        if ($amount <= 0) {
            throw new DomainException(Money::MESSAGE);
        }
        if (!isset(self::METHODS[$method])) {
            throw new DomainException('Mode de paiement invalide.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $idempotencyKey)) {
            throw new DomainException('Formulaire expiré : rechargez la page.');
        }
        $externalRef = $externalRef === null ? null : trim($externalRef);
        if ($externalRef !== null && mb_strlen($externalRef) > 100) {
            throw new DomainException('Référence externe : 100 caractères au maximum.');
        }
        if ($paidAt > Ledger::now()) {
            throw new DomainException('La date du paiement ne peut pas être dans le futur.');
        }
        return $this->db->atomic(function () use ($memberId, $callId, $amount, $method, $paidAt, $externalRef, $accountId, $idempotencyKey, $actor): int {
            // 1. verrou de l'appel AVANT toute lecture de son état (sérialise avec CallService::cancel et les autres paiements)
            $call = $this->db->fetch('SELECT c.id, c.member_id, c.status, c.amount_due, c.period_start, t.kind, t.name AS type_name, t.affects_account_id, t.authorization_ref
                FROM contribution_calls c JOIN contribution_types t ON t.id = c.contribution_type_id WHERE c.id = ? FOR UPDATE', [$callId]) ?? throw new DomainException('Appel inconnu.');
            $this->scope->assertMayAccess($actor, (int) $call['member_id']);
            if ($paidAt->format('Y-m-d') < $call['period_start']) {
                throw new DomainException('La date du paiement ne peut pas précéder le début de la période de l\'appel (' . date('d/m/Y', strtotime($call['period_start'])) . ').');
            }
            $existing = $this->db->fetch('SELECT id, member_id, contribution_call_id, amount FROM payments WHERE idempotency_key = ?', [$idempotencyKey]);
            if ($existing !== null) { // rejeu : même charge utile = même paiement, sinon refus
                if ((int) $existing['member_id'] !== $memberId || (int) $existing['contribution_call_id'] !== $callId || Money::fromDecimal($existing['amount']) !== $amount) {
                    throw new DomainException('Cette clé d\'envoi a déjà servi pour un autre paiement : rechargez le formulaire.');
                }
                return (int) $existing['id'];
            }
            if ((int) $call['member_id'] !== $memberId) {
                throw new DomainException('Cet appel ne concerne pas cet adhérent.');
            }
            if (!in_array($call['status'], ['appelee', 'partielle'], true)) {
                throw new DomainException($call['status'] === 'payee' ? 'Cet appel est déjà soldé.' : 'Cet appel est annulé : aucun paiement possible.');
            }
            if ((int) $call['affects_account_id'] !== $accountId) {
                throw new DomainException('Le compte ne correspond pas à celui du type de cotisation.');
            }
            $due = Money::fromDecimal($call['amount_due']);
            $remaining = $due - $this->paid($callId);
            if ($amount > $remaining) {
                throw new DomainException('Le montant dépasse le reste dû (' . Money::format($remaining) . ') : un paiement supérieur au dû n\'est pas accepté.');
            }
            $categoryId = $this->db->fetchValue('SELECT id FROM financial_categories WHERE direction = \'recette\' AND name = ?', [self::CATEGORIES[$call['kind']] ?? '']);
            if ($categoryId === null) {
                throw new \LogicException('Catégorie financière de ce type de cotisation absente : vérifier les graines de la migration 0010.');
            }
            $tx = $this->ledger->record($accountId, (int) $categoryId, 'recette', $amount, $paidAt, [
                'source_type' => 'contribution_call', 'source_id' => $callId, 'authorization_ref' => $call['authorization_ref'],
                'description' => mb_substr('Encaissement ' . $call['type_name'], 0, 500)], $actor);
            $reference = $this->sequence->next('payment', 'PAY', 6);
            try {
                $id = $this->db->insert('payments', [
                    'reference' => $reference, 'member_id' => $memberId, 'contribution_call_id' => $callId, 'amount' => Money::toDecimal($amount), 'method' => $method,
                    'external_ref' => $externalRef === '' ? null : $externalRef, 'paid_at' => $paidAt->format('Y-m-d H:i:s'), 'received_by' => $actor,
                    'financial_transaction_id' => $tx, 'idempotency_key' => $idempotencyKey,
                ]);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Ce paiement a déjà été enregistré : rechargez la page.', 0, $e) : $e;
            }
            $this->receipts->issue($id);
            $this->refreshStatus($callId);
            $this->audit->log($actor, 'contributions.payment_recorded', 'payment', $id, null, ['reference' => $reference, 'call_id' => $callId, 'amount' => Money::toDecimal($amount), 'method' => $method], $reference);
            return $id;
        });
    }

    /**
     * Annulation par contre-passation (écriture de sens opposé : peut rendre le solde du compte négatif, volontairement non bloqué).
     * Verrous : appel, paiement, puis compte et séquence via Ledger::reverse.
     */
    public function cancel(int $paymentId, string $reason, int $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new DomainException('Le motif de l\'annulation est obligatoire (300 caractères au maximum).');
        }
        $this->db->atomic(function () use ($paymentId, $reason, $actor): void {
            $probe = $this->db->fetch('SELECT contribution_call_id, member_id FROM payments WHERE id = ?', [$paymentId]) ?? throw new DomainException('Paiement inconnu.');
            $this->scope->assertMayAccess($actor, (int) $probe['member_id']);
            $callId = (int) $probe['contribution_call_id'];
            $this->db->fetch('SELECT id FROM contribution_calls WHERE id = ? FOR UPDATE', [$callId]);
            $p = $this->db->fetch('SELECT id, reference, financial_transaction_id, cancelled_at FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($p['cancelled_at'] !== null) {
                throw new DomainException('Ce paiement est déjà annulé.');
            }
            $this->ledger->reverse((int) $p['financial_transaction_id'], 'annulation du paiement ' . $p['reference'] . ' : ' . $reason, $actor);
            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('payments', ['cancelled_at' => $now, 'cancel_reason' => $reason], ['id' => $paymentId]);
            $this->db->update('receipts', ['cancelled_at' => $now, 'cancel_reason' => $reason], ['payment_id' => $paymentId]);
            $this->refreshStatus($callId);
            $this->audit->log($actor, 'contributions.payment_cancelled', 'payment', $paymentId, null, ['reason' => $reason], $p['reference']);
        });
    }

    /** Σ des paiements non annulés, calculée en SQL (DECIMAL). */
    private function paid(int $callId): int
    {
        return Money::fromDecimal((string) $this->db->fetchValue('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE contribution_call_id = ? AND cancelled_at IS NULL', [$callId]));
    }

    private function refreshStatus(int $callId): void
    {
        $this->db->query("UPDATE contribution_calls c SET c.status = CASE
            WHEN (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.contribution_call_id = c.id AND p.cancelled_at IS NULL) >= c.amount_due THEN 'payee'
            WHEN (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.contribution_call_id = c.id AND p.cancelled_at IS NULL) > 0 THEN 'partielle'
            ELSE 'appelee' END WHERE c.id = ?", [$callId]);
    }
}
