<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit;
use App\Core\Gate;
use App\Modules\Social\SocialTypeService;
use DomainException;
use Tests\DatabaseTestCase;

final class SocialTypeServiceTest extends DatabaseTestCase
{
    private int $validator;
    private int $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query("UPDATE social_request_types SET criteria_text = NULL, ceiling_amount = NULL, waiting_period_days = NULL, deciding_body = NULL, allowed_aid_kinds = NULL,
            params_decision_ref = NULL, params_validated_by = NULL, params_validated_at = NULL, is_active = 1");
        $this->db->query("DELETE FROM social_request_types WHERE code NOT IN ('sante','deces','maladie','famille','emploi','formation','retraite','urgences')");
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['st_val', 'st_val']);
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'st_val' AND p.name = 'settings.validate'");
        $n = bin2hex(random_bytes(3));
        $this->validator = $this->user("v$n@st.test", 'st_val');
        $this->plain = $this->user("p$n@st.test", null);
    }

    private function user(string $email, ?string $role): int
    {
        $id = $this->db->insert('users', ['name' => 'U', 'email' => $email, 'password' => 'x', 'is_active' => 1]);
        if ($role !== null) {
            $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        }
        return $id;
    }

    private function svc(): SocialTypeService
    {
        return new SocialTypeService($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))), new Gate($this->db));
    }

    private function sante(): int
    {
        return (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'sante'");
    }

    public function test_unconfigured_parameters_return_null_and_display_non_configure(): void
    {
        $id = $this->sante();
        $s = $this->svc();
        self::assertNull($s->ceilingFor($id));
        self::assertNull($s->waitingPeriodFor($id));
        self::assertNull($s->decidingBodyFor($id));
        self::assertNull($s->allowedAidKinds($id));
        self::assertCount(8, $s->all());
    }

    public function test_configure_requires_settings_validate_and_decision_ref(): void
    {
        $id = $this->sante();
        $p = ['ceiling_amount' => 5000000, 'allowed_aid_kinds' => ['financiere']];
        foreach ([[$this->plain, 'PV-1'], [$this->validator, '  ']] as [$actor, $ref]) {
            try {
                $this->svc()->configure($id, $p, $ref, $actor);
                self::fail('refus attendu');
            } catch (DomainException) {
                self::assertNull($this->svc()->ceilingFor($id));
            }
        }
        $this->svc()->configure($id, $p, 'PV-1', $this->validator);
        self::assertSame(5000000, $this->svc()->ceilingFor($id));
        self::assertSame(['financiere'], $this->svc()->allowedAidKinds($id));
        self::assertSame($this->validator, (int) $this->svc()->find($id)['params_validated_by']);
    }

    public function test_parameters_without_decision_ref_are_ignored(): void
    {
        $id = $this->sante();
        $this->db->query("UPDATE social_request_types SET ceiling_amount = 100.00, waiting_period_days = 30, deciding_body = 'X', allowed_aid_kinds = '[\"financiere\"]' WHERE id = ?", [$id]);
        $s = $this->svc();
        self::assertNull($s->ceilingFor($id));
        self::assertNull($s->waitingPeriodFor($id));
        self::assertNull($s->decidingBodyFor($id));
        self::assertNull($s->allowedAidKinds($id));
    }

    public function test_deactivated_type_not_offered_for_new_requests_but_kept_on_old_ones(): void
    {
        $id = $this->sante();
        $this->svc()->setActive($id, false, 'PV-2', $this->validator);
        self::assertNotContains($id, array_map(static fn (array $t): int => (int) $t['id'], $this->svc()->all(true)));
        self::assertSame('Santé', $this->svc()->find($id)['name']);
        self::assertCount(8, $this->svc()->all());
    }

    public function test_unknown_aid_kind_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->svc()->configure($this->sante(), ['allowed_aid_kinds' => ['virement']], 'PV-3', $this->validator);
    }

    public function test_audit_contains_no_criteria_text(): void
    {
        $this->svc()->configure($this->sante(), ['criteria_text' => 'SECRET-CRITERE-XYZ', 'deciding_body' => 'ORGANE-XYZ'], 'PV-4', $this->validator);
        $rows = $this->db->fetchAll("SELECT old_values, new_values FROM audit_logs WHERE auditable_type = 'social_request_type' ORDER BY id DESC LIMIT 1");
        self::assertNotSame([], $rows);
        self::assertStringNotContainsString('XYZ', json_encode($rows));
        self::assertStringContainsString('criteria_text', json_encode($rows));
    }
}
