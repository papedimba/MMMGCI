<?php
declare(strict_types=1);

// Processus fils des tests de concurrence de l'Espace adhérent ; affiche « ok » ou le message de refus.
//   php activation_worker.php activate <identifiant> <date de naissance> <code> <mot de passe> <ip>
//   php activation_worker.php reset <jeton> <mot de passe> <ip>
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$services = [];
(require dirname(__DIR__, 2) . '/app/kernel.php')($config, false, ['db' => Database::connect($config, test: true), 'defer' => static fn (callable $j) => $j()], $services);
try {
    match ($argv[1]) {
        'activate' => $services['member_accounts']()->activate($argv[2], $argv[3], $argv[4], $argv[5], $argv[5], $argv[6], time()),
        'reset' => $services['member_accounts']()->resetWithToken($argv[2], $argv[3], $argv[3], $argv[4], time()),
    };
    echo 'ok';
} catch (Throwable $e) {
    echo $e->getMessage();
}
