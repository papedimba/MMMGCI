<?php declare(strict_types=1);
/** Dernières actualités publiées (bloc ou page /actualites : $items fourni). @var array<string,mixed> $b @var \App\Modules\Cms\PublishedContent $content */
use App\Modules\Cms\PublishedContent;

$items ??= $content->articles(['limit' => max(1, min(12, (int) plain($b['count'] ?? 3)))]);
?>
<section class="pub-block pub-articles">
<?php if ($items === []): ?>
<p class="pub-empty">Aucune actualité publiée pour le moment.</p>
<?php else: ?>
<ul class="pub-cards">
<?php foreach ($items as $a): ?>
<li class="pub-card">
<h3><a href="<?= e(sprintf(PublishedContent::ARTICLE_PATH, plain($a['slug']))) ?>"><?= e(plain($a['title'])) ?></a></h3>
<?php if (plain($a['published_at'] ?? '') !== ''): ?><p class="pub-meta"><?= e(date_long_fr(plain($a['published_at']))) ?></p>
<?php endif; ?>
<?php if (plain($a['excerpt'] ?? '') !== ''): ?><p><?= e(plain($a['excerpt'])) ?></p>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
