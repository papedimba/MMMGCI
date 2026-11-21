<?php declare(strict_types=1);
/** Album publié : images avec texte alternatif seulement ; description : HTML renettoyé. @var array<string,mixed> $album @var list<array<string,mixed>> $items */
use App\Modules\Cms\RichText;
?>
<article class="pub-page">
<header class="pub-head">
<?= ui('breadcrumbs', ['items' => [['Accueil', '/'], ['Galeries', '/galeries'], [plain($album['title'])]]]) ?>
<h1><?= e(plain($album['title'])) ?></h1>
</header>
<div class="pub-body">
<?php if (plain($album['description'] ?? '') !== ''): ?><div class="pub-rich"><?= RichText::sanitize(plain($album['description'])) ?></div>
<?php endif; ?>
<?php if ($items === []): ?>
<p class="pub-empty">Aucune image publiée dans cette galerie pour le moment.</p>
<?php else: ?>
<ul class="pub-gallery">
<?php foreach ($items as $it): ?>
<li><figure class="pub-figure"><img src="<?= e(plain($it['url'])) ?>" alt="<?= e(plain($it['alt'])) ?>" loading="lazy" decoding="async">
<?php if (plain($it['caption'] ?? '') !== '' || plain($it['credit'] ?? '') !== ''): ?><figcaption><?= e(plain($it['caption'] ?? '')) ?><?php if (plain($it['credit'] ?? '') !== ''): ?> <span class="pub-credit"><?= e(plain($it['credit'])) ?></span><?php endif; ?></figcaption>
<?php endif; ?>
</figure></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>
</article>
