<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/bootstrap.php';
$app->handle(App\Core\Http\Request::fromGlobals())->send();
