<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Csrf;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\VerifyCsrf;
use App\Core\Session;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    public function test_post_without_token_gets_419_and_get_passes(): void
    {
        $store = [];
        $csrf = new Csrf(new Session($store, 30));
        $mw = new VerifyCsrf($csrf, new View(dirname(__DIR__, 2) . '/app/views'));
        $next = static fn (Request $r): Response => new Response('ok');

        $this->assertSame(200, $mw->handle(new Request('GET', '/'), $next)->status());

        $res = $mw->handle(new Request('POST', '/'), $next);
        $this->assertSame(419, $res->status());
        $this->assertStringContainsString('Votre session a expiré', $res->body());

        $t = $csrf->token();
        $this->assertSame(200, $mw->handle(new Request('POST', '/', [], ['_csrf' => $t]), $next)->status());
        $this->assertSame(200, $mw->handle(new Request('POST', '/', [], [], [], ['HTTP_X_CSRF_TOKEN' => $t]), $next)->status());
        $this->assertSame(419, $mw->handle(new Request('POST', '/', [], ['_csrf' => 'bad']), $next)->status());
    }
}
