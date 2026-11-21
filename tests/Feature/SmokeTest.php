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
        $this->assertStringContainsString('Le site de la Mutuelle est en cours de construction', $res->body());
        $this->assertArrayHasKey('Content-Security-Policy', $res->headers());
        $this->assertSame('nosniff', $res->headers()['X-Content-Type-Options']);

        // Cookie de session (porteur du jeton CSRF) : seulement observable via un vrai serveur HTTP.
        self::serve(dirname(__DIR__, 2) . '/public', function (int $port): void {
            [$headers] = self::fetch($port, '/');
            $all = implode("\n", $headers);
            $this->assertStringContainsString('200 OK', $headers[0]);
            $this->assertMatchesRegularExpression('/^Set-Cookie: mmmgci_sid=\w+;.*HttpOnly; SameSite=Lax/mi', $all);
            $this->assertStringContainsString('Content-Security-Policy:', $all);
            $this->assertStringNotContainsStringIgnoringCase('X-Powered-By', $all);

            // Sonde de santé : aucune session ouverte, donc aucun cookie.
            [$headers, $body] = self::fetch($port, '/sante');
            $this->assertSame('ok', $body);
            $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', implode("\n", $headers));
        });
    }

    public function test_exception_while_booting_is_logged_and_renders_french_500_with_security_headers(): void
    {
        $root = dirname(__DIR__, 2);
        $copy = sys_get_temp_dir() . '/mmmgci-boot-' . uniqid();
        mkdir($copy);
        shell_exec('cp -r ' . implode(' ', array_map(static fn(string $d): string => escapeshellarg("$root/$d"), ['app', 'routes', 'public'])) . ' ' . escapeshellarg($copy));
        file_put_contents("$copy/.env", "APP_ENV=local\nAPP_DEBUG=false\n");
        file_put_contents("$copy/routes/web.php", '<?php throw new RuntimeException("boot-secret-detail");');
        try {
            // display_errors=1 côté serveur : le gestionnaire doit quand même masquer les détails.
            self::serve("$copy/public", function (int $port) use ($copy): void {
                [$headers, $body] = self::fetch($port, '/');
                $all = implode("\n", $headers);
                $this->assertStringContainsString('500', $headers[0]);
                $this->assertStringContainsString('Une erreur est survenue', $body);
                $this->assertStringNotContainsString('boot-secret-detail', $body);
                $this->assertStringNotContainsString('#0', $body);
                $this->assertStringContainsString('Content-Security-Policy:', $all);
                $this->assertStringContainsString('X-Frame-Options: DENY', $all);
                $this->assertStringNotContainsStringIgnoringCase('X-Powered-By', $all);
                $this->assertStringContainsString('boot-secret-detail', (string) @file_get_contents("$copy/storage/logs/app-" . date('Y-m-d') . '.log'));
            }, ['-d', 'display_errors=1']);
        } finally {
            shell_exec('rm -rf ' . escapeshellarg($copy));
        }
    }

    /** @param list<string> $phpArgs */
    private static function serve(string $docroot, callable $probe, array $phpArgs = []): void
    {
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, ...$phpArgs, '-S', "127.0.0.1:$port", '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100_000);
            }
            $probe($port);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /** @return array{list<string>, string} en-têtes (ligne de statut en premier) et corps, quel que soit le statut */
    private static function fetch(int $port, string $path): array
    {
        $body = (string) @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        return [$http_response_header ?? [''], $body];
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
