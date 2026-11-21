<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;

/** Socle des écrans de la tâche 7 : rendu dans la mise en page d'administration, 404, identifiants, résumé d'erreurs. Les droits sont posés par routes/admin.php. */
abstract class CmsScreen
{
    public function __construct(protected View $view, protected Csrf $csrf, protected Session $session, protected Gate $gate)
    {
    }

    /** @param array<string,mixed> $data */
    protected function render(Request $r, string $view, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        return new Response($this->view->render($view, $data + [
            'user' => $user, 'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $this->session->pullFlash('status'),
        ], 'layouts/admin'), $status);
    }

    protected function found(callable $fn): Response
    {
        try {
            return $fn();
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }

    protected function id(Request $r): int
    {
        return (int) $r->attribute('id');
    }

    protected function actor(Request $r): int
    {
        return (int) $r->attribute('user')['id'];
    }

    protected function mayPublish(Request $r): bool
    {
        return $this->gate->allows($this->actor($r), 'cms.publish');
    }

    /** @param array<string,string> $errors @param array<string,string> $labels champ => libellé (lien vers le champ) */
    protected static function summary(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $list = '';
        foreach ($errors as $key => $msg) {
            $list .= '<li>' . ($key === 'form' ? e($msg) : '<a href="#f-' . e($key) . '">' . e($msg) . '</a>') . '</li>';
        }
        return '<div class="alert alert-danger" role="alert">' . ui_icon('alert') . '<div><strong class="alert-title">' . (count($errors) > 1 ? 'Corrigez les points suivants' : 'Corrigez le point suivant')
            . '</strong><ul class="error-list">' . $list . '</ul></div></div>';
    }
}
