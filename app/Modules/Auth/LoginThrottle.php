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
            [$this->norm($email), $this->ip($ip), $since],
        );
        if ($account >= $this->maxPerAccount) {
            return true;
        }
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND created_at >= ?',
            [$this->ip($ip), $since],
        ) >= $this->maxPerIp;
    }

    public function record(string $email, string $ip, bool $success, int $now): void
    {
        $this->db->insert('login_attempts', [
            'email' => $this->norm($email),
            'ip' => $this->ip($ip),
            'succeeded' => $success ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    /**
     * Vrai pour la première tentative bloquée de (e-mail, ip) dans la fenêtre, alors notée succeeded = 2 (jamais comptée
     * comme un échec) ; faux ensuite : l'appelant ne journalise que la première, sans quoi chaque essai ajouterait une ligne d'audit.
     */
    public function markBlocked(string $email, string $ip, int $now): bool
    {
        // shortcut: deux tentatives bloquées strictement simultanées peuvent toutes deux passer (deux lignes au plus par rafale), sans enjeu.
        $seen = $this->db->fetchValue(
            'SELECT 1 FROM login_attempts WHERE email = ? AND ip = ? AND succeeded = 2 AND created_at >= ? LIMIT 1',
            [$this->norm($email), $this->ip($ip), gmdate('Y-m-d H:i:s', $now - $this->windowSeconds)],
        );
        if ($seen !== null) {
            return false;
        }
        $this->db->insert('login_attempts', ['email' => $this->norm($email), 'ip' => $this->ip($ip), 'succeeded' => 2, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
        return true;
    }

    public function purge(int $now): void
    {
        $this->db->query('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', $now - 86400)]);
    }

    private function norm(string $email): string
    {
        // Tronqué à la taille de la colonne : sinon l'insertion échoue ou le compteur ne correspond plus.
        return mb_substr(mb_strtolower(trim($email)), 0, 190);
    }

    private function ip(string $ip): string
    {
        return mb_substr($ip, 0, 45);
    }
}
