<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Modules\Auth\Auth;

/** Exige une session d'administration valide ; sinon redirige vers la connexion (avec le chemin de retour en GET). */
final class RequireAuth implements Middleware
{
    public function __construct(private Auth $auth, private string $loginPath = '/admin/connexion')
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect($r->method() === 'GET' ? $this->loginPath . '?retour=' . rawurlencode($r->path()) : $this->loginPath);
        }
        return $next($r->withAttribute('user', $user));
    }
}
