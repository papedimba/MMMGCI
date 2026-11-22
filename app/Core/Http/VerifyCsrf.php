<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Csrf;
use App\Core\View;

final class VerifyCsrf implements Middleware
{
    /** @param Router|null $router si fourni : une requête sans route pour sa méthode passe (le routeur répond 404/405, aucun gestionnaire ne s'exécute) */
    public function __construct(private readonly Csrf $csrf, private readonly View $view, private readonly ?Router $router = null)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if (in_array($r->method(), ['GET', 'HEAD', 'OPTIONS'], true) || ($this->router !== null && !$this->router->has($r->method(), $r->path()))) {
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
