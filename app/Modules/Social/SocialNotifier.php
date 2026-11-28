<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Database;
use Closure;
use Throwable;

/** E-mails du circuit social : la référence AS- et un lien, rien d'autre (ni nom, ni type, ni description). Envoi différé, comme FormInbox. */
final class SocialNotifier
{
    /** @param Closure(): \App\Core\Mailer $mailer @param Closure(callable): void $defer exécute un travail après la réponse */
    public function __construct(private Database $db, private Closure $mailer, private Closure $defer, private string $appUrl = '')
    {
    }

    /** À appeler après la validation de la transaction ; un échec d'envoi est journalisé et n'annule rien. */
    public function assigned(int $requestId, int $assigneeId): void
    {
        $row = $this->db->fetch('SELECT r.reference, u.email FROM social_requests r JOIN users u ON u.id = ? AND u.is_active = 1 WHERE r.id = ?', [$assigneeId, $requestId]);
        if ($row === null || (string) $row['email'] === '') {
            return;
        }
        [$ref, $to] = [(string) $row['reference'], (string) $row['email']];
        $link = preg_match('#^https?://[^/\s]+#i', $this->appUrl) === 1 ? "\nConsulter : " . rtrim($this->appUrl, '/') . '/admin/demandes-sociales/' . $requestId : '';
        $body = "Une demande d'assistance sociale vous a été affectée.\n\nRéférence : $ref$link\n\nConnectez-vous à l'administration pour la consulter.";
        ($this->defer)(function () use ($to, $ref, $body): void {
            try {
                ($this->mailer)()->send($to, "Demande sociale affectée ($ref)", $body);
            } catch (Throwable $e) { // la classe seulement : le message pourrait citer l'adresse
                error_log('Notification d\'affectation non envoyée (' . $ref . ') : ' . get_class($e));
            }
        });
    }
}
