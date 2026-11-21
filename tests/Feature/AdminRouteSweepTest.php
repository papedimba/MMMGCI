<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\PermissionGuard;
use App\Core\Http\RequireAuth;
use App\Core\Http\Response;
use Tests\Support\AdminTestCase;

/** Balaie les routes réelles de l'application : couvre automatiquement celles des lots suivants. */
final class AdminRouteSweepTest extends AdminTestCase
{
    private const OPEN = ['/admin/connexion', '/admin/mot-de-passe-oublie', '/admin/reinitialiser/{token:[a-f0-9]{64}}'];
    private const LOGIN_ONLY = ['GET /admin', 'POST /admin/deconnexion', 'GET /admin/mon-compte/mot-de-passe', 'POST /admin/mon-compte/mot-de-passe'];

    /** @return list<array{method: string, pattern: string, permission: ?string, alternatives: list<string>}> routes /admin à protéger */
    private function protectedRoutes(): array
    {
        $this->app();
        $routes = [];
        foreach ($this->services['router']->routes() as $r) {
            if (!str_starts_with($r['pattern'], '/admin') || in_array($r['pattern'], self::OPEN, true)) {
                continue;
            }
            $guards = array_values(array_filter($r['middleware'], static fn ($m) => $m instanceof PermissionGuard));
            $routes[] = ['method' => $r['method'], 'pattern' => $r['pattern'], 'permission' => $guards[0]->permission ?? null, 'alternatives' => $guards[0]->alternatives ?? []];
        }
        return $routes;
    }

    private static function concrete(string $pattern): string
    {
        return preg_replace('/\{\w+(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}/', '1', $pattern);
    }

    private function probe(): void
    {
        $this->app();
        $this->services['router']->get('/admin/sonde', static fn (): Response => new Response('secret'), [
            new RequireAuth($this->services['auth']()), new PermissionGuard('users.manage', $this->services['auth'], $this->services['view'], $this->services['audit']),
        ]);
    }

    public function test_every_admin_route_declares_a_permission_or_is_whitelisted(): void
    {
        $missing = [];
        foreach ($this->protectedRoutes() as $r) {
            if ($r['permission'] === null && !in_array("{$r['method']} {$r['pattern']}", self::LOGIN_ONLY, true)) {
                $missing[] = "{$r['method']} {$r['pattern']}";
            }
        }
        $this->assertSame([], $missing, 'Routes /admin sans RequirePermission ni liste blanche');
    }

    public function test_guest_is_redirected_on_every_protected_admin_route(): void
    {
        foreach ($this->protectedRoutes() as $r) {
            $res = $this->request($r['method'], self::concrete($r['pattern']));
            $this->assertSame(302, $res->status(), "{$r['method']} {$r['pattern']}");
            $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location']);
        }
    }

    public function test_user_without_permission_gets_403_on_every_protected_route(): void
    {
        $this->actingAs($this->createUser('lecteur@example.test', ['lecture_seule']));
        $this->probe(); // garantit au moins une route à permission refusée tant que routes/admin.php n'en déclare pas
        $gate = $this->services['gate']();
        $uid = $this->services['auth']()->id();
        foreach ($this->protectedRoutes() as $r) {
            if ($r['permission'] === null || array_filter([$r['permission'], ...$r['alternatives']], static fn (string $p): bool => $gate->allows($uid, $p)) !== []) {
                continue;
            }
            $this->assertSame(403, $this->request($r['method'], self::concrete($r['pattern']))->status(), "{$r['method']} {$r['pattern']}");
        }
    }

    public function test_user_without_roles_gets_403_on_every_permission_route(): void
    {
        $this->actingAs($this->createUser('sansrole@example.test', []));
        $this->probe();
        $checked = 0;
        foreach ($this->protectedRoutes() as $r) {
            if ($r['permission'] === null) {
                continue;
            }
            $this->assertSame(403, $this->request($r['method'], self::concrete($r['pattern']))->status(), "{$r['method']} {$r['pattern']}");
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_denied_access_is_audited(): void
    {
        $uid = $this->createUser('lecteur2@example.test', ['lecture_seule']);
        $this->actingAs($uid);
        $this->probe();

        $this->assertSame(403, $this->request('GET', '/admin/sonde')->status());
        $row = $this->db->fetch("SELECT user_id, new_values FROM audit_logs WHERE action = 'access.denied' ORDER BY id DESC LIMIT 1");
        $this->assertSame($uid, (int) $row['user_id']);
        $this->assertSame(['path' => '/admin/sonde', 'permission' => 'users.manage'], json_decode($row['new_values'], true));

        $this->actingAs($this->createUser('admin@example.test', ['super_admin']));
        $this->assertSame(200, $this->request('GET', '/admin/sonde')->status());
    }
}
