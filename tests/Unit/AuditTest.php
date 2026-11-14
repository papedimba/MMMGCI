<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit;
use ReflectionClass;
use ReflectionMethod;
use Tests\DatabaseTestCase;

final class AuditTest extends DatabaseTestCase
{
    private Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM audit_logs');
        $this->db->query("UPDATE audit_chain SET last_id = NULL, last_hash = REPEAT('0', 64), row_count = 0");
        $this->audit = new Audit($this->db, 'cle-de-test');
    }

    private function three(): array
    {
        return [
            $this->audit->log(1, 'create', 'Member', 10, null, ['nom' => 'Élodie'], 'REF-1', '127.0.0.1', 'UA'),
            $this->audit->log(1, 'update', 'Member', 10, ['nom' => 'Élodie'], ['nom' => 'Zoé']),
            $this->audit->log(null, 'delete', 'Member', 10, ['nom' => 'Zoé'], null),
        ];
    }

    public function test_chain_links_each_entry_to_previous(): void
    {
        [$a, $b, $c] = $this->three();
        $rows = $this->db->fetchAll('SELECT id, prev_hash, hash FROM audit_logs ORDER BY id');
        $this->assertSame([$a, $b, $c], array_map(fn ($r) => (int) $r['id'], $rows));
        $this->assertSame(str_repeat('0', 64), $rows[0]['prev_hash']);
        $this->assertSame($rows[0]['hash'], $rows[1]['prev_hash']);
        $this->assertSame($rows[1]['hash'], $rows[2]['prev_hash']);
        $this->assertNull($this->audit->verify());
    }

    public function test_verify_detects_tampered_row(): void
    {
        [, $b] = $this->three();
        $this->db->query('UPDATE audit_logs SET new_values = ? WHERE id = ?', ['{"nom":"Pirate"}', $b]);
        $this->assertSame($b, $this->audit->verify());
    }

    public function test_verify_detects_deleted_row(): void
    {
        [, $b, $c] = $this->three();
        $this->db->query('DELETE FROM audit_logs WHERE id = ?', [$b]);
        $this->assertSame($c, $this->audit->verify());
    }

    public function test_log_joins_open_transaction_and_rolls_back_with_it(): void
    {
        $this->db->pdo()->beginTransaction();
        $this->audit->log(1, 'create', 'Member', 1, null, ['a' => 1]);
        $this->db->pdo()->rollBack();
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM audit_logs'));
    }

    public function test_class_exposes_no_update_or_delete_method(): void
    {
        $names = array_map(fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(Audit::class))->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($names);
        $this->assertSame(['__construct', 'log', 'verify'], $names);
    }

    public function test_verify_detects_deleted_first_row(): void
    {
        [$a, $b] = $this->three();
        $this->db->query('DELETE FROM audit_logs WHERE id = ?', [$a]);
        $this->assertSame($b, $this->audit->verify());
    }

    public function test_verify_detects_deleted_trailing_rows(): void
    {
        [$a, $b, $c] = $this->three();
        $this->db->query('DELETE FROM audit_logs WHERE id >= ?', [$b]);
        $this->assertSame($b, $this->audit->verify());
        $this->db->query('DELETE FROM audit_logs');
        $this->assertSame(1, $this->audit->verify());
    }

    public function test_verify_detects_edited_hashes(): void
    {
        [$a, $b] = $this->three();
        $this->db->query('UPDATE audit_logs SET hash = ? WHERE id = ?', [str_repeat('a', 64), $b]);
        $this->assertSame($b, $this->audit->verify());
        $this->db->query('UPDATE audit_logs SET hash = ?, prev_hash = ? WHERE id = ?', [str_repeat('b', 64), str_repeat('c', 64), $a]);
        $this->assertSame($a, $this->audit->verify());
    }

    public function test_verify_walks_more_than_one_batch(): void
    {
        for ($i = 0; $i < 1005; $i++) {
            $this->audit->log(1, 'bulk', 'T', $i, null, null);
        }
        $this->assertNull($this->audit->verify());
    }

    public function test_client_fields_are_sanitised(): void
    {
        $id = $this->audit->log(1, 'login', 'User', 1, null, null, null, '127.0.0.1', str_repeat('é', 300));
        $this->assertSame(255, mb_strlen((string) $this->db->fetchValue('SELECT user_agent FROM audit_logs WHERE id = ?', [$id])));
        $this->audit->log(1, 'login', 'User', 1, null, null, null, null, "Mozilla\xFF\xFE");
        $this->assertNull($this->audit->verify());
    }

    public function test_too_long_reference_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->audit->log(1, 'x', 'T', 1, null, null, str_repeat('R', 31));
    }

    public function test_concurrent_processes_keep_a_single_chain(): void
    {
        $procs = [];
        for ($w = 1; $w <= 4; $w++) {
            $procs[] = proc_open([PHP_BINARY, __DIR__ . '/../Support/audit_worker.php', (string) $w, '50'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
        }
        $failures = 0;
        foreach ($procs as $i => $p) {
            $failures += (int) stream_get_contents($pipes[$i + 1][1]);
            proc_close($p);
        }
        $this->assertSame(0, $failures);
        $this->assertSame(200, (int) $this->db->fetchValue('SELECT COUNT(*) FROM audit_logs'));
        $this->assertSame(200, (int) $this->db->fetchValue('SELECT COUNT(DISTINCT prev_hash) FROM audit_logs'));
        $this->assertNull($this->audit->verify());
    }
}
