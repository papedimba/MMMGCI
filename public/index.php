<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/bootstrap.php';
App\Core\ErrorHandler::register($app->config()->bool('APP_DEBUG'), dirname(__DIR__) . '/storage/logs/app.log');
$app->handle(App\Core\Http\Request::fromGlobals())->send();
