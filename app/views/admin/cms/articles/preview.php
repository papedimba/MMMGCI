<?php declare(strict_types=1);
/** Aperçu de la copie de travail (gabarits publics en L4) : titre et métadonnées échappés, corps renettoyé par RichText::sanitize. @var array<string,mixed> $article @var ?array{url:string,alt:string} $image */
use App\Modules\Cms\RichText;

$excerpt = (string) ($article['excerpt'] ?? '') !== '' ? (string) $article['excerpt'] : RichText::toText((string) $article['body'], 200);
?>
<div class="preview-banner" role="note"><strong>Aperçu — non publié</strong> <a href="<?= e('/admin/site/articles/' . $article['id']) ?>">Retour à l'article</a></div>
<article class="pub-page preview-page">
<h1><?= e($article['title']) ?></h1>
<p class="preview-meta"><?= e(implode(' · ', array_filter([(string) ($article['category_name'] ?? ''), (string) ($article['author_name'] ?? ''), (string) ($article['tags'] ?? '')]))) ?></p>
<?php if ($image !== null): ?><figure class="preview-figure"><img src="<?= e($image['url']) ?>" alt="<?= e($image['alt']) ?>" loading="lazy"></figure>
<?php endif; ?>
<?php if ($excerpt !== ''): ?><p class="preview-intro"><?= e($excerpt) ?></p>
<?php endif; ?>
<div class="preview-rich"><?= RichText::sanitize((string) $article['body']) ?></div>
</article>
