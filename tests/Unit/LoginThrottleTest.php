<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\LoginThrottle;
use Tests\DatabaseTestCase;

final class LoginThrottleTest extends DatabaseTestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
    }

    public function test_sixth_failure_for_same_account_and_ip_is_blocked(): void
    {
        $t = new LoginThrottle($this->db);
        for ($i = 0; $i < 4; $i++) {
            $t->record('a@x.test', '1.1.1.1', false, self::NOW);
        }
        $this->assertFalse($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
        $t->record('a@x.test', '1.1.1.1', false, self::NOW);
        $this->assertTrue($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
        $this->assertFalse($t->tooManyAttempts('a@x.test', '2.2.2.2', self::NOW));
    }

    public function test_failures_older_than_window_do_not_count(): void
    {
        $t = new LoginThrottle($this->db);
        for ($i = 0; $i < 5; $i++) {
            $t->record('a@x.test', '1.1.1.1', false, self::NOW - 901);
        }
        $this->assertFalse($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
    }

    public function test_twenty_failures_from_one_ip_on_different_emails_block_the_ip(): void
    {
        $t = new LoginThrottle($this->db);
        for ($i = 0; $i < 20; $i++) {
            $t->record("u$i@x.test", '3.3.3.3', false, self::NOW);
        }
        $this->assertTrue($t->tooManyAttempts('new@x.test', '3.3.3.3', self::NOW));
        $this->assertFalse($t->tooManyAttempts('new@x.test', '4.4.4.4', self::NOW));
    }

    public function test_email_case_and_spaces_share_the_same_counter(): void
    {
        $t = new LoginThrottle($this->db);
        foreach (['A@x.test', ' a@X.test ', 'a@x.test', "A@x.test\t", 'a@x.TEST'] as $e) {
            $t->record($e, '1.1.1.1', false, self::NOW);
        }
        $this->assertTrue($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
    }

    public function test_successes_do_not_count_as_failures(): void
    {
        $t = new LoginThrottle($this->db);
        for ($i = 0; $i < 25; $i++) {
            $t->record('a@x.test', '1.1.1.1', true, self::NOW);
        }
        $this->assertFalse($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
    }

    public function test_purge_removes_rows_older_than_one_day(): void
    {
        $t = new LoginThrottle($this->db);
        $t->record('a@x.test', '1.1.1.1', false, self::NOW - 86401);
        $t->record('a@x.test', '1.1.1.1', false, self::NOW - 100);
        $t->purge(self::NOW);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts'));
    }

    public function test_overlong_email_is_truncated_consistently(): void
    {
        $t = new LoginThrottle($this->db);
        $e = str_repeat('é', 300) . '@x.test';
        for ($i = 0; $i < 5; $i++) {
            $t->record($e, str_repeat('9', 80), false, self::NOW);
        }
        $this->assertTrue($t->tooManyAttempts($e, str_repeat('9', 80), self::NOW));
    }

    public function test_window_boundary_is_pinned(): void
    {
        $t = new LoginThrottle($this->db, 1);
        $t->record('a@x.test', '1.1.1.1', false, self::NOW - 900);
        $this->assertTrue($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
        $this->db->query('DELETE FROM login_attempts');
        $t->record('a@x.test', '1.1.1.1', false, self::NOW - 901);
        $this->assertFalse($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW));
    }

    public function test_mark_blocked_is_true_once_per_window_and_never_counts_as_a_failure(): void
    {
        $t = new LoginThrottle($this->db);
        $this->assertTrue($t->markBlocked(' A@x.test', '1.1.1.1', self::NOW));
        $this->assertFalse($t->markBlocked('a@x.test', '1.1.1.1', self::NOW + 10));
        $this->assertTrue($t->markBlocked('a@x.test', '2.2.2.2', self::NOW), 'autre IP : autre clé');
        $this->assertTrue($t->markBlocked('a@x.test', '1.1.1.1', self::NOW + 901), 'fenêtre suivante');
        for ($i = 0; $i < 4; $i++) {
            $t->record('a@x.test', '1.1.1.1', false, self::NOW + 901);
        }
        $this->assertFalse($t->tooManyAttempts('a@x.test', '1.1.1.1', self::NOW + 901));
    }
}
