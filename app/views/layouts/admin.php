<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Administration') ?> — MMMGCI</title>
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
<script src="/assets/js/app.js" defer></script>
</head>
<body>
<header>
<p><strong>MMMGCI — Administration</strong></p>
<p>Connecté : <?= e($user['name']) ?></p>
<nav aria-label="Menu d'administration">
<ul>
<li><a href="/admin">Tableau de bord</a></li>
<?php if ($can('users.manage')): ?><li><a href="/admin/utilisateurs">Utilisateurs</a></li>
<?php endif; ?>
<?php if ($can('roles.manage')): ?><li><a href="/admin/roles">Rôles</a></li>
<?php endif; ?>
<?php if ($can('audit.view')): ?><li><a href="/admin/journal">Journal d'audit</a></li>
<?php endif; ?>
</ul>
</nav>
<form method="post" action="/admin/deconnexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit">Se déconnecter</button>
</form>
</header>
<main>
<?php if (($flash ?? null) !== null): ?>
<p role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
