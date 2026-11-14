<?php
declare(strict_types=1);

// Unique entrée cron cPanel (toutes les 5 minutes) : php /chemin/bin/cron.php
$config = require dirname(__DIR__) . '/app/init.php';
$db = App\Core\Database::connect($config);
$scheduler = new App\Core\Scheduler($db);
// Les lots suivants enregistrent ici leurs tâches : $scheduler->register('nom', 60, fn () => ...);
App\Modules\Auth\AuthMaintenance::register($scheduler, $db);

$results = $scheduler->run(time());
foreach ($results as $task => $status) {
    echo "$task: $status\n";
}
exit(in_array('failed', $results, true) ? 1 : 0);
