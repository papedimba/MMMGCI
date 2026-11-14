<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/** Journal d'audit chaîné par HMAC : ajout seul, altération et suppression détectables par verify(). */
final class Audit
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Changer APP_KEY rend verify() en échec sur tout l'historique existant : la clé ne se change jamais en production. */
    public function __construct(private Database $db, private string $appKey)
    {
        $bytes = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if ($bytes === false || strlen($bytes) < 32) {
            throw new RuntimeException('APP_KEY manquante ou trop courte : générez-la avec php -r "echo \'base64:\'.base64_encode(random_bytes(32));" et placez-la dans .env.');
        }
    }

    /**
     * Rejoint la transaction ouverte (écritures métier et audit validés ensemble), sinon en ouvre une.
     * La ligne unique d'audit_chain est verrouillée FOR UPDATE jusqu'au commit : les log() sont sérialisés
     * sans verrou de plage ni fourche, quel que soit le niveau d'isolation, même table vide.
     *
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(?int $userId, string $action, string $type, ?int $id, ?array $old, ?array $new,
        ?string $reference = null, ?string $ip = null, ?string $ua = null): int
    {
        if ($reference !== null && mb_strlen($reference) > 30) {
            throw new InvalidArgumentException('La référence d\'audit dépasse 30 caractères.');
        }
        $clean = fn (?string $v, int $max): ?string => $v === null ? null : mb_substr(mb_scrub($v, 'UTF-8'), 0, $max);
        $ip = $clean($ip, 45);
        $ua = $clean($ua, 255);
        $write = function () use ($userId, $action, $type, $id, $old, $new, $reference, $ip, $ua): int {
            $prev = $this->db->fetchValue('SELECT last_hash FROM audit_chain WHERE id = 1 FOR UPDATE');
            if ($prev === null) {
                throw new RuntimeException('La table audit_chain est vide : appliquez la migration 0002_audit_chain.');
            }
            $row = [
                'user_id' => $userId, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id,
                'old_values' => $old === null ? null : self::json($old),
                'new_values' => $new === null ? null : self::json($new),
                'ip' => $ip, 'user_agent' => $ua, 'reference' => $reference,
                'prev_hash' => $prev, 'hash' => self::GENESIS, // hash provisoire : l'id n'existe qu'après l'INSERT
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $logId = $this->db->insert('audit_logs', $row);
            $hash = $this->hash($prev, $logId, $row);
            $this->db->update('audit_logs', ['hash' => $hash], ['id' => $logId]);
            $this->db->query('UPDATE audit_chain SET last_id = ?, last_hash = ?, row_count = row_count + 1 WHERE id = 1', [$logId, $hash]);
            return $logId;
        };
        return $this->db->atomic($write);
    }

    /**
     * Id de la première ligne altérée ou dont le prédécesseur a disparu ; si seules des lignes de fin
     * ont été supprimées (écart avec audit_chain), id qui suit la dernière ligne intacte. null si intacte.
     */
    public function verify(): ?int
    {
        // audit_chain lu d'abord : une ligne validée pendant le parcours (id > last_id) est ignorée, pas une fausse alerte.
        $chain = $this->db->fetch('SELECT last_id, last_hash, row_count FROM audit_chain WHERE id = 1');
        $max = (int) ($chain['last_id'] ?? 0);
        $expected = self::GENESIS;
        $lastId = 0;
        $count = 0;
        do {
            $rows = $this->db->fetchAll('SELECT * FROM audit_logs WHERE id > ? AND id <= ? ORDER BY id LIMIT 1000', [$lastId, $max]);
            foreach ($rows as $r) {
                if ($r['prev_hash'] !== $expected || !hash_equals($this->hash($expected, (int) $r['id'], $r), $r['hash'])) {
                    return (int) $r['id'];
                }
                $expected = $r['hash'];
                $lastId = (int) $r['id'];
                $count++;
            }
        } while (count($rows) === 1000);
        if ($chain === null || $chain['last_hash'] !== $expected || $lastId !== $max || (int) $chain['row_count'] !== $count) {
            return $lastId + 1;
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
