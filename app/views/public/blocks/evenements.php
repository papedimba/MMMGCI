<?php declare(strict_types=1);
/** Prochains évènements publiés (bloc ou page /evenements : $items fourni). @var array<string,mixed> $b @var \App\Modules\Cms\PublishedContent $content */
use App\Modules\Cms\PublishedContent;

$items ??= $content->events(['when' => 'upcoming', 'limit' => max(1, min(12, (int) plain($b['count'] ?? 3)))]);
$empty ??= 'Aucun évènement à venir pour le moment.';
if ($items === [] && !empty($home)) {
    return; // accueil : rien de publié, section omise
}
?>
<section class="pub-block pub-events">
<?php if (!empty($home)): ?><h2><a href="/evenements">Prochains évènements</a></h2>
<?php endif; ?>
<?php if ($items === []): ?>
<p class="pub-empty"><?= e($empty) ?></p>
<?php else: ?>
<ul class="pub-cards">
<?php foreach ($items as $ev): ?>
<li class="pub-card">
<h3><a href="<?= e(sprintf(PublishedContent::EVENT_PATH, plain($ev['slug']))) ?>"><?= e(plain($ev['title'])) ?></a></h3>
<p class="pub-meta"><?= e(implode(' · ', array_filter([date_long_fr(plain($ev['starts_at'] ?? ''), true), plain($ev['location'] ?? '')]))) ?></p>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
