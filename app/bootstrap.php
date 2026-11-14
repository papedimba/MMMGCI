<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Abidjan');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/helpers.php';

// Câblage minimal ; complété à la Task 16.
$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
$router = new App\Core\Http\Router();
(require dirname(__DIR__) . '/routes/web.php')($router);

return new App\Core\App($config, $router, []);
