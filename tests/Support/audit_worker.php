<?php
declare(strict_types=1);

// Processus fils du test de concurrence : php audit_worker.php <worker> <n> ; affiche le nombre d'échecs.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$db = Database::connect(new Config(Env::load(dirname(__DIR__, 2) . '/.env')), test: true);
$audit = new Audit($db, 'cle-de-test');
$failures = 0;
for ($i = 0; $i < (int) $argv[2]; $i++) {
    try {
        $audit->log(1, 'work', 'Worker', (int) $argv[1], null, ['i' => $i]);
    } catch (Throwable) {
        $failures++;
    }
}
echo $failures;
