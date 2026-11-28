<?php
declare(strict_types=1);

namespace App\Modules\AuditLog;

use App\Core\Database;

/** Lecture seule du journal d'audit pour l'écran d'administration. */
final class AuditQuery
{
    public const PER_PAGE = 50;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{from?: string, to?: string, user_id?: int, action?: string} $filters bornes UTC incluses ; action : préfixe [a-z._]
     * @return array{rows: list<array<string,mixed>>, page: int, pages: int, total: int}
     */
    public function list(array $filters, int $page): array
    {
        [$where, $params] = [[], []];
        foreach (['from' => 'a.created_at >= ?', 'to' => 'a.created_at <= ?', 'user_id' => 'a.user_id = ?'] as $key => $sql) {
            if (isset($filters[$key])) {
                [$where[], $params[]] = [$sql, $filters[$key]];
            }
        }
        if (isset($filters['action'])) {
            [$where[], $params[]] = ['a.action LIKE ?', addcslashes($filters['action'], '%_\\') . '%']; // « _ » littéral, pas un joker
        }
        $where = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs a $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.created_at, a.user_id, u.name AS user_name, a.action, a.auditable_type, a.auditable_id, a.reference, a.ip,
                    a.old_values, a.new_values
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where
             ORDER BY a.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }
}
