<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Mailer;
use Closure;
use Throwable;

/** Envoi d'un lien contenant un jeton de réinitialisation (mot de passe oublié, invitation) : APP_URL vérifiée, échecs journalisés sans secret. */
final class LinkMailer
{
    /** @param Closure(): Mailer $mailer construit à l'envoi : une configuration e-mail invalide n'est qu'un échec d'envoi journalisé */
    public function __construct(private Closure $mailer, private string $appUrl, private bool $requireHttps)
    {
    }

    /** false (et erreur journalisée) si APP_URL ne permet pas d'envoyer un lien sûr. */
    public function ready(): bool
    {
        if (!preg_match('#^https?://[^/\s]+#i', $this->appUrl)) {
            error_log("APP_URL doit être une adresse absolue (ex. https://mmmgci.ci) : renseignez-la dans .env ; aucun lien de réinitialisation envoyé.");
            return false;
        }
        if ($this->requireHttps && !preg_match('#^https://#i', $this->appUrl)) { // un jeton envoyé en clair sur HTTP serait interceptable
            error_log("APP_URL doit commencer par https:// hors environnement local : corrigez-la dans .env ; aucun lien de réinitialisation envoyé.");
            return false;
        }
        return true;
    }

    /**
     * @param array{id: int|string, email: string} $user
     * @param Closure(string $link): string $body texte de l'e-mail autour du lien
     */
    public function send(array $user, string $token, string $subject, Closure $body): void
    {
        if (!$this->ready()) {
            return;
        }
        try {
            $link = rtrim($this->appUrl, '/') . '/admin/reinitialiser/' . $token;
            ($this->mailer)()->send($user['email'], $subject, $body($link));
        } catch (Throwable $e) {
            // Classe et utilisateur seulement : le message pourrait contenir le lien ou un secret SMTP.
            error_log('Lien de réinitialisation non envoyé (utilisateur #' . $user['id'] . ') : ' . get_class($e));
        }
    }
}
