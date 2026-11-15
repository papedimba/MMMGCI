<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Gate;
use App\Core\NotFound;
use App\Modules\Members\MemberScope;
use Tests\DatabaseTestCase;

final class MemberScopeTest extends DatabaseTestCase
{
    private int $zoneA;
    private int $zoneB;
    private int $inA;
    private int $inB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
        $this->db->query('DELETE FROM members');
        $this->db->query('DELETE FROM zones');
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
        $this->inA = $this->member('M-A', $this->zoneA);
        $this->inB = $this->member('M-B', $this->zoneB);
    }

    private function member(string $n, int $zone): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $n, 'first_names' => 'X', 'zone_id' => $zone]);
    }

    private function user(string $role, ?int $zone): int
    {
        $id = $this->db->insert('users', ['name' => $role, 'email' => "$role" . random_int(1, 99999) . '@x.test', 'password' => 'x', 'zone_id' => $zone]);
        $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        return $id;
    }

    private function scope(): MemberScope
    {
        return new MemberScope($this->db, new Gate($this->db));
    }

    /** @return list<int> */
    private function visible(int $user): array
    {
        [$sql, $p] = $this->scope()->where($user);
        return array_map('intval', array_column($this->db->fetchAll("SELECT m.id FROM members m WHERE $sql ORDER BY m.id", $p), 'id'));
    }

    public function test_user_without_scope_all_and_without_zone_sees_nobody(): void
    {
        $u = $this->user('delegue', null);
        $this->assertSame(0, $this->scope()->forUser($u));
        $this->assertSame([], $this->visible($u));
    }

    public function test_zone_user_sees_only_his_zone(): void
    {
        $u = $this->user('delegue', $this->zoneA);
        $this->assertSame($this->zoneA, $this->scope()->forUser($u));
        $this->assertSame([$this->inA], $this->visible($u));
    }

    public function test_scope_all_sees_everyone(): void
    {
        $u = $this->user('gest_adherents', null);
        $this->assertNull($this->scope()->forUser($u));
        $this->assertSame([$this->inA, $this->inB], $this->visible($u));
    }

    public function test_out_of_scope_member_is_not_found(): void
    {
        $u = $this->user('point_focal', $this->zoneA);
        $this->scope()->assertMayAccess($u, $this->inA);
        $this->expectException(NotFound::class);
        $this->scope()->assertMayAccess($u, $this->inB);
    }

    public function test_unknown_member_is_not_found(): void
    {
        $this->expectException(NotFound::class);
        $this->scope()->assertMayAccess($this->user('gest_adherents', null), 999999);
    }

    public function test_soft_deleted_member_is_not_found_for_everyone(): void
    {
        $this->db->query('UPDATE members SET deleted_at = NOW() WHERE id = ?', [$this->inA]);
        $all = $this->user('gest_adherents', null);
        $zone = $this->user('delegue', $this->zoneA);
        $this->assertSame([$this->inB], $this->visible($all));
        $this->assertSame([], $this->visible($zone));
        $this->expectException(NotFound::class);
        $this->scope()->assertMayAccess($all, $this->inA);
    }
}
