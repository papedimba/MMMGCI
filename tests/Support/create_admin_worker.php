<?php
declare(strict_types=1);

// Processus fils du test de concurrence : php create_admin_worker.php <n> ; affiche le code de sortie de FirstAdmin.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\{Audit, Config, Database, Env, Gate};
use App\Modules\Users\{FirstAdmin, UserService};

$db = Database::connect(new Config(Env::load(dirname(__DIR__, 2) . '/.env')), test: true);
$users = new UserService($db, new Audit($db, 'base64:' . base64_encode(str_repeat('k', 32))), new Gate($db));
echo (new FirstAdmin($users, $db))->run(['race' . $argv[1] . '@exemple.ci', 'Course ' . $argv[1]])[0];
