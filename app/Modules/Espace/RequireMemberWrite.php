<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Csrf;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;

/** Écritures métier de l'Espace : accès complet seulement (placé après RequireMember) ; consultation seule → 403. */
final class RequireMemberWrite implements Middleware
{
    public function __construct(private View $view, private Csrf $csrf)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        $member = $r->attribute('member');
        if (is_array($member) && ($member['access'] ?? null) === 'complet') {
            return $next($r);
        }
        return new Response($this->view->render('espace/read-only', ['title' => 'Consultation seule', 'member' => is_array($member) ? $member : null,
            'csrfToken' => $this->csrf->token()], 'layouts/espace'), 403); // en-têtes no-store et noindex : app/kernel.php
    }
}
