<?php declare(strict_types=1);
/** Page d'accueil publiée (slug « accueil », servie à /) : mêmes blocs, mise en page d'accueil (direction artistique : tâche 2). @var array<string,mixed> $page @var string $body */
?>
<article class="pub-page pub-home">
<h1><?= e(plain($page['title'])) ?></h1>
<?= $body ?>
</article>
