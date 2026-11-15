<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;

/**
 * Périmètre de lecture des adhérents. Sans members.scope.all : la zone de rattachement du compte, rien d'autre.
 * shortcut: les sous-zones (zones.parent_id) ne sont pas incluses ; à traiter quand les instances auront fixé la hiérarchie.
 */
final class MemberScope
{
    public function __construct(private Database $db, private Gate $gate)
    {
    }

    /** null = périmètre complet ; sinon id de zone, ou 0 pour un compte sans zone (aucun adhérent visible). */
    public function forUser(int $userId): ?int
    {
        if ($this->gate->allows($userId, 'members.scope.all')) {
            return null;
        }
        return (int) $this->db->fetchValue('SELECT zone_id FROM users WHERE id = ?', [$userId]);
    }

    /** @return array{string, list<int>} fragment SQL (jamais vide) et paramètres */
    public function where(int $userId, string $alias = 'm'): array
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new \InvalidArgumentException("Alias SQL invalide : $alias.");
        }
        $zone = $this->forUser($userId);
        return match (true) {
            $zone === null => ['1 = 1', []],
            $zone === 0 => ['1 = 0', []],
            default => ["$alias.zone_id = ?", [$zone]],
        };
    }

    /** @throws NotFound adhérent inconnu ou hors périmètre (même réponse : on ne révèle pas son existence) */
    public function assertMayAccess(int $userId, int $memberId): void
    {
        [$sql, $params] = $this->where($userId);
        if ($this->db->fetchValue("SELECT 1 FROM members m WHERE m.id = ? AND $sql", [$memberId, ...$params]) === null) {
            throw new NotFound("Adhérent $memberId introuvable.");
        }
    }
}
