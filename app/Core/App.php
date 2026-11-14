<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;

final class App
{
    /** @param list<Middleware> $globalMiddleware */
    public function __construct(private Config $config, private Router $router, private array $globalMiddleware = [])
    {
    }

    public function config(): Config { return $this->config; }

    public function handle(Request $r): Response
    {
        $next = array_reduce(
            array_reverse($this->globalMiddleware),
            static fn(callable $next, Middleware $mw) => static fn(Request $req): Response => $mw->handle($req, $next),
            fn(Request $req): Response => $this->router->dispatch($req),
        );
        return $next($r);
    }
}
