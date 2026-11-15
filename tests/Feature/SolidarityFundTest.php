<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Modules\Disbursements\DisbursementTransitionInvalid;
use App\Modules\Finance\AccountService;
use App\Modules\Solidarity\SolidarityService;
use DateTimeImmutable;
use DomainException;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;

final class SolidarityFundTest extends AdminTestCase
{
    use DisbursementFixtures;

    private int $donCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCircuit('0.00');
        $this->db->query("UPDATE financial_accounts SET is_active = 0 WHERE type = 'solidarity_fund'");
        $this->donCategory = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Dons, legs et subventions'");
        foreach (['sol_view' => ['solidarity.view'], 'sol_export' => ['solidarity.view', 'reports.export'], 'sol_manage' => ['solidarity.view', 'solidarity.manage'], 'rep_only' => ['reports.export']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
    }

    /** Le compte de la fixture devient l'unique fonds actif. */
    private function makeFund(): void
    {
        $this->db->update('financial_accounts', ['type' => 'solidarity_fund'], ['id' => $this->account]);
    }

    private function receipt(int $cents, string $at = 'now'): int
    {
        return $this->ledger()->record($this->account, $this->donCategory, 'recette', $cents, new DateTimeImmutable($at), ['source_type' => 'ressource', 'description' => 'Don de Kouadio'], $this->initiator);
    }

    private function service(): SolidarityService
    {
        return new SolidarityService($this->db, $this->audit(), $this->ledger());
    }

    public function test_summary_balance_equals_entries_minus_disbursements(): void
    {
        $this->makeFund();
        $a = $this->receipt(2_000_000);
        $this->receipt(500_050, '-40 days');
        $this->ledger()->reverse($a, 'Erreur de saisie', $this->initiator); // contre-passation de recette : réduit les entrées, n'est pas un décaissement
        $this->receipt(3_000_000);
        $id = $this->approved(1_000_000);
        $this->payNow($id);
        $id2 = $this->approved(250_000);
        $this->payNow($id2);
        $tx2 = (int) $this->db->fetchValue("SELECT id FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id2]);
        $this->ledger()->reverse($tx2, 'Paiement annulé', $this->initiator); // contre-passation de décaissement : réduit les sorties

        $s = $this->service()->summary();
        $this->assertSame(3_500_050, $s['receipts_total']);
        $this->assertSame(1_000_000, $s['disbursed_total']);
        $this->assertSame(2_500_050, $s['balance']);
        $this->assertSame($s['balance'], $s['opening'] + $s['receipts_total'] - $s['disbursed_total']);
        $this->assertSame($s['balance'], $this->ledger()->balance($this->account));
        $this->assertCount(12, $s['by_month']);
        $this->assertSame($s['receipts_total'], array_sum(array_column($s['by_month'], 'receipts'))); // tout est dans la fenêtre de 12 mois
        $this->assertSame($s['disbursed_total'], array_sum(array_column($s['by_month'], 'disbursed')));
        $this->assertSame(date('Y-m'), end($s['by_month'])['month']);
    }

    public function test_only_one_active_solidarity_account(): void
    {
        $svc = new AccountService($this->db, $this->audit());
        $first = $svc->create('Fonds A ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator);
        try {
            $svc->create('Fonds B ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator);
            $this->fail('second fonds actif refusé');
        } catch (DomainException $e) {
            $this->assertStringContainsString('un seul', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1"));
        $svc->setActive($first, false, $this->initiator);
        $second = $svc->create('Fonds C ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator); // plus aucun actif : permis
        $this->expectException(DomainException::class);
        try {
            $svc->setActive($first, true, $this->initiator); // réactivation d'un second fonds actif refusée
        } finally {
            $this->assertSame([$second], array_map('intval', array_column($this->db->fetchAll("SELECT id FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1"), 'id')));
        }
    }

    public function test_single_fund_creation_is_serialised_across_connections(): void
    {
        $other = Database::connect(self::config(), test: true);
        $this->assertSame(1, (int) $other->fetchValue('SELECT GET_LOCK(?, 1)', ['mmmgci.solidarity-fund'])); // l'autre session est en train de créer le fonds
        try {
            (new AccountService($this->db, $this->audit(), 1))->create('Fonds X ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator);
            $this->fail('création concurrente refusée');
        } catch (DomainException $e) {
            $this->assertStringContainsString('réessayez', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1"));
        // L'autre session crée son fonds, libère le verrou : la nôtre voit son commit et se voit refuser.
        (new AccountService($other, $this->audit($other)))->create('Fonds Y ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator);
        $other->query('SELECT RELEASE_LOCK(?)', ['mmmgci.solidarity-fund']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('un seul');
        (new AccountService($this->db, $this->audit(), 1))->create('Fonds Z ' . bin2hex(random_bytes(3)), 'solidarity_fund', '', $this->initiator);
    }

    public function test_fund_cannot_be_debited_without_an_approved_disbursement(): void
    {
        $this->makeFund();
        $this->receipt(5_000_000);
        // Le grand livre refuse toute dépense hors circuit, sur le fonds comme ailleurs.
        foreach ([[], ['source_type' => 'ressource'], ['source_type' => 'contribution_call']] as $meta) {
            $this->refuses(fn () => $this->ledger()->record($this->account, $this->category, 'depense', 1000, new DateTimeImmutable('now'), $meta, $this->initiator), DomainException::class, 'circuit de décaissement');
        }
        // Le circuit exige les deux approbations avant tout paiement.
        $id = $this->submitted(1_000_000);
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class, 'soumise');
        $this->approveNow($id, $this->approverA);
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class, 'en cours d\'approbation');
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE account_id = ? AND direction = 'depense'", [$this->account]));
        $this->approveNow($id, $this->approverB);
        $this->payNow($id);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE account_id = ? AND direction = 'depense' AND source_type = 'disbursement'", [$this->account]));
        // Aucun code applicatif n'écrit au grand livre en dehors de Ledger ; le module Solidarité ne fait que lire.
        $root = dirname(__DIR__, 2) . '/app';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && $f->getFilename() !== 'Ledger.php') {
                $this->assertDoesNotMatchRegularExpression('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+financial_transactions|->(insert|update)\(\s*\'financial_transactions\'/i', (string) file_get_contents($f->getPathname()), $f->getPathname());
            }
        }
        $this->assertStringNotContainsString('->record(', (string) file_get_contents($root . '/Modules/Solidarity/SolidarityService.php') . (string) file_get_contents($root . '/Modules/Solidarity/SolidarityController.php'));
        // Aucune route /admin/solidarite* n'accepte autre chose que la création du compte (sans montant).
        $posts = array_filter($this->routes(), static fn (array $r): bool => $r['method'] === 'POST' && str_starts_with($r['pattern'], '/admin/solidarite'));
        $this->assertSame(['/admin/solidarite/compte'], array_values(array_map(static fn (array $r): string => $r['pattern'], $posts)));
    }

    /** @return list<array{method:string,pattern:string}> */
    private function routes(): array
    {
        $this->app();
        return array_map(static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern']], $this->services['router']->routes());
    }

    public function test_dashboard_has_no_invented_figures_when_empty(): void
    {
        $viewer = $this->createUser('v@sol.test', ['sol_view']);
        $this->actingAs($viewer);
        $res = $this->request('GET', '/admin/solidarite');
        $body = $res->body();
        $this->assertSame(200, $res->status());
        $this->assertSame(1, substr_count($body, '<h1'));
        $this->assertStringNotContainsString('style="', $body);
        $this->assertStringContainsString('en attente de validation par les instances', $body);
        $this->assertStringContainsString('Aucun compte de fonds', $body);
        $this->assertStringNotContainsString('XOF', $body);
        $this->assertStringNotContainsString('Créer le compte du fonds', $body); // lecteur seul : pas d'action
        $this->assertStringNotContainsString('Exporter', $body);
        $this->assertDoesNotMatchRegularExpression('/bénéficiaire|éligib|%/iu', $body);

        $manager = $this->createUser('m@sol.test', ['sol_manage']);
        $this->actingAs($manager);
        $this->assertStringContainsString('Créer le compte du fonds', $this->request('GET', '/admin/solidarite')->body());
        $this->assertSame(302, $this->request('POST', '/admin/solidarite/compte')->status());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1"));
        $this->request('POST', '/admin/solidarite/compte'); // seconde tentative : message clair, aucun doublon
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1"));
        $this->assertStringContainsString('un seul', $this->request('GET', '/admin/solidarite')->body());
    }

    public function test_dashboard_with_data(): void
    {
        $this->makeFund();
        $this->receipt(1_234_500);
        $id = $this->approved(500_000);
        $this->payNow($id);
        $this->actingAs($this->createUser('f@sol.test', ['sol_view', 'dec_init']));
        $body = $this->request('GET', '/admin/solidarite')->body();
        $this->assertStringContainsString("7\u{202F}345 XOF", $body);
        $this->assertStringContainsString('/admin/decaissements/' . $id, $body); // lien : le lecteur a une permission du circuit
        $this->assertStringNotContainsString('Veuve Kouassi', $body);
        $this->actingAs($this->createUser('p@sol.test', ['sol_view']));
        $this->assertStringNotContainsString('/admin/decaissements/', $this->request('GET', '/admin/solidarite')->body());
    }

    public function test_export_audited(): void
    {
        $this->makeFund();
        $this->receipt(1_000_000);
        $id = $this->approved(300_000);
        $this->payNow($id);
        $this->actingAs($this->createUser('e@sol.test', ['sol_export']));
        $before = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'solidarity.exported'");
        $res = $this->request('GET', '/admin/solidarite/export.csv');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('text/csv', $res->headers()['Content-Type']);
        $csv = $res->body();
        $this->assertStringContainsString('Référence', $csv);
        $this->assertStringContainsString('10000,00', $csv);
        $this->assertStringContainsString('Sortie', $csv);
        foreach (['Veuve Kouassi', 'Décaissement', 'Don de'] as $pii) { // ni bénéficiaire ni libellé libre
            $this->assertStringNotContainsString($pii, $csv);
        }
        $this->assertSame(200, $this->request('GET', '/admin/solidarite/export.xlsx')->status());
        $this->assertSame($before + 2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'solidarity.exported'"));
        $this->assertStringContainsString('Exporter en CSV', $this->request('GET', '/admin/solidarite')->body());
    }

    public function test_requires_solidarity_view(): void
    {
        $this->makeFund();
        $this->assertSame(302, $this->request('GET', '/admin/solidarite')->status());
        $this->actingAs($this->createUser('n@sol.test', ['rep_only'])); // reports.export seul, sans solidarity.view
        foreach (['/admin/solidarite', '/admin/solidarite/export.csv', '/admin/solidarite/export.xlsx'] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status(), $url);
        }
        $this->assertSame(403, $this->request('POST', '/admin/solidarite/compte')->status());
        $this->actingAs($this->createUser('w@sol.test', ['sol_view'])); // solidarity.view sans reports.export
        $this->assertSame(200, $this->request('GET', '/admin/solidarite')->status());
        $this->assertSame(403, $this->request('GET', '/admin/solidarite/export.csv')->status());
        $this->assertSame(403, $this->request('POST', '/admin/solidarite/compte')->status());
        $this->assertStringContainsString('Fonds de solidarité', $this->request('GET', '/admin')->body()); // entrée de menu
    }
}
