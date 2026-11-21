<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;

/** Jetons de réinitialisation à usage unique : seul leur SHA-256 est stocké, le jeton en clair ne vit que dans l'e-mail. */
final class PasswordReset
{
    public function __construct(private Database $db, private int $ttlSeconds = 3600, private int $maxPerHour = 3)
    {
    }

    /** Jeton en clair (64 hex), ou null si l'utilisateur a déjà reçu $maxPerHour jetons dans l'heure ; $ttlSeconds remplace la durée par défaut (invitation). */
    public function issue(int $userId, int $now, ?int $ttlSeconds = null): ?string
    {
        $recent = (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?', [$userId, gmdate('Y-m-d H:i:s', $now - 3600)]);
        if ($recent >= $this->maxPerHour) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $this->db->insert('password_resets', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + ($ttlSeconds ?? $this->ttlSeconds)),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        return $token;
    }

    /** user_id si le jeton est utilisable (connu, non utilisé, non expiré, utilisateur actif), sans le consommer. */
    public function check(string $token, int $now): ?int
    {
        return $this->find($token, $now, '');
    }

    /** Comme check(), puis marque ce jeton et tous les autres jetons ouverts de l'utilisateur comme utilisés. */
    public function consume(string $token, int $now): ?int
    {
        return $this->db->atomic(function () use ($token, $now): ?int {
            // Compte verrouillé avant les jetons, comme UserService::setActive (users puis password_resets) : pas d'interblocage.
            $owner = $this->db->fetchValue('SELECT user_id FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]);
            if ($owner === null) {
                return null;
            }
            $this->db->fetchValue('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $owner]);
            $userId = $this->find($token, $now, ' FOR UPDATE'); // deux consommations simultanées : la seconde attend le compte puis ne trouve plus rien
            if ($userId !== null) {
                $this->db->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s', $now), $userId]);
            }
            return $userId;
        });
    }

    private function find(string $token, int $now, string $lock): ?int
    {
        $id = $this->db->fetchValue(
            'SELECT r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.is_active = 1' . $lock,
            [hash('sha256', $token), gmdate('Y-m-d H:i:s', $now)],
        );
        return $id === null ? null : (int) $id;
    }
}
