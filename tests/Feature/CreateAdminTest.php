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

    private function cli(int $wait = 10): FirstAdmin
    {
        return new FirstAdmin(new UserService($this->db, new Audit($this->db, 'test-key-test-key-test-key-test-key'), new Gate($this->db)), $this->db, $wait);
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

    public function test_create_admin_refuses_while_another_creation_holds_the_lock(): void
    {
        $other = \App\Core\Database::connect(self::config(), test: true);
        $this->assertSame(1, (int) $other->fetchValue("SELECT GET_LOCK('mmmgci.create-admin', 0)"));
        [$code, $out] = $this->cli(1)->run(['a@exemple.ci', 'Premier Admin']);
        $other->fetchValue("SELECT RELEASE_LOCK('mmmgci.create-admin')");
        $this->assertSame(1, $code);
        $this->assertStringContainsString("Une autre création d'administrateur est en cours", $out);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame(0, $this->cli()->run(['a@exemple.ci', 'Premier Admin'])[0]); // verrou libéré par le fils
    }

    public function test_concurrent_create_admin_creates_exactly_one_admin(): void
    {
        $procs = $pipes = [];
        for ($w = 1; $w <= 3; $w++) {
            $procs[$w] = proc_open([PHP_BINARY, __DIR__ . '/../Support/create_admin_worker.php', (string) $w], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
        }
        $codes = [];
        foreach ($procs as $w => $p) {
            $codes[] = stream_get_contents($pipes[$w][1]);
            stream_get_contents($pipes[$w][2]);
            proc_close($p);
        }
        sort($codes);
        $this->assertSame(['0', '1', '1'], $codes);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_purges_keep_rows_up_to_24h_and_delete_older(): void
    {
        $now = 1_800_000_000;
        $uid = (new UserService($this->db, new Audit($this->db, 'test-key-test-key-test-key-test-key'), new Gate($this->db)))
            ->create(['name' => 'U Test', 'email' => 'u@exemple.ci', 'roles' => []], null);
        $future = gmdate('Y-m-d H:i:s', $now + 3600);
        foreach ([86399 => 'a', 86400 => 'b', 86401 => 'c'] as $age => $tag) {
            $at = gmdate('Y-m-d H:i:s', $now - $age);
            $this->db->insert('login_attempts', ['email' => "$tag@x.test", 'ip' => '1.1.1.1', 'succeeded' => 0, 'created_at' => $at]);
            $this->db->insert('password_resets', ['user_id' => $uid, 'token_hash' => hash('sha256', "e$tag"), 'expires_at' => $at, 'used_at' => null, 'created_at' => $at]);
            $this->db->insert('password_resets', ['user_id' => $uid, 'token_hash' => hash('sha256', "u$tag"), 'expires_at' => $future, 'used_at' => $at, 'created_at' => $at]);
        }
        AuthMaintenance::purgePasswordResets($this->db, $now);
        (new \App\Modules\Auth\LoginThrottle($this->db))->purge($now);
        $this->assertSame(4, (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets'));
        $this->assertSame(['a@x.test', 'b@x.test'], array_column($this->db->fetchAll('SELECT email FROM login_attempts ORDER BY email'), 'email'));
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
        $this->assertSame(['login_attempts.purge' => 'ran', 'password_resets.purge' => 'ran', 'member_forgot_tokens.purge' => 'ran'], $scheduler->run($now));
        $this->assertSame([$keepOpen, $keepRecent], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM password_resets ORDER BY id'), 'id')));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts'));
        $this->assertSame(['login_attempts.purge' => 'skipped_not_due', 'password_resets.purge' => 'skipped_not_due', 'member_forgot_tokens.purge' => 'skipped_not_due'], $scheduler->run($now + 60));
    }
}
