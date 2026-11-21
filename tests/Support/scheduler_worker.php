<?php
declare(strict_types=1);

// Processus fils : exécute une tâche qui dort 1 s puis incrémente un compteur ; affiche son résultat.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{Config, Database, Env, Scheduler};

$db = Database::connect(new Config(Env::load(dirname(__DIR__, 2) . '/.env')), test: true);
$s = new Scheduler($db);
$s->register('slow', 60, function () use ($db) {
    sleep(1);
    $db->query('UPDATE scheduler_test_counter SET n = n + 1');
});
echo $s->run(time())['slow'];
