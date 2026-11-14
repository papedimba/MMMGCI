<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Gate;
use Tests\DatabaseTestCase;

final class GateTest extends DatabaseTestCase
{
    private function user(string $email, bool $active = true): int
    {
        return $this->db->insert('users', ['name' => 'U', 'email' => $email, 'password' => 'x', 'is_active' => $active ? 1 : 0]);
    }

    private function grant(int $user, string $role, ?int $zone = null): void
    {
        $this->db->query('INSERT INTO user_roles (user_id, role_id, scope_zone_id) SELECT ?, id, ? FROM roles WHERE name = ?', [$user, $zone, $role]);
    }

    public function test_permissions_are_union_of_roles(): void
    {
        $u = $this->user('a@x.test');
        $this->grant($u, 'redacteur');
        $this->grant($u, 'gest_medias');
        $gate = new Gate($this->db);
        $this->assertEqualsCanonicalizing(['cms.pages.manage', 'cms.media.manage'], $gate->permissions($u));
        $this->assertTrue($gate->allows($u, 'cms.media.manage'));
        $this->assertFalse($gate->allows($u, 'users.manage'));
        $this->assertSame([], $gate->permissions(999999));
    }

    public function test_inactive_user_has_no_permission(): void
    {
        $u = $this->user('b@x.test', false);
        $this->grant($u, 'redacteur');
        $this->assertSame([], (new Gate($this->db))->permissions($u));
        $this->assertSame([], (new Gate($this->db))->zoneScope($u, 'cms.pages.manage'));
    }

    public function test_zone_scope_null_when_any_granting_role_is_unscoped(): void
    {
        $u = $this->user('c@x.test');
        $this->grant($u, 'delegue', 3);
        $this->grant($u, 'lecture_seule');
        $gate = new Gate($this->db);
        $this->assertNull($gate->zoneScope($u, 'members.view'));
        $this->assertSame([3], $gate->zoneScope($u, 'members.create'));
        $this->assertSame([], $gate->zoneScope($u, 'users.manage'));
    }

    public function test_zone_scope_lists_zones_when_all_granting_roles_are_scoped(): void
    {
        $u = $this->user('d@x.test');
        $this->grant($u, 'point_focal', 5);
        $this->grant($u, 'delegue', 3);
        $this->assertSame([3, 5], (new Gate($this->db))->zoneScope($u, 'members.view'));
    }

    public function test_forget_clears_cache_after_role_change(): void
    {
        $u = $this->user('e@x.test');
        $gate = new Gate($this->db);
        $this->assertSame([], $gate->permissions($u));
        $this->grant($u, 'redacteur');
        $this->assertSame([], $gate->permissions($u));
        $gate->forget($u);
        $this->assertSame(['cms.pages.manage'], $gate->permissions($u));
    }
}
