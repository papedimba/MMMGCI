<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Audit;
use App\Core\View;
use App\Modules\Auth\Auth;

/** Refuse (403, audité) l'utilisateur courant qui n'a pas la permission ; à placer après RequireAuth. */
final class RequirePermission implements Middleware
{
    public function __construct(private Auth $auth, private string $permission, private View $view, private Audit $audit)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if ($this->auth->can($this->permission)) {
            return $next($r);
        }
        $this->audit->log($this->auth->id(), 'access.denied', 'permission', null, null,
            ['path' => $r->path(), 'permission' => $this->permission], null, $r->ip(), $r->header('User-Agent'));
        return new Response($this->view->render('errors/403', ['title' => 'Accès refusé']), 403);
    }
}
