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
        if (in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($r);
        }
        // Corps ou en-tête uniquement : un jeton dans l'URL fuirait dans les journaux et le Referer.
        $t = $r->post('_csrf') ?? $r->header('X-CSRF-Token');
        if (is_string($t) && $this->csrf->check($t)) {
            return $next($r);
        }
        return new Response($this->view->render('errors/419', ['title' => 'Session expirée']), 419, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
