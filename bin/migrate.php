<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Core/Env.php';
spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'App\\') && is_file($f = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($c, 4)) . '.php')) {
        require $f;
    }
});

$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
$migrator = new App\Core\Migrator(App\Core\Database::connect($config), dirname(__DIR__) . '/database/migrations');
$applied = $migrator->migrate();
echo $applied === [] ? "Rien à migrer.\n" : implode("\n", array_map(fn ($v) => "Appliquée : $v", $applied)) . "\n";
