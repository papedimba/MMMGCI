<?php
declare(strict_types=1);

namespace App\Core\Http;

final class Router
{
    /** @var list<array{string,string,callable|array,array,list<string>}> */
    private array $routes = [];
    private string $prefix = '';
    private array $groupMiddleware = [];
    private $factory;
    private $errorRenderer;

    public function __construct(?callable $factory = null, ?callable $errorRenderer = null)
    {
        $this->factory = $factory ?? static fn(string $c): object => new $c();
        $this->errorRenderer = $errorRenderer ?? self::defaultError(...);
    }

    public function get(string $pattern, callable|array $handler, array $middleware = []): self
    {
        return $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable|array $handler, array $middleware = []): self
    {
        return $this->add('POST', $pattern, $handler, $middleware);
    }

    public function group(string $prefix, array $middleware, callable $define): void
    {
        [$p, $m] = [$this->prefix, $this->groupMiddleware];
        $this->prefix .= $prefix;
        $this->groupMiddleware = [...$m, ...$middleware];
        $define($this);
        [$this->prefix, $this->groupMiddleware] = [$p, $m];
    }

    public function dispatch(Request $r): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
            if (!preg_match($regex, $r->path(), $m)) {
                continue;
            }
            if ($method !== $r->method()) {
                $allowed[] = $method;
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $r = $r->withAttribute($k, $v);
                }
            }
            $call = is_array($handler) ? [($this->factory)($handler[0]), $handler[1]] : $handler;
            $core = static fn(Request $req): Response => $call($req);
            $next = array_reduce(
                array_reverse($middleware),
                fn(callable $next, $mw) => fn(Request $req): Response => (is_string($mw) ? ($this->factory)($mw) : $mw)->handle($req, $next),
                $core,
            );
            return $next($r);
        }
        if ($allowed) {
            return ($this->errorRenderer)(405, $r)->withHeader('Allow', implode(', ', array_unique($allowed)));
        }
        return ($this->errorRenderer)(404, $r);
    }

    private function add(string $method, string $pattern, callable|array $handler, array $middleware): self
    {
        $path = '/' . trim($this->prefix . $pattern, '/');
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            static fn(array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path,
        );
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, [...$this->groupMiddleware, ...$middleware]];
        return $this;
    }

    private static function defaultError(int $status, Request $r): Response
    {
        $msg = $status === 405 ? 'Méthode non autorisée' : 'Page introuvable';
        return new Response(
            "<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>$msg</title></head><body><h1>$msg</h1></body></html>",
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
