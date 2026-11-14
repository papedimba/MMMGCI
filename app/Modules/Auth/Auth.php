<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Session;

/** Session d'administration : connexion, utilisateur courant revalidé en base à chaque requête, déconnexion. */
final class Auth
{
    /** Haché factice : un e-mail inconnu ou inactif coûte le même password_verify qu'un compte réel (même algorithme et coût). */
    private static ?string $dummyHash = null;

    public function __construct(private Session $session, private Database $db, private Gate $gate)
    {
    }

    /**
     * Vérifie les identifiants ; en cas de succès met à jour last_login_at (et ré-hache si besoin) et retourne la ligne.
     * Ne touche pas à la session : l'appelant appelle login() après validation de sa transaction (audit compris).
     * $ip est réservé à l'appelant (limitation, audit) : la vérification ne dépend que du compte.
     *
     * @return array<string,mixed>|null
     */
    public function attempt(string $email, string $password, string $ip, int $now): ?array
    {
        $user = $this->db->fetch('SELECT id, password, is_active, session_version FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        $active = $user !== null && (int) $user['is_active'] === 1;
        $hash = $active ? $user['password'] : (self::$dummyHash ??= PasswordPolicy::hash(bin2hex(random_bytes(16))));
        if (!password_verify($password, $hash) || !$active) {
            return null;
        }
        $row = ['last_login_at' => gmdate('Y-m-d H:i:s', $now)];
        if (PasswordPolicy::needsRehash($user['password'])) {
            $row['password'] = PasswordPolicy::hash($password);
        }
        $this->db->update('users', $row, ['id' => $user['id']]);
        return $user;
    }

    /** @param array<string,mixed> $user ligne retournée par attempt() */
    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->forget('_csrf'); // le jeton émis avant connexion ne vaut plus pour la session authentifiée
        $this->session->set('auth.user_id', (int) $user['id']);
        $this->session->set('auth.session_version', (int) $user['session_version']);
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $id = $this->session->get('auth.user_id');
        if ($id === null) {
            return null;
        }
        $user = $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || (int) $user['is_active'] !== 1 || (int) $user['session_version'] !== $this->session->get('auth.session_version')) {
            $this->session->invalidate();
            return null;
        }
        return $user;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user === null ? null : (int) $user['id'];
    }

    public function can(string $permission): bool
    {
        $id = $this->id();
        return $id !== null && $this->gate->allows($id, $permission);
    }

    public function logout(): void
    {
        $this->session->invalidate();
    }
}
