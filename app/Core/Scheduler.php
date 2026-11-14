<?php
declare(strict_types=1);

namespace App\Core;

/** Exécute les tâches échues depuis une unique entrée cron (bin/cron.php). */
final class Scheduler
{
    private const STALE_LOCK_SECONDS = 1800;

    /** @var array<string,array{int,callable}> */
    private array $tasks = [];

    public function __construct(private Database $db)
    {
    }

    public function register(string $name, int $everyMinutes, callable $task): void
    {
        $this->tasks[$name] = [$everyMinutes, $task];
    }

    /** @return array<string,string> nom => ran | skipped_not_due | skipped_locked | failed */
    public function run(int $now): array
    {
        $result = [];
        foreach ($this->tasks as $name => [$every, $task]) {
            $last = $this->db->fetchValue(
                "SELECT MAX(UNIX_TIMESTAMP(started_at)) FROM scheduled_runs WHERE task = ? AND status = 'success'", [$name]);
            if ($last !== null && $now - (int) $last < $every * 60) {
                $result[$name] = 'skipped_not_due';
                continue;
            }
            if (!$this->lock($name, $now)) {
                $result[$name] = 'skipped_locked';
                continue;
            }
            $runId = $this->db->insert('scheduled_runs', ['task' => $name, 'started_at' => gmdate('Y-m-d H:i:s', $now), 'status' => 'running']);
            try {
                $task();
                $status = 'success';
                $error = null;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = mb_substr($e->getMessage(), 0, 500);
                error_log("Tâche planifiée « $name » en échec : " . $e->getMessage());
            }
            $this->db->update('scheduled_runs', ['status' => $status, 'error' => $error, 'finished_at' => gmdate('Y-m-d H:i:s', time())], ['id' => $runId]);
            $this->db->update('scheduler_locks', ['locked_at' => null], ['task' => $name]);
            $result[$name] = $status === 'success' ? 'ran' : 'failed';
        }
        return $result;
    }

    /**
     * Verrou atomique : un seul UPDATE conditionnel (verrou de 30 min ou plus = abandonné, repris).
     * InnoDB sérialise les UPDATE sur la même ligne, donc un seul processus voit rowCount = 1.
     */
    private function lock(string $name, int $now): bool
    {
        $this->db->query('INSERT IGNORE INTO scheduler_locks (task, locked_at) VALUES (?, NULL)', [$name]);
        $stale = $this->db->query('SELECT locked_at FROM scheduler_locks WHERE task = ? AND locked_at IS NOT NULL', [$name])->rowCount() > 0;
        $won = $this->db->query(
            'UPDATE scheduler_locks SET locked_at = ? WHERE task = ? AND (locked_at IS NULL OR locked_at <= ?)',
            [$now, $name, $now - self::STALE_LOCK_SECONDS])->rowCount() === 1;
        if ($won && $stale) {
            $this->db->query("UPDATE scheduled_runs SET status = 'failed', error = 'Verrou abandonné', finished_at = ? WHERE task = ? AND status = 'running'", [gmdate('Y-m-d H:i:s', $now), $name]);
        }
        return $won;
    }
}
