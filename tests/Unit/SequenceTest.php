<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Sequence;
use Tests\DatabaseTestCase;

final class SequenceTest extends DatabaseTestCase
{
    public function test_sequence_increments_per_name_and_year(): void
    {
        $s = new Sequence($this->db);
        $this->assertSame('DEC-2026-0001', $s->next('dec', 'DEC', 4, 2026));
        $this->assertSame('DEC-2026-0002', $s->next('dec', 'DEC', 4, 2026));
        $this->assertSame('DEC-2027-0001', $s->next('dec', 'DEC', 4, 2027));
    }

    public function test_sequence_joins_existing_transaction(): void
    {
        $s = new Sequence($this->db);
        $r = $this->db->transaction(fn () => $s->next('tx', 'TX', 3, 2026));
        $this->assertSame('TX-2026-001', $r);
        try {
            $this->db->transaction(function () use ($s) {
                $s->next('tx', 'TX', 3, 2026);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('TX-2026-002', $s->next('tx', 'TX', 3, 2026));
    }

    public function test_sequence_is_unique_across_two_connections(): void
    {
        $a = new Sequence($this->db);
        $b = new Sequence(Database::connect(self::config(), test: true));
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[] = $a->next('par', 'P', 4, 2026);
            $seen[] = $b->next('par', 'P', 4, 2026);
        }
        $this->assertCount(100, array_unique($seen));
    }
}
