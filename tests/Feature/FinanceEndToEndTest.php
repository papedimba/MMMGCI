<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Money;
use App\Core\Uploads;
use App\Modules\Disbursements\DisbursementService;
use App\Modules\Solidarity\SolidarityService;
use DomainException;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;

/**
 * Parcours de bout en bout du lot L6, par les écrans et les rôles réels : trésorier général (seed), contrôleur (seed), et deux rôles de test
 * portant disbursement.approve / disbursement.pay (aucun rôle ne les a par défaut). Preuve par scénario ; la concurrence est prouvée ailleurs (DisbursementConcurrencyTest).
 */
final class FinanceEndToEndTest extends AdminTestCase
{
    use DisbursementFixtures;

    private string $root;
    private int $treasurer;
    private int $controller;
    private int $typeId;
    /** @var list<int> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['receipts', 'payments', 'contribution_calls', 'contribution_types', 'members', 'disbursement_payments', 'financial_transactions', 'sensitive_access_logs'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->setUpCircuit('0.00'); // approverA, approverB (disbursement.approve), payer (disbursement.pay) : trois personnes distinctes
        $this->db->query("UPDATE financial_accounts SET is_active = 0 WHERE type = 'solidarity_fund'");
        $this->db->update('financial_accounts', ['type' => 'solidarity_fund'], ['id' => $this->account]); // le compte crédité est aussi le fonds de solidarité
        $n = bin2hex(random_bytes(3));
        $this->treasurer = $this->createUser("tres$n@e2e.test", ['tresorier_general']);
        $this->controller = $this->createUser("ctl$n@e2e.test", ['controleur']);
        $this->initiator = $this->treasurer;
        for ($i = 1; $i <= 3; $i++) {
            $this->members[] = $this->db->insert('members', ['member_number' => "E2E-$i-$n", 'last_name' => "Nom$i", 'first_names' => 'Yao', 'status' => 'actif']);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }

    /** @return array<string,mixed> */
    private function pdf(): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => 'piece.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function money(string $xof): int
    {
        return Money::parse($xof);
    }

    /** @return array<string,string> champs cachés rendus par un formulaire (clé, révision et montant vus) */
    private function hidden(string $url): array
    {
        $html = $this->request('GET', $url)->body();
        preg_match_all('/<input type="hidden" name="(key|seen_revision|seen_amount)" value="([^"]*)"/', $html, $m);
        return $m[1] === [] ? [] : array_combine($m[1], $m[2]);
    }

    private function newDisbursement(string $amount = '15 000'): int
    {
        $this->as($this->treasurer);
        $res = $this->request('POST', '/admin/decaissements/nouveau', ['amount' => $amount, 'beneficiary_name' => 'Famille Koné', 'account_id' => (string) $this->account,
            'category_id' => (string) $this->category, 'purpose' => 'Aide décès', 'internal_note' => '', 'label' => 'Acte de décès'], ['justificatif' => $this->pdf()]);
        $this->assertSame(302, $res->status());
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM disbursement_requests');
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/soumettre")->status());
        $this->assertSame('soumis', $this->state($id));
        return $id;
    }

    private function approveVia(int $id, int $user): int
    {
        $this->as($user);
        return $this->request('POST', "/admin/decaissements/$id/approuver", ['seen_revision' => (string) $this->revision($id)])->status();
    }

