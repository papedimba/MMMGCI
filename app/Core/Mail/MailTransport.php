<?php
declare(strict_types=1);

namespace App\Core\Mail;

use RuntimeException;

final class MailTransport implements Transport
{
    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $from = $headers['From'] ?? '';
        $params = filter_var($from, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\s"\\\\]/', $from) ? '-f' . $from : '';
        if (!@mail($to, $subject, $body, implode("\r\n", $lines), $params)) {
            throw new RuntimeException("L'envoi de l'e-mail a échoué. Vérifiez les paramètres de messagerie de l'hébergement (cPanel) : adresse d'expédition et fonction mail() activée.");
        }
    }
}
