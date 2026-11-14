<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Validator;
use App\Modules\Auth\PasswordPolicy;
use DomainException;
use PDOException;

/** Comptes d'administration : création, modification, activation et rôles, chaque écriture auditée dans sa transaction. */
final class UserService
{
    public const SELF = 'Vous ne pouvez pas modifier vos propres rôles ni désactiver votre propre compte : demandez-le à un autre administrateur.';
    public const LAST_ADMIN = 'Opération impossible : il doit rester au moins un administrateur actif.';
    public const DUPLICATE = 'Cette adresse e-mail est déjà utilisée par un autre compte.';
    public const NOT_FOUND = "Cet utilisateur n'existe pas : revenez à la liste des utilisateurs.";
    private const MANAGE = 'users.manage';

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
    }

    /**
     * Crée le compte sans mot de passe utilisable : l'appelant envoie aussitôt un lien de réinitialisation servant d'invitation.
     * @param array<string,mixed> $data name, email, phone ?, roles ? (list<string>)
     */
    public function create(array $data, int $actorId): int
    {
        $fields = $this->validate($data);
        $roles = $this->roleIds($data['roles'] ?? []);
        return $this->db->atomic(function () use ($fields, $roles, $actorId): int {
            $this->assertEmailFree($fields['email'], 0);
            // Haché d'un secret aléatoire jamais conservé : aucune connexion possible avant le choix du mot de passe.
            $id = $this->uniqueEmail(fn () => $this->db->insert('users', $fields + ['password' => PasswordPolicy::hash(bin2hex(random_bytes(32)))]));
            foreach ($roles as $roleId) {
                $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId, 'scope_zone_id' => null]);
            }
            $this->audit->log($actorId, 'user.created', 'user', $id, null, $fields + ['roles' => array_keys($roles)]);
            return $id;
        });
    }

    /** @param array<string,mixed> $data name, email, phone ? */
    public function update(int $id, array $data, int $actorId): void
    {
        $fields = $this->validate($data);
        $this->db->atomic(function () use ($id, $fields, $actorId): void {
            $before = $this->db->fetch('SELECT name, email, phone FROM users WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException(self::NOT_FOUND);
            $this->assertEmailFree($fields['email'], $id);
            $changed = array_diff_assoc($fields, $before);
            if ($changed === []) {
                return;
            }
            $this->uniqueEmail(fn () => $this->db->update('users', $changed, ['id' => $id]));
            $this->audit->log($actorId, 'user.updated', 'user', $id, array_intersect_key($before, $changed), $changed);
        });
    }

    /** La désactivation incrémente session_version : les sessions ouvertes du compte sont fermées. */
    public function setActive(int $id, bool $active, int $actorId): void
    {
        if ($id === $actorId) {
            throw new DomainException(self::SELF);
        }
        $this->db->atomic(function () use ($id, $active, $actorId): void {
            if (!$active && $this->wouldLeaveNoAdmin($id, deactivate: true)) {
                throw new DomainException(self::LAST_ADMIN);
            }
            $current = $this->db->fetchValue('SELECT is_active FROM users WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException(self::NOT_FOUND);
            if ((bool) $current === $active) {
                return;
            }
            $this->db->query('UPDATE users SET is_active = ?, session_version = session_version + ? WHERE id = ?', [(int) $active, $active ? 0 : 1, $id]);
            $this->audit->log($actorId, $active ? 'user.activated' : 'user.deactivated', 'user', $id, ['is_active' => !$active], ['is_active' => $active]);
            $this->gate->forget($id);
        });
    }

    /** @param list<string> $roleNames rôles complets du compte après l'opération */
    public function setRoles(int $id, array $roleNames, int $actorId): void
    {
        if ($id === $actorId) {
            throw new DomainException(self::SELF);
        }
        $roles = $this->roleIds($roleNames);
        $this->db->atomic(function () use ($id, $roles, $actorId): void {
            $after = array_keys($roles);
            if ($this->wouldLeaveNoAdmin($id, $after)) {
                throw new DomainException(self::LAST_ADMIN);
            }
            $this->db->fetchValue('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException(self::NOT_FOUND);
            $before = $this->roleNames($id);
            if ($before === $after) {
                return;
            }
            $this->db->query('DELETE FROM user_roles WHERE user_id = ?', [$id]);
            foreach ($roles as $roleId) {
                $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId, 'scope_zone_id' => null]);
            }
            $this->audit->log($actorId, 'user.roles_changed', 'user', $id, ['roles' => $before], ['roles' => $after]);
            $this->gate->forget($id);
        });
    }

    /**
     * Vrai si l'opération sur $userId (nouveaux rôles, ou désactivation) ne laisserait aucun utilisateur actif détenant users.manage.
     * À appeler dans la transaction de l'écriture : les comptes détenteurs sont verrouillés (FOR UPDATE, dans l'ordre des id)
     * jusqu'au commit, donc deux rétrogradations simultanées ne peuvent pas passer toutes les deux.
     *
     * @param list<string>|null $newRoleNames
     */
    public function wouldLeaveNoAdmin(int $userId, ?array $newRoleNames = null, bool $deactivate = false): bool
    {
        // Jointures (pas de sous-requête) : chaque table est lue en lecture verrouillante, donc dans sa dernière version validée,
        // quel que soit l'instantané de la transaction ; une écriture concurrente sur ces lignes fait attendre.
        $holders = array_map('intval', array_column($this->db->fetchAll(
            'SELECT DISTINCT u.id FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.is_active = 1 AND p.name = ? ORDER BY u.id FOR UPDATE',
            [self::MANAGE],
        ), 'id'));
        if (!in_array($userId, $holders, true) || count($holders) > 1) {
            return false; // le compte ne détient pas users.manage, ou un autre détenteur actif reste
        }
        if ($deactivate || $newRoleNames === []) {
            return true;
        }
        if ($newRoleNames === null) {
            return false;
        }
        $keeps = $this->db->fetchValue(
            'SELECT 1 FROM roles r JOIN role_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id
             WHERE p.name = ? AND r.name IN (' . implode(',', array_fill(0, count($newRoleNames), '?')) . ') LIMIT 1',
            [self::MANAGE, ...$newRoleNames],
        );
        return $keeps === null;
    }

    /** @return list<string> noms triés */
    public function roleNames(int $userId): array
    {
        $names = array_column($this->db->fetchAll('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$userId]), 'name');
        sort($names); // même ordre que roleIds() (ksort), comparable à l'octet près
        return $names;
    }

    /** @param array<string,mixed> $data @return array{name: string, email: string, phone: ?string} */
    private function validate(array $data): array
    {
        $email = $data['email'] ?? null;
        $data['email'] = is_string($email) ? mb_strtolower(trim($email)) : $email;
        $v = Validator::make($data, ['name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'phone' => 'nullable|phone_ci'],
            ['name' => 'nom', 'email' => 'adresse e-mail', 'phone' => 'téléphone']);
        if ($v->fails()) {
            throw new DomainException(implode(' ', $v->errors()));
        }
        ['name' => $name, 'email' => $email, 'phone' => $phone] = $v->validated();
        return ['name' => (string) $name, 'email' => (string) $email, 'phone' => $phone === null ? null : (string) $phone];
    }

    /** @param mixed $names @return array<string,int> nom => id, trié par nom */
    private function roleIds(mixed $names): array
    {
        if (!is_array($names) || array_filter($names, 'is_string') !== $names) {
            throw new DomainException('Rôles invalides : choisissez les rôles dans la liste proposée.');
        }
        $names = array_values(array_unique($names));
        $known = $names === [] ? [] : array_column($this->db->fetchAll(
            'SELECT name, id FROM roles WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')', $names), 'id', 'name');
        foreach ($names as $name) {
            if (!isset($known[$name])) {
                throw new DomainException("Rôle inconnu : $name. Choisissez les rôles dans la liste proposée.");
            }
        }
        ksort($known);
        return array_map('intval', $known);
    }

    private function assertEmailFree(string $email, int $exceptId): void
    {
        if ($this->db->fetchValue('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId]) !== null) {
            throw new DomainException(self::DUPLICATE);
        }
    }

    /** @param callable(): int $write */
    private function uniqueEmail(callable $write): int
    {
        try {
            return $write();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // même adresse enregistrée simultanément : la clé unique tranche
                throw new DomainException(self::DUPLICATE);
            }
            throw $e;
        }
    }
}
