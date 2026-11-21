<?php
declare(strict_types=1);

// Unique entrée cron cPanel (toutes les 5 minutes) : php /chemin/bin/cron.php
$config = require dirname(__DIR__) . '/app/init.php';
$db = App\Core\Database::connect($config);
$scheduler = new App\Core\Scheduler($db);
// Les lots suivants enregistrent ici leurs tâches : $scheduler->register('nom', 60, fn () => ...);
App\Modules\Auth\AuthMaintenance::register($scheduler, $db);
App\Modules\Contributions\CallService::register($scheduler, $db, new App\Core\Audit($db, (string) $config->get('APP_KEY')));
App\Modules\Cms\CmsScheduler::register($scheduler, $db, new App\Core\Audit($db, (string) $config->get('APP_KEY')));
// Pièces de demandes d'adhésion en ligne abandonnées (plus de 24 h, jamais envoyées) : disque privé seulement (chemin résolu par Uploads).
App\Modules\Site\ApplyFlow::register($scheduler, $db, new App\Core\Uploads($db, dirname(__DIR__) . '/storage/uploads/private', dirname(__DIR__) . '/public/media',
    max(1, (int) $config->get('UPLOAD_MAX_MB', 5)) * 1048576));

$results = $scheduler->run(time());
foreach ($results as $task => $status) {
    echo "$task: $status\n";
}
exit(in_array('failed', $results, true) ? 1 : 0);
