<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Settings;
use Tests\Support\AdminTestCase;

/** Passe de corrections de fin de lot L5 : bandeau du numéro, historique initial, note de compléments, APP_URL en production. */
final class FinalFixesTest extends AdminTestCase
{
    private int $user;
    private int $zone;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['membership_cards', 'sensitive_access_logs', 'member_documents', 'membership_application_files', 'membership_applications', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'members' AND `key` = 'number_format'");
        $this->zone = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->user = $this->createUser('g@x.test', ['gest_adherents']);
        $this->actingAs($this->user);
    }

    public function test_number_format_banner_is_shown_until_validated(): void
    {
        $banner = 'format du numéro de membre';
        foreach (['/admin/adherents/nouveau', '/admin/adherents/demandes/nouvelle'] as $url) {
            $this->assertStringContainsString($banner, $this->request('GET', $url)->body(), $url);
        }
        (new Settings($this->db))->validate('members', 'number_format', 'ADH-{YYYY}-{NNNNN}', $this->user, 'PV test');
        foreach (['/admin/adherents/nouveau', '/admin/adherents/demandes/nouvelle'] as $url) {
            $this->assertStringNotContainsString($banner, $this->request('GET', $url)->body(), $url);
        }
    }

    public function test_exactly_one_initial_history_row_on_both_creation_paths(): void
    {
        $this->request('POST', '/admin/adherents/nouveau', ['last_name' => 'Direct', 'first_names' => 'Awa', 'zone_id' => (string) $this->zone]);
        $direct = (int) $this->db->fetchValue("SELECT id FROM members WHERE last_name = 'Direct'");
        $rows = $this->db->fetchAll('SELECT from_status, reason, decision_ref FROM member_status_history WHERE member_id = ?', [$direct]);
        $this->assertCount(1, $rows);
        $this->assertSame(['Création de la fiche', null, null], [$rows[0]['reason'], $rows[0]['decision_ref'], $rows[0]['from_status']]);

        $this->request('POST', '/admin/adherents/demandes/nouvelle', ['last_name' => 'Instruit', 'first_names' => 'Awa', 'zone_id' => (string) $this->zone]);
        $app = (int) $this->db->fetchValue('SELECT id FROM membership_applications');
        $this->request('POST', "/admin/adherents/demandes/$app/valider");
        $m = (int) $this->db->fetchValue('SELECT member_id FROM membership_applications WHERE id = ?', [$app]);
        $rows = $this->db->fetchAll('SELECT reason, decision_ref FROM member_status_history WHERE member_id = ?', [$m]);
        $this->assertCount(1, $rows);
        $this->assertSame('Adhésion validée', $rows[0]['reason']);
        $this->assertMatchesRegularExpression('/^AD-/', (string) $rows[0]['decision_ref']);
    }

    public function test_complements_note_is_stored_and_shown_escaped(): void
    {
        $this->request('POST', '/admin/adherents/demandes/nouvelle', ['last_name' => 'Instruit', 'first_names' => 'Awa', 'zone_id' => (string) $this->zone]);
        $app = (int) $this->db->fetchValue('SELECT id FROM membership_applications');
        $this->request('POST', "/admin/adherents/demandes/$app/complements", ['note' => '<b>Photo</b> manquante']);
        $this->assertSame('<b>Photo</b> manquante', $this->db->fetchValue('SELECT complements_note FROM membership_applications WHERE id = ?', [$app]));
        $body = $this->request('GET', "/admin/adherents/demandes/$app")->body();
        $this->assertStringContainsString('Derniers compléments demandés', $body);
        $this->assertStringContainsString('&lt;b&gt;Photo&lt;/b&gt; manquante', $body);
        $this->assertStringNotContainsString('<b>Photo</b>', $body);
    }

    public function test_card_print_refuses_to_build_qr_from_host_header_in_production(): void
    {
        $m = $this->db->insert('members', ['member_number' => 'M1', 'last_name' => 'Kone', 'first_names' => 'Awa', 'zone_id' => $this->zone, 'status' => 'actif']);
        $this->request('POST', "/admin/adherents/$m/carte");
        $this->assertStringContainsString('/verifier/', $this->request('GET', "/admin/adherents/$m/carte/imprimer")->body());

        $this->env = ['APP_ENV' => 'production', 'APP_URL' => ''];
        $this->forgetApp();
        $this->actingAs($this->user);
        $https = static fn (string $p): Request => new Request('GET', $p, [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'evil.test'], []);
        $res = $this->app()->handle($https("/admin/adherents/$m/carte/imprimer"));
        $this->assertSame(302, $res->status());
        $page = $this->app()->handle($https("/admin/adherents/$m/carte"))->body();
        $this->assertStringContainsString('APP_URL', $page);
        $this->assertStringNotContainsString('evil.test', $res->body() . $page);
    }
}
