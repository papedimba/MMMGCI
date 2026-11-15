<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class FinanceScreensTest extends AdminTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$users = null;
    }

    /** @var ?array{viewer: int, manager: int} */
    private static ?array $users = null;
    private int $viewer;
    private int $manager;
    private int $account;
    private int $exceptional;
    private int $don;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['fin_view' => ['finance.view'], 'fin_manage' => ['finance.view', 'finance.manage']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        // Hachage de mot de passe lent : les deux utilisateurs sont créés une seule fois pour la classe.
        self::$users ??= ['viewer' => $this->createUser('v@fin.test', ['fin_view']), 'manager' => $this->createUser('m@fin.test', ['fin_manage'])];
        [$this->viewer, $this->manager] = [self::$users['viewer'], self::$users['manager']];
        $n = bin2hex(random_bytes(3));
        $this->account = $this->db->insert('financial_accounts', ['name' => "Caisse $n", 'opening_balance' => '500.00']);
        $this->exceptional = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Contributions exceptionnelles'");
        $this->don = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Dons, legs et subventions'");
    }

    private function resource(array $over = []): array
    {
        return $over + ['account_id' => (string) $this->account, 'category_id' => (string) $this->don, 'amount' => '12 500,50', 'occurred_on' => '2026-03-10', 'description' => 'Don de test', 'authorization_ref' => ''];
    }

    public function test_screens_smoke(): void
    {
        $this->actingAs($this->manager);
        $tx = null;
        $this->assertSame(302, $this->request('POST', '/admin/finances/ressources', $this->resource())->status());
        $tx = (int) $this->db->fetchValue('SELECT id FROM financial_transactions WHERE account_id = ?', [$this->account]);
        foreach (['/admin/finances', "/admin/finances/comptes/{$this->account}", "/admin/finances/comptes/{$this->account}?du=2026-01-01&au=2026-12-31&categorie={$this->don}",
            '/admin/finances/categories', '/admin/finances/comptes/nouveau', '/admin/finances/ressources', "/admin/finances/ecritures/$tx/contre-passation"] as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(200, $res->status(), $url);
            $this->assertSame(1, substr_count($res->body(), '<h1'), $url);
            $this->assertStringNotContainsString('style="', $res->body(), $url);
        }
        $body = $this->request('GET', "/admin/finances/comptes/{$this->account}")->body();
        $this->assertStringContainsString("13\u{202F}000,50", $body); // 500 + 12 500,50 (espace fine insécable)
        $this->assertStringContainsString('Finances', $this->request('GET', '/admin')->body());
        $this->assertSame(404, $this->request('GET', '/admin/finances/comptes/999999')->status());

        // création de compte et de catégorie, puis contre-passation avec motif
        $this->assertSame(302, $this->request('POST', '/admin/finances/comptes/nouveau', ['name' => 'Banque ' . bin2hex(random_bytes(2)), 'type' => 'bank', 'opening' => '1 000'])->status());
        $this->assertSame(422, $this->request('POST', '/admin/finances/comptes/nouveau', ['name' => '', 'type' => 'bank', 'opening' => ''])->status());
        $this->assertSame(302, $this->request('POST', '/admin/finances/categories', ['name' => 'Cat ' . bin2hex(random_bytes(2)), 'direction' => 'recette'])->status());
        $this->assertSame(422, $this->request('POST', "/admin/finances/ecritures/$tx/contre-passation", ['reason' => ''])->status());
        $this->assertSame(302, $this->request('POST', "/admin/finances/ecritures/$tx/contre-passation", ['reason' => 'Erreur de saisie'])->status());
        $this->assertSame(422, $this->request('POST', "/admin/finances/ecritures/$tx/contre-passation", ['reason' => 'Encore'])->status());
        $this->assertSame(302, $this->request('POST', "/admin/finances/comptes/{$this->account}/activation", ['active' => '0'])->status());
        $this->assertSame(422, $this->request('POST', '/admin/finances/ressources', $this->resource())->status(), 'compte désactivé');
    }

    public function test_other_resource_requires_authorization_ref_for_exceptional_contribution(): void
    {
        $this->actingAs($this->manager);
        $res = $this->request('POST', '/admin/finances/ressources', $this->resource(['category_id' => (string) $this->exceptional]));
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('référence de son autorisation', html_entity_decode($res->body()));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$this->account]));
        $this->assertSame(302, $this->request('POST', '/admin/finances/ressources', $this->resource(['category_id' => (string) $this->exceptional, 'authorization_ref' => 'AG-2026-04']))->status());
        $this->assertSame('AG-2026-04', $this->db->fetchValue('SELECT authorization_ref FROM financial_transactions WHERE account_id = ?', [$this->account]));
        $this->assertSame('recette', $this->db->fetchValue('SELECT direction FROM financial_transactions WHERE account_id = ?', [$this->account]));
    }

    public function test_finance_view_cannot_write(): void
    {
        $this->actingAs($this->viewer);
        $this->assertSame(200, $this->request('GET', '/admin/finances')->status());
        $this->assertStringNotContainsString('Nouveau compte', $this->request('GET', '/admin/finances')->body());
        $tx = $this->db->insert('financial_transactions', ['reference' => 'TX-V-' . bin2hex(random_bytes(3)), 'account_id' => $this->account, 'category_id' => $this->don, 'direction' => 'recette', 'amount' => '1.00', 'occurred_at' => '2026-01-01 00:00:00']);
        foreach ([['GET', '/admin/finances/ressources'], ['POST', '/admin/finances/ressources'], ['GET', '/admin/finances/comptes/nouveau'], ['POST', '/admin/finances/comptes/nouveau'],
            ['POST', '/admin/finances/categories'], ['POST', "/admin/finances/comptes/{$this->account}/activation"], ['GET', "/admin/finances/ecritures/$tx/contre-passation"], ['POST', "/admin/finances/ecritures/$tx/contre-passation"]] as [$m, $u]) {
            $this->assertSame(403, $this->request($m, $u, $m === 'POST' ? $this->resource(['reason' => 'x', 'name' => 'x']) : [])->status(), "$m $u");
        }
        $this->assertStringNotContainsString('Contre-passer', $this->request('GET', "/admin/finances/comptes/{$this->account}")->body());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$this->account]));
    }

    public function test_hostile_labels_are_escaped(): void
    {
        $evil = '<script>alert(1)</script>';
        $this->db->update('financial_accounts', ['name' => $evil . bin2hex(random_bytes(2))], ['id' => $this->account]);
        $this->actingAs($this->manager);
        $this->request('POST', '/admin/finances/ressources', $this->resource(['description' => $evil, 'authorization_ref' => $evil]));
        $this->request('POST', '/admin/finances/categories', ['name' => $evil, 'direction' => 'recette']);
        foreach (['/admin/finances', "/admin/finances/comptes/{$this->account}", '/admin/finances/categories', '/admin/finances/ressources'] as $url) {
            $body = $this->request('GET', $url)->body();
            $this->assertStringNotContainsString('<script>alert(1)', $body, $url);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->request('GET', "/admin/finances/comptes/{$this->account}")->body());
    }

    public function test_direct_expense_route_does_not_exist(): void
    {
        $this->actingAs($this->manager);
        $this->app();
        foreach ($this->services['router']->routes() as $route) {
            if (str_starts_with($route['pattern'], '/admin/finances')) {
                $this->assertDoesNotMatchRegularExpression('/depense|decaiss|sortie|expense/i', $route['pattern'], $route['pattern']);
            }
        }
        // Aucun contrôleur n'appelle Ledger::record avec 'depense' ; la saisie de ressource impose 'recette'.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Finance/FinanceController.php');
        $this->assertDoesNotMatchRegularExpression("/record\\([^;]*'depense'/s", $src);
        $this->assertSame(1, preg_match_all('/->record\(/', $src));
        $this->assertStringContainsString("'recette'", $src);
        // Même en postant un sens, il est ignoré.
        $this->request('POST', '/admin/finances/ressources', $this->resource(['direction' => 'depense']));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE account_id = ? AND direction = 'depense'", [$this->account]));
    }
}
