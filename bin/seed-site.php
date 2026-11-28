<?php
declare(strict_types=1);

// Contenu du cahier des charges en BROUILLON : php bin/seed-site.php --actor=<courriel d'un administrateur> (jamais de publication).
$config = require dirname(__DIR__) . '/app/init.php';
$db = App\Core\Database::connect($config);
$audit = new App\Core\Audit($db, (string) $config->get('APP_KEY'));
$gate = new App\Core\Gate($db);
$pages = new App\Modules\Cms\PageService($db, $audit, $gate);
[$code, $message] = (new App\Modules\Site\ContentSeed($db, $pages, new App\Modules\Cms\MenuService($db, $audit, $gate, $pages), $gate))->cli(array_slice($argv, 1));
fwrite($code === 0 ? STDOUT : STDERR, $message . "\n");
exit($code);
