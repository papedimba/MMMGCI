<?php
declare(strict_types=1);

namespace App\Modules\AuditLog;

use App\Core\Database;

/** Journal des consultations de données sensibles : insertion seule, lecture paginée pour l'écran du journal. */
final class SensitiveAccess
{
    public const PER_PAGE = 50;

    public function __construct(private Database $db)
    {
    }

    public function record(int $userId, string $subjectType, int $subjectId, string $fieldGroup, ?string $ip): void
    {
        $this->db->insert('sensitive_access_logs', ['user_id' => $userId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'field_group' => $fieldGroup, 'ip' => $ip === null ? null : mb_substr($ip, 0, 45), 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @param array{from?: string, to?: string, user_id?: int} $filters bornes UTC « AAAA-MM-JJ HH:MM:SS » incluses
     * @return array{rows: list<array<string,mixed>>, page: int, pages: int, total: int}
     */
    public function list(array $filters, int $page): array
    {
        [$where, $params] = [[], []];
        foreach (['from' => 's.created_at >= ?', 'to' => 's.created_at <= ?', 'user_id' => 's.user_id = ?'] as $key => $sql) {
            if (isset($filters[$key])) {
                [$where[], $params[]] = [$sql, $filters[$key]];
            }
        }
        $where = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs s $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll(
            "SELECT s.id, s.created_at, s.user_id, u.name AS user_name, s.subject_type, s.subject_id, s.field_group, s.ip
             FROM sensitive_access_logs s LEFT JOIN users u ON u.id = s.user_id $where
             ORDER BY s.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }
}
