<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Database;
use App\Core\Session;
use App\Modules\Auth\Auth;
use App\Modules\Auth\PasswordPolicy;
use App\Modules\Members\MemberService;

/**
 * Session de l'Espace adhérent (cookie mmmgci_esp, chemin /espace) : distincte de l'administration (Auth, table users).
 * Un compte du personnel n'est jamais un compte adhérent, même si users.member_id pointe vers le membre.
 */
final class MemberAuth
{
    private const ROW = 'SELECT a.id, a.member_id, a.password, a.status, a.session_version, m.status AS member_status, m.deleted_at, m.anonymized_at
        FROM member_accounts a JOIN members m ON m.id = a.member_id';

    public function __construct(private Session $session, private Database $db, private MemberAccess $access)
    {
    }

    private const PHONE = '/^[+\d .\-()]+$/';

    /**
     * Forme canonique d'un identifiant, partagée par la recherche et par la clé du limiteur (connexion, activation, oubli) :
     * deux saisies qui désignent la même fiche par la même voie ont la même clé. ASCII imprimable seulement (la collation
     * de la base confondrait pleine chasse, accents et casse) ; sinon null = inconnu.
     *
     * @return array{0: 'email'|'phone'|'number', 1: string, 2: string}|null voie, valeur cherchée, saisie nettoyée
     */
    public static function canonical(string $identifier): ?array
    {
        $id = trim($identifier);
        if ($id === '' || strlen($id) > 190 || preg_match('/^[\x20-\x7E]+$/D', $id) !== 1) {
            return null;
        }
        if (str_contains($id, '@')) {
            return ['email', strtolower($id), $id];
        }
        if (preg_match(self::PHONE, $id) === 1) {
            $phone = MemberService::normalizePhone($id);
            return $phone === null ? null : ['phone', $phone, $id];
        }
        return ['number', strtoupper($id), $id];
    }

    /** Clé de LoginThrottle : préfixe (« espace: », « espace-activation: », « espace-oubli: ») + forme canonique. */
    public static function throttleKey(string $prefix, string $identifier): string
    {
        return $prefix . (self::canonical($identifier)[1] ?? '?'); // saisie invalide : jamais un compte, une seule clé
    }

    /**
     * Compte désigné par l'identifiant : numéro de membre exact, sinon e-mail ou téléphone d'une seule fiche non supprimée ayant
     * un compte (aucune ou plusieurs : inconnu, pour qu'un téléphone partagé n'ouvre jamais le compte d'un autre).
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $identifier): ?array
    {
        $c = self::canonical($identifier);
        if ($c === null) {
            return null;
        }
        [$kind, $value, $raw] = $c;
        if ($kind !== 'email') {
            // Numéro saisi tel quel ; pour une saisie « téléphone », seul le texte exact peut aussi être un numéro (même clé).
            $row = $this->db->fetch(self::ROW . ' WHERE m.member_number = ? AND m.deleted_at IS NULL', [$kind === 'number' ? $value : $raw]);
            if ($row !== null || $kind === 'number') {
                return $row;
            }
        }
        $rows = $this->db->fetchAll(self::ROW . ' WHERE ' . ($kind === 'email' ? 'm.email' : 'm.phone') . ' = ? AND m.deleted_at IS NULL LIMIT 2', [$value]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Compte utilisable : actif, avec mot de passe, membre présent (ni supprimé ni anonymisé) et niveau d'accès ouvert. @param array<string,mixed>|null $row */
    private function usable(?array $row): bool
    {
        return $row !== null && $row['status'] === 'actif' && $row['password'] !== null && $row['deleted_at'] === null && $row['anonymized_at'] === null
            && $this->access->level((string) $row['member_status']) !== 'desactive';
    }

    /**
     * Vérifie les identifiants ; succès : last_login_at (et ré-hachage si besoin), ligne retournée. Ne touche pas à la session.
     * Inconnu ou refusé : même password_verify sur un haché factice, même réponse (null).
     *
     * @return array<string,mixed>|null
     */
    public function attempt(string $identifier, string $password, int $now): ?array
    {
        $row = $this->resolve($identifier);
        $ok = $this->usable($row);
        if (!password_verify($password, $ok ? (string) $row['password'] : Auth::dummyHash()) || !$ok) {
            return null;
        }
        $update = ['last_login_at' => gmdate('Y-m-d H:i:s', $now)];
        if (PasswordPolicy::needsRehash((string) $row['password'])) {
            $update['password'] = PasswordPolicy::hash($password);
        }
        $this->db->update('member_accounts', $update, ['id' => $row['id']]);
        return $row;
    }

    /** @param array<string,mixed> $account ligne retournée par attempt() (ou l'activation) */
    public function login(array $account): void
    {
        $this->session->regenerate(); // fixation de session : jamais l'identifiant d'avant connexion
        $this->session->forget('_csrf');
        $this->session->set('espace.account_id', (int) $account['id']);
        $this->session->set('espace.session_version', (int) $account['session_version']);
    }

    /**
     * Adhérent courant, revalidé en base à chaque requête ; sinon la session est fermée.
     *
     * @return array{account_id: int, member_id: int, access: string, member_status: string}|null
     */
    public function current(): ?array
    {
        $id = $this->session->get('espace.account_id');
        if ($id === null) {
            return null;
        }
        $row = $this->db->fetch(self::ROW . ' WHERE a.id = ?', [$id]);
        if (!$this->usable($row) || (int) $row['session_version'] !== $this->session->get('espace.session_version')) {
            $this->session->invalidate();
            return null;
        }
        return ['account_id' => (int) $row['id'], 'member_id' => (int) $row['member_id'], 'access' => $this->access->level((string) $row['member_status']),
            'member_status' => (string) $row['member_status']];
    }

    /** La session porte-t-elle un compte (sans le revalider) ? */
    public function hasSession(): bool
    {
        return $this->session->get('espace.account_id') !== null;
    }

    public function logout(): void
    {
        $this->session->invalidate();
    }
}
