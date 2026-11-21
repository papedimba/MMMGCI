<?php declare(strict_types=1);
/** Documents publiés (bloc d'une page ou page /documents : $items fourni). @var array<string,mixed> $b @var \App\Modules\Cms\PublishedContent $content */
$items ??= $content->documents(plain($b['category'] ?? '') !== '' ? plain($b['category']) : null);
?>
<section class="pub-block pub-docs">
<?php if ($items === []): ?>
<p class="pub-empty">Aucun document publié pour le moment.</p>
<?php else: ?>
<ul class="pub-cards">
<?php foreach ($items as $d): ?>
<li class="pub-card">
<h3><a href="<?= e(plain($d['url'])) ?>"><?= e(plain($d['title'])) ?></a></h3>
<p class="pub-meta"><?= e(implode(' · ', array_filter([plain($d['category'] ?? ''), date_long_fr(plain($d['document_date'] ?? '')),
    plain($d['mime'] ?? '') === 'application/pdf' ? 'PDF' : '', (int) ($d['size'] ?? 0) > 0 ? number_format((int) $d['size'] / 1048576, 1, ',', ' ') . ' Mo' : '']))) ?></p>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
