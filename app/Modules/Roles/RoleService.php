<?php
declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Validator;
use App\Modules\Users\ProtectedRoles;
use App\Modules\Users\UserService;
use DomainException;
use PDOException;

/**
 * Rôles et permissions. Chaque écriture : verrous dans l'ordre documenté à UserService::lockHolders() (détenteurs, rôle cible,
 * lignes de rôle), règle des rôles protégés (ProtectedRoles), audit dans la même transaction.
 */
final class RoleService
{
    public const SYSTEM = 'Ce rôle système ne peut pas être modifié.';
    public const NOT_FOUND = "Ce rôle n'existe pas : revenez à la liste des rôles.";
    public const NAME = 'Identifiant de rôle invalide : de 3 à 60 caractères parmi a-z, 0-9 et _, en commençant par une lettre (ex. gest_tresorerie).';
    public const DUPLICATE = 'Cet identifiant de rôle est déjà utilisé : choisissez-en un autre.';
    private ProtectedRoles $guard;
    private UserService $users;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
        $this->guard = new ProtectedRoles($db);
        $this->users = new UserService($db, $audit, $gate);
    }

    /** Un nouveau rôle part sans permission : setPermissions() les ajoute, sous ses propres règles. */
    public function create(string $name, string $label, ?string $description, int $actorId): int
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,59}$/D', $name) !== 1) {
            throw new DomainException(self::NAME);
        }
        $fields = ['name' => $name] + $this->validate($label, $description);
        return $this->db->atomic(function () use ($fields, $actorId): int {
            if ($this->db->fetchValue('SELECT id FROM roles WHERE name = ?', [$fields['name']]) !== null) {
                throw new DomainException(self::DUPLICATE);
            }
            try {
                $id = $this->db->insert('roles', $fields);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) { // même identifiant enregistré simultanément (uq_roles_name)
                    throw new DomainException(self::DUPLICATE);
                }
                throw $e;
            }
            $this->audit->log($actorId, 'role.created', 'role', $id, null, $fields + ['permissions' => []]);
            return $id;
        });
    }

    public function update(int $id, string $label, ?string $description, int $actorId): void
    {
        $fields = $this->validate($label, $description);
        $this->db->atomic(function () use ($id, $fields, $actorId): void {
            $role = $this->lockEditable($id, $actorId);
            $changed = array_diff_assoc($fields, ['label' => $role['label'], 'description' => $role['description']]);
            if ($changed === []) {
                return;
            }
            $this->db->update('roles', $changed, ['id' => $id]);
            $this->audit->log($actorId, 'role.updated', 'role', $id, array_intersect_key($role, $changed), $changed);
        });
    }

    /**
     * Ajouter une permission exige de la détenir (ou un rôle système) : seul le super administrateur désigne par exemple
     * les approbateurs des décaissements. Retirer est toujours permis, sauf le dernier users.manage actif.
     *
     * @param list<string> $permissionNames permissions complètes du rôle après l'opération
     */
    public function setPermissions(int $id, array $permissionNames, int $actorId): void
    {
        $catalog = array_column($this->db->fetchAll('SELECT id, name FROM permissions'), 'name', 'id');
        if (array_filter($permissionNames, 'is_string') !== $permissionNames) {
            throw new DomainException('Permissions invalides : choisissez les permissions dans la liste proposée.');
        }
        $after = array_values(array_unique($permissionNames));
        sort($after);
        $ids = array_flip($catalog);
        foreach ($after as $name) {
            if (!isset($ids[$name])) {
                throw new DomainException("Permission inconnue : $name. Choisissez les permissions dans la liste proposée.");
            }
        }
        $this->db->atomic(function () use ($id, $after, $actorId, $catalog, $ids): void {
            $this->lockEditable($id, $actorId);
            $before = array_map(static fn ($pid): string => $catalog[$pid],
                array_column($this->db->fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = ? FOR UPDATE', [$id]), 'permission_id'));
            sort($before);
            if ($before === $after) {
                return;
            }
            $grantable = $this->guard->grantable($actorId);
            $refused = $grantable === null ? [] : array_values(array_diff($after, $before, $grantable));
            if ($refused !== []) {
                throw new DomainException("Vous ne pouvez attribuer que les droits que vous détenez : demandez à un super administrateur d'ajouter " . implode(', ', $refused) . '.');
            }
            if ($this->users->wouldLeaveNoAdminAfterRoleChange($id, $after)) {
                throw new DomainException(UserService::LAST_ADMIN);
            }
            $this->db->query('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($after as $name) {
                $this->db->insert('role_permissions', ['role_id' => $id, 'permission_id' => $ids[$name]]);
            }
            $this->audit->log($actorId, 'role.permissions_changed', 'role', $id, ['permissions' => $before], ['permissions' => $after]);
            foreach ($this->db->fetchAll('SELECT user_id FROM user_roles WHERE role_id = ?', [$id]) as $row) {
                $this->gate->forget((int) $row['user_id']); // les requêtes suivantes relisent leurs droits
            }
        });
    }

    /** Refusé tant qu'un compte, même désactivé, porte ce rôle. */
    public function delete(int $id, int $actorId): void
    {
        $this->db->atomic(function () use ($id, $actorId): void {
            $role = $this->lockEditable($id, $actorId);
            $holders = (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_roles WHERE role_id = ? FOR UPDATE', [$id]);
            if ($holders > 0) {
                throw new DomainException("Ce rôle est attribué à $holders utilisateur(s) : retirez-le de leurs comptes avant de le supprimer.");
            }
            if ($this->users->wouldLeaveNoAdminAfterRoleChange($id, deleted: true)) {
                throw new DomainException(UserService::LAST_ADMIN);
            }
            $permissions = array_column($this->db->fetchAll(
                'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.name', [$id]), 'name');
            $this->db->query('DELETE FROM roles WHERE id = ?', [$id]); // role_permissions supprimées en cascade
            $this->audit->log($actorId, 'role.deleted', 'role', $id, $role + ['permissions' => $permissions], null);
        });
    }

    /** @return ?list<string> voir ProtectedRoles::grantable() */
    public function grantable(int $actorId): ?array
    {
        return $this->guard->grantable($actorId);
    }

    /** Verrous (détenteurs, puis rôle) et contrôles communs aux modifications. @return array{name: string, label: string, description: ?string} */
    private function lockEditable(int $id, int $actorId): array
    {
        $this->users->lockHolders();
        $role = $this->db->fetch('SELECT name, label, description, is_system FROM roles WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException(self::NOT_FOUND);
        if ((int) $role['is_system'] === 1) {
            throw new DomainException(self::SYSTEM);
        }
        $this->guard->assertMayHandle($actorId, [$id]);
        unset($role['is_system']);
        return $role;
    }

    /** @return array{label: string, description: ?string} */
    private function validate(string $label, ?string $description): array
    {
        $description = $description === null || trim($description) === '' ? null : trim($description);
        $v = Validator::make(['label' => trim($label), 'description' => $description], ['label' => 'required|string|max:120', 'description' => 'nullable|string|max:1000'],
            ['label' => 'libellé', 'description' => 'description']);
        if ($v->fails()) {
            throw new DomainException(implode(' ', $v->errors()));
        }
        return ['label' => trim($label), 'description' => $description];
    }
}
