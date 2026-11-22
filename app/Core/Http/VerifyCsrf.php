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
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];
        if (self::isAdmin($r->path())) {
            return new Response($this->view->render('errors/419', ['title' => 'Session expirée']), 419, $headers);
        }
        // Visiteur public (session expirée, envoi trop lourd pour post_max_size…) : gabarit public, retour au début de son formulaire.
        $path = $r->path();
        $back = match (true) {
            $path === '/adhesion-en-ligne' || str_starts_with($path, '/adhesion-en-ligne/') => ['/adhesion-en-ligne', 'Reprendre ma demande d\'adhésion'],
            $path === '/contact' => ['/contact', 'Revenir au formulaire de contact'],
            $path === '/partenariat' => ['/partenariat', 'Revenir au formulaire de partenariat'],
            default => ['/', 'Retour à l\'accueil'],
        };
        return new Response($this->view->render('public/pages/not-found', ['title' => 'Session expirée', 'noindex' => true, 'heading' => 'Session expirée',
            'message' => 'Votre session a expiré ou l\'envoi était trop volumineux : rien n\'a été enregistré. Reprenez depuis le début du formulaire.',
            'back' => $back[0], 'backLabel' => $back[1]], 'layouts/public'), 419, $headers);
    }

    /** Administration (et tout ce qui n'est pas le site public) : /admin et ses sous-chemins. */
    public static function isAdmin(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }
}
