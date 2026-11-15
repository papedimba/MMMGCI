<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Money;
use App\Modules\Disbursements\DisbursementNotOperational;
use App\Modules\Disbursements\DisbursementRuleViolation;
use App\Modules\Disbursements\DisbursementTransitionInvalid;
use App\Modules\Finance\AttachmentService;
use App\Modules\Finance\Ledger;
use DateTimeImmutable;
use DomainException;
use PDOException;
use Tests\DatabaseTestCase;
use Tests\Support\DisbursementFixtures;

final class DisbursementCircuitTest extends DatabaseTestCase
{
    use DisbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCircuit();
    }

    private function approvals(int $id, bool $validOnly = false): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?' . ($validOnly ? ' AND invalidated_at IS NULL' : ''), [$id]);
    }

    private function expenses(int $id): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]);
    }

    private function validate(string $key, mixed $value): void
    {
        $this->settings()->validate('finance', $key, $value, $this->initiator, 'CA-2026-01');
    }

    public function test_initiator_can_never_approve(): void
    {
        $initiator = $this->user('cumul', ['dec_init_appr']); // une seule fonction : initier ET approuver
        $id = $this->submitted(initiator: $initiator);
        $this->refuses(fn () => $this->approveNow($id, $initiator, null), DisbursementRuleViolation::class, 'initiateur');
        $this->refuses(fn () => $this->rejectNow($id, $initiator, 'non'), DisbursementRuleViolation::class, 'initiateur');
        $this->assertSame(0, $this->approvals($id));
        // Une approbation de l'initiateur forgée en base ne compte jamais.
        $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $id, 'revision' => 1, 'approver_id' => $initiator, 'decision' => 'approve']);
        $this->approveNow($id, $this->approverA, null);
        $this->assertSame('approuve_1', $this->state($id));
        $this->db->update('disbursement_requests', ['status' => 'approuve_2'], ['id' => $id]); // état forgé
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'approbations requises');
        $this->assertNotContains('approve', $this->svc()->allowedActions($id, $initiator));
    }

    public function test_approval_by_initiator_via_second_role_is_refused(): void
    {
        $initiator = $this->user('deuxroles', ['dec_init', 'dec_appr']); // deux rôles distincts
        $id = $this->submitted(initiator: $initiator);
        $this->refuses(fn () => $this->approveNow($id, $initiator, 'je valide'), DisbursementRuleViolation::class, 'initiateur');
        $this->assertSame('soumis', $this->state($id));
        $this->assertSame(0, $this->approvals($id));
    }

    public function test_same_approver_twice_is_refused(): void
    {
        $id = $this->submitted();
        $this->approveNow($id, $this->approverA, null);
        $this->refuses(fn () => $this->approveNow($id, $this->approverA, 'encore'), DisbursementRuleViolation::class, 'déjà prononcé');
        $this->assertSame('approuve_1', $this->state($id));
        $this->assertSame(1, $this->approvals($id));
        try { // filet de la base : UNIQUE(disbursement_request_id, revision, approver_id)
            $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $id, 'revision' => 1, 'approver_id' => $this->approverA, 'decision' => 'approve']);
            $this->fail('UNIQUE attendu');
        } catch (PDOException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_one_approval_never_allows_payment(): void
    {
        $id = $this->submitted();
        $this->approveNow($id, $this->approverA, null);
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class);
        $this->db->update('disbursement_requests', ['status' => 'approuve_2'], ['id' => $id]); // état forgé avec une seule approbation
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'approbations requises');
        $this->assertNotContains('pay', $this->svc()->allowedActions($id, $this->payer));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->assertSame(0, $this->expenses($id));
    }

    public function test_rejected_has_no_path_to_paid(): void
    {
        $id = $this->submitted();
        $this->approveNow($id, $this->approverA, null);
        $this->refuses(fn () => $this->rejectNow($id, $this->approverB, '  '), DisbursementRuleViolation::class, 'motif');
        $this->rejectNow($id, $this->approverB, 'Pièce illisible');
        $this->assertSame('rejete', $this->state($id));
        foreach ([fn () => $this->approveNow($id, $this->approverA, null), fn () => $this->approveNow($id, $this->user('appC', ['dec_appr']), null),
            fn () => $this->payNow($id), fn () => $this->svc()->submit($id, $this->initiator), fn () => $this->svc()->cancel($id, $this->initiator),
            fn () => $this->svc()->revise($id, ['amount' => 100], 'corrigé', $this->initiator), fn () => $this->rejectNow($id, $this->approverA, 'encore')] as $op) {
            $this->refuses($op, DisbursementTransitionInvalid::class, 'rejetée');
        }
        foreach ([$this->initiator, $this->approverA, $this->approverB, $this->payer] as $u) {
            $this->assertSame([], $this->svc()->allowedActions($id, $u));
        }
        $this->assertSame('rejete', $this->state($id));
        $this->assertSame([$this->approverB, 'Pièce illisible'], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v,
            $this->db->fetch('SELECT rejected_by, rejection_reason FROM disbursement_requests WHERE id = ?', [$id]))));
    }

    public function test_cannot_pay_without_proof_file(): void
    {
        $id = $this->approved();
        $this->refuses(fn () => $this->payNow($id, proof: 999_999), DisbursementRuleViolation::class, 'Pièce justificative');
        $this->assertSame('approuve_2', $this->state($id));
        $this->assertSame(0, $this->expenses($id));
    }

    public function test_cannot_submit_without_attachment(): void
    {
        $id = $this->draft();
        $this->refuses(fn () => $this->svc()->submit($id, $this->initiator), DisbursementRuleViolation::class, 'justificatif');
        $this->assertSame('brouillon', $this->state($id));
        // Le service générique de pièces ne peut pas contourner le circuit (cible absente de sa liste blanche).
        $this->refuses(fn () => (new AttachmentService($this->db, $this->audit()))->attach('disbursement_request', $id, $this->file(), 'x', $this->initiator), DomainException::class, 'non géré');
        $this->svc()->revise($id, ['add_files' => [$this->file() => 'Devis']], '', $this->initiator);
        $this->refuses(fn () => $this->svc()->submit($id, $this->approverA), DisbursementRuleViolation::class); // ni approbateur…
        $this->svc()->submit($id, $this->initiator);
        $this->assertSame('soumis', $this->state($id));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_revisions WHERE disbursement_request_id = ? AND revision = 1', [$id]));
    }

    public function test_substantial_edit_invalidates_previous_approvals_and_keeps_history(): void
    {
        $id = $this->approved();
        $this->assertSame('approuve_2', $this->state($id));
        $this->refuses(fn () => $this->svc()->revise($id, ['amount' => 6_000_000], '', $this->initiator), DisbursementRuleViolation::class, 'motif');
        $this->svc()->revise($id, ['amount' => 6_000_000], 'Frais d\'obsèques réévalués', $this->initiator);
        $r = $this->db->fetch('SELECT status, revision, amount FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertSame(['soumis', 2, '60000.00'], [$r['status'], (int) $r['revision'], $r['amount']]);
        $this->assertSame(2, $this->approvals($id), 'les anciennes approbations sont conservées');
        $this->assertSame(0, $this->approvals($id, validOnly: true), '… mais caduques');
        $revs = $this->db->fetchAll('SELECT revision, snapshot, changed_by, reason FROM disbursement_revisions WHERE disbursement_request_id = ? ORDER BY revision', [$id]);
        $this->assertSame([1, 2], array_map(fn ($x) => (int) $x['revision'], $revs));
        $this->assertSame('50000.00', json_decode($revs[0]['snapshot'], true)['amount']);
        $this->assertSame('60000.00', json_decode($revs[1]['snapshot'], true)['amount']);
        $this->assertSame([$this->initiator, 'Frais d\'obsèques réévalués'], [(int) $revs[1]['changed_by'], $revs[1]['reason']]);
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class);
        // Les mêmes approbateurs peuvent se prononcer sur la nouvelle révision.
        $this->approveNow($id, $this->approverA, null);
        $this->assertSame('approuve_1', $this->state($id));
        // Chaque champ substantiel, et l'ensemble des justificatifs, crée une révision.
        $member = $this->db->insert('members', ['member_number' => 'M' . bin2hex(random_bytes(3)), 'last_name' => 'Kouassi', 'first_names' => 'Awa', 'status' => 'actif']);
        $other = $this->db->insert('financial_accounts', ['name' => 'Banque ' . bin2hex(random_bytes(3))]);
        $att = (int) $this->db->fetchValue("SELECT id FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = ?", [$id]);
        $expected = 2;
        foreach ([['beneficiary_name' => 'Awa Kouassi'], ['beneficiary_member_id' => $member], ['account_id' => $other], ['purpose' => 'Aide décès (veuve)'],
            ['add_files' => [$this->file() => 'Facture']], ['remove_attachments' => [$att]]] as $change) {
            $this->svc()->revise($id, $change, 'Correction', $this->initiator);
            $this->assertSame(++$expected, (int) $this->db->fetchValue('SELECT revision FROM disbursement_requests WHERE id = ?', [$id]), json_encode(array_keys($change)));
            $this->assertSame('soumis', $this->state($id));
        }
        $snap = json_decode((string) $this->db->fetchValue('SELECT snapshot FROM disbursement_revisions WHERE disbursement_request_id = ? AND revision = ?', [$id, $expected]), true);
        $this->assertSame(['Facture'], array_column($snap['attachments'], 'label'), 'l\'instantané garde l\'ensemble des justificatifs de la révision');
        $this->assertContains($att, array_column(json_decode((string) $this->db->fetchValue('SELECT snapshot FROM disbursement_revisions WHERE disbursement_request_id = ? AND revision = 1', [$id]), true)['attachments'], 'id'));
        // Retirer le dernier justificatif d'une demande soumise est refusé.
        $last = (int) $this->db->fetchValue("SELECT id FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = ?", [$id]);
        $this->refuses(fn () => $this->svc()->revise($id, ['remove_attachments' => [$last]], 'x', $this->initiator), DisbursementRuleViolation::class, 'au moins un justificatif');
    }

    public function test_non_substantial_edit_keeps_approvals(): void
    {
        $id = $this->submitted();
        $this->approveNow($id, $this->approverA, null);
        $cat2 = $this->db->insert('financial_categories', ['name' => 'Secours ' . bin2hex(random_bytes(3)), 'direction' => 'depense']);
        $this->svc()->revise($id, ['internal_note' => 'Dossier transmis par le délégué', 'category_id' => $cat2], '', $this->initiator);
        $r = $this->db->fetch('SELECT status, revision, internal_note, category_id FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertSame(['approuve_1', 1, 'Dossier transmis par le délégué', $cat2], [$r['status'], (int) $r['revision'], $r['internal_note'], (int) $r['category_id']]);
        $this->assertSame(1, $this->approvals($id, validOnly: true));
        // Une catégorie de recette est refusée (aucun changement de sens possible).
        $recette = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE direction = 'recette' LIMIT 1");
        $this->refuses(fn () => $this->svc()->revise($id, ['category_id' => $recette], '', $this->initiator), DisbursementRuleViolation::class, 'dépense');
        $this->refuses(fn () => $this->svc()->revise($id, ['status' => 'approuve_2'], '', $this->initiator), DisbursementRuleViolation::class, 'non modifiable');
        $this->refuses(fn () => $this->svc()->revise($id, ['revision' => 1], '', $this->initiator), DisbursementRuleViolation::class, 'non modifiable');
        $this->refuses(fn () => $this->svc()->revise($id, ['amount' => 5_000_000], '', $this->initiator), DisbursementRuleViolation::class, 'Aucune modification');
        $this->approveNow($id, $this->approverB, null);
        $this->assertSame('approuve_2', $this->state($id));
    }

    public function test_approval_of_stale_revision_is_refused(): void
    {
        $id = $this->submitted();
        $this->approveNow($id, $this->approverA, null, seenRevision: 1);
        $this->svc()->revise($id, ['amount' => 7_000_000], 'Montant corrigé', $this->initiator);
        // B approuve sur la base de la révision 1 qu'il avait sous les yeux : refus.
        $this->refuses(fn () => $this->approveNow($id, $this->approverB, null, seenRevision: 1), DisbursementRuleViolation::class, 'modifiée');
        // Une approbation de la révision 1 forgée en base ne compte pas pour la révision 2.
        $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $id, 'revision' => 1, 'approver_id' => $this->approverB, 'decision' => 'approve']);
        $this->approveNow($id, $this->approverA, null, seenRevision: 2);
        $this->assertSame('approuve_1', $this->state($id));
        $this->approveNow($id, $this->approverB, null, seenRevision: 2);
        $this->assertSame('approuve_2', $this->state($id));
    }

    public function test_cancel_only_before_any_approval(): void
    {
        $draft = $this->draft();
        $this->refuses(fn () => $this->svc()->cancel($draft, $this->initiator), DisbursementTransitionInvalid::class);
        $id = $this->submitted();
        $this->refuses(fn () => $this->svc()->cancel($id, $this->approverA), DisbursementRuleViolation::class);
        $this->approveNow($id, $this->approverA, null);
        $this->refuses(fn () => $this->svc()->cancel($id, $this->initiator), DisbursementTransitionInvalid::class);
        $other = $this->submitted();
        $other2 = $this->user('init2', ['dec_init']);
        $this->refuses(fn () => $this->svc()->cancel($other, $other2), DisbursementRuleViolation::class, 'initiateur');
        $this->assertContains('cancel', $this->svc()->allowedActions($other, $this->initiator));
        $this->svc()->cancel($other, $this->initiator);
        $this->assertSame('annule', $this->state($other));
        $this->assertNotNull($this->db->fetchValue('SELECT cancelled_at FROM disbursement_requests WHERE id = ?', [$other]));
        $this->refuses(fn () => $this->approveNow($other, $this->approverA, null), DisbursementTransitionInvalid::class);
    }

    public function test_payer_cannot_be_initiator_by_default(): void
    {
        $initiator = $this->user('initpay', ['dec_init_pay']);
        $id = $this->approved(initiator: $initiator);
        $this->refuses(fn () => $this->payNow($id, payer: $initiator), DisbursementRuleViolation::class, 'initiateur');
        $this->assertNotContains('pay', $this->svc()->allowedActions($id, $initiator));
        $this->validate('payer_may_be_initiator', false);
        $this->refuses(fn () => $this->payNow($id, payer: $initiator), DisbursementRuleViolation::class, 'initiateur');
        $this->payNow($id);
        $this->assertSame('paye', $this->state($id));
    }

    public function test_payer_may_be_initiator_only_when_setting_validated_true(): void
    {
        $initiator = $this->user('initpay', ['dec_init_pay']);
        $id = $this->approved(initiator: $initiator);
        $this->db->query("UPDATE settings SET `value` = 'true' WHERE `group` = 'finance' AND `key` = 'payer_may_be_initiator'"); // valeur sans validation
        $this->refuses(fn () => $this->payNow($id, payer: $initiator), DisbursementRuleViolation::class, 'initiateur');
        foreach (['true', 1, 'oui'] as $notStrictTrue) {
            $this->validate('payer_may_be_initiator', $notStrictTrue);
            $this->refuses(fn () => $this->payNow($id, payer: $initiator), DisbursementRuleViolation::class, 'initiateur');
        }
        $this->validate('payer_may_be_initiator', true);
        $this->assertContains('pay', $this->svc()->allowedActions($id, $initiator));
        $this->payNow($id, payer: $initiator);
        $this->assertSame('paye', $this->state($id));
    }

    public function test_insufficient_balance_blocks_payment_by_default(): void
    {
        $this->db->update('financial_accounts', ['opening_balance' => '100.00'], ['id' => $this->account]);
        $id = $this->approved(50_000); // 500 > 100
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'Solde insuffisant');
        $this->db->query("UPDATE settings SET `value` = '\"alerter\"' WHERE `group` = 'finance' AND `key` = 'insufficient_balance_policy'"); // non validé
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'Solde insuffisant');
        foreach (['bloquer', 'n\'importe quoi', 'ALERTER'] as $policy) {
            $this->validate('insufficient_balance_policy', $policy);
            $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'Solde insuffisant');
        }
        $this->assertSame('approuve_2', $this->state($id));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->assertSame(10_000, $this->ledger()->balance($this->account));
        // Solde exactement suffisant : payé, solde à zéro.
        $this->db->update('financial_accounts', ['opening_balance' => '500.00'], ['id' => $this->account]);
        $this->payNow($id);
        $this->assertSame(0, $this->ledger()->balance($this->account));
    }

    public function test_alert_policy_pays_and_audits_the_warning(): void
    {
        $this->db->update('financial_accounts', ['opening_balance' => '100.00'], ['id' => $this->account]);
        $this->validate('insufficient_balance_policy', 'alerter');
        $id = $this->approved(50_000);
        $p = $this->payNow($id);
        $this->assertSame('paye', $this->state($id));
        $this->assertSame(-40_000, $this->ledger()->balance($this->account));
        $new = json_decode((string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'disbursement.paid' AND auditable_id = ?", [$id]), true);
        $this->assertSame($p, $new['payment_id']);
        $this->assertStringContainsString('Solde insuffisant (100.00)', (string) $new['balance_warning']);
    }

    public function test_balance_never_goes_negative_through_payment_by_default(): void
    {
        $this->db->update('financial_accounts', ['opening_balance' => '1000.00'], ['id' => $this->account]);
        $first = $this->approved(60_000);
        $second = $this->approved(60_000);
        // La transaction de l'appelant a figé sa vue (lecture non verrouillante) AVANT que l'autre connexion ne paie la première demande.
        $other = \App\Core\Database::connect(self::config(), test: true);
        $proof = $this->file($this->payer);
        $this->db->pdo()->beginTransaction();
        try {
            $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$this->account]);
            $this->svc($other)->pay($first, $this->payer, 1, 60_000, ['method' => 'especes'], $proof, bin2hex(random_bytes(16)));
            // Le solde est relu sous le verrou du compte (lecture verrouillante) : 400 < 600, paiement bloqué malgré la vue périmée.
            $this->refuses(fn () => $this->payNow($second), DisbursementRuleViolation::class, 'Solde insuffisant');
        } finally {
            $this->db->pdo()->rollBack();
        }
        $this->refuses(fn () => $this->payNow($second), DisbursementRuleViolation::class, 'Solde insuffisant');
        $this->assertSame(40_000, $this->ledger()->balance($this->account));
        $this->assertSame('approuve_2', $this->state($second));
    }

    public function test_second_payment_is_impossible_at_service_and_database_level(): void
    {
        $id = $this->approved();
        $this->payNow($id);
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class, 'payée');
        // État forgé : le service retombe sur UNIQUE(disbursement_request_id), traduit en erreur métier, sans seconde dépense.
        $this->db->update('disbursement_requests', ['status' => 'approuve_2'], ['id' => $id]);
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'déjà été payée');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        $this->assertSame(1, $this->expenses($id));
        try {
            $this->db->insert('disbursement_payments', ['disbursement_request_id' => $id, 'paid_at' => '2026-01-01 00:00:00', 'method' => 'especes', 'paid_by' => $this->payer, 'proof_file_id' => $this->file()]);
            $this->fail('UNIQUE attendu');
        } catch (PDOException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_payment_is_idempotent_on_same_key(): void
    {
        $id = $this->approved();
        $key = bin2hex(random_bytes(16));
        $proof = $this->file($this->payer);
        $p = $this->payNow($id, key: $key, proof: $proof);
        $this->assertSame($p, $this->payNow($id, key: $key, proof: $proof));
        $this->refuses(fn () => $this->payNow($id, key: $key, method: 'especes', proof: $proof), DisbursementRuleViolation::class, 'clé d\'envoi');
        $this->refuses(fn () => $this->payNow($id, key: $key, proof: $this->file($this->payer)), DisbursementRuleViolation::class, 'clé d\'envoi');
        $other = $this->approved();
        $this->refuses(fn () => $this->payNow($other, key: $key, proof: $proof), DisbursementRuleViolation::class, 'clé d\'envoi');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->assertSame(1, $this->expenses($id));
        $row = $this->db->fetch('SELECT financial_transaction_id, idempotency_key FROM disbursement_payments WHERE id = ?', [$p]);
        $this->assertNotNull($row['financial_transaction_id']);
        $tx = $this->db->fetch('SELECT direction, amount, source_type, source_id, category_id FROM financial_transactions WHERE id = ?', [$row['financial_transaction_id']]);
        $this->assertSame(['depense', '50000.00', 'disbursement', $id, $this->category], [$tx['direction'], $tx['amount'], $tx['source_type'], (int) $tx['source_id'], (int) $tx['category_id']]);
    }

    public function test_forged_transition_draft_to_paid_is_refused(): void
    {
        $id = $this->draft();
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class, 'brouillon');
        $this->refuses(fn () => $this->approveNow($id, $this->approverA, null), DisbursementTransitionInvalid::class);
        $this->refuses(fn () => $this->rejectNow($id, $this->approverA, 'x'), DisbursementTransitionInvalid::class);
        $sub = $this->submitted();
        $this->refuses(fn () => $this->payNow($sub), DisbursementTransitionInvalid::class);
        $this->refuses(fn () => $this->svc()->submit($sub, $this->initiator), DisbursementTransitionInvalid::class);
        $this->assertSame('brouillon', $this->state($id));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->assertSame([], array_values(array_intersect(['approve', 'reject', 'pay', 'cancel'], $this->svc()->allowedActions($id, $this->initiator))));
        $this->assertSame(['revise'], $this->svc()->allowedActions($id, $this->initiator), 'soumission impossible sans justificatif');
    }

    public function test_every_transition_is_audited(): void
    {
        $id = $this->approved();
        $ref = (string) $this->db->fetchValue('SELECT reference FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertMatchesRegularExpression('/^DEC-\d{4}-\d{5}$/', $ref);
        $this->payNow($id);
        $rejected = $this->submitted();
        $this->svc()->revise($rejected, ['amount' => 100_000], 'Correction', $this->initiator);
        $this->rejectNow($rejected, $this->approverA, 'Hors objet');
        $cancelled = $this->submitted();
        $this->svc()->cancel($cancelled, $this->initiator);
        $expect = [
            [$id, 'disbursement.created', null, 'brouillon', $this->initiator], [$id, 'disbursement.revised', 'brouillon', 'brouillon', $this->initiator],
            [$id, 'disbursement.submitted', 'brouillon', 'soumis', $this->initiator], [$id, 'disbursement.approved', 'soumis', 'approuve_1', $this->approverA],
            [$id, 'disbursement.approved', 'approuve_1', 'approuve_2', $this->approverB], [$id, 'disbursement.paid', 'approuve_2', 'paye', $this->payer],
            [$rejected, 'disbursement.revised', 'soumis', 'soumis', $this->initiator], [$rejected, 'disbursement.rejected', 'soumis', 'rejete', $this->approverA],
            [$cancelled, 'disbursement.cancelled', 'soumis', 'annule', $this->initiator],
        ];
        foreach ($expect as [$rid, $action, $from, $to, $actor]) {
            $rows = $this->db->fetchAll('SELECT user_id, old_values, new_values, reference FROM audit_logs WHERE action = ? AND auditable_type = ? AND auditable_id = ? ORDER BY id', [$action, 'disbursement_request', $rid]);
            $match = array_filter($rows, fn ($r) => (json_decode((string) $r['old_values'], true)['status'] ?? null) === $from && json_decode($r['new_values'], true)['status'] === $to);
            $this->assertCount(1, $match, "$action $from -> $to");
            $row = array_values($match)[0];
            $this->assertSame($actor, (int) $row['user_id']);
            $this->assertMatchesRegularExpression('/^DEC-/', $row['reference']);
            $this->assertArrayHasKey('revision', json_decode($row['new_values'], true));
        }
        $revised = json_decode((string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'disbursement.revised' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$rejected]), true);
        $this->assertSame([2, 'Correction', ['amount']], [$revised['revision'], $revised['reason'], $revised['changed']]);
        $this->assertSame('Hors objet', json_decode((string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'disbursement.rejected' AND auditable_id = ?", [$rejected]), true)['reason']);
        // Jamais le contenu ni le nom des justificatifs dans l'audit.
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'disbursement.%' AND (new_values LIKE '%facture-secrete%' OR new_values LIKE '%p/%')"));
        $this->assertNull($this->audit()->verify());
    }

    public function test_gate_blocks_workflow_with_fewer_than_two_possible_approvers(): void
    {
        $approved = $this->approved(); // préparé tant que le circuit fonctionne
        $submitted = $this->submitted();
        $this->db->update('users', ['is_active' => 0], ['id' => $this->approverB]);
        // Création, modification du brouillon et soumission restent possibles.
        $id = $this->draft();
        $this->svc()->revise($id, ['add_files' => [$this->file() => 'Devis'], 'amount' => 10_000], '', $this->initiator);
        $this->svc()->submit($id, $this->initiator);
        foreach ([fn () => $this->approveNow($id, $this->approverA, null), fn () => $this->rejectNow($id, $this->approverA, 'non'),
            fn () => $this->svc()->cancel($id, $this->initiator), fn () => $this->svc()->revise($submitted, ['amount' => 1], 'x', $this->initiator),
            fn () => $this->payNow($approved)] as $op) {
            $this->refuses($op, DisbursementNotOperational::class, 'deux personnes');
        }
        $this->assertSame([], $this->svc()->allowedActions($id, $this->approverA));
        $this->assertSame('approuve_2', $this->state($approved));
        $this->db->update('users', ['is_active' => 1], ['id' => $this->approverB]);
        $this->approveNow($id, $this->approverA, null);
        $this->assertSame('approuve_1', $this->state($id));
    }

    public function test_inactive_or_unauthorised_actor_is_refused_by_the_service_itself(): void
    {
        $nobody = $this->user('rien', []);
        $this->refuses(fn () => $this->draft(initiator: $nobody), DisbursementRuleViolation::class, 'autorisé');
        $id = $this->submitted();
        $this->refuses(fn () => $this->approveNow($id, $this->payer, null), DisbursementRuleViolation::class, 'autorisé');
        $this->refuses(fn () => $this->rejectNow($id, $this->initiator, 'x'), DisbursementRuleViolation::class, 'autorisé');
        $this->db->update('users', ['is_active' => 0], ['id' => $this->approverA]);
        $this->user('appC', ['dec_appr']); // le circuit reste opérationnel (B et C)
        $this->refuses(fn () => $this->approveNow($id, $this->approverA, null), DisbursementRuleViolation::class, 'actif');
        $this->approveNow($id, $this->approverB, null);
        $this->approveNow($id, (int) $this->db->fetchValue("SELECT id FROM users WHERE name = 'appC'"), null);
        $this->refuses(fn () => $this->payNow($id, payer: $this->approverB), DisbursementRuleViolation::class, 'autorisé');
        $this->db->update('users', ['is_active' => 0], ['id' => $this->payer]);
        $this->refuses(fn () => $this->payNow($id), DisbursementRuleViolation::class, 'actif');
        $this->db->update('users', ['is_active' => 0], ['id' => $this->initiator]);
        $this->refuses(fn () => $this->svc()->revise($id, ['internal_note' => 'x'], '', $this->initiator), DisbursementRuleViolation::class, 'actif');
        $this->assertSame([], $this->svc()->allowedActions($id, $this->payer));
        $this->assertSame('approuve_2', $this->state($id));
    }

    public function test_reverseResource_refuses_null_and_disbursement_sources(): void
    {
        $id = $this->approved();
        $p = $this->payNow($id);
        $tx = (int) $this->db->fetchValue('SELECT financial_transaction_id FROM disbursement_payments WHERE id = ?', [$p]);
        $ledger = $this->ledger();
        $this->refuses(fn () => $ledger->reverseResource($tx, 'tentative', $this->payer), DomainException::class, 'processus métier');
        $don = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Dons, legs et subventions'");
        $null = $ledger->record($this->account, $don, 'recette', 1_000, new DateTimeImmutable('now'), [], $this->payer);
        $this->refuses(fn () => $ledger->reverseResource($null, 'tentative', $this->payer), DomainException::class, 'processus métier');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE reversal_of_id IN (?, ?)', [$tx, $null]));
        $res = $ledger->record($this->account, $don, 'recette', 1_000, new DateTimeImmutable('now'), ['source_type' => 'ressource'], $this->payer);
        $this->assertGreaterThan(0, $ledger->reverseResource($res, 'Erreur de saisie', $this->payer));
        $this->assertFalse(Ledger::isManual(null));
        $this->assertFalse(Ledger::isManual('disbursement'));
        // Une dépense ne passe que par le circuit : Ledger::record refuse toute autre source.
        foreach ([[], ['source_type' => 'ressource']] as $meta) {
            $this->refuses(fn () => $ledger->record($this->account, $this->category, 'depense', 100, new DateTimeImmutable('now'), $meta, $this->payer), DomainException::class, 'circuit de décaissement');
        }
    }

    /** Audit::log verrouille audit_chain jusqu'au commit : dans chaque action, c'est la dernière instruction avant le retour (preuve structurelle). */
    public function test_audit_is_the_last_lock(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Disbursements/DisbursementService.php');
        preg_match_all('/public function (\w+)\(/', $src, $m, PREG_OFFSET_CAPTURE);
        $bodies = [];
        foreach ($m[1] as [$name, $offset]) {
            $bodies[$name] = $this->block($src, strpos($src, '{', $offset));
        }
        foreach (['create', 'submit', 'approve', 'reject', 'revise', 'cancel', 'pay'] as $action) {
            $body = $bodies[$action];
            $atomic = strpos($body, '$this->run(function');
            $this->assertNotFalse($atomic, "$action : en transaction");
            $closure = $this->block($body, strpos($body, '{', $atomic));
            $this->assertMatchesRegularExpression('/^\{\s*\$r = \$this->lock\(\$id\);|^\{\s*\$this->deny\(\$this->permission/', $closure, "$action : le verrou (ou l'autorisation sans état) d'abord");
            $pos = max((int) strrpos($closure, '$this->auditTransition('), (int) strrpos($closure, '$this->audit->log('));
            $this->assertGreaterThan(0, $pos, "$action : audité");
            $paren = strpos($closure, '(', $pos);
            $tail = substr($closure, $paren + strlen($this->block($closure, $paren, '(', ')')));
            $this->assertMatchesRegularExpression('/^;\s*(return \$\w+;\s*)?\}$/', $tail, "$action : rien après l'audit");
        }
        // pay() : après Ledger::record (qui audite lui-même), seule la ligne de paiement insérée par la transaction est mise à jour.
        $pay = $bodies['pay'];
        $after = substr($pay, strpos($pay, '$this->ledger->record('));
        $after = substr($after, 0, strpos($after, '$this->auditTransition('));
        preg_match_all('/\$this->(\w+)->/', $after, $calls);
        $this->assertSame(['ledger', 'db'], $calls[1], 'une seule instruction SQL entre le grand livre et l\'audit');
        $this->assertStringContainsString("\$this->db->update('disbursement_payments', ['financial_transaction_id' => \$tx], ['id' => \$paymentId])", $after);
        $this->assertLessThan(strpos($pay, '$this->ledger->record('), strpos($pay, "insert('disbursement_payments'"), 'ligne de paiement insérée avant les séquences et l\'audit');
        $this->assertLessThan(strpos($pay, "insert('disbursement_payments'"), strpos($pay, 'FROM financial_accounts WHERE id = ? FOR UPDATE'), 'compte verrouillé avant la ligne de paiement');
        $helper = $this->block($src, strpos($src, '{', strpos($src, 'private function auditTransition(')));
        $this->assertSame(1, substr_count($helper, ';'), 'auditTransition ne fait que l\'appel à Audit::log');
        $this->assertStringContainsString('$this->audit->log(', $helper);
    }

    /** Bloc équilibré à partir de $start (qui pointe sur $open). */
    private function block(string $s, int $start, string $open = '{', string $close = '}'): string
    {
        $depth = 0;
        for ($i = $start, $n = strlen($s); $i < $n; $i++) {
            $depth += $s[$i] === $open ? 1 : ($s[$i] === $close ? -1 : 0);
            if ($depth === 0) {
                return substr($s, $start, $i - $start + 1);
            }
        }
        $this->fail('bloc non fermé');
    }

    /** Scénario de la revue (I1) : l'approbateur lit 50 000, l'initiateur passe à 5 000 000 ; approuver la page périmée est refusé. */
    public function test_approving_after_amount_raised_since_read_is_refused(): void
    {
        $id = $this->submitted(5_000_000);
        $seenByA = $this->revision($id);
        $seenByB = $this->revision($id);
        $this->svc()->revise($id, ['amount' => 500_000_000], 'Montant corrigé', $this->initiator);
        foreach ([[$this->approverA, $seenByA], [$this->approverB, $seenByB]] as [$u, $seen]) {
            $this->refuses(fn () => $this->svc()->approve($id, $u, $seen, 'vu à 50 000'), DisbursementRuleViolation::class, 'modifiée depuis votre lecture, relisez-la');
            $this->refuses(fn () => $this->svc()->reject($id, $u, $seen, 'vu à 50 000'), DisbursementRuleViolation::class, 'modifiée depuis votre lecture, relisez-la');
        }
        $this->assertSame(0, $this->approvals($id));
        $this->assertSame('soumis', $this->state($id));
    }

    public function test_paying_with_stale_amount_or_revision_is_refused(): void
    {
        $id = $this->approved();
        $this->refuses(fn () => $this->payNow($id, seenAmount: 4_999_999), DisbursementRuleViolation::class, 'relisez-la');
        $this->refuses(fn () => $this->payNow($id, seenRevision: 2), DisbursementRuleViolation::class, 'relisez-la');
        // Révision 2 réapprouvée : le payeur qui avait affiché la révision 1 / l'ancien montant est refusé.
        $this->svc()->revise($id, ['amount' => 6_000_000], 'Ajustement', $this->initiator);
        $this->approveNow($id, $this->approverA);
        $this->approveNow($id, $this->approverB);
        $this->refuses(fn () => $this->payNow($id, seenRevision: 1, seenAmount: 5_000_000), DisbursementRuleViolation::class, 'relisez-la');
        $this->refuses(fn () => $this->payNow($id, seenAmount: 5_000_000), DisbursementRuleViolation::class, 'relisez-la');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->payNow($id, seenRevision: 2, seenAmount: 6_000_000);
        $this->assertSame('paye', $this->state($id));
    }

    /** La révision vue (et le montant vu pour le paiement) ne peut pas être omise : paramètres int obligatoires. */
    public function test_seen_revision_cannot_be_omitted(): void
    {
        $required = function (string $method, array $names): void {
            $params = array_column(array_map(fn (\ReflectionParameter $p) => [$p->getName(), $p], (new \ReflectionMethod(\App\Modules\Disbursements\DisbursementService::class, $method))->getParameters()), 1, 0);
            foreach ($names as $n) {
                $this->assertArrayHasKey($n, $params, "$method::$n");
                $this->assertFalse($params[$n]->isOptional(), "$method::$n obligatoire");
                $this->assertSame('int', (string) $params[$n]->getType(), "$method::$n int non nullable");
            }
        };
        $required('approve', ['seenRevision']);
        $required('reject', ['seenRevision']);
        $required('pay', ['seenRevision', 'seenAmount']);
        $this->expectException(\ArgumentCountError::class);
        $this->svc()->approve(1, $this->approverA); // @phpstan-ignore-line
    }

    public function test_attachment_and_proof_files_must_be_uploaded_by_the_actor_and_not_member_documents(): void
    {
        $id = $this->draft();
        $member = $this->db->insert('members', ['member_number' => 'M' . bin2hex(random_bytes(3)), 'last_name' => 'Yao', 'first_names' => 'Ama', 'status' => 'actif']);
        $type = (string) $this->db->fetchValue('SELECT code FROM member_document_types LIMIT 1');
        $memberDoc = $this->file($this->initiator);
        $this->db->insert('member_documents', ['member_id' => $member, 'type' => $type, 'file_id' => $memberDoc]);
        $application = $this->db->insert('membership_applications', ['reference' => 'APP-' . bin2hex(random_bytes(3)), 'payload' => '{}']);
        $applicationFile = $this->file($this->initiator);
        $this->db->insert('membership_application_files', ['application_id' => $application, 'type' => $type, 'file_id' => $applicationFile]);
        $orphan = $this->db->insert('files', ['path' => 'p/x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('d', 64)]);
        foreach ([$this->file($this->approverA), $memberDoc, $applicationFile, $orphan, 999_999] as $f) {
            $this->refuses(fn () => $this->svc()->revise($id, ['add_files' => [$f => 'Pièce']], '', $this->initiator), DisbursementRuleViolation::class, 'non téléversé par vous');
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = ?", [$id]));
        // Pièce de paiement : téléversée par le payeur lui-même.
        $paid = $this->approved();
        $payerDoc = $this->file($this->payer);
        $this->db->insert('member_documents', ['member_id' => $member, 'type' => $type, 'file_id' => $payerDoc]);
        foreach ([$this->file($this->initiator), $payerDoc, $orphan] as $f) {
            $this->refuses(fn () => $this->payNow($paid, proof: $f), DisbursementRuleViolation::class, 'non téléversé par vous');
        }
        $this->assertSame('approuve_2', $this->state($paid));
        $this->payNow($paid, proof: $this->file($this->payer));
        $this->assertSame('paye', $this->state($paid));
    }

    public function test_circuit_status_reports_required_valid_eligible_and_stall(): void
    {
        $id = $this->submitted();
        $this->assertSame(['required' => 2, 'valid' => 0, 'other_eligible_approvers' => 2, 'stalled' => false], $this->svc()->circuitStatus($id));
        $this->approveNow($id, $this->approverA);
        $this->assertSame(['required' => 2, 'valid' => 1, 'other_eligible_approvers' => 1, 'stalled' => false], $this->svc()->circuitStatus($id));
        // L'initiateur est l'un des deux seuls approbateurs : le circuit est « opérationnel » mais la demande ne peut pas aboutir.
        $this->db->update('users', ['is_active' => 0], ['id' => $this->approverB]);
        $cumul = $this->user('cumul', ['dec_init_appr']);
        $stuck = $this->submitted(initiator: $cumul);
        $this->assertSame(['required' => 2, 'valid' => 0, 'other_eligible_approvers' => 1, 'stalled' => true], $this->svc()->circuitStatus($stuck));
        // Tranche à 3 approbations avec 2 approbateurs possibles.
        $this->db->update('users', ['is_active' => 1], ['id' => $this->approverB]);
        $this->db->insert('approval_rules', ['min_amount' => '100000', 'max_amount' => null, 'approvals_required' => 3]);
        $big = $this->submitted(20_000_000);
        $this->assertSame(['required' => 3, 'valid' => 0, 'other_eligible_approvers' => 3, 'stalled' => false], $this->svc()->circuitStatus($big), 'A, B et le cumulard');
        $this->db->update('users', ['is_active' => 0], ['id' => $cumul]);
        $this->assertTrue($this->svc()->circuitStatus($big)['stalled']);
        $this->expectException(\App\Core\NotFound::class);
        $this->svc()->circuitStatus(999_999);
    }

    public function test_ip_is_stored_in_audit_for_every_state_change(): void
    {
        $svc = $this->svc();
        $id = $svc->create(['amount' => 5_000_000, 'beneficiary_name' => 'B', 'account_id' => $this->account, 'category_id' => $this->category, 'purpose' => 'P'], $this->initiator, '10.0.0.1');
        $svc->revise($id, ['add_files' => [$this->file() => 'Pièce']], '', $this->initiator, '10.0.0.2');
        $svc->submit($id, $this->initiator, '10.0.0.3');
        $svc->approve($id, $this->approverA, 1, null, '10.0.0.4');
        $svc->approve($id, $this->approverB, 1, null, '2001:db8::5');
        $svc->pay($id, $this->payer, 1, 5_000_000, ['method' => 'especes'], $this->file($this->payer), bin2hex(random_bytes(16)), '10.0.0.6');
        $other = $this->submitted();
        $svc->reject($other, $this->approverA, 1, 'non', '10.0.0.7');
        $third = $this->submitted();
        $svc->cancel($third, $this->initiator, '10.0.0.8');
        $ips = fn (int $rid) => array_column($this->db->fetchAll("SELECT action, ip FROM audit_logs WHERE auditable_type = 'disbursement_request' AND auditable_id = ? ORDER BY id", [$rid]), 'ip', 'action');
        $this->assertSame(['disbursement.created' => '10.0.0.1', 'disbursement.revised' => '10.0.0.2', 'disbursement.submitted' => '10.0.0.3',
            'disbursement.approved' => '2001:db8::5', 'disbursement.paid' => '10.0.0.6'], $ips($id));
        $this->assertSame('10.0.0.4', $this->db->fetchValue("SELECT ip FROM audit_logs WHERE action = 'disbursement.approved' AND auditable_id = ? ORDER BY id LIMIT 1", [$id]));
        $this->assertSame('10.0.0.7', $ips($other)['disbursement.rejected']);
        $this->assertSame('10.0.0.8', $ips($third)['disbursement.cancelled']);
        $this->assertNull($ips($third)['disbursement.submitted'], 'sans IP fournie : NULL');
    }

    public function test_allowed_actions_follow_the_same_predicates(): void
    {
        $id = $this->submitted();
        $this->assertSame(['revise', 'cancel'], $this->svc()->allowedActions($id, $this->initiator));
        $this->assertSame(['approve', 'reject'], $this->svc()->allowedActions($id, $this->approverA));
        $this->assertSame([], $this->svc()->allowedActions($id, $this->payer));
        $this->approveNow($id, $this->approverA, null);
        $this->assertSame(['reject'], $this->svc()->allowedActions($id, $this->approverA));
        $this->approveNow($id, $this->approverB, null);
        $this->assertSame(['pay'], $this->svc()->allowedActions($id, $this->payer));
        $this->assertSame(['revise'], $this->svc()->allowedActions($id, $this->initiator));
        $this->assertSame([], $this->svc()->allowedActions(999_999, $this->initiator));
        $this->assertSame(Money::toDecimal(5_000_000), $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$id]));
    }
}
