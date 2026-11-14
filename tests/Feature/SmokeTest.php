<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    private static function app(array $overrides = []): App
    {
        $env = $overrides + ['APP_ENV' => 'testing'] + Env::load(dirname(__DIR__, 2) . '/.env');
        return (require dirname(__DIR__, 2) . '/app/kernel.php')(new Config($env), false);
    }

    public function test_home_returns_200_with_security_headers_and_csrf_cookie(): void
    {
        $res = self::app()->handle(new Request('GET', '/'));
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Socle MMMGCI opérationnel', $res->body());
        $this->assertArrayHasKey('Content-Security-Policy', $res->headers());
        $this->assertSame('nosniff', $res->headers()['X-Content-Type-Options']);

        // Cookie de session (porteur du jeton CSRF) : seulement observable via un vrai serveur HTTP.
        $port = random_int(20000, 40000);
        $root = dirname(__DIR__, 2);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            $headers = [];
            for ($i = 0; $i < 50 && $headers === []; $i++) {
                usleep(100_000);
                $headers = @get_headers("http://127.0.0.1:$port/") ?: [];
            }
            $all = implode("\n", $headers);
            $this->assertStringContainsString('200 OK', $headers[0] ?? '');
            $this->assertMatchesRegularExpression('/^Set-Cookie: mmmgci_sid=\w+;.*HttpOnly; SameSite=Lax/mi', $all);
            $this->assertStringContainsString('Content-Security-Policy:', $all);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function test_health_endpoint_reports_database(): void
    {
        $ok = self::app()->handle(new Request('GET', '/sante'));
        $this->assertSame(200, $ok->status());
        $this->assertSame('ok', $ok->body());

        $app = self::app(['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1']);
        $down = $app->handle(new Request('GET', '/sante'));
        $this->assertSame(503, $down->status());
        $this->assertSame('indisponible', $down->body());
        // Base injoignable : les autres routes et les pages d'erreur restent servies.
        $this->assertSame(200, $app->handle(new Request('GET', '/'))->status());
        $this->assertSame(404, $app->handle(new Request('GET', '/xyz'))->status());
    }

    public function test_post_to_home_without_token_is_419(): void
    {
        $app = self::app();
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
            $res = $app->handle(new Request($m, '/'));
            $this->assertSame(419, $res->status(), $m);
            $this->assertStringContainsString('<title>Session expirée</title>', $res->body());
        }
    }

    public function test_error_pages_use_layout_with_french_titles(): void
    {
        $app = self::app();
        $cases = [
            [new Request('GET', '/xyz'), 404, 'Page introuvable'],
            [new Request('GET', "/a%00b"), 400, 'Requête invalide'],
            [new Request('POST', '/sante', [], ['_csrf' => self::token($app)]), 405, 'Méthode non autorisée'],
        ];
        foreach ($cases as [$req, $status, $title]) {
            $res = $app->handle($req);
            $this->assertSame($status, $res->status(), $title);
            $this->assertStringContainsString("<title>$title</title>", $res->body());
            $this->assertStringContainsString('<html lang="fr">', $res->body());
        }
    }

    /** Le jeton vit dans la session de l'App (tableau local en test) : on le lit via la page d'accueil. */
    private static function token(App $app): string
    {
        preg_match('/name="csrf-token" content="(\w+)"/', $app->handle(new Request('GET', '/'))->body(), $m);
        return $m[1] ?? '';
    }
}
