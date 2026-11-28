<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/** Exige une session adhérent valide (jamais une session d'administration) ; pose l'attribut « member ». */
final class RequireMember implements Middleware
{
    /** @param Closure(): MemberAuth $auth paresseux : aucune connexion base à l'enregistrement des routes */
    public function __construct(private Closure $auth)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        $member = ($this->auth)()->current();
        if ($member === null) {
            $back = $r->method() === 'GET' && str_starts_with($r->path(), '/espace/') ? '?retour=' . rawurlencode($r->path()) : '';
            return Response::redirect('/espace/connexion' . $back);
        }
        return $next($r->withAttribute('member', $member));
    }
}
