<?php declare(strict_types=1);
/** @var array{rows:list<array<string,mixed>>} $result @var string $q */
?>
<div class="media-picker">
<form method="get" action="/admin/site/medias/selecteur"><?= ui('field', ['name' => 'q', 'label' => 'Rechercher une image', 'type' => 'search', 'value' => $q, 'attrs' => ['maxlength' => 100]]) ?><?= ui('button', ['label' => 'Rechercher', 'type' => 'submit', 'variant' => 'secondary']) ?></form>
<?php if ($result['rows'] === []): ?><p>Aucune image trouvée.</p>
<?php else: ?><ul class="media-grid">
<?php foreach ($result['rows'] as $m): ?><li class="media-card">
<img class="media-thumb" src="<?= e($m['url']) ?>" alt="<?= e((string) $m['alt']) ?>" loading="lazy"<?= $m['width'] ? ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"' : '' ?>>
<span class="media-title"><?= e($m['title']) ?></span>
<a class="btn btn-secondary" href="<?= e($m['url']) ?>" data-media-id="<?= (int) $m['id'] ?>" data-media-url="<?= e($m['url']) ?>" data-media-alt="<?= e((string) $m['alt']) ?>"><span>Choisir<span class="visually-hidden"> : <?= e($m['title']) ?></span></span></a>
</li>
<?php endforeach; ?></ul>
<?php endif; ?>
</div>
