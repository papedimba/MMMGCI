<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;

/** Limite les échecs de connexion par (e-mail, ip) et par ip sur une fenêtre glissante. */
final class LoginThrottle
{
    public function __construct(
        private Database $db,
        private int $maxPerAccount = 5,
        private int $maxPerIp = 20,
        private int $windowSeconds = 900,
    ) {
    }

    public function tooManyAttempts(string $email, string $ip, int $now): bool
    {
        $since = gmdate('Y-m-d H:i:s', $now - $this->windowSeconds);
        $account = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND succeeded = 0 AND created_at >= ?',
            [$this->norm($email), $ip, $since],
        );
        if ($account >= $this->maxPerAccount) {
            return true;
        }
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND created_at >= ?',
            [$ip, $since],
        ) >= $this->maxPerIp;
    }

    public function record(string $email, string $ip, bool $success, int $now): void
    {
        $this->db->insert('login_attempts', [
            'email' => $this->norm($email),
            'ip' => $ip,
            'succeeded' => $success ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    public function purge(int $now): void
    {
        $this->db->query('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', $now - 86400)]);
    }

    private function norm(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
