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
}
