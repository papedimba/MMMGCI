<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Database;
use App\Core\Gate;
use App\Modules\Members\MemberScope;

/** Liste des demandes sociales : périmètre MemberScope, aucune colonne médicale ni description, comptes désactivés sans résultat. */
final class SocialSearch
{
    public const PER_PAGE = 50;
    private const CHUNK = 500;
    private const FROM = 'FROM social_requests r JOIN members m ON m.id = r.member_id JOIN social_request_types t ON t.id = r.type_id
        LEFT JOIN zones z ON z.id = m.zone_id LEFT JOIN users u ON u.id = r.assigned_to LEFT JOIN social_decisions d ON d.social_request_id = r.id';
    private const ORDER = "r.priority = 'urgente' DESC, r.received_at DESC, r.id DESC";

    public function __construct(private Database $db, private MemberScope $scope, private Gate $gate)
    {
    }

    /**
     * @param array<string,mixed> $criteria q, status, type_id, priority, assignee (moi|non), zone_id (si members.scope.all), from, to
     * @return array{rows: list<array<string,mixed>>, page: int, pages: int, total: int}
     */
    public function search(int $userId, array $criteria, int $page): array
    {
        [$cond, $params] = $this->build($userId, $criteria);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) ' . self::FROM . " WHERE $cond", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll('SELECT r.id, r.reference, r.received_at, r.priority, r.status, m.id AS member_id, m.member_number, m.last_name, m.first_names,
            t.name AS type_name, u.name AS assignee_name ' . self::FROM . " WHERE $cond ORDER BY " . self::ORDER . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** Lignes d'export (colonnes fixes, sans texte libre), lues par lots. @return \Generator<array<string,mixed>> */
    public function stream(int $userId, array $criteria): \Generator
    {
        [$cond, $params] = $this->build($userId, $criteria);
        for ($offset = 0;; $offset += self::CHUNK) {
            $rows = $this->db->fetchAll('SELECT r.reference, r.received_at, r.priority, r.status, r.closed_at, m.member_number, z.name AS zone_name, t.name AS type_name,
                u.name AS assignee_name, d.decision, d.aid_kind, d.amount ' . self::FROM . " WHERE $cond ORDER BY " . self::ORDER . ' LIMIT ' . self::CHUNK . " OFFSET $offset", $params);
            yield from $rows;
            if (count($rows) < self::CHUNK) {
                return;
            }
        }
    }

    /** Critères réellement appliqués (le texte cherché n'est jamais conservé : q => true). @return array<string,mixed> */
    public function applied(int $userId, array $criteria): array
    {
        $out = [];
        $q = $criteria['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $out['q'] = true;
        }
        foreach (['status' => SocialWorkflow::STATUSES, 'priority' => array_keys(SocialRequestService::PRIORITIES), 'assignee' => ['moi', 'non']] as $k => $allowed) {
            if (in_array($criteria[$k] ?? null, $allowed, true)) {
                $out[$k] = $criteria[$k];
            }
        }
        foreach (['type_id', 'zone_id'] as $k) {
            $v = $criteria[$k] ?? null;
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && ($k !== 'zone_id' || $this->gate->allows($userId, 'members.scope.all'))) {
                $out[$k] = (string) $v;
            }
        }
        foreach (['from', 'to'] as $k) {
            if (is_string($criteria[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $criteria[$k])) {
                $out[$k] = $criteria[$k];
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function build(int $userId, array $criteria): array
    {
        [$scope, $params] = $this->scope->where($userId);
        $where = [$scope];
        if ((int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$userId]) !== 1) {
            $where[] = '1 = 0';
        }
        $c = $this->applied($userId, $criteria);
        if (isset($c['q'])) {
            $like = static fn (string $s): string => addcslashes($s, '\\%_') . '%';
            $q = trim((string) $criteria['q']);
            $or = ['r.reference LIKE ?', 'm.member_number LIKE ?'];
            array_push($params, $like($q), $like($q));
            $names = [];
            foreach (preg_split('/\s+/u', $q) ?: [] as $w) {
                $names[] = '(m.last_name LIKE ? OR m.first_names LIKE ? OR m.first_names LIKE ?)';
                array_push($params, $like($w), $like($w), '% ' . $like($w));
            }
            $or[] = '(' . implode(' AND ', $names) . ')';
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        foreach (['status' => 'r.status', 'priority' => 'r.priority', 'type_id' => 'r.type_id', 'zone_id' => 'm.zone_id'] as $k => $col) {
            if (isset($c[$k])) {
                $where[] = "$col = ?";
                $params[] = in_array($k, ['type_id', 'zone_id'], true) ? (int) $c[$k] : $c[$k];
            }
        }
        if (($c['assignee'] ?? null) === 'moi') {
            $where[] = 'r.assigned_to = ?';
            $params[] = $userId;
        } elseif (($c['assignee'] ?? null) === 'non') {
            $where[] = 'r.assigned_to IS NULL';
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (isset($c[$k])) {
                $where[] = "r.received_at $op ?";
                $params[] = $c[$k];
            }
        }
        return [implode(' AND ', $where), $params];
    }
}
