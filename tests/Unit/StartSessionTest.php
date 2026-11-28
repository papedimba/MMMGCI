<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\StartSession;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class StartSessionTest extends TestCase
{
    public function test_idle_session_is_cleared_and_active_one_is_touched(): void
    {
        $store = ['user' => 7, '_last_activity' => time() - 31 * 60];
        $mw = new StartSession(new Session($store, 30), false);
        $next = static fn (Request $r): Response => new Response('ok');

        $mw->handle(new Request('GET', '/'), $next);
        $this->assertArrayNotHasKey('user', $store);
        $this->assertGreaterThanOrEqual(time() - 1, $store['_last_activity']);

        $store['user'] = 8;
        $mw->handle(new Request('GET', '/'), $next);
        $this->assertSame(8, $store['user']);
    }

    public function test_only_listed_prefixes_open_a_session(): void
    {
        $store = [];
        $mw = new StartSession(new Session($store, 30), false, [], ['/admin', '/admin/', '/contact']);
        $next = static fn (Request $r): Response => new Response('ok');
        foreach (['/', '/statuts', '/administration', '/adminx', '/contact-presse', '/actualites/x'] as $p) {
            $mw->handle(new Request('GET', $p), $next);
            $this->assertArrayNotHasKey('_last_activity', $store, $p);
        }
        foreach (['/admin', '/admin/adherents', '/contact'] as $p) {
            $store = [];
            $mw->handle(new Request('GET', $p), $next);
            $this->assertArrayHasKey('_last_activity', $store, $p);
        }
    }

    public function test_skip_predicate_prevents_the_session(): void
    {
        $store = [];
        $closed = true;
        $mw = new StartSession(new Session($store, 30), false, [], ['/adhesion-en-ligne', '/adhesion-en-ligne/'], static function (Request $r) use (&$closed): bool {
            return $closed;
        });
        $next = static fn (Request $r): Response => new Response('ok');
        $mw->handle(new Request('GET', '/adhesion-en-ligne/etape/1'), $next);
        $this->assertArrayNotHasKey('_last_activity', $store);
        $closed = false;
        $mw->handle(new Request('GET', '/adhesion-en-ligne/etape/1'), $next);
        $this->assertArrayHasKey('_last_activity', $store);
    }

    public function test_staff_and_member_sessions_open_only_on_their_own_prefixes(): void
    {
        [$staffStore, $memberStore] = [[], []];
        $staff = new StartSession(new Session($staffStore, 30), false, [], ['/admin', '/admin/', '/adhesion-en-ligne', '/adhesion-en-ligne/', '/contact', '/partenariat']);
        $member = new StartSession(new Session($memberStore, 30, 'mmmgci_esp', '/espace'), false, [], ['/espace', '/espace/']);
        $next = static fn (Request $r): Response => new Response('ok');
        $run = static fn (string $p) => $staff->handle(new Request('GET', $p), static fn (Request $r): Response => $member->handle($r, $next));
        foreach (['/', '/espacex', '/espace-presse', '/actualites/espace'] as $p) {
            $run($p);
            $this->assertSame([[], []], [$staffStore, $memberStore], $p);
        }
        foreach (['/espace', '/espace/connexion'] as $p) {
            $memberStore = [];
            $run($p);
            $this->assertSame([], $staffStore, $p);
            $this->assertArrayHasKey('_last_activity', $memberStore, $p);
        }
        $memberStore = [];
        $run('/admin/connexion');
        $this->assertArrayHasKey('_last_activity', $staffStore);
        $this->assertSame([], $memberStore);
    }
}
