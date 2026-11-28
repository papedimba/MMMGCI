<?php declare(strict_types=1);
/** Gabarit de l'Espace adhérent : aucune donnée ni lien d'administration. @var ?array $member @var string $csrfToken */
$exists = $routeExists ?? static fn (string $h): bool => false;
$current = $currentPath ?? '';
$links = ['/espace' => 'Accueil', '/espace/fiche' => 'Ma fiche', '/espace/cotisations' => 'Mes cotisations', '/espace/carte' => 'Ma carte',
    '/espace/demandes' => 'Mes demandes', '/espace/messages' => 'Messages', '/espace/compte' => 'Mon compte'];
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title ?? 'Espace adhérent') ?> — Espace adhérent MMMGCI</title>
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<link rel="icon" type="image/png" href="/assets/img/emblem-40.png">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/espace.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
</head>
<body class="espace">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<header class="esp-top">
<a class="esp-brand" href="/espace"><img src="/assets/img/emblem-80.png" alt="" width="40" height="40"><span>Espace adhérent</span></a>
<?php if (!empty($member)): ?>
<form class="esp-logout" method="post" action="/espace/deconnexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit" class="btn btn-secondary"><?= ui_icon('logout') ?><span>Se déconnecter</span></button>
</form>
<?php endif; ?>
</header>
<?php if (!empty($member)): ?>
<nav class="esp-nav" aria-label="Rubriques de l'Espace adhérent">
<ul>
<?php foreach ($links as $href => $label): if (!$exists($href)) { continue; } $on = $current === $href || ($href !== '/espace' && str_starts_with($current, $href . '/')); ?>
<li><a href="<?= e($href) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
<?php endforeach; ?>
</ul>
</nav>
<?php endif; ?>
<main class="esp-main" id="contenu" tabindex="-1">
<?php if (($member['access'] ?? null) === 'lecture'): ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Votre accès est en consultation seule : vous pouvez consulter vos informations, mais pas envoyer de demande ni de correction. Contactez la Mutuelle pour toute question.']) ?>
<?php endif; ?>
<?php if (($flash ?? null) !== null): ?>
<?= ui('alert', ['kind' => $flashKind ?? 'ok', 'message' => $flash]) ?>
<?php endif; ?>
<?= $content ?>
</main>
</body>
</html>
