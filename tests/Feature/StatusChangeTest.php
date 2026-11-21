<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Gate;
use App\Core\NotFound;
use App\Modules\Members\MemberScope;
use App\Modules\Members\StatusInvalid;
use App\Modules\Members\StatusService;
use Tests\Support\AdminTestCase;

final class StatusChangeTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;
    private int $member;
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['sensitive_access_logs', 'member_documents', 'membership_applications', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
        $this->manager = $this->createUser('g@x.test', ['gest_adherents']);
        $this->member = $this->newMember($this->zoneA);
    }

    private function newMember(int $zone, string $last = 'Kouassi'): int
    {
        return $this->db->insert('members', ['member_number' => 'M-' . bin2hex(random_bytes(3)), 'last_name' => $last, 'first_names' => 'Yao', 'status' => 'actif', 'zone_id' => $zone]);
    }

    private function service(): StatusService
    {
        $this->app();
        return new StatusService($this->db, $this->services['audit'](), new MemberScope($this->db, new Gate($this->db)));
    }

    private function memberStatus(?int $id = null): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM members WHERE id = ?', [$id ?? $this->member]);
    }

    private function histCount(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_status_history WHERE member_id = ?', [$this->member]);
    }

    public function test_status_change_requires_reason_and_decision_reference(): void
    {
        foreach ([['', 'PV-1'], ["  \n", 'PV-1'], ['Motif', ''], ['Motif', '   ']] as [$reason, $ref]) {
            try {
                $this->service()->change($this->member, 'suspendu', $reason, $ref, $this->manager);
                $this->fail('Motif et référence obligatoires.');
            } catch (StatusInvalid $e) {
                $this->assertNotSame([], $e->errors);
            }
        }
        try {
            $this->service()->change($this->member, 'suspendu', str_repeat('a', 2001), str_repeat('b', 101), $this->manager);
            $this->fail('Longueurs plafonnées.');
        } catch (StatusInvalid $e) {
            $this->assertSame(['reason', 'decision_ref'], array_keys($e->errors));
        }
        $this->assertSame('actif', $this->memberStatus());
        $this->assertSame(0, $this->histCount());
        $this->actingAs($this->manager);
        $r = $this->request('POST', "/admin/adherents/{$this->member}/statut", ['to' => 'suspendu', 'reason' => ' ', 'decision_ref' => '']);
        $this->assertSame(422, $r->status());
        $this->assertStringContainsString('Le motif est obligatoire', $r->body());
        $this->assertStringContainsString('La référence de la décision est obligatoire', $r->body());
        $this->assertSame('actif', $this->memberStatus());
        $this->service()->change($this->member, 'suspendu', '  Impayés  ', ' PV-12 ', $this->manager);
        $h = $this->db->fetch('SELECT * FROM member_status_history WHERE member_id = ?', [$this->member]);
        $this->assertSame(['Impayés', 'PV-12', 'actif', 'suspendu', $this->manager], [$h['reason'], $h['decision_ref'], $h['from_status'], $h['to_status'], (int) $h['changed_by']]);
        $this->assertNotNull($this->db->fetchValue('SELECT status_changed_at FROM members WHERE id = ?', [$this->member]));
        $a = $this->db->fetch("SELECT old_values, new_values FROM audit_logs WHERE action = 'member.status_changed'");
        $this->assertStringContainsString('Impayés', (string) $a['new_values']);
    }

    public function test_history_is_append_only_and_complete(): void
    {
        $this->service()->change($this->member, 'suspendu', 'Un', 'R1', $this->manager);
        $this->service()->change($this->member, 'actif', 'Deux', 'R2', $this->manager);
        $this->service()->change($this->member, 'radie', 'Trois', 'R3', $this->manager);
        $rows = $this->db->fetchAll('SELECT from_status, to_status, reason FROM member_status_history WHERE member_id = ? ORDER BY id', [$this->member]);
        $this->assertSame([['actif', 'suspendu', 'Un'], ['suspendu', 'actif', 'Deux'], ['actif', 'radie', 'Trois']], array_map('array_values', $rows));
        // Concurrence : le changement suivant part toujours du statut réel (relu sous verrou), jamais d'une valeur périmée.
        $this->db->query("UPDATE members SET status = 'suspendu' WHERE id = ?", [$this->member]);
        $this->service()->change($this->member, 'decede', 'Quatre', 'R4', $this->manager);
        $this->assertSame('suspendu', $this->db->fetchValue('SELECT from_status FROM member_status_history WHERE reason = ?', ['Quatre']));
        // Aucune écriture de l'application ne modifie ni ne supprime l'historique.
        foreach (['app', 'routes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . "/$dir", \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php') {
                    $this->assertDoesNotMatchRegularExpression('/(UPDATE|DELETE\s+FROM)\s+member_status_history|update\(\s*.member_status_history|delete\(\s*.member_status_history/i', (string) file_get_contents($f->getPathname()), $f->getPathname());
                }
            }
        }
    }

    public function test_same_status_is_refused(): void
    {
        $this->expectException(StatusInvalid::class);
        try {
            $this->service()->change($this->member, 'actif', 'Rien', 'R', $this->manager);
        } finally {
            $this->assertSame(0, $this->histCount());
        }
    }

    public function test_unknown_or_inactive_status_is_refused(): void
    {
        $this->db->query("UPDATE member_statuses SET is_active = 0 WHERE code = 'radie'");
        try {
            foreach (['inconnu', 'radie', ''] as $to) {
                try {
                    $this->service()->change($this->member, $to, 'Motif', 'R', $this->manager);
                    $this->fail("Statut $to accepté.");
                } catch (StatusInvalid $e) {
                    $this->assertArrayHasKey('to', $e->errors);
                }
            }
            $this->assertSame('actif', $this->memberStatus());
            $this->assertSame(0, $this->histCount());
        } finally {
            $this->db->query("UPDATE member_statuses SET is_active = 1 WHERE code = 'radie'");
        }
    }

    public function test_requires_members_status_permission(): void
    {
        $viewer = $this->createUser('v@x.test', ['delegue']);
        $this->actingAs($viewer);
        $this->assertSame(403, $this->request('GET', "/admin/adherents/{$this->member}/statut")->status());
        $this->assertSame(403, $this->request('POST', "/admin/adherents/{$this->member}/statut", ['to' => 'suspendu', 'reason' => 'x', 'decision_ref' => 'y'])->status());
        $this->assertSame('actif', $this->memberStatus());
        $this->actingAs($this->manager);
        $this->assertStringContainsString('Changer le statut', $this->request('GET', "/admin/adherents/{$this->member}")->body());
        $r = $this->request('POST', "/admin/adherents/{$this->member}/statut", ['to' => 'suspendu', 'reason' => 'Impayés', 'decision_ref' => 'PV-1']);
        $this->assertSame(302, $r->status());
        $this->assertSame('suspendu', $this->memberStatus());
    }

    public function test_out_of_scope_member_is_404(): void
    {
        // Rôle de test avec members.view + members.status mais sans members.scope.all : limité à sa zone.
        $role = $this->db->insert('roles', ['name' => 'zone_status_test', 'label' => 'Test zone']);
        $this->db->query("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name IN ('members.view', 'members.status')", [$role]);
        $zoned = $this->createUser('z@x.test', ['zone_status_test']);
        $this->db->update('users', ['zone_id' => $this->zoneB], ['id' => $zoned]);
        $this->actingAs($zoned);
        $this->assertSame(404, $this->request('GET', "/admin/adherents/{$this->member}/statut")->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/{$this->member}/statut", ['to' => 'suspendu', 'reason' => 'x', 'decision_ref' => 'y'])->status());
        $this->assertSame('actif', $this->memberStatus());
        $this->expectException(NotFound::class);
        $this->service()->change($this->member, 'suspendu', 'x', 'y', $zoned);
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE name = 'zone_status_test')");
        $this->db->query("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE name = 'zone_status_test')");
        $this->db->query("DELETE FROM roles WHERE name = 'zone_status_test'");
    }

    public function test_soft_deleted_member_is_not_found(): void
    {
        $this->db->query('UPDATE members SET deleted_at = NOW() WHERE id = ?', [$this->member]);
        $this->expectException(NotFound::class);
        $this->service()->change($this->member, 'suspendu', 'x', 'y', $this->manager);
    }

    public function test_hostile_reason_is_escaped_in_history_tab(): void
    {
        $this->service()->change($this->member, 'suspendu', '<script>alert(1)</script>', '"><img src=x>', $this->manager);
        $this->actingAs($this->manager);
        $body = $this->request('GET', "/admin/adherents/{$this->member}?onglet=historique")->body();
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringNotContainsString('<img src=x>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $body);
        $this->assertStringContainsString('Actif → Suspendu', $body);
    }
}
