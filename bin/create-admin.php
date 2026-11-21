<?php
declare(strict_types=1);

// Premier administrateur : php bin/create-admin.php <email> "<Nom complet>"
$config = require dirname(__DIR__) . '/app/init.php';
$db = App\Core\Database::connect($config);
$users = new App\Modules\Users\UserService($db, new App\Core\Audit($db, (string) $config->get('APP_KEY')), new App\Core\Gate($db));
[$code, $message] = (new App\Modules\Users\FirstAdmin($users, $db))->run(array_slice($argv, 1));
fwrite($code === 0 ? STDOUT : STDERR, $message . "\n");
exit($code);
