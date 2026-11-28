<?php
declare(strict_types=1);

// Processus fils du test d'activation simultanée : php activation_worker.php <identifiant> <date de naissance> <code> <mot de passe> <ip> ;
// affiche « ok » ou le message de refus.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$services = [];
(require dirname(__DIR__, 2) . '/app/kernel.php')($config, false, ['db' => Database::connect($config, test: true), 'defer' => static fn (callable $j) => $j()], $services);
try {
    $services['member_accounts']()->activate($argv[1], $argv[2], $argv[3], $argv[4], $argv[4], $argv[5], time());
    echo 'ok';
} catch (Throwable $e) {
    echo $e->getMessage();
}
