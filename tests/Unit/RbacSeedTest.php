<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\DatabaseTestCase;

final class RbacSeedTest extends DatabaseTestCase
{
    /** Matrice A6 transcrite du brief de la tâche 1 (rôle => permissions triées). */
    private const MATRIX = [
        'super_admin' => ['audit.view', 'cms.forms.manage', 'cms.media.manage', 'cms.pages.manage', 'cms.publish', 'cms.settings.manage', 'contributions.export', 'contributions.manage', 'contributions.view', 'delegates.manage', 'delegates.report', 'delegates.view', 'disbursement.initiate', 'discipline.manage', 'discipline.view', 'employment.manage', 'employment.view', 'finance.control', 'finance.manage', 'finance.view', 'governance.manage', 'governance.view', 'members.create', 'members.export', 'members.scope.all', 'members.status', 'members.update', 'members.validate', 'members.view', 'partners.manage', 'partners.view', 'reports.export', 'reports.view', 'roles.manage', 'settings.manage', 'social.manage', 'social.view', 'solidarity.manage', 'solidarity.view', 'users.manage'],
        'admin_general' => ['audit.view', 'cms.forms.manage', 'cms.media.manage', 'cms.pages.manage', 'cms.publish', 'cms.settings.manage', 'contributions.view', 'delegates.manage', 'delegates.report', 'delegates.view', 'discipline.view', 'employment.manage', 'employment.view', 'finance.view', 'governance.manage', 'governance.view', 'members.create', 'members.export', 'members.scope.all', 'members.status', 'members.update', 'members.validate', 'members.view', 'partners.manage', 'partners.view', 'reports.export', 'reports.view', 'roles.manage', 'settings.manage', 'social.view', 'solidarity.view', 'users.manage'],
        'pca' => ['audit.view', 'contributions.view', 'delegates.view', 'discipline.decide', 'discipline.view', 'employment.view', 'finance.view', 'governance.view', 'members.scope.all', 'members.view', 'partners.view', 'reports.export', 'reports.view', 'settings.validate', 'social.decide', 'social.view', 'solidarity.view'],
        'vice_president' => ['contributions.view', 'delegates.manage', 'delegates.report', 'delegates.view', 'discipline.view', 'employment.view', 'finance.view', 'governance.manage', 'governance.view', 'members.scope.all', 'members.view', 'partners.view', 'reports.export', 'reports.view', 'social.view', 'solidarity.view'],
        'secretaire_general' => ['cms.pages.manage', 'contributions.view', 'delegates.manage', 'delegates.report', 'delegates.view', 'disbursement.initiate', 'discipline.manage', 'discipline.view', 'employment.view', 'finance.view', 'governance.manage', 'governance.view', 'members.create', 'members.export', 'members.scope.all', 'members.status', 'members.update', 'members.validate', 'members.view', 'partners.manage', 'partners.view', 'reports.export', 'reports.view', 'social.view', 'solidarity.view'],
        'tresorier_general' => ['contributions.export', 'contributions.manage', 'contributions.view', 'disbursement.initiate', 'finance.manage', 'finance.view', 'governance.view', 'members.scope.all', 'members.view', 'partners.view', 'reports.export', 'reports.view', 'social.view', 'solidarity.manage', 'solidarity.view'],
        'resp_communication' => ['cms.forms.manage', 'cms.media.manage', 'cms.pages.manage', 'cms.publish', 'cms.settings.manage', 'employment.manage', 'employment.view', 'partners.view', 'reports.view'],
        'resp_commission' => ['governance.view', 'members.scope.all', 'members.view', 'reports.view'],
        'delegue' => ['delegates.report', 'members.create', 'members.view', 'social.view'],
        'point_focal' => ['delegates.report', 'members.create', 'members.view', 'social.view'],
        'controleur' => ['audit.view', 'contributions.export', 'contributions.view', 'delegates.view', 'discipline.view', 'employment.view', 'finance.control', 'finance.view', 'governance.view', 'members.scope.all', 'members.view', 'partners.view', 'reports.export', 'reports.view', 'social.view', 'solidarity.view'],
        'gest_adherents' => ['contributions.view', 'delegates.view', 'members.create', 'members.documents.view', 'members.export', 'members.scope.all', 'members.status', 'members.update', 'members.validate', 'members.view', 'reports.view'],
        'gest_social' => ['contributions.view', 'disbursement.initiate', 'employment.view', 'members.scope.all', 'members.view', 'reports.view', 'social.manage', 'social.medical.view', 'social.view', 'solidarity.view'],
        'gest_cms' => ['cms.forms.manage', 'cms.media.manage', 'cms.pages.manage', 'cms.publish'],
        'lecture_seule' => ['contributions.view', 'delegates.view', 'employment.view', 'finance.view', 'governance.view', 'members.scope.all', 'members.view', 'partners.view', 'reports.view', 'solidarity.view'],
        'admin_site' => ['cms.forms.manage', 'cms.media.manage', 'cms.pages.manage', 'cms.publish', 'cms.settings.manage'],
        'editeur' => ['cms.media.manage', 'cms.pages.manage', 'cms.publish'],
        'redacteur' => ['cms.pages.manage'],
        'gest_medias' => ['cms.media.manage'],
    ];

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

    public function test_full_role_permission_matrix_is_pinned(): void
    {
        $actual = [];
        foreach ($this->db->fetchAll('SELECT r.name AS role, p.name AS perm FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p ON p.id = rp.permission_id') as $row) {
            $actual[$row['role']] ??= [];
            if ($row['perm'] !== null) {
                $actual[$row['role']][] = $row['perm'];
            }
        }
        array_walk($actual, static fn (array &$p) => sort($p));
        ksort($actual);
        $expected = self::MATRIX;
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function test_permission_catalogue_has_forty_seven_entries(): void
    {
        $this->assertSame(47, (int) $this->db->fetchValue('SELECT COUNT(*) FROM permissions'));
        // 47 permissions, dont deux (approbation et paiement des décaissements) qu'aucun rôle ne détient à l'installation.
        $granted = array_unique(array_merge(...array_values(self::MATRIX)));
        $this->assertSame(['disbursement.approve', 'disbursement.pay'], array_column($this->db->fetchAll('SELECT name FROM permissions WHERE name NOT IN (' . implode(',', array_fill(0, count($granted), '?')) . ') ORDER BY name', array_values($granted)), 'name'));
    }
}
