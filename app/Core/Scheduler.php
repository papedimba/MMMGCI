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
            if (!$this->isDue($name, $every, $now)) {
                $result[$name] = 'skipped_not_due';
                continue;
            }
            if (!$this->lock($name, $now)) {
                $result[$name] = 'skipped_locked';
                continue;
            }
            // Re-contrôle sous verrou : un autre processus a pu terminer la tâche entre-temps.
            if (!$this->isDue($name, $every, $now)) {
                $this->release($name, $now);
                $result[$name] = 'skipped_not_due';
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
            // Si notre verrou a été repris, la ligne d'historique est déjà « abandonnée » : on n'y touche pas.
            $this->db->update('scheduled_runs', ['status' => $status, 'error' => $error, 'finished_at' => gmdate('Y-m-d H:i:s', time())], ['id' => $runId, 'status' => 'running']);
            $this->release($name, $now);
            $result[$name] = $status === 'success' ? 'ran' : 'failed';
        }
        return $result;
    }

    private function isDue(string $name, int $every, int $now): bool
    {
        $last = $this->db->fetchValue(
            "SELECT MAX(UNIX_TIMESTAMP(started_at)) FROM scheduled_runs WHERE task = ? AND status = 'success'", [$name]);
        return $last === null || $now - (int) $last >= $every * 60;
    }

    /** Ne libère que notre propre verrou (le jeton est $now) : un verrou repris par un autre reste en place. */
    private function release(string $name, int $now): void
    {
        $this->db->update('scheduler_locks', ['locked_at' => null], ['task' => $name, 'locked_at' => $now]);
    }

    /**
     * Verrou atomique : la ligne est lue FOR UPDATE puis mise à jour dans la même transaction
     * (verrou de 30 min ou plus = abandonné, repris). Un seul processus à la fois passe ce bloc par tâche.
     */
    private function lock(string $name, int $now): bool
    {
        $this->db->query('INSERT IGNORE INTO scheduler_locks (task, locked_at) VALUES (?, NULL)', [$name]);
        $prev = null;
        $won = $this->db->transaction(function (Database $db) use ($name, $now, &$prev): bool {
            $prev = $db->fetchValue('SELECT locked_at FROM scheduler_locks WHERE task = ? FOR UPDATE', [$name]);
            if ($prev !== null && (int) $prev > $now - self::STALE_LOCK_SECONDS) {
                return false;
            }
            $db->update('scheduler_locks', ['locked_at' => $now], ['task' => $name]);
            return true;
        });
        if ($won && $prev !== null) {
            $this->db->query("UPDATE scheduled_runs SET status = 'failed', error = 'Verrou abandonné', finished_at = ? WHERE task = ? AND status = 'running'", [gmdate('Y-m-d H:i:s', $now), $name]);
        }
        return $won;
    }
}
