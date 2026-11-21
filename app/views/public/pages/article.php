<?php declare(strict_types=1);
/**
 * Article publié. Auteur : libellé saisi seulement (jamais le nom réel du rédacteur). Corps : HTML renettoyé.
 * @var array<string,mixed> $article @var array{name:string,slug:string}|null $category @var array{url:string,alt:?string}|null $image
 */
use App\Modules\Cms\RichText;

$meta = array_filter([date_long_fr(plain($article['published_at'] ?? '')), $category['name'] ?? '', plain($article['author_label'] ?? '')]);
?>
<article class="pub-page">
<header class="pub-head">
<?= ui('breadcrumbs', ['items' => [['Accueil', '/'], ['Actualités', '/actualites'], [plain($article['title'])]]]) ?>
<h1><?= e(plain($article['title'])) ?></h1>
<?php if ($meta !== []): ?><p class="pub-meta"><?= e(implode(' · ', $meta)) ?></p>
<?php endif; ?>
</header>
<div class="pub-body">
<?php if ($image !== null && plain($image['alt'] ?? '') !== ''): ?><figure class="pub-figure"><img src="<?= e($image['url']) ?>" alt="<?= e($image['alt']) ?>" loading="lazy" decoding="async"></figure>
<?php endif; ?>
<div class="pub-rich"><?= RichText::sanitize(plain($article['body'] ?? '')) ?></div>
</div>
</article>
