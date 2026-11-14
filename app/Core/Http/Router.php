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

    /**
     * Un middleware est un objet Middleware, un nom de classe (construit par la fabrique) ou une Closure
     * retournant un Middleware, appelée seulement quand la route correspond (dépendances paresseuses).
     */
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
        if (preg_match('/[\x00-\x1F\x7F]/', rawurldecode($r->path()))) {
            return ($this->errorRenderer)(400, $r);
        }
        $head = $r->method() === 'HEAD';
        $verb = $head ? 'GET' : $r->method();
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $middleware]) {
            if (!preg_match($regex, $r->path(), $m)) {
                continue;
            }
            if ($method !== $verb) {
                $allowed[] = $method;
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $r = $r->withAttribute($k, rawurldecode($v));
                }
            }
            $call = is_array($handler) ? [($this->factory)($handler[0]), $handler[1]] : $handler;
            $core = static fn(Request $req): Response => $call($req);
            $next = array_reduce(
                array_reverse($middleware),
                fn(callable $next, $mw) => fn(Request $req): Response => (match (true) {
                    is_string($mw) => ($this->factory)($mw),
                    $mw instanceof \Closure => $mw(), // construit à la requête : pas de connexion base au démarrage
                    default => $mw,
                })->handle($req, $next),
                $core,
            );
            $res = $next($r);
            return $head ? new Response('', $res->status(), $res->headers()) : $res;
        }
        if ($allowed) {
            return ($this->errorRenderer)(405, $r)->withHeader('Allow', implode(', ', array_unique($allowed)));
        }
        return ($this->errorRenderer)(404, $r);
    }

    private function add(string $method, string $pattern, callable|array $handler, array $middleware): self
    {
        $path = '/' . trim($this->prefix . $pattern, '/');
        // Littéraux échappés ; {nom} ou {nom:regex} (la regex peut contenir des quantificateurs {n,m}).
        $parts = preg_split('/(\\{\\w+(?::(?:[^{}]|\\{\\d+(?:,\\d*)?\\})+)?\\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $regex .= preg_quote($part, '#');
                continue;
            }
            [$name, $re] = explode(':', substr($part, 1, -1), 2) + [1 => '[^/]+'];
            $regex .= "(?P<$name>$re)";
        }
        $this->routes[] = [$method, '#^' . $regex . '$#D', $handler, [...$this->groupMiddleware, ...$middleware]];
        return $this;
    }

    private static function defaultError(int $status, Request $r): Response
    {
        $msg = match ($status) { 400 => 'Requête invalide', 405 => 'Méthode non autorisée', default => 'Page introuvable' };
        return new Response(
            "<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>$msg</title></head><body><h1>$msg</h1></body></html>",
            $status,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
