<?php
declare(strict_types=1);

// Processus fils des tests de concurrence : php user_worker.php setActive <id> <0|1> <actor> | setRoles <id> <role> | create <email> <role> ;
// setRoles et create sans acteur (ligne de commande) ; affiche « ok » ou le message d'erreur.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Gate;
use App\Modules\Users\UserService;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$db = Database::connect($config, test: true);
$svc = new UserService($db, new Audit($db, (string) $config->get('APP_KEY')), new Gate($db));
try {
    match ($argv[1]) {
        'setActive' => $svc->setActive((int) $argv[2], $argv[3] === '1', (int) $argv[4]),
        'setRoles' => $svc->setRoles((int) $argv[2], [$argv[3]], null),
        'create' => $svc->create(['name' => 'Fils', 'email' => $argv[2], 'roles' => [$argv[3]]], null),
    };
    echo 'ok';
} catch (Throwable $e) {
    echo $e->getMessage();
}
