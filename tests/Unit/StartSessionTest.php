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
}
