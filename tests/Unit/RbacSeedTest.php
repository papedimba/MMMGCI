<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\DatabaseTestCase;

final class RbacSeedTest extends DatabaseTestCase
{
    private function holders(string $permission): array
    {
        return array_column($this->db->fetchAll('SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE p.name = ? ORDER BY r.name', [$permission]), 'name');
    }

    public function test_nineteen_roles_and_only_super_admin_is_system(): void
    {
        $this->assertSame(19, (int) $this->db->fetchValue('SELECT COUNT(*) FROM roles'));
        $this->assertSame(['super_admin'], array_column($this->db->fetchAll('SELECT name FROM roles WHERE is_system = 1'), 'name'));
    }

    public function test_no_role_can_approve_or_pay_disbursements_at_install(): void
    {
        $this->assertSame([], $this->holders('disbursement.approve'));
        $this->assertSame([], $this->holders('disbursement.pay'));
    }

    public function test_super_admin_lacks_decision_and_sensitive_permissions(): void
    {
        foreach (['social.decide', 'discipline.decide', 'settings.validate', 'members.documents.view', 'social.medical.view'] as $p) {
            $this->assertNotContains('super_admin', $this->holders($p), $p);
        }
        $this->assertContains('super_admin', $this->holders('users.manage'));
    }

    public function test_medical_data_permission_only_for_social_manager(): void
    {
        $this->assertSame(['gest_social'], $this->holders('social.medical.view'));
        $this->assertSame(['gest_adherents'], $this->holders('members.documents.view'));
    }
}
