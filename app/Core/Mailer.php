<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Mail\MailTransport;
use App\Core\Mail\SmtpTransport;
use App\Core\Mail\Transport;
use InvalidArgumentException;

final class Mailer
{
    public function __construct(private Transport $transport, private string $from)
    {
        self::assertAddress($from, 'expéditeur');
    }

    public static function fromConfig(Config $c): self
    {
        $from = (string) $c->get('MAIL_FROM', '');
        $transport = match ((string) $c->get('MAIL_DRIVER', 'mail')) {
            'smtp' => new SmtpTransport(
                (string) $c->get('SMTP_HOST', ''),
                (int) $c->get('SMTP_PORT', 587),
                (string) $c->get('SMTP_USER', ''),
                (string) $c->get('SMTP_PASS', ''),
            ),
            default => new MailTransport(),
        };
        return new self($transport, $from);
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        self::assertAddress($to, 'destinataire');
        if (preg_match('/[\r\n]/', $subject)) {
            throw new InvalidArgumentException("Le sujet de l'e-mail ne peut pas contenir de saut de ligne.");
        }
        $domain = substr((string) strrchr($this->from, '@'), 1);
        $headers = [
            'From' => $this->from,
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];
        $body = chunk_split(base64_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $textBody)), 76, "\r\n");
        $this->transport->send($to, mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"), $body, $headers);
    }

    private static function assertAddress(string $a, string $role): void
    {
        if (preg_match('/[\r\n]/', $a) || filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Adresse e-mail invalide pour le $role.");
        }
    }
}
