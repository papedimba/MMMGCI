<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Espace\LoginController;
use App\Modules\Espace\RequireMember;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

final class EspaceSessionIsolationTest extends AdminTestCase
{
    use EspaceFixtures;

    private const ADMIN_OPEN = ['/admin/connexion', '/admin/mot-de-passe-oublie', '/admin/reinitialiser/{token:[a-f0-9]{64}}'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
    }

    private static function concrete(string $pattern): string
    {
        return preg_replace('/\{\w+(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}/', '1', $pattern);
    }

    public function test_staff_session_list_of_prefixes_is_unchanged(): void
    {
        $kernel = (string) file_get_contents(dirname(__DIR__, 2) . '/app/kernel.php');
        $this->assertStringContainsString("new StartSession(\$session, \$httpSession, [], ['/admin', '/admin/', '/adhesion-en-ligne', '/adhesion-en-ligne/', '/contact', '/partenariat'],", $kernel);
        $this->assertStringContainsString("new StartSession(\$memberSession, \$httpSession, [], ['/espace', '/espace/']),", $kernel);
        $this->assertSame(2, substr_count($kernel, 'new StartSession('));
        // Chaque monde ne touche que sa propre session.
        $this->request('GET', '/espace/connexion');
        $this->assertNotNull($this->services['member_session']->get('_last_activity'));
        $this->assertNull($this->services['session']->get('_last_activity'));
        $this->forgetApp();
        $this->request('GET', '/admin/connexion');
        $this->assertNotNull($this->services['session']->get('_last_activity'));
        $this->assertNull($this->services['member_session']->get('_last_activity'));
    }

    public function test_member_session_never_opens_admin(): void
    {
        $ids = $this->memberWithAccount();
        $this->espaceLogin($this->memberNumber($ids['member']));
        $this->assertSame($ids['account'], $this->loggedAccount());
        $count = 0;
        foreach ($this->services['router']->routes() as $r) {
            if (!str_starts_with($r['pattern'], '/admin') || in_array($r['pattern'], self::ADMIN_OPEN, true)) {
                continue;
            }
            $res = $this->request($r['method'], self::concrete($r['pattern']));
            $case = "{$r['method']} {$r['pattern']}";
            $this->assertContains($res->status(), [302, 403], $case);
            if ($res->status() === 302) {
                $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location'], $case);
            }
            $count++;
        }
        $this->assertGreaterThan(50, $count);
        $this->assertSame($ids['account'], $this->loggedAccount(), 'la session adhérent est restée intacte');
    }

