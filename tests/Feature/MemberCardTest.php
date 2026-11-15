<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Settings;
use Tests\Support\AdminTestCase;

final class MemberCardTest extends AdminTestCase
{
    private int $zone;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['membership_cards', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query("DELETE FROM login_attempts");
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'members' AND `key` IN ('card_validity_months', 'card_conditions')");
        $this->zone = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->user = $this->createUser('carte@x.test', ['gest_adherents']);
        $this->actingAs($this->user);
    }

    private function member(string $n = 'M1', string $status = 'actif', ?int $zone = null): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => 'Kouassi', 'first_names' => 'Yao', 'zone_id' => $zone ?? $this->zone, 'status' => $status]);
    }

    /** @return array<string,mixed> */
    private function issue(int $memberId, array $post = []): array
    {
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$memberId/carte", $post)->status());
        return $this->db->fetch('SELECT * FROM membership_cards WHERE member_id = ? AND revoked_at IS NULL', [$memberId]);
    }

    private function validate(string $key, mixed $value): void
    {
        (new Settings($this->db))->validate('members', $key, $value, $this->user, 'PV test');
    }

    public function test_issue_creates_unguessable_token_and_revokes_the_previous_card(): void
    {
        $id = $this->member();
        $a = $this->issue($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['verify_token']);
        $this->assertMatchesRegularExpression('/^CARTE-\d{4}-\d{5}$/', $a['card_number']);
        $b = $this->issue($id);
        $this->assertNotSame($a['verify_token'], $b['verify_token']);
        $this->assertNotNull($this->db->fetchValue('SELECT revoked_at FROM membership_cards WHERE id = ?', [$a['id']]));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM membership_cards WHERE member_id = ? AND revoked_at IS NULL', [$id]));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'member_card.%' AND (old_values LIKE ? OR new_values LIKE ?)", ['%' . $b['verify_token'] . '%', '%' . $b['verify_token'] . '%']));
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'member_card.issued'"));
    }

    public function test_validity_is_null_when_setting_not_validated(): void
    {
        $id = $this->member();
        $this->assertNull($this->issue($id)['expires_at']);
        $this->assertStringContainsString('Validité non définie', $this->request('GET', "/admin/adherents/$id/carte")->body());
        $this->validate('card_validity_months', 12);
        $c = $this->issue($id);
        $this->assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->modify('+12 months')->format('Y-m-d'), $c['expires_at']);
    }

    public function test_verify_shows_only_name_number_status_validity(): void
    {
        $id = $this->member();
        $this->db->update('members', ['phone' => '0707070707', 'address' => 'Rue secrète 12'], ['id' => $id]);
        $c = $this->issue($id);
        $res = $this->request('GET', '/verifier/' . $c['verify_token']);
        $body = $res->body();
        $this->assertSame(200, $res->status());
        foreach (['Kouassi Yao', 'M1', 'Carte valide', 'Validité non définie'] as $s) {
            $this->assertStringContainsString($s, $body);
        }
        foreach (['0707070707', 'Rue secrète', 'Abidjan', '/photo', '<img class="card-photo'] as $s) {
            $this->assertStringNotContainsString($s, $body);
        }
        $this->assertStringContainsString('noindex', $res->headers()['X-Robots-Tag']);
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $this->db->update('membership_cards', ['expires_at' => date('Y-m-d', time() + 5 * 86400)], ['id' => $c['id']]);
        $this->assertMatchesRegularExpression('#Valide jusqu\'au \d\d/\d\d/\d{4}#', $this->request('GET', '/verifier/' . $c['verify_token'])->body());
    }

    public function test_unknown_revoked_and_struck_off_cards_look_identical(): void
    {
        $revoked = $this->issue($this->member('M1'));
        $this->request('POST', '/admin/adherents/' . $revoked['member_id'] . '/carte/' . $revoked['id'] . '/revoquer', ['reason' => 'Perte']);
        $struck = $this->issue($this->member('M2', 'radie'));
        $dead = $this->issue($this->member('M3', 'decede'));
        $expired = $this->issue($this->member('M4'));
        $this->db->update('membership_cards', ['expires_at' => '2020-01-01'], ['id' => $expired['id']]);
        $deleted = $this->issue($mid = $this->member('M5'));
        $this->db->update('members', ['deleted_at' => '2026-01-01 00:00:00'], ['id' => $mid]);
        $ref = $this->request('GET', '/verifier/' . str_repeat('a', 64));
        $this->assertSame(200, $ref->status());
        $this->assertStringContainsString('Carte non valide', $ref->body());
        foreach ([$revoked, $struck, $dead, $expired, $deleted] as $c) {
            $res = $this->request('GET', '/verifier/' . $c['verify_token']);
            $this->assertSame(200, $res->status());
            $this->assertSame($ref->body(), $res->body());
        }
        $this->assertSame($ref->body(), $this->request('GET', '/verifier/mal-forme')->body());
    }

    public function test_verify_page_sets_no_cookie_and_starts_no_session(): void
    {
        $c = $this->issue($this->member());
        $this->services['session']->forget('_last_activity');
        $res = $this->request('GET', '/verifier/' . $c['verify_token']);
        $this->assertArrayNotHasKey('Set-Cookie', $res->headers());
        $this->assertNull($this->services['session']->get('_last_activity'));
        $this->assertSame(200, $this->request('GET', '/admin/adherents')->status());
        $this->assertNotNull($this->services['session']->get('_last_activity')); // contrôle : une autre route démarre bien la session
    }

    public function test_verify_is_throttled_per_ip(): void
    {
        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->request('GET', '/verifier/' . str_repeat('b', 64));
        }
        $this->assertSame(429, $last->status());
    }

    public function test_card_print_view_has_qr_container_and_no_inline_script(): void
    {
        $id = $this->member();
        $c = $this->issue($id);
        $res = $this->request('GET', "/admin/adherents/$id/carte/imprimer");
        $body = $res->body();
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('/verifier/' . $c['verify_token'] . '"', $body);
        $this->assertStringContainsString('class="card-cr80"', $body);
        $this->assertStringContainsString('/assets/js/qrcode.js', $body);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/', $body);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=|\son[a-z]+=/', $body);
        $this->assertStringContainsString('Kouassi Yao', $body);
    }

    public function test_revoke_requires_reason_and_other_members_card_is_404(): void
    {
        $a = $this->member('M1');
        $b = $this->member('M2');
        $ca = $this->issue($a);
        $cb = $this->issue($b);
        $this->assertSame(422, $this->request('POST', "/admin/adherents/$a/carte/{$ca['id']}/revoquer", ['reason' => '  '])->status());
        $this->assertNull($this->db->fetchValue('SELECT revoked_at FROM membership_cards WHERE id = ?', [$ca['id']]));
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$a/carte/{$cb['id']}/revoquer", ['reason' => 'x'])->status());
        $this->assertNull($this->db->fetchValue('SELECT revoked_at FROM membership_cards WHERE id = ?', [$cb['id']]));
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$a/carte/{$ca['id']}/revoquer", ['reason' => 'Perte'])->status());
        $this->assertNotNull($this->db->fetchValue('SELECT revoked_at FROM membership_cards WHERE id = ?', [$ca['id']]));
        $this->assertStringContainsString('Perte', (string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'member_card.revoked' ORDER BY id DESC LIMIT 1"));
    }

    public function test_out_of_scope_member_is_404(): void
    {
        $other = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
        $m = $this->member('M9', 'actif', $other);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('zone_card', 'Carte de zone')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.name = 'zone_card' AND p.name IN ('members.view', 'members.update')");
        $u = $this->createUser('zc@x.test', ['zone_card']);
        $this->db->update('users', ['zone_id' => $this->zone], ['id' => $u]);
        $this->actingAs($u);
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$m/carte")->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$m/carte")->status());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$m/carte/imprimer")->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM membership_cards'));
    }

    public function test_validated_conditions_must_be_ticked_before_issue(): void
    {
        $id = $this->member();
        $this->validate('card_conditions', 'Cotisation à jour');
        $res = $this->request('POST', "/admin/adherents/$id/carte");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Cotisation à jour', $res->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM membership_cards'));
        $this->assertStringContainsString('Conditions de délivrance vérifiées', $this->request('GET', "/admin/adherents/$id/carte")->body());
        $this->issue($id, ['conditions_checked' => '1']);
    }

    public function test_pending_conditions_banner_is_shown(): void
    {
        $id = $this->member();
        $this->assertStringContainsString('en attente de validation par les instances', $this->request('GET', "/admin/adherents/$id/carte")->body());
    }
}
