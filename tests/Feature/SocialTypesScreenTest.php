<?php
declare(strict_types=1);

namespace Tests\Feature;

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
        $this->actingAs($this->validator);
        $bad = $this->request('POST', "/admin/demandes-sociales/types/$id/parametres", ['ceiling_amount' => '12 500', 'decision_ref' => '']);
        self::assertSame(422, $bad->status());
        $ok = $this->request('POST', "/admin/demandes-sociales/types/$id/parametres", ['ceiling_amount' => '12 500', 'decision_ref' => 'PV-9']);
        self::assertSame(302, $ok->status());
        self::assertStringContainsString('12', $this->request('GET', '/admin/demandes-sociales/types')->body());
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