    public function test_staff_session_never_opens_espace(): void
    {
        $this->app();
        $this->services['router']->get('/espace/sonde', static fn (): Response => new Response('secret'), [$this->services['require_member']]);
        $admin = $this->createUser('super-espace@x.test', ['super_admin']);
        $this->actingAs($admin);
        $this->assertSame(200, $this->request('GET', '/admin')->status());
        $protected = array_filter($this->services['router']->routes(), static fn (array $r): bool => (bool) array_filter($r['middleware'], static fn ($m) => $m instanceof RequireMember));
        $this->assertGreaterThanOrEqual(3, count($protected));
        foreach ($protected as $r) {
            $path = self::concrete($r['pattern']);
            $res = $r['method'] === 'GET' ? $this->request('GET', $path) : $this->espacePost($path);
            $this->assertSame(302, $res->status(), "{$r['method']} {$r['pattern']}");
            $this->assertStringStartsWith('/espace/connexion', $res->headers()['Location']);
        }
        // Toute route /espace autre que la connexion est protégée.
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/espace') && $r['pattern'] !== '/espace/connexion') {
                $this->assertContains($r, $protected, "{$r['method']} {$r['pattern']} sans RequireMember");
            }
        }
    }

    public function test_staff_account_linked_to_member_is_not_a_member_account(): void
    {
        $staff = $this->createUser('agent.marin@x.test', ['super_admin']);
        $member = $this->db->insert('members', ['member_number' => 'AGENT-' . getmypid(), 'last_name' => 'Agent', 'first_names' => 'Marin', 'email' => 'agent.marin@x.test']);
        $this->db->query('UPDATE users SET member_id = ? WHERE id = ?', [$member, $staff]);
        foreach (['agent.marin@x.test', 'AGENT-' . getmypid()] as $identifier) {
            $res = $this->espaceLogin($identifier, 'phrase secrète longue 2026');
            $this->assertStringContainsString(LoginController::FAILED, $res->body(), $identifier);
            $this->assertNull($this->loggedAccount());
        }
        // Même avec un compte adhérent, le mot de passe du personnel ne l'ouvre pas, et la session du personnel ne compte pas.
        $this->db->insert('member_accounts', ['member_id' => $member, 'status' => 'actif', 'created_by' => $staff, 'password' => \App\Modules\Auth\PasswordPolicy::hash('autre phrase adhérent 2026')]);
        $this->actingAs($staff);
        $this->assertStringContainsString(LoginController::FAILED, $this->espaceLogin('agent.marin@x.test', 'phrase secrète longue 2026')->body());
        $this->assertSame(302, $this->request('GET', '/espace')->status());
    }

    public function test_csrf_token_of_one_world_is_refused_in_the_other(): void
    {
        $ids = $this->memberWithAccount();
        $this->app();
        $staff = $this->services['csrf']->token();
        $member = $this->services['member_csrf']->token();
        $this->assertNotSame($staff, $member);
        $res = $this->request('POST', '/espace/connexion', ['_csrf' => $staff, 'identifiant' => $this->memberNumber($ids['member']), 'password' => self::MEMBER_PASSWORD]);
        $this->assertSame(419, $res->status());
        $this->assertNull($this->loggedAccount());
        $this->assertSame(419, $this->request('POST', '/admin/connexion', ['_csrf' => $member, 'email' => 'x@x.test', 'password' => 'y'])->status());
        $this->assertSame(302, $this->request('POST', '/espace/connexion', ['_csrf' => $member, 'identifiant' => $this->memberNumber($ids['member']), 'password' => self::MEMBER_PASSWORD])->status());
        // Connecté : le jeton d'avant connexion ne vaut plus, celui du personnel non plus.
        $this->assertSame(419, $this->request('POST', '/espace/deconnexion', ['_csrf' => $member])->status());
        $this->assertSame(419, $this->request('POST', '/espace/deconnexion', ['_csrf' => $staff])->status());
        $this->assertSame($ids['account'], $this->loggedAccount());
    }

    public function test_session_id_regenerated_on_login(): void
    {
        $ids = $this->memberWithAccount();
        $dir = sys_get_temp_dir() . '/mmmgci-esp-' . uniqid();
        mkdir($dir);
        try {
            $out = shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__) . '/Support/espace_session_worker.php',
                $this->memberNumber($ids['member']), self::MEMBER_PASSWORD, $dir])) . ' 2>&1');
            $r = json_decode((string) $out, true);
            $this->assertIsArray($r, (string) $out);
            $this->assertSame(302, $r['status']);
            $this->assertSame($ids['account'], $r['account']);
            $this->assertNotSame('', $r['before']);
            $this->assertNotSame($r['before'], $r['after'], 'nouvel identifiant de session à la connexion');
            $this->assertFileDoesNotExist("$dir/sess_{$r['before']}", 'l\'ancienne session est détruite');
            $this->assertSame('mmmgci_esp', $r['name']);
            $this->assertSame(['/espace', true, 'Lax', 0], [$r['cookie']['path'], $r['cookie']['httponly'], $r['cookie']['samesite'], $r['cookie']['lifetime']]);
            $this->assertNull($r['staff'], 'aucune session du personnel ouverte sous /espace');
        } finally {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function test_unknown_espace_path_is_router_404_not_cms(): void
    {
        $this->app();
        $router = $this->services['router'];
        foreach (['/espace/inconnu', '/espace/x/y', '/espace/accueil'] as $p) {
            $this->assertFalse($router->has('GET', $p), $p);
        }
        $this->assertTrue($router->has('GET', '/espacex'), 'l\'attrape-tout couvre toujours /espacex');
        $res = $this->request('GET', '/espace/inconnu');
        $this->assertSame(404, $res->status());
        $this->assertStringContainsString('Page introuvable', $res->body());
        $this->assertSame(405, $this->request('POST', '/espace')->status(), 'GET seul : 405 du routeur, pas l\'attrape-tout');
    }
}
