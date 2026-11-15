<?php declare(strict_types=1);
$menu = require __DIR__ . '/../public/_menu.php';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'MMMGCI') ?></title>
<?php if (isset($csrfToken)): ?><meta name="csrf-token" content="<?= e($csrfToken) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/public.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
</head>
<body class="public">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<header class="pub-top">
<a class="pub-brand" href="/">
<img class="pub-logo" src="/assets/img/logo-complet.jpg" srcset="/assets/img/logo-complet.jpg 1x, /assets/img/logo-complet@2x.jpg 2x" alt="Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire, Maritime Oceans and Hospitality Services" width="118" height="104">
<img class="pub-emblem" src="/assets/img/emblem-80.png" alt="Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire" width="56" height="56">
</a>
<button type="button" class="pub-menu-btn" data-menu-toggle aria-controls="menu-principal" aria-expanded="false" aria-label="Ouvrir le menu"><?= ui_icon('menu') ?><span>Menu</span></button>
<nav class="pub-menu" id="menu-principal" aria-label="Menu principal">
<?php foreach ($menu as $item): ?>
<?php if ($item['href'] !== null): ?>
<a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
<?php else: ?>
<a aria-disabled="true"><?= e($item['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
</nav>
</header>
<div class="flag" aria-hidden="true"><i class="flag-orange"></i><i class="flag-white"></i><i class="flag-green"></i></div>
<main id="contenu" tabindex="-1">
<?= $content ?>
</main>
<footer class="pub-foot">
<div class="cordage" aria-hidden="true"></div>
<div class="pub-foot-in">
<img src="/assets/img/logo-complet.jpg" srcset="/assets/img/logo-complet.jpg 1x, /assets/img/logo-complet@2x.jpg 2x" alt="MMMGCI et MOHS, Maritime Oceans and Hospitality Services" width="118" height="104">
<p class="pub-foot-motto">Un nouveau jour se lève</p>
<?php if (!empty($imageCredits)): ?><p class="pub-credits"><?= e($imageCredits) ?></p>
<?php endif; ?>
</div>
</footer>
</body>
</html>
