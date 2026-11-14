<?php
declare(strict_types=1);

// Unique entrée cron cPanel (toutes les 5 minutes) : php /chemin/bin/cron.php
$config = require dirname(__DIR__) . '/app/init.php';
$scheduler = new App\Core\Scheduler(App\Core\Database::connect($config));
// Les lots L1+ enregistrent ici leurs tâches : $scheduler->register('nom', 60, fn () => ...);

$results = $scheduler->run(time());
foreach ($results as $task => $status) {
    echo "$task: $status\n";
}
exit(in_array('failed', $results, true) ? 1 : 0);
