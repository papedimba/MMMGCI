<?php
declare(strict_types=1);

namespace App\Core;

/** Journal d'audit chaîné par HMAC : ajout seul, altération et suppression détectables par verify(). */
final class Audit
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private Database $db, private string $appKey)
    {
    }

    /**
     * Rejoint la transaction ouverte (écritures métier et audit validés ensemble), sinon en ouvre une.
     * Le verrou FOR UPDATE sur la dernière ligne est tenu jusqu'au commit : deux log() ne peuvent pas
     * se chaîner au même prev_hash. Table vide : le verrou de plage InnoDB fait échouer l'un des deux
     * appels concurrents (interblocage signalé) au lieu de forker la chaîne ; pas de GET_LOCK, car il
     * serait relâché avant le commit d'une transaction externe.
     *
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(?int $userId, string $action, string $type, ?int $id, ?array $old, ?array $new,
        ?string $reference = null, ?string $ip = null, ?string $ua = null): int
    {
        $write = function () use ($userId, $action, $type, $id, $old, $new, $reference, $ip, $ua): int {
            $prev = $this->db->fetchValue('SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE') ?? self::GENESIS;
            $row = [
                'user_id' => $userId, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id,
                'old_values' => $old === null ? null : self::json($old),
                'new_values' => $new === null ? null : self::json($new),
                'ip' => $ip, 'user_agent' => $ua, 'reference' => $reference,
                'prev_hash' => $prev, 'hash' => self::GENESIS, // hash provisoire : l'id n'existe qu'après l'INSERT
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $logId = $this->db->insert('audit_logs', $row);
            $this->db->update('audit_logs', ['hash' => $this->hash($prev, $logId, $row)], ['id' => $logId]);
            return $logId;
        };
        return $this->db->pdo()->inTransaction() ? $write() : $this->db->transaction($write);
    }

    /** Id de la première ligne altérée (ou dont le prédécesseur a disparu), null si la chaîne est intacte. */
    public function verify(): ?int
    {
        // shortcut: la suppression de la toute dernière ligne est indétectable sans ancre externe.
        $expected = self::GENESIS;
        foreach ($this->db->query('SELECT * FROM audit_logs ORDER BY id') as $r) {
            if ($r['prev_hash'] !== $expected || !hash_equals($this->hash($expected, (int) $r['id'], $r), $r['hash'])) {
                return (int) $r['id'];
            }
            $expected = $r['hash'];
        }
        return null;
    }

    /** @param array<string,mixed> $r */
    private function hash(string $prev, int $id, array $r): string
    {
        $fields = [$id, $r['user_id'], $r['action'], $r['auditable_type'], $r['auditable_id'], $r['old_values'],
            $r['new_values'], $r['ip'], $r['user_agent'], $r['reference'], $r['created_at']];
        return hash_hmac('sha256', $prev . self::json($fields), $this->appKey);
    }

    private static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
