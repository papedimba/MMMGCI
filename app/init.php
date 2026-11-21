<?php
declare(strict_types=1);

// Amorçage commun au web (bootstrap.php) et aux scripts bin/ : retourne la Config.
date_default_timezone_set('Africa/Abidjan');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers.php';

$config = new App\Core\Config(App\Core\Env::load(dirname(__DIR__) . '/.env'));
// Avant tout le reste : une erreur d'amorçage est journalisée et rendue sans détail.
App\Core\ErrorHandler::register($config->bool('APP_DEBUG'), dirname(__DIR__) . '/storage/logs/app.log');

return $config;
