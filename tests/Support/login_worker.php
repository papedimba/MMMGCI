<?php
declare(strict_types=1);

// Processus fils des tests de concurrence de la limitation : php login_worker.php <espace|admin> <identifiant> <mot de passe> <instant de départ (µs)>
// Attend l'instant de départ (tous les fils partent ensemble), tente une connexion ; affiche S (connecté), B (bloqué) ou F (refusé).
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Http\Request;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$services = [];
$app = (require dirname(__DIR__, 2) . '/app/kernel.php')($config, false, ['db' => Database::connect($config, test: true), 'defer' => static fn (callable $j) => $j()], $services);
[$espace, $field] = $argv[1] === 'espace' ? [true, 'identifiant'] : [false, 'email'];
$token = $services[$espace ? 'member_csrf' : 'csrf']->token();
time_sleep_until((int) $argv[4] / 1e6);
$res = $app->handle(new Request('POST', $espace ? '/espace/connexion' : '/admin/connexion', [], [$field => $argv[2], 'password' => $argv[3], '_csrf' => $token], [],
    ['REMOTE_ADDR' => '127.0.0.9', 'HTTP_USER_AGENT' => 'worker']));
echo $res->status() === 302 ? 'S' : (str_contains($res->body(), 'Trop de tentatives') ? 'B' : 'F');
