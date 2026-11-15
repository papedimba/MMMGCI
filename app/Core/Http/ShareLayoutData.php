<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\View;

/** Donne aux gabarits la page courante et un test « cette route GET existe », pour que le menu ne pointe jamais vers une page absente. */
final class ShareLayoutData implements Middleware
{
    public function __construct(private readonly View $view, private readonly Router $router)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        $this->view->share([
            'currentPath' => $r->path(),
            'routeExists' => function (string $href): bool {
                foreach ($this->router->routes() as $route) {
                    if ($route['method'] === 'GET' && $route['pattern'] === $href) {
                        return true;
                    }
                }
                return false;
            },
        ]);
        return $next($r);
    }
}
