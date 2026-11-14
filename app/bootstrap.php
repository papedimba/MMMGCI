<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Abidjan');
mb_internal_encoding('UTF-8');

require __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers.php';

// Câblage minimal ; complété à la Task 16.
$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
$router = new App\Core\Http\Router();
(require dirname(__DIR__) . '/routes/web.php')($router);

return new App\Core\App($config, $router, []);
