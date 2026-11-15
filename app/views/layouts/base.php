<?php declare(strict_types=1); ?>
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
<link rel="stylesheet" href="/assets/css/auth.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
</head>
<body class="auth-page">
<div class="auth">
<main class="auth-card">
<img class="auth-emblem" src="/assets/img/emblem-160.png" alt="Emblème de la Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire" width="80" height="80">
<?= $content ?>
</main>
</div>
</body>
</html>
