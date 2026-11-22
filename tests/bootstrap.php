<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/DatabaseTestCase.php';
require __DIR__ . '/Support/AdminTestCase.php';
require __DIR__ . '/Support/DisbursementFixtures.php';
require __DIR__ . '/Support/SocialFixtures.php';

// AuditLogController::verify() pose set_time_limit(60) qui persisterait pour tout le processus PHPUnit.
set_time_limit(0);
