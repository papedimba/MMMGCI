<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\ForceHttps;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    private const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function test_headers_present_on_200_and_404(): void
    {
        $mw = new SecurityHeaders();
        foreach ([200, 404] as $status) {
            $next = static fn (Request $r): Response => new Response('', $status);
            $headers = $mw->handle(new Request('GET', '/'), $next)->headers();

            $this->assertSame(self::CSP, $headers['Content-Security-Policy']);
            $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
            $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
            $this->assertSame('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy']);
            $this->assertSame('DENY', $headers['X-Frame-Options']);
        }
    }

    public function test_hsts_only_over_https(): void
    {
        $mw = new SecurityHeaders();
        $next = static fn (Request $r): Response => new Response('ok');

        $http = $mw->handle(new Request('GET', '/'), $next)->headers();
        $this->assertArrayNotHasKey('Strict-Transport-Security', $http);

        $https = $mw->handle(new Request('GET', '/', [], [], [], ['HTTPS' => 'on']), $next)->headers();
        $this->assertSame('max-age=31536000', $https['Strict-Transport-Security']);
    }

    public function test_force_https_redirects_in_production_only(): void
    {
        $next = static fn (Request $r): Response => new Response('ok');
        $req = new Request('GET', '/membres', ['a' => '1'], [], [], ['HTTP_HOST' => 'x']);

        $res = (new ForceHttps('production'))->handle($req, $next);
        $this->assertSame(301, $res->status());
        $this->assertSame('https://x/membres?a=1', $res->headers()['Location']);

        foreach (['local', 'testing'] as $env) {
            $this->assertSame(200, (new ForceHttps($env))->handle($req, $next)->status(), $env);
        }
    }

    public function test_force_https_passes_secure_requests_through(): void
    {
        $next = static fn (Request $r): Response => new Response('ok');
        $req = new Request('GET', '/membres', [], [], [], ['HTTP_HOST' => 'x', 'HTTPS' => 'on']);

        $this->assertSame(200, (new ForceHttps('production'))->handle($req, $next)->status());
    }

    public function test_force_https_rejects_suspicious_host_without_redirect(): void
    {
        $next = static fn (Request $r): Response => new Response('ok');
        $mw = new ForceHttps('production');

        $missing = $mw->handle(new Request('GET', '/'), $next);
        $this->assertSame(400, $missing->status());
        $this->assertArrayNotHasKey('Location', $missing->headers());

        $evil = $mw->handle(new Request('GET', '/', [], [], [], ['HTTP_HOST' => 'evil.com/@x']), $next);
        $this->assertSame(400, $evil->status());
        $this->assertArrayNotHasKey('Location', $evil->headers());
    }
}
