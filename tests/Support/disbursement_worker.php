<?php
declare(strict_types=1);

// Processus fils des tests de concurrence du circuit de décaissement (vraie seconde connexion, vrai parallélisme) :
// php disbursement_worker.php approve <id> <user> [revision vue] | pay <id> <user> <proof> <key> | revise <id> <user> <montant en centimes>
// Affiche « ok » ou « NomCourtDeLException: message ».
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Gate;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Disbursements\DisbursementService;
use App\Modules\Finance\Ledger;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$db = Database::connect($config, test: true);
$audit = new Audit($db, (string) $config->get('APP_KEY'));
$svc = new DisbursementService($db, $audit, new Ledger($db, $audit, new Sequence($db)), new Sequence($db), new Settings($db), new Gate($db));
try {
    match ($argv[1]) {
        'approve' => $svc->approve((int) $argv[2], (int) $argv[3], null, isset($argv[4]) ? (int) $argv[4] : null),
        'pay' => $svc->pay((int) $argv[2], (int) $argv[3], ['method' => 'virement'], (int) $argv[4], $argv[5]),
        'revise' => $svc->revise((int) $argv[2], ['amount' => (int) $argv[4]], 'Modification concurrente', (int) $argv[3]),
    };
    echo 'ok';
} catch (Throwable $e) {
    echo (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
}
