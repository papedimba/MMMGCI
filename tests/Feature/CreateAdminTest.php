<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Scheduler;
use App\Modules\Auth\AuthMaintenance;
use App\Modules\Auth\PasswordPolicy;
use App\Modules\Users\FirstAdmin;
use App\Modules\Users\UserService;
use Tests\DatabaseTestCase;

final class CreateAdminTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['login_attempts', 'password_resets', 'scheduled_runs', 'scheduler_locks', 'user_roles', 'users'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
    }

    private function cli(): FirstAdmin
    {
        return new FirstAdmin(new UserService($this->db, new Audit($this->db, 'test-key-test-key-test-key-test-key'), new Gate($this->db)), $this->db);
    }

    public function test_create_admin_creates_super_admin_and_prints_password_once(): void
    {
        [$code, $out] = $this->cli()->run(['Chef@Exemple.ci', 'Admin Test']);
        $this->assertSame(0, $code);
        $this->assertSame(1, preg_match('/Mot de passe[^:]*: (\S{20})\n/', $out, $m));
        $this->assertSame(1, substr_count($out, $m[1]));
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $m[1]);
        $this->assertNull(PasswordPolicy::check($m[1], 'chef@exemple.ci', 'Admin Test'));
        $this->assertStringContainsString('/admin/mon-compte/mot-de-passe', $out);
        $user = $this->db->fetch('SELECT * FROM users WHERE email = ?', ['chef@exemple.ci']);
        $this->assertTrue(password_verify($m[1], $user['password']));
        $roles = array_column($this->db->fetchAll('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name', [$user['id']]), 'name');
        $this->assertSame(['admin_general', 'super_admin'], $roles);
        $audit = $this->db->fetchAll("SELECT * FROM audit_logs WHERE action = 'user.created' AND auditable_id = ?", [$user['id']]);
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]['user_id']);
        $this->assertStringNotContainsString($m[1], json_encode($this->db->fetchAll('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 20')));
    }

    public function test_create_admin_refuses_when_an_admin_exists(): void
    {
        $this->assertSame(0, $this->cli()->run(['a@exemple.ci', 'Premier Admin'])[0]);
        [$code, $out] = $this->cli()->run(['b@exemple.ci', 'Second Admin']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Un administrateur existe déjà', $out);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_create_admin_refuses_invalid_input(): void
    {
        $this->assertSame(1, $this->cli()->run(['pas-un-email', 'Nom'])[0]);
        $this->assertSame(1, $this->cli()->run(['a@exemple.ci', '  '])[0]);
        $this->assertSame(1, $this->cli()->run([])[0]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_cron_registers_purge_tasks(): void
    {
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 90000);
        $recent = gmdate('Y-m-d H:i:s', $now - 600);
        $future = gmdate('Y-m-d H:i:s', $now + 3600);
        $uid = (new UserService($this->db, new Audit($this->db, 'test-key-test-key-test-key-test-key'), new Gate($this->db)))
            ->create(['name' => 'U Test', 'email' => 'u@exemple.ci', 'roles' => []], null);
        $pr = fn (string $tok, string $exp, ?string $used) => $this->db->insert('password_resets', ['user_id' => $uid, 'token_hash' => hash('sha256', $tok), 'expires_at' => $exp, 'used_at' => $used, 'created_at' => $old]);
        $pr('expired-old', $old, null);
        $pr('used-old', $future, $old);
        $keepOpen = $pr('open', $future, null);
        $keepRecent = $pr('expired-recent', $recent, null);
        foreach ([$old, $recent] as $at) {
            $this->db->insert('login_attempts', ['email' => 'a@x.test', 'ip' => '1.1.1.1', 'succeeded' => 0, 'created_at' => $at]);
        }

        $scheduler = new Scheduler($this->db);
        AuthMaintenance::register($scheduler, $this->db);
        $this->assertSame(['login_attempts.purge' => 'ran', 'password_resets.purge' => 'ran'], $scheduler->run($now));
        $this->assertSame([$keepOpen, $keepRecent], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM password_resets ORDER BY id'), 'id')));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts'));
        $this->assertSame(['login_attempts.purge' => 'skipped_not_due', 'password_resets.purge' => 'skipped_not_due'], $scheduler->run($now + 60));
    }
}
