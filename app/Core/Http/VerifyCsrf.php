<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Csrf;
use App\Core\View;

final class VerifyCsrf implements Middleware
{
    public function __construct(private readonly Csrf $csrf, private readonly View $view)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if ($r->method() !== 'POST') {
            return $next($r);
        }
        $t = $r->input('_csrf') ?? $r->header('X-CSRF-Token');
        if (is_string($t) && $this->csrf->check($t)) {
            return $next($r);
        }
        return new Response($this->view->render('errors/419'), 419);
    }
}
