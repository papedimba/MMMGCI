<?php
declare(strict_types=1);

// Processus fils des tests de concurrence du circuit social (vraie seconde connexion, vrai parallélisme) :
// php social_worker.php decide <id> <user> | register <member> <type> <user> | prepare <id> <user> <account> <category> | create <decision> <user> <account> <category> <member>
// Affiche « ok <id> » ou « NomCourtDeLException: message ».
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
use App\Modules\Social\SocialDisbursementLink;
use App\Modules\Social\SocialRequestService;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$db = Database::connect($config, test: true);
$audit = new Audit($db, (string) $config->get('APP_KEY'));
$svc = new SocialRequestService($db, $audit, new Sequence($db), new Gate($db));
$disbursements = new DisbursementService($db, $audit, new Ledger($db, $audit, new Sequence($db)), new Sequence($db), new Settings($db), new Gate($db));
try {
    $id = match ($argv[1]) {
        'decide' => $svc->decide((int) $argv[2], ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => 7_000_000, 'reason' => 'Second clic',
            'member_message' => 'Accordée', 'decision_ref' => 'PV-2', 'deciding_body' => 'Conseil'], (int) $argv[3]),
        'register' => $svc->register(['member_id' => (int) $argv[2], 'type_id' => (int) $argv[3], 'received_at' => Ledger::now()->format('Y-m-d'),
            'channel' => 'guichet', 'description' => 'Concurrente'], (int) $argv[4]),
        'prepare' => (new SocialDisbursementLink($db, $audit, new Gate($db), $disbursements))->prepare((int) $argv[2], ['account_id' => (int) $argv[4], 'category_id' => (int) $argv[5], 'beneficiary_name' => 'Concurrent'], (int) $argv[3]),
        'create' => $disbursements->create(['amount' => 5_000_000, 'beneficiary_member_id' => (int) $argv[6], 'social_decision_id' => (int) $argv[2], 'purpose' => 'Aide sociale',
            'account_id' => (int) $argv[4], 'category_id' => (int) $argv[5], 'beneficiary_name' => 'Concurrent'], (int) $argv[3]),
    };
    echo "ok $id";
} catch (Throwable $e) {
    echo (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
}
