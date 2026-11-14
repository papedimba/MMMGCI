<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Abidjan');
mb_internal_encoding('UTF-8');

require __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers.php';

$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
// Avant kernel.php et les routes : une erreur d'amorçage est journalisée et rendue sans détail.
App\Core\ErrorHandler::register($config->bool('APP_DEBUG'), dirname(__DIR__) . '/storage/logs/app.log');

// En CLI (PHPUnit, scripts bin/) aucune session HTTP n'est ouverte ; `php -S` (cli-server) en ouvre une.
return (require __DIR__ . '/kernel.php')($config, PHP_SAPI !== 'cli');
