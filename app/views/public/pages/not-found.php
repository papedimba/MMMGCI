<?php declare(strict_types=1);
/**
 * Page d'erreur du site public (404 par défaut ; aussi 405 et 419 hors /admin) : ne répète jamais l'adresse demandée.
 * @var ?string $heading @var ?string $message @var ?string $back @var ?string $backLabel
 */
$heading ??= 'Page introuvable';
$message ??= 'La page demandée n\'existe pas, n\'est pas encore publiée ou a été déplacée.';
$back ??= '/';
$backLabel ??= 'Retour à l\'accueil';
?>
<article class="pub-page">
<header class="pub-head">
<h1><?= e($heading) ?></h1>
</header>
<div class="pub-body pub-lost">
<div>
<p><?= e($message) ?></p>
<p><a class="btn btn-primary" href="<?= e($back) ?>"><span><?= e($backLabel) ?></span></a></p>
</div>
<img class="pub-lost-art" src="/assets/img/art/ancre.svg" alt="Illustration d'une ancre entourée d'un cordage" width="240" height="280">
</div>
</article>
