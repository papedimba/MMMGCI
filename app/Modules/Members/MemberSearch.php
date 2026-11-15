<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Database;

/** Recherche paginée d'adhérents, bornée au périmètre de l'utilisateur. */
final class MemberSearch
{
    public const PER_PAGE = 50;
    private const SORTS = ['name' => 'm.last_name, m.first_names', 'number' => 'm.member_number', 'joined_at' => 'm.joined_at', 'status' => 'm.status'];

    public function __construct(private Database $db, private MemberScope $scope)
    {
    }

    /**
     * @param array<string,mixed> $criteria q, status, category_id, zone_id, profession_id, joined_from, joined_to
     * @param string $sort name|number|joined_at|status, préfixé de « - » pour l'ordre décroissant ; inconnu : name
     * @return array{rows: list<array<string,mixed>>, total: int, pages: int}
     */
    public function run(int $userId, array $criteria, string $sort, int $page): array
    {
        [$where, $params] = $this->scope->where($userId);
        $where = [$where];
        $q = $criteria['q'] ?? null;
        $q = is_string($q) ? trim($q) : '';
        if ($q !== '') {
            // Préfixes seulement (index utilisables) ; chaque mot doit correspondre au nom ou aux prénoms.
            $like = static fn (string $s): string => addcslashes($s, '\\%_') . '%';
            $or = ['m.member_number LIKE ?'];
            $params[] = $like($q);
            $phone = MemberService::normalizePhone($q);
            if ($phone !== null && strlen($phone) >= 3) {
                $or[] = 'm.phone LIKE ?';
                $params[] = $like($phone);
            }
            $names = [];
            foreach (preg_split('/\s+/u', $q) ?: [] as $word) {
                $names[] = '(m.last_name LIKE ? OR m.last_name LIKE ? OR m.first_names LIKE ? OR m.first_names LIKE ?)';
                array_push($params, $like($word), '% ' . $like($word), $like($word), '% ' . $like($word));
            }
            $or[] = '(' . implode(' AND ', $names) . ')';
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        foreach (['status', 'category_id', 'zone_id', 'profession_id'] as $k) {
            $v = $criteria[$k] ?? null;
            if ($k === 'status' ? is_string($v) && preg_match('/^[a-z_]{1,30}$/', $v) : (is_int($v) || (is_string($v) && ctype_digit($v)))) {
                $where[] = "m.$k = ?";
                $params[] = $k === 'status' ? $v : (int) $v; // critère forgé (tableau, texte) : ignoré, jamais d'erreur SQL
            }
        }
        foreach (['joined_from' => '>=', 'joined_to' => '<='] as $k => $op) {
            if (isset($criteria[$k]) && is_string($criteria[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $criteria[$k])) {
                $where[] = "m.joined_at $op ?";
                $params[] = $criteria[$k];
            }
        }
        $cond = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM members m WHERE $cond", $params);
        $desc = str_starts_with($sort, '-');
        $order = self::SORTS[ltrim($sort, '-')] ?? self::SORTS['name'];
        $dir = $desc ? ' DESC' : '';
        $order = implode(', ', array_map(static fn (string $c): string => $c . $dir, explode(', ', $order))) . ', m.id' . $dir; // m.id : ordre stable
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll("SELECT m.id, m.member_number, m.last_name, m.first_names, m.phone, m.status, m.joined_at, m.zone_id, z.name AS zone_name, c.name AS category_name
            FROM members m LEFT JOIN zones z ON z.id = m.zone_id LEFT JOIN member_categories c ON c.id = m.category_id WHERE $cond ORDER BY $order LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages];
    }
}
