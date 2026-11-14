<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;
use DomainException;

/**
 * Règle anti-escalade partagée par UserService et RoleService. Un rôle est protégé s'il est système ou porte l'une des
 * permissions de PROTECTING (administration, ou les 7 que super_admin n'a pas) ; seul un acteur détenant un rôle système
 * ou toutes les permissions de ce rôle peut l'attribuer, modifier son détenteur, ou modifier le rôle lui-même.
 * Lectures fraîches en base (pas le cache de Gate) ; acteur null = ligne de commande, exemptée.
 */
final class ProtectedRoles
{
    public const DENIED = 'Cette opération est réservée aux administrateurs détenant déjà ces droits : demandez-la à un super administrateur.';
    private const PROTECTING = ['users.manage', 'roles.manage', 'disbursement.approve', 'disbursement.pay', 'social.decide',
        'discipline.decide', 'settings.validate', 'members.documents.view', 'social.medical.view'];

    public function __construct(private Database $db)
    {
    }

    /** @param list<int> $roleIds */
    public function assertMayHandle(?int $actorId, array $roleIds): void
    {
        if ($actorId === null || $roleIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($roleIds), '?'));
        $denied = $this->db->fetchValue(
            "SELECT r.id FROM roles r
             WHERE r.id IN ($in)
               AND NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles sr ON sr.id = ur.role_id
                               WHERE u.id = ? AND u.is_active = 1 AND sr.is_system = 1)
               AND (r.is_system = 1 OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                                               WHERE rp.role_id = r.id AND p.name IN (" . implode(',', array_fill(0, count(self::PROTECTING), '?')) . ")))
               AND EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id NOT IN (
                               SELECT ap.permission_id FROM users au JOIN user_roles aur ON aur.user_id = au.id
                               JOIN role_permissions ap ON ap.role_id = aur.role_id WHERE au.id = ? AND au.is_active = 1))
             LIMIT 1",
            [...$roleIds, $actorId, ...self::PROTECTING, $actorId],
        );
        if ($denied !== null) {
            throw new DomainException(self::DENIED);
        }
    }

    /** Permissions que l'acteur peut ajouter à un rôle : null = toutes (rôle système), sinon celles qu'il détient. @return ?list<string> */
    public function grantable(int $actorId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT p.name, r.is_system FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ? AND u.is_active = 1',
            [$actorId],
        );
        if (in_array(1, array_map('intval', array_column($rows, 'is_system')), true)) {
            return null;
        }
        return array_values(array_unique(array_filter(array_column($rows, 'name'), 'is_string')));
    }
}
