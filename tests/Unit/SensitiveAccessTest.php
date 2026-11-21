<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\AuditLog\SensitiveAccess;
use Tests\DatabaseTestCase;

final class SensitiveAccessTest extends DatabaseTestCase
{
    public function test_sensitive_access_is_recorded_and_listed(): void
    {
        $log = new SensitiveAccess($this->db);
        $log->record(7, 'member', 42, 'identite', '10.0.0.1');
        $log->record(8, 'member', 43, 'sante', null);
        $this->db->query("UPDATE sensitive_access_logs SET created_at = '2026-03-10 05:00:00' WHERE subject_id = 42");

        $all = $log->list([], 1);
        $this->assertSame(2, $all['total']);
        $this->assertSame([43, 42], array_map(static fn ($r) => (int) $r['subject_id'], $all['rows']));

        $byUser = $log->list(['user_id' => 7], 1);
        $this->assertSame(['member', 42, 'identite', '10.0.0.1'], [$byUser['rows'][0]['subject_type'], (int) $byUser['rows'][0]['subject_id'],
            $byUser['rows'][0]['field_group'], $byUser['rows'][0]['ip']]);
        $this->assertSame(1, $byUser['total']);

        $byDate = $log->list(['from' => '2026-03-10 00:00:00', 'to' => '2026-03-10 23:59:59'], 1);
        $this->assertSame([42], array_map(static fn ($r) => (int) $r['subject_id'], $byDate['rows']));
    }
}
