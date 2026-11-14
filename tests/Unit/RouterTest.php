<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function ok(): callable
    {
        return static fn(Request $r): Response => new Response('ok:' . ($r->attribute('id') ?? ''));
    }

    public function test_route_with_typed_param_matches_and_passes_param(): void
    {
        $r = (new Router())->get('/membres/{id:\d+}', $this->ok());
        $res = $r->dispatch(new Request('GET', '/membres/42'));
        $this->assertSame(200, $res->status());
        $this->assertSame('ok:42', $res->body());
    }

    public function test_typed_param_rejects_non_digits_with_404(): void
    {
        $r = (new Router())->get('/membres/{id:\d+}', $this->ok());
        $this->assertSame(404, $r->dispatch(new Request('GET', '/membres/abc'))->status());
    }

    public function test_wrong_method_returns_405_with_allow_header(): void
    {
        $r = (new Router())->get('/contact', $this->ok());
        $res = $r->dispatch(new Request('POST', '/contact'));
        $this->assertSame(405, $res->status());
        $this->assertSame('GET', $res->headers()['Allow']);
    }

    public function test_trailing_slash_is_normalized(): void
    {
        $r = (new Router())->get('/contact', $this->ok());
        $this->assertSame(200, $r->dispatch(new Request('GET', '/contact/'))->status());
    }

    public function test_group_applies_prefix_and_middleware_in_order(): void
    {
        $log = new \ArrayObject();
        $mw = fn(string $n) => new class($n, $log) implements Middleware {
            public function __construct(private string $n, private \ArrayObject $log) {}
            public function handle(Request $r, callable $next): Response { $this->log->append($this->n); return $next($r); }
        };
        $router = new Router();
        $router->group('/admin', [$mw('groupe')], function (Router $g) use ($mw): void {
            $g->get('/x', $this->ok(), [$mw('route')]);
        });
        $app = new \App\Core\App(new \App\Core\Config([]), $router, [$mw('global')]);
        $this->assertSame(200, $app->handle(new Request('GET', '/admin/x'))->status());
        $this->assertSame(['global', 'groupe', 'route'], $log->getArrayCopy());
    }

    public function test_unknown_path_returns_404_page(): void
    {
        $res = (new Router())->dispatch(new Request('GET', '/nope'));
        $this->assertSame(404, $res->status());
        $this->assertStringContainsString('Page introuvable', $res->body());
    }

    public function test_literal_dot_is_not_a_wildcard(): void
    {
        $r = (new Router())->get('/sitemap.xml', $this->ok());
        $this->assertSame(404, $r->dispatch(new Request('GET', '/sitemapXxml'))->status());
        $this->assertSame(200, $r->dispatch(new Request('GET', '/sitemap.xml'))->status());
    }

    public function test_trailing_newline_does_not_match_typed_param(): void
    {
        $r = (new Router())->get('/membres/{id:\d+}', $this->ok());
        foreach (["/membres/42\n", '/membres/42%0A'] as $path) {
            $this->assertNotSame(200, $r->dispatch(new Request('GET', $path))->status());
        }
    }

    public function test_brace_quantifier_in_param_regex(): void
    {
        $r = (new Router())->get('/c/{id:\d{2}}', $this->ok());
        $this->assertSame('ok:42', $r->dispatch(new Request('GET', '/c/42'))->body());
        $this->assertSame(404, $r->dispatch(new Request('GET', '/c/421'))->status());
    }

    public function test_double_leading_slash_is_not_treated_as_host(): void
    {
        $_SERVER['REQUEST_URI'] = '//admin/x';
        $this->assertSame('/admin/x', Request::fromGlobals()->path());
    }

    public function test_encoded_slash_stays_inside_param(): void
    {
        $r = (new Router())->get('/actualites/{slug}', static fn(Request $q): Response => new Response((string) $q->attribute('slug')));
        $res = $r->dispatch(new Request('GET', '/actualites/a%2Fb'));
        $this->assertSame(200, $res->status());
        $this->assertSame('a/b', $res->body());
    }

    public function test_control_characters_in_path_are_rejected(): void
    {
        $r = (new Router())->get('/actualites/{slug}', $this->ok());
        $this->assertSame(400, $r->dispatch(new Request('GET', '/actualites/a%00b'))->status());
    }

    public function test_query_string_is_stripped_from_path(): void
    {
        $_SERVER['REQUEST_URI'] = '/contact?a=1&b=/x';
        $this->assertSame('/contact', Request::fromGlobals()->path());
    }

    public function test_head_is_served_like_get_without_body(): void
    {
        $r = (new Router())->get('/contact', $this->ok());
        $res = $r->dispatch(new Request('HEAD', '/contact'));
        $this->assertSame(200, $res->status());
        $this->assertSame('', $res->body());
    }

    public function test_array_handler_is_built_by_factory(): void
    {
        $r = (new Router(fn(string $c) => new $c()))->get('/h', [RouterTestHandler::class, 'index']);
        $this->assertSame('handled', $r->dispatch(new Request('GET', '/h'))->body());
    }
}

final class RouterTestHandler
{
    public function index(Request $r): Response { return new Response('handled'); }
}
