<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\View;

return static function (Router $router, View $view, Csrf $csrf, callable $db): void {
    // Page d'attente, remplacée en L4.
    $router->get('/', static fn(Request $r): Response => new Response(
        $view->render('home', ['title' => 'MMMGCI', 'csrfToken' => $csrf->token()], 'layouts/public'),
    ));

    $router->get('/sante', static function (Request $r) use ($db): Response {
        try {
            $ok = (string) $db()->fetchValue('SELECT 1') === '1';
        } catch (Throwable) {
            $ok = false; // aucun détail exposé
        }
        return new Response($ok ? 'ok' : 'indisponible', $ok ? 200 : 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    });
};
