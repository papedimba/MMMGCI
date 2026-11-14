<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/autoload.php';

$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
$migrator = new App\Core\Migrator(App\Core\Database::connect($config), dirname(__DIR__) . '/database/migrations');
$applied = $migrator->migrate();
echo $applied === [] ? "Rien à migrer.\n" : implode("\n", array_map(fn ($v) => "Appliquée : $v", $applied)) . "\n";
