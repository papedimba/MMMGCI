<?php
declare(strict_types=1);

namespace App\Core;

/** Autorisations : union des permissions des rôles d'un utilisateur actif. */
final class Gate
{
    /** @var array<int, list<string>> */
    private array $cache = [];

    public function __construct(private Database $db)
    {
    }

    /** @return list<string> */
    public function permissions(int $userId): array
    {
        return $this->cache[$userId] ??= array_column($this->db->fetchAll(
            'SELECT DISTINCT p.name FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ? AND u.is_active = 1 ORDER BY p.name',
            [$userId]
        ), 'name');
    }

    public function allows(int $userId, string $permission): bool
    {
        return in_array($permission, $this->permissions($userId), true);
    }

    /** null = sans restriction de zone ; [] = aucun accès ; sinon les zones autorisées. @return ?list<int> */
    public function zoneScope(int $userId, string $permission): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT ur.scope_zone_id FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ? AND u.is_active = 1 AND p.name = ?',
            [$userId, $permission]
        );
        $zones = [];
        foreach ($rows as $r) {
            if ($r['scope_zone_id'] === null) {
                return null;
            }
            $zones[(int) $r['scope_zone_id']] = true;
        }
        $ids = array_keys($zones);
        sort($ids);
        return $ids;
    }

    public function forget(int $userId): void
    {
        unset($this->cache[$userId]);
    }
}
