<?php
declare(strict_types=1);

$config = require __DIR__ . '/init.php';

// En CLI (PHPUnit, scripts bin/) aucune session HTTP n'est ouverte ; `php -S` (cli-server) en ouvre une.
return (require __DIR__ . '/kernel.php')($config, PHP_SAPI !== 'cli');
