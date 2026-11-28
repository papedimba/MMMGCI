<?php declare(strict_types=1);
$initials = mb_strtoupper(implode('', array_map(static fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim((string) $user['name'])) ?: [], 0, 2))));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Administration') ?> — MMMGCI</title>
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<link rel="icon" type="image/png" href="/assets/img/emblem-40.png">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/admin.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/editor.js" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<div class="shell">
<aside class="side" id="menu-admin">
<a class="brand" href="/admin"><img src="/assets/img/emblem-80.png" alt="" width="40" height="40"><span>MMMGCI</span></a>
<nav aria-label="Menu d'administration">
<?php $current = $currentPath ?? ''; include __DIR__ . '/../admin/_nav-links.php'; ?>
</nav>
</aside>
<div class="main">
<header class="topbar">
<button type="button" class="menu-btn" data-menu-toggle aria-controls="menu-admin" aria-expanded="false" aria-label="Ouvrir le menu"><?= ui_icon('menu') ?></button>
<img class="top-emblem" src="/assets/img/emblem-40.png" alt="" width="32" height="32">
<p class="page-name"><?= e($title ?? 'Administration') ?></p>
<details class="account">
<summary><span class="avatar" aria-hidden="true"><?= e($initials) ?></span><span class="account-name"><?= e($user['name']) ?></span><span class="visually-hidden">Mon compte</span><?= ui_icon('chevron-down') ?></summary>
<div class="account-menu">
<a href="/admin/mon-compte/mot-de-passe"><?= ui_icon('key') ?>Mon mot de passe</a>
<form method="post" action="/admin/deconnexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit"><?= ui_icon('logout') ?>Se déconnecter</button>
</form>
</div>
</details>
</header>
<main class="sheet" id="contenu" tabindex="-1">
<?php if (($flash ?? null) !== null): ?>
<?= ui('alert', ['kind' => $flashKind ?? 'ok', 'message' => $flash]) ?>
<?php endif; ?>
<?= $content ?>
</main>
</div>
</div>
</body>
</html>
