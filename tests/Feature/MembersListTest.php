<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class MembersListTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    private function member(string $n, string $last, int $zone, array $extra = []): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $last, 'first_names' => 'Yao', 'zone_id' => $zone] + $extra);
    }

    public function test_list_requires_members_view(): void
    {
        $this->actingAs($this->createUser('sans@x.test', ['gest_cms']));
        $this->assertSame(403, $this->request('GET', '/admin/adherents')->status());
        $this->assertSame(403, $this->request('GET', '/admin/adherents/1')->status());
        $this->actingAs($this->createUser('ok@x.test', ['gest_adherents']));
        $this->assertSame(200, $this->request('GET', '/admin/adherents')->status());
    }

    public function test_list_shows_only_scoped_members_for_a_delegate(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA);
        $this->member('M2', 'Konan', $this->zoneB);
        $d = $this->createUser('del@x.test', ['delegue']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $d]);
        $this->actingAs($d);
        $html = $this->request('GET', '/admin/adherents')->body();
        $this->assertStringContainsString('Kouassi', $html);
        $this->assertStringNotContainsString('Konan', $html);
        $this->assertStringContainsString('1 adhérent.', $html);
    }

    public function test_show_out_of_scope_member_is_404(): void
    {
        $other = $this->member('M2', 'Konan', $this->zoneB);
        $d = $this->createUser('del@x.test', ['delegue']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $d]);
        $this->actingAs($d);
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$other")->status());
        $this->assertSame(404, $this->request('GET', '/admin/adherents/999999')->status());
    }

    public function test_hostile_names_are_escaped_in_list_and_show(): void
    {
        $id = $this->member('M1', '<script>alert(1)</script>', $this->zoneA, ['employer' => '<img src=x onerror=1>']);
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        foreach (['/admin/adherents?q=' . urlencode('<script>'), '/admin/adherents', "/admin/adherents/$id", "/admin/adherents/$id?onglet=profession"] as $url) {
            $html = $this->request('GET', $url)->body();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $this->request('GET', "/admin/adherents/$id")->body());
    }

    public function test_documents_tab_hidden_without_permission_and_sensitive_numbers_logged(): void
    {
        $id = $this->member('M1', 'Kouassi', $this->zoneA, ['seaman_book_number' => 'LIV-777', 'seaman_book_expiry' => '2030-01-01']);
        $this->actingAs($this->createUser('adm@x.test', ['admin_general'])); // members.view sans members.documents.view
        $html = $this->request('GET', "/admin/adherents/$id?onglet=profession")->body();
        $this->assertStringNotContainsString('onglet=pieces', $html);
        $this->assertStringNotContainsString('LIV-777', $html);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));

        $this->actingAs($this->createUser('gest@x.test', ['gest_adherents']));
        $html = $this->request('GET', "/admin/adherents/$id?onglet=profession")->body();
        $this->assertStringContainsString('onglet=pieces', $html);
        $this->assertStringContainsString('LIV-777', $html);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE subject_id = ? AND field_group = 'seaman_book_number'", [$id]));
    }

    public function test_empty_list_shows_empty_state(): void
    {
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $html = $this->request('GET', '/admin/adherents')->body();
        $this->assertStringContainsString('Aucun adhérent pour le moment', $html);
        $this->assertStringContainsString('/admin/adherents/nouveau', $html);
    }

    public function test_tabs_history_and_later_lot_states(): void
    {
        $id = $this->member('M1', 'Kouassi', $this->zoneA);
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $this->actingAs($u);
        $this->db->insert('member_status_history', ['member_id' => $id, 'from_status' => 'actif', 'to_status' => 'suspendu', 'reason' => 'Impayés', 'changed_by' => $u]);
        $this->assertStringContainsString('Impayés', $this->request('GET', "/admin/adherents/$id?onglet=historique")->body());
        // L6 T3 : l'espace réservé a disparu ; sans contributions.view l'onglet n'existe pas (repli sur l'identité).
        $this->assertStringNotContainsString('Disponible avec un prochain lot', $this->request('GET', "/admin/adherents/$id?onglet=cotisations")->body());
        $this->assertSame(200, $this->request('GET', "/admin/adherents/$id?onglet=inconnu")->status());
    }
}
