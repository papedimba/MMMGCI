<?php
declare(strict_types=1);

// Processus fils (vraie session PHP, impossible dans PHPUnit dont la sortie a commencé) : php espace_session_worker.php <identifiant> <mot de passe> <dossier>
// Ouvre /espace/connexion puis se connecte ; affiche en JSON les identifiants de session avant et après, et le cookie posé.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Http\Request;

ini_set('session.save_path', $argv[3]);
$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$services = [];
$app = (require dirname(__DIR__, 2) . '/app/kernel.php')($config, true, ['db' => Database::connect($config, test: true), 'defer' => static fn (callable $j) => $j()], $services);
$server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'worker'];
$app->handle(new Request('GET', '/espace/connexion', [], [], [], $server));
$before = session_id();
$res = $app->handle(new Request('POST', '/espace/connexion', [], ['identifiant' => $argv[1], 'password' => $argv[2],
    '_csrf' => $services['member_csrf']->token()], [], $server));
echo json_encode(['before' => $before, 'after' => session_id(), 'name' => session_name(), 'cookie' => session_get_cookie_params(),
    'status' => $res->status(), 'account' => $services['member_session']->get('espace.account_id'), 'staff' => $services['session']->get('_last_activity')]);
