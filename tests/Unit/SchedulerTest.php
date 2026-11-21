<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Scheduler;
use Tests\DatabaseTestCase;

final class SchedulerTest extends DatabaseTestCase
{
    private Scheduler $s;
    private int $calls = 0;

    private string|false $prevLog = false;

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->prevLog);
        $this->db->query('DROP TRIGGER IF EXISTS scheduler_test_trg');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevLog = ini_set('error_log', sys_get_temp_dir() . '/scheduler_test.log');
        $this->db->query('DELETE FROM scheduled_runs');
        $this->db->query('DELETE FROM scheduler_locks');
        $this->s = new Scheduler($this->db);
    }

    public function test_task_runs_when_due_and_skips_otherwise(): void
    {
        $this->s->register('t', 10, function () { $this->calls++; });
        $this->assertSame(['t' => 'ran'], $this->s->run(1_000_000));
        $this->assertSame(['t' => 'skipped_not_due'], $this->s->run(1_000_000 + 599));
        $this->assertSame(['t' => 'ran'], $this->s->run(1_000_000 + 600));
        $this->assertSame(2, $this->calls);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM scheduled_runs WHERE status = 'success'"));
    }

    public function test_concurrent_run_is_skipped_while_locked(): void
    {
        $this->db->query('INSERT INTO scheduler_locks (task, locked_at) VALUES (?, ?)', ['t', 1_000_000 - 60]);
        $this->s->register('t', 10, function () { $this->calls++; });
        $this->assertSame(['t' => 'skipped_locked'], $this->s->run(1_000_000));
        $this->assertSame(0, $this->calls);
    }

    public function test_stale_lock_older_than_30_minutes_is_reclaimed(): void
    {
        $this->db->query('INSERT INTO scheduler_locks (task, locked_at) VALUES (?, ?)', ['t', 1_000_000 - 1801]);
        $this->db->insert('scheduled_runs', ['task' => 't', 'started_at' => gmdate('Y-m-d H:i:s', 1_000_000 - 1801), 'status' => 'running']);
        $this->s->register('t', 10, function () { $this->calls++; });
        $this->assertSame(['t' => 'ran'], $this->s->run(1_000_000));
        $this->assertSame(1, $this->calls);
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM scheduled_runs WHERE status = 'running'"));
        $this->assertNull($this->db->fetchValue('SELECT locked_at FROM scheduler_locks WHERE task = ?', ['t']));
    }

    public function test_failing_task_does_not_stop_others(): void
    {
        $this->s->register('bad', 10, function () { throw new \RuntimeException('boom'); });
        $this->s->register('good', 10, function () { $this->calls++; });
        $res = $this->s->run(1_000_000);
        $this->assertSame(['bad' => 'failed', 'good' => 'ran'], $res);
        $this->assertSame('boom', $this->db->fetchValue("SELECT error FROM scheduled_runs WHERE task = 'bad'"));
        $this->assertNull($this->db->fetchValue("SELECT locked_at FROM scheduler_locks WHERE task = 'bad'"));
        $this->assertSame(['bad' => 'failed', 'good' => 'skipped_not_due'], $this->s->run(1_000_001));
    }

    public function test_two_real_processes_run_the_task_exactly_once(): void
    {
        $this->db->query('DROP TABLE IF EXISTS scheduler_test_counter');
        $this->db->query('CREATE TABLE scheduler_test_counter (n INT NOT NULL)');
        $this->db->query('INSERT INTO scheduler_test_counter VALUES (0)');
        $procs = $pipes = [];
        for ($i = 0; $i < 2; $i++) {
            $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/../Support/scheduler_worker.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $out[] = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            proc_close($p);
        }
        sort($out);
        $this->assertSame(['ran', 'skipped_locked'], $out);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT n FROM scheduler_test_counter'));
        $this->db->query('DROP TABLE scheduler_test_counter');
    }

    public function test_release_after_reclaim_keeps_the_new_owner_lock_and_history(): void
    {
        $this->s->register('t', 10, function () {
            // Simule B qui reprend le verrou périmé de A pendant son exécution.
            $this->db->query("UPDATE scheduler_locks SET locked_at = ? WHERE task = 't'", [1_001_801]);
            $this->db->query("UPDATE scheduled_runs SET status = 'failed', error = 'Verrou abandonné' WHERE task = 't'");
        });
        $this->s->run(1_000_000);
        $this->assertSame(1_001_801, (int) $this->db->fetchValue("SELECT locked_at FROM scheduler_locks WHERE task = 't'"));
        $this->assertSame('failed', $this->db->fetchValue("SELECT status FROM scheduled_runs WHERE task = 't'"));
    }

    public function test_due_is_rechecked_under_the_lock(): void
    {
        // Un autre processus termine la tâche entre le premier contrôle et la prise du verrou.
        $this->db->query('DROP TRIGGER IF EXISTS scheduler_test_trg');
        $this->db->query("CREATE TRIGGER scheduler_test_trg AFTER UPDATE ON scheduler_locks FOR EACH ROW
            INSERT INTO scheduled_runs (task, started_at, status) VALUES (NEW.task, FROM_UNIXTIME(1000000), 'success')");
        $this->s->register('t', 10, function () { $this->calls++; });
        $this->assertSame(['t' => 'skipped_not_due'], $this->s->run(1_000_000));
        $this->assertSame(0, $this->calls);
        $this->db->query('DROP TRIGGER scheduler_test_trg');
        $this->assertNull($this->db->fetchValue("SELECT locked_at FROM scheduler_locks WHERE task = 't'"));
    }

    public function test_task_raising_a_warning_under_the_cli_init_is_recorded_as_failed(): void
    {
        // Même amorçage que bin/cron.php : app/init.php installe le gestionnaire d'erreurs (avertissement -> exception).
        $script = sys_get_temp_dir() . '/mmmgci-cron-' . uniqid() . '.php';
        file_put_contents($script, '<?php declare(strict_types=1); $config = require ' . var_export(dirname(__DIR__, 2) . '/app/init.php', true) . ';
            $s = new App\Core\Scheduler(App\Core\Database::connect($config, test: true));
            $s->register("warn", 10, function () { $undefined = []; return $undefined["missing"]; });
            echo $s->run(time())["warn"];');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($proc);
        unlink($script);

        $this->assertSame('failed', $out);
        $this->assertSame('Undefined array key "missing"', $this->db->fetchValue("SELECT error FROM scheduled_runs WHERE task = 'warn'"));
    }

    public function test_task_name_longer_than_the_column_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s->register(str_repeat('t', 101), 10, function () {});
    }
}
