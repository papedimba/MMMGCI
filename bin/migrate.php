<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/app/init.php';
$migrator = new App\Core\Migrator(App\Core\Database::connect($config), dirname(__DIR__) . '/database/migrations');
$applied = $migrator->migrate();
echo $applied === [] ? "Aucune migration en attente.\n" : implode("\n", array_map(fn ($v) => "Appliquée : $v", $applied)) . "\n";
