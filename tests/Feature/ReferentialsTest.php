<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use Tests\Support\AdminTestCase;

final class ReferentialsTest extends AdminTestCase
{
    private const URL = '/admin/adherents/referentiels';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
        $this->db->query('DELETE FROM zones');
        $this->db->query('DELETE FROM maritime_professions');
        $this->actingAs($this->createUser('admin@x.test', ['admin_general']));
    }

    public function test_create_zone_and_profession_then_deactivate_never_deletes(): void
    {
        $this->assertSame(302, $this->request('POST', self::URL, ['onglet' => 'zones', 'name' => 'Port d\'Abidjan', 'type' => 'port'])->status());
        $zone = $this->db->fetch("SELECT * FROM zones WHERE name = 'Port d''Abidjan'");
        $this->assertSame('port', $zone['type']);
        $this->request('POST', self::URL, ['onglet' => 'professions', 'name' => 'Capitaine']);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM maritime_professions'));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'referential.created' AND auditable_type = 'zones'"));

        $this->assertSame(302, $this->request('POST', self::URL . '/activation', ['onglet' => 'zones', 'id' => (string) $zone['id'], 'active' => '0'])->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT is_active FROM zones WHERE id = ?', [$zone['id']]));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM zones'));
    }

    public function test_duplicate_and_invalid_input_rejected(): void
    {
        $this->request('POST', self::URL, ['onglet' => 'categories', 'name' => 'Gens de mer']);
        $this->assertSame(422, $this->request('POST', self::URL, ['onglet' => 'categories', 'name' => 'Gens de mer'])->status());
        $this->assertSame(422, $this->request('POST', self::URL, ['onglet' => 'zones', 'name' => 'Z', 'type' => 'ocean'])->status());
        $this->assertSame(422, $this->request('POST', self::URL, ['onglet' => 'categories', 'name' => ' '])->status());
    }

    public function test_forbidden_without_settings_manage(): void
    {
        $this->actingAs($this->createUser('lect@x.test', ['lecture_seule']));
        $this->assertSame(403, $this->request('GET', self::URL)->status());
        $this->assertSame(403, $this->request('POST', self::URL, ['onglet' => 'zones', 'name' => 'Z', 'type' => 'port'])->status());
    }

    public function test_hostile_label_is_escaped_and_status_banner_shown(): void
    {
        $this->request('POST', self::URL, ['onglet' => 'categories', 'name' => '<script>alert(1)</script>']);
        $html = $this->request('GET', self::URL)->body();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        $this->assertStringContainsString('href="/admin/adherents/referentiels"', $html); // lien de menu
        $statuts = $this->request('GET', self::URL . '?onglet=statuts')->body();
        $this->assertStringContainsString('paramètre en attente de validation par les instances', $statuts);
    }

    public function test_user_form_sets_zone_and_rejects_unknown_zone(): void
    {
        $zone = $this->db->insert('zones', ['name' => 'Sassandra', 'type' => 'port']);
        $u = $this->createUser('cible@x.test', ['lecture_seule']);
        $post = ['name' => 'Cible', 'email' => 'cible@x.test', 'phone' => ''];
        $this->assertSame(302, $this->request('POST', "/admin/utilisateurs/$u", $post + ['zone_id' => (string) $zone])->status());
        $this->assertSame($zone, (int) $this->db->fetchValue('SELECT zone_id FROM users WHERE id = ?', [$u]));
        $svc = new \App\Modules\Users\UserService($this->db, $this->services['audit'](), $this->services['gate']());
        $svc->update($u, ['name' => 'Cible 2', 'email' => 'cible@x.test'], null); // sans clé zone_id : zone conservée
        $this->assertSame($zone, (int) $this->db->fetchValue('SELECT zone_id FROM users WHERE id = ?', [$u]));
        $svc->update($u, ['name' => 'Cible 2', 'email' => 'cible@x.test', 'zone_id' => null], null); // clé présente et vide : zone retirée
        $this->assertNull($this->db->fetchValue('SELECT zone_id FROM users WHERE id = ?', [$u]));
        $this->request('POST', "/admin/utilisateurs/$u", $post + ['zone_id' => (string) $zone]);
        $this->assertSame(422, $this->request('POST', "/admin/utilisateurs/$u", $post + ['zone_id' => '999999'])->status());
        $this->assertStringContainsString('Sassandra', $this->request('GET', "/admin/utilisateurs/$u")->body());
    }

    public function test_all_referential_routes_forbidden_without_settings_manage(): void
    {
        $this->actingAs($this->createUser('lect2@x.test', ['lecture_seule']));
        $this->assertSame(403, $this->request('POST', self::URL . '/activation', ['onglet' => 'zones', 'id' => '1', 'active' => '0'])->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM zones'));
    }

    public function test_post_without_csrf_is_refused(): void
    {
        $this->app();
        $res = $this->app()->handle(new Request('POST', self::URL, [], ['onglet' => 'zones', 'name' => 'Z', 'type' => 'port'], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertSame(419, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM zones'));
    }

    public function test_not_found_thrown_by_handler_is_a_standard_404_for_get_and_post(): void
    {
        $this->app();
        $boom = static function (): Response {
            throw new NotFound('Adhérent 42 introuvable : secret');
        };
        $this->services['router']->get('/admin/sonde-404', $boom, [new \App\Core\Http\RequireAuth($this->services['auth']())]);
        $this->services['router']->post('/admin/sonde-404', $boom, [new \App\Core\Http\RequireAuth($this->services['auth']())]);
        $expected = $this->request('GET', '/admin/page-inexistante');
        foreach (['GET', 'POST'] as $m) {
            $res = $this->request($m, '/admin/sonde-404');
            $this->assertSame(404, $res->status(), $m);
            $this->assertSame($expected->body(), $res->body(), $m);
            $this->assertStringNotContainsString('secret', $res->body());
        }
    }
}
