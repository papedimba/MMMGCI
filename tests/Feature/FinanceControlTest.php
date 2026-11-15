<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Uploads;
use App\Modules\Finance\ControlService;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;

/** Écran de contrôle : lecture seule, rien de masqué, exports audités et neutralisés, contrôles d'intégrité. */
final class FinanceControlTest extends AdminTestCase
{
    use DisbursementFixtures;

    private string $root;
    private int $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-ctl-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        // Base propre : les corruptions volontaires d'un test ne doivent pas polluer les autres (les clés étrangères sont levées le temps du nettoyage).
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['receipts', 'payments', 'disbursement_payments', 'financial_transactions', 'sensitive_access_logs'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->setUpCircuit();
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['fin_view_only', 'x']);
        $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', ['fin_view_only', 'finance.view']);
        $this->controller = $this->createUser('ctl' . bin2hex(random_bytes(3)) . '@ctl.test', ['controleur']);
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
        return ['name' => 'preuve.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function tx(string $dir = 'depense', string $amount = '1000.00', ?string $sourceType = null, ?int $sourceId = null, ?int $reversalOf = null): int
    {
        return $this->db->insert('financial_transactions', ['reference' => 'TX-T-' . bin2hex(random_bytes(5)), 'account_id' => $this->account, 'category_id' => $this->category, 'direction' => $dir,
            'amount' => $amount, 'occurred_at' => date('Y-m-d H:i:s'), 'source_type' => $sourceType, 'source_id' => $sourceId, 'reversal_of_id' => $reversalOf]);
    }

    /** @return array<string,list<int>> clé du contrôle -> ids */
    private function anomalies(): array
    {
        $out = [];
        foreach ((new ControlService($this->db, $this->audit(), $this->ledger()))->anomalies() as $a) {
            $out[$a['key']] = $a['ids'];
        }
        return $out;
    }

    public function test_control_screen_is_read_only(): void
    {
        $this->app();
        $found = 0;
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/controle')) {
                $found++;
                $this->assertSame('GET', $r['method'], $r['pattern']);
            }
        }
        $this->assertGreaterThanOrEqual(10, $found);
        $this->as($this->controller);
        foreach (['/admin/controle', '/admin/controle/soldes', '/admin/controle/decaissements/1', '/admin/controle/export/ecritures.csv'] as $url) {
            foreach (['POST', 'PUT', 'DELETE'] as $m) {
                $this->assertContains($this->request($m, $url)->status(), [404, 405], "$m $url");
            }
        }
        $this->assertSame(405, $this->request('POST', '/admin/controle')->status());
        // Aucun formulaire ni bouton d'écriture dans les pages.
        $id = $this->approved();
        foreach (['/admin/controle', '/admin/controle/decaissements', "/admin/controle/decaissements/$id", '/admin/controle/contre-passations', '/admin/controle/annulations', '/admin/controle/soldes'] as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(200, $res->status(), $url);
            $main = substr($res->body(), (int) strpos($res->body(), '<main'));
            $this->assertStringNotContainsString('<form method="post"', $main, $url);
            $this->assertStringNotContainsString('_csrf', $main, $url);
            $this->assertSame(1, substr_count($res->body(), '<h1'), $url);
            $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        }
    }

    public function test_control_shows_invalidated_approvals_and_reversals(): void
    {
        $id = $this->submitted(5_000_000);
        $this->approveNow($id, $this->approverA, 'vu');
        $this->svc()->revise($id, ['amount' => 6_000_000], 'Montant corrigé', $this->initiator); // modification substantielle : l'approbation de A devient caduque
        $this->approveNow($id, $this->approverA);
        $this->approveNow($id, $this->approverB);
        $this->payNow($id);
        $txId = (int) $this->db->fetchValue("SELECT id FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]);
        $this->ledger()->reverse($txId, 'Paiement annulé pour erreur', $this->initiator);
        $this->as($this->controller);

        $detail = $this->request('GET', "/admin/controle/decaissements/$id")->body();
        $this->assertStringContainsString('Caduque depuis le', $detail);
        $this->assertStringContainsString('Montant corrigé', $detail);
        $this->assertStringContainsString('n° 2', $detail);
        $this->assertStringContainsString('Contre-passation TX-', $detail);
        $this->assertStringContainsString('/admin/decaissements/' . $id . '/pieces/', $detail);

        $rev = $this->request('GET', '/admin/controle/contre-passations')->body();
        $this->assertStringContainsString((string) $this->db->fetchValue('SELECT reference FROM financial_transactions WHERE id = ?', [$txId]), $rev);
        $this->assertStringContainsString('Paiement annulé pour erreur', $rev);
        $tx = $this->request('GET', '/admin/controle?compte=' . $this->account)->body();
        $this->assertStringContainsString('Contre-passée par', $tx);
        $this->assertStringContainsString('Contre-passation de', $tx);
    }

    public function test_exports_are_audited_and_neutralised(): void
    {
        $id = $this->submitted(2_500_050);
        $this->db->update('disbursement_requests', ['beneficiary_name' => '=HYPERLINK("http://x")', 'purpose' => "@cmd|' /C calc'!A0"], ['id' => $id]);
        $this->db->query("DELETE FROM sensitive_access_logs");
        $this->tx('recette', '1234.50');
        $this->as($this->controller);

        $csv = $this->request('GET', '/admin/controle/export/decaissements.csv?compte=' . $this->account);
        $this->assertSame(200, $csv->status());
        $this->assertStringContainsString("'=HYPERLINK", $csv->body());
        $this->assertStringContainsString("'@cmd", $csv->body());
        $this->assertStringContainsString(';25000,50;', $csv->body());
        $this->assertStringNotContainsString('Note interne', $csv->body());
        $this->assertStringContainsString('no-store', $csv->headers()['Cache-Control']);
        $this->assertStringStartsWith('attachment;', $csv->headers()['Content-Disposition']);
        $audit = $this->db->fetch("SELECT new_values FROM audit_logs WHERE action = 'finance.control_exported' ORDER BY id DESC LIMIT 1");
        $new = json_decode((string) $audit['new_values'], true);
        $this->assertSame(['dataset' => 'disbursements', 'format' => 'csv', 'criteria' => ['compte' => (string) $this->account], 'rows' => 1], $new);

        $xlsx = $this->request('GET', '/admin/controle/export/decaissements.xlsx?compte=' . $this->account);
        $this->assertStringStartsWith('PK', $xlsx->body());
        $zip = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($zip, $xlsx->body());
        $z = new \ZipArchive();
        $z->open($zip);
        $sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($zip);
        $this->assertStringContainsString('&apos;=HYPERLINK', $sheet);
        $this->assertStringContainsString('t="n"><v>25000.50</v>', $sheet); // vraie cellule numérique

        $tx = $this->request('GET', '/admin/controle/export/ecritures.csv?compte=' . $this->account . '&sens=recette');
        $this->assertStringContainsString('1234,50', $tx->body());
        $this->assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.control_exported'"));
    }

    public function test_export_walks_all_chunks_with_keyset(): void
    {
        for ($i = 0; $i < 1003; $i++) {
            $this->tx('recette', '10.00');
        }
        $this->as($this->controller);
        $body = $this->request('GET', '/admin/controle/export/ecritures.csv?compte=' . $this->account)->body();
        $this->assertSame(1003, substr_count($body, "\r\n") - 1);
        $this->assertSame(1003, count(array_unique(array_filter(explode("\r\n", $body), static fn (string $l): bool => str_contains($l, 'TX-T-')))));
    }

    public function test_requires_finance_control(): void
    {
        $urls = ['/admin/controle', '/admin/controle/decaissements', '/admin/controle/decaissements/1', '/admin/controle/contre-passations', '/admin/controle/annulations',
            '/admin/controle/soldes', '/admin/controle/export/ecritures.csv', '/admin/controle/export/decaissements.xlsx'];
        foreach ($urls as $u) {
            $this->assertSame(302, $this->request('GET', $u)->status(), "anonyme $u");
        }
        $id = $this->submitted();
        foreach (['fin_view_only', 'dec_init', 'dec_appr', 'dec_pay'] as $role) {
            $this->as($this->createUser("$role" . bin2hex(random_bytes(3)) . '@ctl.test', [$role]));
            foreach ($urls as $u) {
                $this->assertSame(403, $this->request('GET', $u)->status(), "$role $u");
            }
        }
        // Détenteurs : le rôle contrôleur et le super administrateur.
        foreach (['controleur', 'super_admin'] as $role) {
            $this->as($this->createUser("$role" . bin2hex(random_bytes(3)) . '@ctl.test', [$role]));
            $this->assertSame(200, $this->request('GET', '/admin/controle')->status(), $role);
            $this->assertSame(200, $this->request('GET', "/admin/controle/decaissements/$id")->status(), $role);
            $this->assertStringContainsString('href="/admin/controle"', $this->request('GET', '/admin')->body(), "menu $role");
        }
        $this->as($this->createUser('nomenu' . bin2hex(random_bytes(3)) . '@ctl.test', ['fin_view_only']));
        $this->assertStringNotContainsString('href="/admin/controle"', $this->request('GET', '/admin/finances')->body());
        $this->as($this->controller);
        $this->assertSame(404, $this->request('GET', '/admin/controle/decaissements/99999999')->status());
    }

    public function test_balance_check_reports_no_gap_on_consistent_data(): void
    {
        $this->tx('recette', '700.00');
        $this->tx('depense', '150.25');
        $this->tx('depense', '100.00');
        $this->as($this->controller);
        $res = $this->request('GET', '/admin/controle/soldes');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Aucun écart', $res->body());
        $this->assertStringNotContainsString('Écart de solde détecté', $res->body());
        $this->assertStringContainsString('Aucune anomalie détectée', $res->body());
        $rows = (new ControlService($this->db, $this->audit(), $this->ledger()))->balances();
        $this->assertNotSame([], $rows);
        foreach ($rows as $b) {
            $this->assertTrue($b['ok'], $b['name']);
            $this->assertSame(0, $b['gap']);
        }
        $mine = array_values(array_filter($rows, fn (array $b): bool => $b['id'] === $this->account))[0];
        $this->assertSame(3, $mine['count']);
        $this->assertSame(100_000_000 + 70_000 - 15_025 - 10_000, $mine['displayed']);
        $ko = $this->services['view']->render('admin/finance/control/soldes', ['balances' => [['id' => 1, 'name' => 'Caisse', 'count' => 2, 'displayed' => 500, 'recomputed' => 300, 'gap' => 200, 'ok' => false]],
            'anomalies' => [], 'user' => ['name' => 'x']]);
        $this->assertStringContainsString('Écart de solde détecté', $ko);
        $this->assertStringContainsString('KO', $ko);
    }

    public function test_anomaly_detection_lists_corrupted_rows(): void
    {
        $this->assertSame([], array_filter($this->anomalies()));
        $member = (int) $this->db->fetchValue('SELECT id FROM members LIMIT 1') ?: $this->db->insert('members', ['member_number' => 'M-ANO-1', 'last_name' => 'Ano', 'first_names' => 'Ly']);

        $p = $this->db->insert('payments', ['reference' => 'PAY-ANO-' . bin2hex(random_bytes(3)), 'member_id' => $member, 'amount' => '100.00', 'method' => 'especes', 'paid_at' => date('Y-m-d H:i:s'), 'idempotency_key' => bin2hex(random_bytes(8))]);
        $paidDraft = $this->draft();
        $this->db->update('disbursement_requests', ['status' => 'paye'], ['id' => $paidDraft]);
        $unpaid = $this->submitted();
        $orphanTx = $this->tx('depense', '50.00', 'disbursement', $unpaid);
        $this->db->insert('disbursement_approvals', ['disbursement_request_id' => $unpaid, 'revision' => 1, 'approver_id' => $this->initiator, 'decision' => 'approve']);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0'); // l'application ne peut pas produire ces deux cas : la base les refuse normalement
        $r = $this->db->insert('receipts', ['payment_id' => 987654, 'receipt_number' => 'REC-ANO-1', 'issued_at' => date('Y-m-d H:i:s')]);
        $orphanRev = $this->tx('recette', '5.00', null, null, 876543);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $base = $this->tx('depense', '10.00');
        $bad = $this->tx('recette', '99.00', null, null, $base); // montant différent de l'origine

        $a = $this->anomalies();
        $this->assertSame([$p], $a['payment_no_tx']);
        $this->assertSame([$paidDraft], $a['paid_no_tx']);
        $this->assertSame([$orphanTx], $a['tx_unpaid']);
        $this->assertSame([$r], $a['receipt_no_payment']);
        $this->assertSame([$unpaid], $a['approval_by_initiator']);
        $this->assertSame([$paidDraft], $a['paid_few_approvers']);
        $this->assertSame([$orphanRev], $a['reversal_orphan']);
        $this->assertSame([$bad], $a['reversal_mismatch']);

        $this->as($this->controller);
        $page = $this->request('GET', '/admin/controle/soldes')->body();
        $this->assertStringContainsString('Anomalies détectées', $page);
        $this->assertStringContainsString('href="/admin/controle/decaissements/' . $paidDraft . '"', $page);
        $this->assertStringContainsString('Reçu sans paiement', $page);
    }

    public function test_proof_download_goes_through_existing_binding_and_is_logged(): void
    {
        $id = $this->approved();
        $other = $this->approved();
        $this->as($this->payer);
        $pay = $this->request('GET', "/admin/decaissements/$id/payer")->body();
        preg_match_all('/<input type="hidden" name="(key|seen_revision|seen_amount)" value="([^"]*)"/', $pay, $m);
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/payer", ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'external_ref' => '', 'confirm' => '1'] + array_combine($m[1], $m[2]), ['preuve' => $this->pdf()])->status());
        $proof = (int) $this->db->fetchValue('SELECT proof_file_id FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]);
        $this->db->query('DELETE FROM sensitive_access_logs');

        $this->as($this->controller);
        $this->assertStringContainsString("/admin/decaissements/$id/pieces/$proof", $this->request('GET', "/admin/controle/decaissements/$id")->body());
        $dl = $this->request('GET', "/admin/decaissements/$id/pieces/$proof");
        $this->assertSame(200, $dl->status());
        $this->assertStringStartsWith('%PDF-', $dl->body());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE user_id = ? AND subject_type = 'disbursement_request' AND subject_id = ? AND field_group = 'preuve_paiement'", [$this->controller, $id]));
        // Fichier d'une autre demande : refusé (404), rien de journalisé ; la consultation de la liste n'est pas journalisée.
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$other/pieces/$proof")->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
    }

    private function realFile(): int
    {
        $name = bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($this->root . '/private/' . $name, "%PDF-1.4\n%%EOF");
        return $this->db->insert('files', ['path' => $name, 'original_name' => 'justif.pdf', 'mime' => 'application/pdf', 'size' => 14, 'sha256' => str_repeat('e', 64), 'uploaded_by' => $this->initiator]);
    }

    public function test_transaction_attachments_are_linked_logged_and_bound(): void
    {
        $t1 = $this->tx('recette', '10.00');
        $t2 = $this->tx('recette', '20.00');
        $f1 = $this->realFile();
        $f2 = $this->realFile();
        $this->db->insert('financial_attachments', ['attachable_type' => 'financial_transaction', 'attachable_id' => $t1, 'file_id' => $f1]);
        $this->db->insert('financial_attachments', ['attachable_type' => 'financial_transaction', 'attachable_id' => $t2, 'file_id' => $f2]);
        $this->db->query('DELETE FROM sensitive_access_logs');
        $this->as($this->controller); // rôle contrôleur : finance.view + finance.control
        $this->assertStringContainsString("/admin/finances/ecritures/$t1/justificatif/$f1", $this->request('GET', '/admin/controle?compte=' . $this->account)->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        $dl = $this->request('GET', "/admin/finances/ecritures/$t1/justificatif/$f1");
        $this->assertSame(200, $dl->status());
        $this->assertStringStartsWith('%PDF-', $dl->body());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE user_id = ? AND subject_type = 'financial_transaction' AND subject_id = ? AND field_group = 'justificatif'", [$this->controller, $t1]));
        $this->assertSame(404, $this->request('GET', "/admin/finances/ecritures/$t1/justificatif/$f2")->status()); // fichier d'une autre écriture
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        // finance.control seul (sans finance.view) suffit ; sans l'une ni l'autre : 403.
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['ctl_only', 'x']);
        $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', ['ctl_only', 'finance.control']);
        $this->as($this->createUser('co' . bin2hex(random_bytes(3)) . '@ctl.test', ['ctl_only']));
        $this->assertSame(200, $this->request('GET', "/admin/finances/ecritures/$t2/justificatif/$f2")->status());
        $this->as($this->createUser('no' . bin2hex(random_bytes(3)) . '@ctl.test', ['dec_init']));
        $this->assertSame(403, $this->request('GET', "/admin/finances/ecritures/$t2/justificatif/$f2")->status());
    }

    public function test_extra_integrity_checks_and_real_total_count(): void
    {
        $member = (int) $this->db->fetchValue('SELECT id FROM members LIMIT 1') ?: $this->db->insert('members', ['member_number' => 'M-ANO-2', 'last_name' => 'Ano', 'first_names' => 'Ly']);
        $txA = $this->tx('recette', '100.00');
        $pay = fn (?int $tx, string $amt, bool $cancelled = false) => $this->db->insert('payments', ['reference' => 'PAY-X-' . bin2hex(random_bytes(3)), 'member_id' => $member, 'amount' => $amt, 'method' => 'especes',
            'paid_at' => date('Y-m-d H:i:s'), 'financial_transaction_id' => $tx, 'idempotency_key' => bin2hex(random_bytes(8)), 'cancelled_at' => $cancelled ? date('Y-m-d H:i:s') : null]);
        $noReceipt = $pay($txA, '100.00');                       // actif, sans reçu
        $txB = $this->tx('recette', '50.00');
        $diff = $pay($txB, '60.00');                              // écriture 50 / paiement 60 (et sans reçu)
        $txC = $this->tx('recette', '70.00');
        $cancelled = $pay($txC, '70.00', true);                   // annulé, sans contre-passation
        $d = $this->approved();
        $this->payNow($d);
        $this->db->query("UPDATE financial_transactions SET amount = amount + 1 WHERE source_type = 'disbursement' AND source_id = ?", [$d]);
        $a = $this->anomalies();
        $this->assertContains($noReceipt, $a['payment_no_receipt']);
        $this->assertContains($diff, $a['payment_amount']);
        $this->assertNotContains($noReceipt, $a['payment_amount']);
        $this->assertSame([$cancelled], $a['cancelled_no_reversal']);
        $this->assertSame([$d], $a['disbursement_amount']);
        // Règle exigeant 3 approbations : la demande payée avec 2 est signalée (la règle code en dur 2 ne le voyait pas).
        $this->assertSame([], $a['paid_few_approvers']);
        $this->db->query('DELETE FROM approval_rules');
        $this->db->query('INSERT INTO approval_rules (min_amount, max_amount, approvals_required) VALUES (0, NULL, 3)');
        $this->assertSame([$d], $this->anomalies()['paid_few_approvers']);
        // Décompte réel au-delà de la liste affichée (50) et de l'ancien plafond (200).
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        for ($i = 0; $i < 260; $i++) {
            $this->tx('recette', '1.00', null, null, 900000 + $i);
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $row = array_values(array_filter((new ControlService($this->db, $this->audit(), $this->ledger()))->anomalies(), fn (array $x): bool => $x['key'] === 'reversal_orphan'))[0];
        $this->assertSame(260, $row['count']);
        $this->assertCount(50, $row['ids']);
    }

    public function test_xlsx_amount_is_written_as_exact_decimal(): void
    {
        $this->tx('recette', '9999999999999.99');
        $this->as($this->controller);
        $xlsx = $this->request('GET', '/admin/controle/export/ecritures.xlsx?compte=' . $this->account)->body();
        $zip = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($zip, $xlsx);
        $z = new \ZipArchive();
        $z->open($zip);
        $sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($zip);
        $this->assertStringContainsString('t="n"><v>9999999999999.99</v>', $sheet);
        $this->assertStringNotContainsString('E+', $sheet);
    }

    public function test_hostile_text_is_escaped(): void
    {
        $x = '<script>alert(1)</script>';
        $id = $this->submitted();
        $this->db->update('disbursement_requests', ['beneficiary_name' => $x, 'purpose' => '<img src=x onerror=alert(2)>', 'rejection_reason' => $x], ['id' => $id]);
        $this->db->update('disbursement_approvals', ['comment' => '<b>hostile</b>'], ['disbursement_request_id' => $id]);
        $this->db->update('financial_accounts', ['name' => '<i>Caisse</i>' . bin2hex(random_bytes(2))], ['id' => $this->account]);
        $this->tx('depense', '5.00');
        $this->as($this->controller);
        foreach (['/admin/controle', '/admin/controle/decaissements', "/admin/controle/decaissements/$id", '/admin/controle/soldes'] as $url) {
            $body = $this->request('GET', $url)->body();
            $this->assertStringNotContainsString($x, $body, $url);
            $this->assertStringNotContainsString('<img src=x', $body, $url);
            $this->assertStringNotContainsString('<i>Caisse', $body, $url);
        }
        $this->assertStringContainsString('&lt;script&gt;', $this->request('GET', "/admin/controle/decaissements/$id")->body());
    }
}
