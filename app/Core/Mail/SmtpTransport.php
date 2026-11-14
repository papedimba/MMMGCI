<?php
declare(strict_types=1);

namespace App\Core\Mail;

use RuntimeException;

final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $user = '',
        private string $password = '',
        private bool $requireTls = true, // false only for the plain-text fake server in tests
    ) {
    }

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $from = $headers['From'] ?? '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $this->socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if ($this->socket === false) {
            $this->socket = null;
            throw new RuntimeException("Connexion au serveur SMTP {$this->host}:{$this->port} impossible ($errstr). Vérifiez SMTP_HOST et SMTP_PORT ; le port 465 (TLS implicite) n'est pas pris en charge, utilisez le port 587.");
        }
        stream_set_timeout($this->socket, 15);
        try {
            $this->expect(220, $this->read());
            $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            $advertised = preg_match('/^250[- ]STARTTLS\b/mi', $ehlo) === 1;
            if ($advertised || ($this->user === '' && $this->port === 587)) {
                $this->command('STARTTLS', 220);
                $this->enableTls();
                $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            } elseif ($this->user !== '' && $this->requireTls) {
                throw new RuntimeException("Le serveur de messagerie n'annonce pas de connexion chiffrée (STARTTLS) : refus d'envoyer le mot de passe en clair. Utilisez le port 587 ou vérifiez SMTP_HOST.");
            }
            if ($this->user !== '') {
                try {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode($this->user), 334);
                    $this->command(base64_encode($this->password), 235);
                } catch (RuntimeException $e) {
                    throw new RuntimeException($e->getMessage() . ' Vérifiez SMTP_USER et SMTP_PASS.');
                }
            }
            $this->command("MAIL FROM:<$from>", 250);
            $this->command("RCPT TO:<$to>", 250);
            $this->command('DATA', 354);
            $head = '';
            foreach (['To' => $to, 'Subject' => $subject] + $headers as $name => $value) {
                $head .= "$name: $value\r\n";
            }
            $data = $head . "\r\n" . $body;
            $data = preg_replace('/^\./m', '..', $data);
            // keep the blank header/body separator even for an empty body
            fwrite($this->socket, $data . (str_ends_with($data, "\r\n") ? '' : "\r\n") . ".\r\n");
            $this->expect(250, $this->read());
            $this->write('QUIT');
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function enableTls(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $ok = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            throw new RuntimeException("Connexion chiffrée impossible avec SMTP_HOST : le certificat ne correspond pas ou n'est pas valide. Utilisez le nom d'hôte du certificat (ex. mail.votredomaine.ci).");
        }
    }

    private function write(string $line): void
    {
        fwrite($this->socket, $line . "\r\n");
    }

    private function command(string $line, int $expected): string
    {
        $this->write($line);
        return $this->expect($expected, $this->read());
    }

    /** Reads a full (possibly multi-line) reply. */
    private function read(): string
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                return $reply;
            }
        }
        throw new RuntimeException('Le serveur SMTP ne répond plus (délai dépassé ou connexion fermée) : vérifiez SMTP_HOST et utilisez SMTP_PORT=587 ; le port 465 (TLS implicite) n\'est pas pris en charge.');
    }

    private function expect(int $code, string $reply): string
    {
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new RuntimeException('Réponse SMTP inattendue (code ' . substr($reply, 0, 3) . ', attendu ' . $code . ') : ' . trim($reply));
        }
        return $reply;
    }
}
