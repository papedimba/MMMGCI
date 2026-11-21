<?php declare(strict_types=1);
/** Page publiée (gabarits « standard » et « liste ») : titre puis blocs rendus par PageRenderer. @var array<string,mixed> $page @var string $body */
?>
<article class="pub-page">
<h1><?= e(plain($page['title'])) ?></h1>
<?= $body ?>
</article>
