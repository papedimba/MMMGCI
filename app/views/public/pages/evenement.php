<?php declare(strict_types=1);
/** Évènement publié ; description : HTML renettoyé. @var array<string,mixed> $event @var array{url:string,alt:?string}|null $image */
use App\Modules\Cms\RichText;

$when = date_long_fr(plain($event['starts_at'] ?? ''), true);
$until = date_long_fr(plain($event['ends_at'] ?? ''), true);
?>
<article class="pub-page">
<p class="pub-back"><a href="/evenements">Tous les évènements</a></p>
<h1><?= e(plain($event['title'])) ?></h1>
<dl class="pub-facts-inline">
<div><dt>Date</dt><dd><?= e($until !== '' ? $when . ' au ' . $until : $when) ?></dd></div>
<?php if (plain($event['location'] ?? '') !== ''): ?><div><dt>Lieu</dt><dd><?= e(plain($event['location'])) ?></dd></div>
<?php endif; ?>
</dl>
<?php if ($image !== null && plain($image['alt'] ?? '') !== ''): ?><figure class="pub-figure"><img src="<?= e($image['url']) ?>" alt="<?= e($image['alt']) ?>" loading="lazy" decoding="async"></figure>
<?php endif; ?>
<?php if (plain($event['description'] ?? '') !== ''): ?><div class="pub-rich"><?= RichText::sanitize(plain($event['description'])) ?></div>
<?php endif; ?>
</article>
