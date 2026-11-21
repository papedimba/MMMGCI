<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class MemberFormTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['member_documents', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    private function delegate(string $email = 'del@x.test', ?int $zone = null): int
    {
        $d = $this->createUser($email, ['delegue']);
        $this->db->update('users', ['zone_id' => $zone ?? $this->zoneA], ['id' => $d]);
        return $d;
    }

    /** Compte de zone qui peut modifier et voir les pièces (le rôle delegue n'a ni members.update ni members.documents.view). */
    private function zoneEditor(int $zone): int
    {
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('zone_editeur', 'Éditeur de zone')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.name = 'zone_editeur' AND p.name IN ('members.view', 'members.create', 'members.update', 'members.documents.view')");
        $u = $this->createUser('zed' . $zone . '@x.test', ['zone_editeur']);
        $this->db->update('users', ['zone_id' => $zone], ['id' => $u]);
        return $u;
    }

    private function member(string $n, string $last, int $zone, array $extra = []): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $last, 'first_names' => 'Yao', 'zone_id' => $zone] + $extra);
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        return $over + ['last_name' => 'Kouassi', 'first_names' => 'Yao', 'birth_date' => '1980-05-01', 'phone' => '0102030405', 'zone_id' => (string) $this->zoneA];
    }

    public function test_create_member_requires_csrf_and_permission(): void
    {
        $this->actingAs($this->createUser('sans@x.test', ['gest_cms']));
        $this->assertSame(403, $this->request('GET', '/admin/adherents/nouveau')->status());
        $this->assertSame(403, $this->request('POST', '/admin/adherents/nouveau', $this->form())->status());
        $this->actingAs($this->createUser('ok@x.test', ['gest_adherents']));
        $this->assertSame(200, $this->request('GET', '/admin/adherents/nouveau')->status());
        // Sans jeton CSRF : refusé, rien n'est écrit.
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', '/admin/adherents/nouveau', [], $this->form(), [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertContains($res->status(), [403, 419]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
        $res = $this->request('POST', '/admin/adherents/nouveau', $this->form());
        $this->assertSame(302, $res->status());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM members WHERE status = 'actif' AND member_number <> ''"));
    }

    public function test_validation_errors_rerender_the_form_not_a_500(): void
    {
        $this->actingAs($this->createUser('ok@x.test', ['gest_adherents']));
        $res = $this->request('POST', '/admin/adherents/nouveau', $this->form(['last_name' => '', 'email' => 'pas-un-email']));
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('value="Yao"', $res->body()); // saisie conservée
        $this->assertStringContainsString('role="alert"', $res->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_status_is_not_exposed_nor_accepted_on_create(): void
    {
        $this->actingAs($this->createUser('ok@x.test', ['gest_adherents']));
        $this->assertStringNotContainsString('name="status"', $this->request('GET', '/admin/adherents/nouveau')->body());
        $this->request('POST', '/admin/adherents/nouveau', $this->form(['status' => 'radie']));
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM members'));
    }

    public function test_duplicate_requires_explicit_confirmation(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA, ['birth_date' => '1980-05-01']);
        $this->member('M2', 'Kouassi', $this->zoneB, ['birth_date' => '1980-05-01']);
        $d = $this->delegate();
        $this->actingAs($d);
        $res = $this->request('POST', '/admin/adherents/nouveau', $this->form());
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Homonymes possibles', $res->body());
        $this->assertStringContainsString('M1', $res->body());
        $this->assertStringNotContainsString('M2', $res->body()); // hors périmètre : compté seulement
        $this->assertStringContainsString('1 homonyme hors de votre périmètre', $res->body());
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
        $this->assertSame(302, $this->request('POST', '/admin/adherents/nouveau', $this->form(['confirm_duplicate' => '1']))->status());
        $this->assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_duplicate_page_with_only_hidden_homonyms_still_allows_creation(): void
    {
        $this->member('M2', 'Kouassi', $this->zoneB, ['birth_date' => '1980-05-01']);
        $this->actingAs($this->delegate());
        $body = $this->request('POST', '/admin/adherents/nouveau', $this->form())->body();
        $this->assertStringContainsString('1 homonyme hors de votre périmètre', $body);
        $this->assertStringContainsString('name="confirm_duplicate"', $body);
        $this->assertStringNotContainsString('M2', $body);
    }

    public function test_delegate_creation_forces_his_zone(): void
    {
        $this->actingAs($this->delegate());
        $html = $this->request('GET', '/admin/adherents/nouveau')->body();
        $this->assertStringNotContainsString('name="zone_id"', $html);
        $this->request('POST', '/admin/adherents/nouveau', $this->form(['zone_id' => (string) $this->zoneB]));
        $this->assertSame($this->zoneA, (int) $this->db->fetchValue('SELECT zone_id FROM members'));
    }

    public function test_user_without_scope_nor_zone_cannot_create(): void
    {
        $this->actingAs($this->createUser('nozone@x.test', ['delegue']));
        $this->assertSame(403, $this->request('GET', '/admin/adherents/nouveau')->status());
        $this->assertSame(403, $this->request('POST', '/admin/adherents/nouveau', $this->form())->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_update_is_audited_with_before_after(): void
    {
        $id = $this->member('M1', 'Kouassi', $this->zoneA, ['employer' => 'Ancien', 'seaman_book_number' => 'LIV-1']);
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $this->assertSame(200, $this->request('GET', "/admin/adherents/$id/modifier")->status());
        $res = $this->request('POST', "/admin/adherents/$id/modifier", $this->form(['employer' => 'Nouveau', 'first_names' => 'Yao', 'seaman_book_number' => 'LIV-1']));
        $this->assertSame(302, $res->status());
        $row = $this->db->fetch("SELECT old_values, new_values FROM audit_logs WHERE action = 'member.updated' AND auditable_id = ?", [$id]);
        $this->assertNotNull($row);
        $this->assertStringContainsString('Ancien', (string) $row['old_values']);
        $this->assertStringContainsString('Nouveau', (string) $row['new_values']);
        $this->assertStringNotContainsString('LIV-1', (string) $row['new_values']);
    }

    public function test_update_without_documents_permission_keeps_sensitive_fields(): void
    {
        $zone = $this->zoneA;
        $id = $this->member('M1', 'Kouassi', $zone, ['seaman_book_number' => 'LIV-9', 'id_document_number' => 'CNI-9']);
        $u = $this->createUser('adm@x.test', ['admin_general']); // members.update, pas members.documents.view
        $this->actingAs($u);
        $html = $this->request('GET', "/admin/adherents/$id/modifier")->body();
        $this->assertStringNotContainsString('seaman_book_number', $html);
        $this->request('POST', "/admin/adherents/$id/modifier", $this->form(['employer' => 'X']));
        $this->assertSame('LIV-9', $this->db->fetchValue('SELECT seaman_book_number FROM members WHERE id = ?', [$id]));
        $this->assertSame('CNI-9', $this->db->fetchValue('SELECT id_document_number FROM members WHERE id = ?', [$id]));
    }

    public function test_edit_out_of_scope_is_404(): void
    {
        $id = $this->member('M2', 'Konan', $this->zoneB);
        $this->actingAs($this->zoneEditor($this->zoneA));
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$id/modifier")->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$id/modifier", $this->form())->status());
    }

    public function test_beneficiary_added_and_removed(): void
    {
        $id = $this->member('M1', 'Kouassi', $this->zoneA);
        $other = $this->member('M2', 'Konan', $this->zoneA);
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $res = $this->request('POST', "/admin/adherents/$id/ayants-droit", ['last_name' => 'Kouassi', 'first_names' => 'Awa', 'relationship' => 'Conjointe', 'phone' => '0102030405']);
        $this->assertSame(302, $res->status());
        $bid = (int) $this->db->fetchValue('SELECT id FROM member_beneficiaries WHERE member_id = ?', [$id]);
        $this->assertGreaterThan(0, $bid);
        $this->assertStringContainsString('Awa', $this->request('GET', "/admin/adherents/$id?onglet=ayants-droit")->body());
        $bad = $this->request('POST', "/admin/adherents/$id/ayants-droit", ['last_name' => '', 'first_names' => 'X', 'relationship' => '']);
        $this->assertSame(422, $bad->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_beneficiaries'));
        // Un ayant droit d'un autre adhérent n'est pas atteignable par son identifiant.
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$other/ayants-droit/$bid/supprimer")->status());
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$id/ayants-droit/$bid/supprimer")->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_beneficiaries'));
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'member.beneficiary_%'"));
    }

    public function test_beneficiary_of_out_of_scope_member_is_404(): void
    {
        $id = $this->member('M2', 'Konan', $this->zoneB);
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $this->db->insert('member_beneficiaries', ['member_id' => $id, 'last_name' => 'A', 'first_names' => 'B', 'relationship' => 'Enfant']);
        $bid = (int) $this->db->fetchValue('SELECT id FROM member_beneficiaries');
        $this->actingAs($this->zoneEditor($this->zoneA));
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$id/ayants-droit", ['last_name' => 'A', 'first_names' => 'B', 'relationship' => 'Enfant'])->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$id/ayants-droit/$bid/supprimer")->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_beneficiaries'));
    }
}
