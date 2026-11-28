<?php declare(strict_types=1);
/** Page publiée (gabarits « standard » et « liste ») : titre puis blocs rendus par PageRenderer. Pas de fil d'Ariane au premier niveau : réduit à « Accueil », il se lirait comme un petit label au-dessus du titre (le logo ramène à l'accueil). @var array<string,mixed> $page @var string $body */
?>
<article class="pub-page">
<header class="pub-head">
<h1><?= e(plain($page['title'])) ?></h1>
</header>
<div class="pub-body">
<?= $body ?>
</div>
</article>
