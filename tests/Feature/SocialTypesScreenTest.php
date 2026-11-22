<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Money;
use App\Modules\Social\SocialTypeService;
use Tests\Support\AdminTestCase;

final class SocialTypesScreenTest extends AdminTestCase
{
    private int $viewer;
    private int $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query("UPDATE social_request_types SET criteria_text = NULL, ceiling_amount = NULL, waiting_period_days = NULL, deciding_body = NULL, allowed_aid_kinds = NULL,
            params_decision_ref = NULL, params_validated_by = NULL, params_validated_at = NULL, is_active = 1");
        $this->db->query("UPDATE settings SET status = 'non_configure', validated_by = NULL, decision_ref = NULL WHERE `group` = 'social'");
        $this->db->query("UPDATE settings SET `value` = NULL WHERE `group` = 'social' AND `key` <> 'reference_format'");
        $this->db->query("DELETE FROM social_request_types WHERE code NOT IN ('sante','deces','maladie','famille','emploi','formation','retraite','urgences')");
        foreach (['stv_view' => ['social.view'], 'stv_val' => ['social.view', 'settings.validate']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $n = bin2hex(random_bytes(3));
        $this->viewer = $this->createUser("v$n@stv.test", ['stv_view']);
        $this->validator = $this->createUser("a$n@stv.test", ['stv_val']);
    }

    public function test_types_screen_requires_social_view(): void
    {
        $nobody = $this->createUser('n' . bin2hex(random_bytes(3)) . '@stv.test', []);
        $this->actingAs($nobody);
        self::assertSame(403, $this->request('GET', '/admin/demandes-sociales/types')->status());
        $this->actingAs($this->viewer);
        $res = $this->request('GET', '/admin/demandes-sociales/types');
        self::assertSame(200, $res->status());
        self::assertStringContainsString('Sous réserve des ressources disponibles', $res->body());
        self::assertStringContainsString('Non configuré', $res->body());
        self::assertSame(403, $this->request('GET', '/admin/demandes-sociales/types/1/parametres')->status());
    }

    public function test_configure_through_screen_shows_value_once_validated(): void
    {
        $id = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'deces'");
        $svc = new SocialTypeService($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))), new Gate($this->db));
        $this->actingAs($this->validator);
        $bad = $this->request('POST', "/admin/demandes-sociales/types/$id/parametres", ['ceiling_amount' => '12 500', 'decision_ref' => '']);
        self::assertSame(422, $bad->status());
        self::assertNull($svc->ceilingFor($id));
        self::assertNull($this->db->fetchValue('SELECT params_decision_ref FROM social_request_types WHERE id = ?', [$id]));
        $ok = $this->request('POST', "/admin/demandes-sociales/types/$id/parametres", ['ceiling_amount' => '12 500', 'decision_ref' => 'PV-9']);
        self::assertSame(302, $ok->status());
        self::assertSame(1250000, $svc->ceilingFor($id));
        self::assertSame('PV-9', $this->db->fetchValue('SELECT params_decision_ref FROM social_request_types WHERE id = ?', [$id]));
        self::assertStringContainsString(htmlspecialchars(Money::format(1250000), ENT_QUOTES), $this->request('GET', '/admin/demandes-sociales/types')->body());
    }

    public function test_new_type_route_needs_validate_and_creates_unconfigured_type(): void
    {
        $post = ['code' => 'nouveau_t', 'name' => 'Nouveau', 'action_text' => 'Action', 'decision_ref' => 'PV-1'];
        $this->actingAs($this->viewer);
        self::assertSame(403, $this->request('GET', '/admin/demandes-sociales/types/nouveau')->status());
        self::assertSame(403, $this->request('POST', '/admin/demandes-sociales/types/nouveau', $post)->status());
        $this->actingAs($this->validator);
        self::assertSame(200, $this->request('GET', '/admin/demandes-sociales/types/nouveau')->status());
        self::assertSame(302, $this->request('POST', '/admin/demandes-sociales/types/nouveau', $post)->status());
        self::assertNull($this->db->fetchValue("SELECT params_decision_ref FROM social_request_types WHERE code = 'nouveau_t'"));
        self::assertSame(422, $this->request('POST', '/admin/demandes-sociales/types/nouveau', ['decision_ref' => ''] + $post)->status());
    }

    public function test_activation_route_needs_validate_ref_and_csrf(): void
    {
        $id = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'sante'");
        $url = "/admin/demandes-sociales/types/$id/activation";
        $this->actingAs($this->viewer);
        self::assertSame(403, $this->request('POST', $url, ['active' => '0', 'decision_ref' => 'PV-2'])->status());
        $this->actingAs($this->validator);
        self::assertSame(422, $this->request('POST', $url, ['active' => '0', 'decision_ref' => ''])->status());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM social_request_types WHERE id = ?', [$id]));
        $noCsrf = $this->app()->handle(new Request('POST', $url, [], ['active' => '0', 'decision_ref' => 'PV-2'], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit', 'QUERY_STRING' => '']));
        self::assertSame(419, $noCsrf->status());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM social_request_types WHERE id = ?', [$id]));
        self::assertSame(302, $this->request('POST', $url, ['active' => '0', 'decision_ref' => 'PV-2'])->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT is_active FROM social_request_types WHERE id = ?', [$id]));
    }

    public function test_deactivation_uses_confirm_dialog(): void
    {
        $id = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'sante'");
        $this->actingAs($this->validator);
        self::assertStringContainsString('id="confirm-desactivation"', $this->request('GET', "/admin/demandes-sociales/types/$id/parametres")->body());
    }

    public function test_general_settings_screen_validates_per_key(): void
    {
        $url = '/admin/demandes-sociales/parametres';
        $this->actingAs($this->viewer);
        $page = $this->request('GET', $url);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Non configuré', $page->body());
        self::assertSame(403, $this->request('POST', "$url/retention_months", ['value_retention_months' => '24', 'decision_ref_retention_months' => 'PV-3'])->status());
        $this->actingAs($this->validator);
        foreach ([['retention_months', 'abc', 'PV-3'], ['retention_months', '0', 'PV-3'], ['retention_months', '24', ''], ['processing_deadline_days', '-5', 'PV-3'], ['reference_format', 'sans numéro', 'PV-3'], ['inconnu', '1', 'PV-3']] as [$k, $v, $ref]) {
            self::assertContains($this->request('POST', "$url/$k", ["value_$k" => $v, "decision_ref_$k" => $ref])->status(), [404, 422], "$k=$v");
        }
        self::assertSame('non_configure', $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'social' AND `key` = 'retention_months'"));
        self::assertSame(302, $this->request('POST', "$url/retention_months", ['value_retention_months' => '24', 'decision_ref_retention_months' => 'PV-3'])->status());
        $row = $this->db->fetch("SELECT `value`, status, decision_ref, validated_by FROM settings WHERE `group` = 'social' AND `key` = 'retention_months'");
        self::assertSame(['24', 'valide', 'PV-3', $this->validator], [$row['value'], $row['status'], $row['decision_ref'], (int) $row['validated_by']]);
        $audit = (string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'social.setting_validated' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('retention_months', $audit);
        self::assertStringNotContainsString('24', str_replace('retention_months', '', $audit));
    }

    public function test_hostile_type_labels_are_escaped(): void
    {
        $this->db->insert('social_request_types', ['code' => 'xss', 'name' => '<script>alert(1)</script>', 'action_text' => '<img src=x onerror=1>']);
        $this->actingAs($this->viewer);
        $body = $this->request('GET', '/admin/demandes-sociales/types')->body();
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }
}
