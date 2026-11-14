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
    ) {
    }

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $from = $headers['From'] ?? '';
        $this->socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 15);
        if ($this->socket === false) {
            $this->socket = null;
            throw new RuntimeException("Connexion au serveur SMTP {$this->host}:{$this->port} impossible ($errstr). Vérifiez SMTP_HOST et SMTP_PORT.");
        }
        stream_set_timeout($this->socket, 15);
        try {
            $this->expect(220, $this->read());
            $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            if ($this->port === 587 || stripos($ehlo, 'STARTTLS') !== false) {
                $this->command('STARTTLS', 220);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (!stream_socket_enable_crypto($this->socket, true, $method)) {
                    throw new RuntimeException('Négociation TLS (STARTTLS) refusée par le serveur SMTP.');
                }
                $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            }
            if ($this->user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->user), 334);
                $this->command(base64_encode($this->password), 235);
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
            $this->write(rtrim($data, "\r\n") . "\r\n.");
            $this->expect(250, $this->read());
            $this->write('QUIT');
        } finally {
            fclose($this->socket);
            $this->socket = null;
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
        throw new RuntimeException('Le serveur SMTP ne répond plus (délai dépassé ou connexion fermée).');
    }

    private function expect(int $code, string $reply): string
    {
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new RuntimeException('Réponse SMTP inattendue (code ' . substr($reply, 0, 3) . ', attendu ' . $code . ') : ' . trim($reply));
        }
        return $reply;
    }
}
