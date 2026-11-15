<?php
declare(strict_types=1);

// Processus fils : provoque un vrai interblocage avec une action du circuit, dont le service doit être la victime.
// php disbursement_deadlock_worker.php <id demande> : tient audit_chain, alourdit sa transaction (la victime est la plus légère),
// attend que l'action du test soit bloquée sur audit_chain, puis demande la ligne de la demande (cycle), la garde 0,5 s et annule tout.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

$config = new Config(Env::load(dirname(__DIR__, 2) . '/.env'));
$db = Database::connect($config, test: true);
$observer = Database::connect($config, test: true);
$db->pdo()->beginTransaction();
$db->fetchValue('SELECT last_hash FROM audit_chain WHERE id = 1 FOR UPDATE');
$tag = bin2hex(random_bytes(4));
for ($i = 0; $i < 200; $i++) {
    $db->query('INSERT INTO sequences (name, year, last_value) VALUES (?, 2000, 1)', ["dlk-$tag-$i"]);
}
echo "ready\n";
for ($i = 0; $i < 400 && (int) $observer->fetchValue("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE 'SELECT last_hash FROM audit_chain%'") === 0; $i++) {
    usleep(25_000);
}
try {
    $db->fetch('SELECT id FROM disbursement_requests WHERE id = ? FOR UPDATE', [(int) $argv[1]]);
    echo "locked\n";
} catch (PDOException $e) {
    echo 'victim ' . ($e->errorInfo[1] ?? 0) . "\n";
}
usleep(500_000);
$db->pdo()->rollBack();
echo 'done';
