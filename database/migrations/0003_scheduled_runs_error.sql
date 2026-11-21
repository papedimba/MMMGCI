ALTER TABLE scheduled_runs ADD COLUMN error VARCHAR(500) NULL AFTER status;

-- Une ligne par tâche ; le verrou est pris par un UPDATE conditionnel atomique (voir Scheduler).
CREATE TABLE scheduler_locks (
    task VARCHAR(100) NOT NULL PRIMARY KEY,
    locked_at BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