    public function test_full_cycle_from_contribution_to_payout_and_reversal(): void
    {
        // 1. Type de cotisation validé (montant, autorisation, compte) par le trésorier général.
        $this->as($this->treasurer);
        $res = $this->request('POST', '/admin/cotisations/types/nouveau', ['name' => 'Cotisation E2E', 'kind' => 'periodique', 'amount' => '10 000', 'periodicity' => 'mensuelle',
            'authorization_ref' => 'PV-2026-01', 'affects_account_id' => (string) $this->account, 'status' => 'actif']);
        $this->assertSame(302, $res->status());
        $this->typeId = (int) $this->db->fetchValue("SELECT id FROM contribution_types WHERE name = 'Cotisation E2E'");
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM contribution_types WHERE id = ?', [$this->typeId]));

        // 2. Appel en lot manuel : un appel par adhérent actif, au montant du type.
        $this->assertSame(302, $this->request('POST', '/admin/cotisations/appels/lot', ['type_id' => (string) $this->typeId, 'start' => date('Y-m-d')])->status());
        $calls = $this->db->fetchAll('SELECT id, member_id, amount_due FROM contribution_calls WHERE contribution_type_id = ? ORDER BY id', [$this->typeId]);
        $this->assertCount(3, $calls);
        $this->assertSame(['10000.00'], array_values(array_unique(array_column($calls, 'amount_due'))));

        // 3. Encaissements par l'écran, reçu numéroté, solde du compte.
        $paymentIds = [];
        foreach ($calls as $c) {
            $form = $this->request('GET', "/admin/cotisations/appels/{$c['id']}/encaisser");
            $this->assertSame(200, $form->status());
            preg_match('/name="key" value="([^"]+)"/', $form->body(), $k);
            $this->assertSame(302, $this->request('POST', "/admin/cotisations/appels/{$c['id']}/encaisser",
                ['amount' => '10 000', 'method' => 'especes', 'paid_on' => date('Y-m-d'), 'external_ref' => '', 'key' => $k[1]])->status());
            $paymentIds[] = (int) $this->db->fetchValue('SELECT id FROM payments WHERE contribution_call_id = ?', [$c['id']]);
        }
        $receipt = $this->request('GET', "/admin/cotisations/paiements/{$paymentIds[0]}/recu");
        $this->assertSame(200, $receipt->status());
        $this->assertMatchesRegularExpression('/REC-\d{4}-\d+/', $receipt->body());
        $this->assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM receipts'));
        $this->assertSame($this->money('30 000'), $this->ledger()->balance($this->account));
        $this->assertStringContainsString("30\u{202F}000", $this->request('GET', "/admin/finances/comptes/{$this->account}")->body());

        // 4. Décaissement : initié, soumis avec justificatif ; l'initiateur ne peut ni approuver ni payer (pas de permission).
        $id = $this->newDisbursement();
        $this->assertSame(403, $this->approveVia($id, $this->treasurer));
        $this->assertSame(403, $this->request('POST', "/admin/decaissements/$id/payer", ['key' => bin2hex(random_bytes(16))])->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_attachments WHERE attachable_type = ? AND attachable_id = ?', ['disbursement_request', $id]));

        // 5. Deux approbateurs DISTINCTS ; le même approbateur ne compte pas deux fois ; le payeur ne peut pas payer avant.
        $this->assertSame(302, $this->approveVia($id, $this->approverA));
        $this->assertSame('approuve_1', $this->state($id));
        $this->assertSame(422, $this->approveVia($id, $this->approverA), 'même approbateur');
        $this->as($this->payer);
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$id/payer", ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'key' => bin2hex(random_bytes(16)),
            'seen_revision' => '1', 'seen_amount' => (string) $this->money('15 000')], ['preuve' => $this->pdf()])->status(), 'une seule approbation');
        $this->assertSame(302, $this->approveVia($id, $this->approverB));
        $this->assertSame('approuve_2', $this->state($id));

        // 6. Paiement par un troisième utilisateur, avec justificatif : solde diminué, dépense inscrite une fois.
        $this->as($this->payer);
        $fields = $this->hidden("/admin/decaissements/$id/payer") + ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'external_ref' => 'VIR-1', 'confirm' => '1'];
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/payer", $fields, ['preuve' => $this->pdf()])->status());
        $this->assertSame('paye', $this->state($id));
        $this->assertSame($this->money('15 000'), $this->ledger()->balance($this->account));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE direction = 'depense' AND source_type = 'disbursement' AND source_id = ?", [$id]));
        $this->assertNotSame($this->treasurer, (int) $this->db->fetchValue('SELECT paid_by FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        $actions = array_column($this->db->fetchAll("SELECT action FROM audit_logs WHERE auditable_type = 'disbursement_request' AND auditable_id = ?", [$id]), 'action');
        foreach (['disbursement.created', 'disbursement.submitted', 'disbursement.approved', 'disbursement.paid'] as $a) {
            $this->assertContains($a, $actions);
        }
        $this->assertSame(2, count(array_keys($actions, 'disbursement.approved', true)), 'deux approbations auditées');

        // 7. Écran de contrôle : écritures, décaissement détaillé (acteurs, pièces), soldes sans anomalie.
        $this->as($this->controller);
        $ref = (string) $this->db->fetchValue('SELECT reference FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertStringContainsString($ref, $this->request('GET', '/admin/controle/decaissements')->body());
        $detail = $this->request('GET', "/admin/controle/decaissements/$id");
        $this->assertSame(200, $detail->status());
        foreach ([$ref, 'piece.pdf', 'Utilisateur', 'Valable'] as $needle) {
            $this->assertStringContainsString($needle, $detail->body());
        }
        $this->assertSame(200, $this->request('GET', '/admin/controle')->status());
        $this->assertStringContainsString('Aucune anomalie détectée', $this->request('GET', '/admin/controle/soldes')->body());

        // 8. Annulation d'un paiement de cotisation : contre-passation, reçu annulé, solde recalculé, contrôle et fonds cohérents.
        $this->as($this->treasurer);
        $this->assertSame(302, $this->request('POST', "/admin/cotisations/paiements/{$paymentIds[0]}/annuler", ['reason' => 'Erreur de saisie'])->status());
        $this->assertSame($this->money('5 000'), $this->ledger()->balance($this->account));
        $this->as($this->controller);
        $this->assertStringContainsString('Erreur de saisie', $this->request('GET', '/admin/controle/contre-passations')->body() . $this->request('GET', '/admin/controle/annulations')->body());
        $this->assertStringContainsString('Aucune anomalie détectée', $this->request('GET', '/admin/controle/soldes')->body());
        $fund = (new SolidarityService($this->db, $this->audit(), $this->ledger()))->summary();
        $this->assertSame($this->money('20 000'), $fund['receipts_total'], 'entrées nettes : 3 encaissements moins 1 annulé');
        $this->assertSame($this->money('15 000'), $fund['disbursed_total']);
        $this->assertSame($fund['opening'] + $fund['receipts_total'] - $fund['disbursed_total'], $fund['balance']);
        $this->assertSame($this->money('5 000'), $fund['balance']);
        $this->assertSame(200, $this->request('GET', '/admin/solidarite')->status());
    }

    public function test_circumvention_attempts_are_refused(): void
    {
        $this->db->update('financial_accounts', ['opening_balance' => '1000000.00'], ['id' => $this->account]);
        $both = $this->user('initappr', ['dec_init_appr']);
        $dep = (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE direction = ?', ['depense']);

        // L'initiateur qui détient aussi disbursement.approve ne peut pas approuver sa propre demande (règle du service).
        $own = $this->submitted(1_500_000, $both);
        $this->assertSame(422, $this->approveVia($own, $both));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$own]));

        // Un seul approbateur ne suffit pas à payer.
        $one = $this->submitted();
        $this->assertSame(302, $this->approveVia($one, $this->approverA));
        $this->as($this->payer);
        $res = $this->request('POST', "/admin/decaissements/$one/payer", ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'key' => bin2hex(random_bytes(16)),
            'seen_revision' => '1', 'seen_amount' => '5000000'], ['preuve' => $this->pdf()]);
        $this->assertSame(422, $res->status());
        $this->assertSame('approuve_1', $this->state($one));

        // Révision périmée : modification substantielle après la lecture de l'approbateur.
        $stale = $this->submitted();
        $this->svc()->revise($stale, ['amount' => 6_000_000], 'Montant revu', $this->initiator);
        $this->as($this->approverA);
        $res = $this->request('POST', "/admin/decaissements/$stale/approuver", ['seen_revision' => '1']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(DisbursementService::STALE), $res->body());

        // Transitions forgées : approuver un brouillon, resoumettre une demande payée, payer un brouillon.
        $draft = $this->draft();
        $this->assertSame(422, $this->approveVia($draft, $this->approverA));
        $paid = $this->approved();
        $this->payNow($paid);
        $this->as($this->treasurer);
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$paid/soumettre")->status());
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$paid/annuler")->status());
        $this->as($this->payer);
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$draft/payer", ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'key' => bin2hex(random_bytes(16)),
            'seen_revision' => '1', 'seen_amount' => '5000000'], ['preuve' => $this->pdf()])->status());
        $this->assertSame('brouillon', $this->state($draft));
        $dep++; // seul le paiement légitime de $paid a écrit une dépense

        // Dépense directe par l'écran du grand livre : refusée ; aucune route ne l'accepte, le grand livre non plus.
        $this->as($this->treasurer);
        $res = $this->request('POST', '/admin/finances/ressources', ['account_id' => (string) $this->account, 'category_id' => (string) $this->category, 'amount' => '1 000',
            'occurred_on' => date('Y-m-d'), 'description' => 'Sortie directe', 'authorization_ref' => '']);
        $this->assertSame(422, $res->status());
        $this->assertSame($dep, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE direction = ?', ['depense']));
        $this->refuses(fn () => $this->ledger()->record($this->account, $this->category, 'depense', 100_000, new \DateTimeImmutable('now'), ['source_type' => 'ressource'], $this->treasurer),
            DomainException::class, 'circuit de décaissement');

        // Contre-passation, depuis l'écran du grand livre, d'une écriture issue du circuit : refusée (GET et POST).
        $tx = (int) $this->db->fetchValue("SELECT id FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$paid]);
        $this->assertSame(422, $this->request('GET', "/admin/finances/ecritures/$tx/contre-passation")->status());
        $this->assertSame(422, $this->request('POST', "/admin/finances/ecritures/$tx/contre-passation", ['reason' => 'Contournement'])->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE reversal_of_id = ?', [$tx]));
    }
}
