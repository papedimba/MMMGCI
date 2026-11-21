<?php declare(strict_types=1);
/** @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} $result @var array<string,string> $query @var list<string> $folders @var Closure $can */
$base = '/admin/site/medias';
$canWrite = $can('cms.media.manage');
$folderOptions = ['' => 'Tous les dossiers'];
foreach ($folders as $f) {
    $folderOptions[$f] = $f;
}
?>
<?= ui('page-header', ['title' => 'Médias', 'subtitle' => 'Images et documents du site public. Chaque image porte un texte alternatif.'],
    $canWrite ? ui('button', ['label' => 'Ajouter un média', 'href' => $base . '/nouveau', 'icon' => 'plus']) : '') ?>
<form method="get" action="<?= e($base) ?>" class="filter-row">
<?= ui('field', ['name' => 'q', 'label' => 'Recherche', 'type' => 'search', 'value' => $query['q'] ?? '', 'hint' => 'Titre, texte alternatif ou légende', 'attrs' => ['maxlength' => 100]]) ?>
<?= ui('field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'value' => $query['type'] ?? '', 'options' => ['' => 'Tous', 'image' => 'Images', 'document' => 'Documents']]) ?>
<?= ui('field', ['name' => 'folder', 'label' => 'Dossier', 'type' => 'select', 'value' => $query['folder'] ?? '', 'options' => $folderOptions]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<?php if ($result['rows'] === []): ?>
<?= ui('empty-state', ['title' => 'Aucun média', 'message' => $query === [] ? 'La médiathèque est vide.' : 'Aucun média ne correspond à ces critères.']) ?>
<?php else: ?>
<p class="field-hint"><?= e((string) $result['total']) ?> média<?= $result['total'] > 1 ? 's' : '' ?></p>
<ul class="media-grid">
<?php foreach ($result['rows'] as $m): ?>
<li class="media-card">
<a class="media-link" href="<?= e($canWrite ? $base . '/' . $m['id'] : $m['url']) ?>">
<?php if ($m['is_image']): ?><img class="media-thumb" src="<?= e($m['url']) ?>" alt="<?= e((string) $m['alt']) ?>" loading="lazy"<?= $m['width'] ? ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"' : '' ?>>
<?php else: ?><span class="media-thumb media-doc"><?= ui_icon('file') ?><span>PDF</span></span>
<?php endif; ?>
<span class="media-title"><?= e($m['title']) ?></span>
</a>
<span class="media-meta"><?= $m['folder'] !== null ? e($m['folder']) : 'Racine' ?></span>
</li>
<?php endforeach; ?>
</ul>
<?= ui('pagination', ['base' => $base, 'page' => $result['page'], 'pages' => $result['pages'], 'query' => $query]) ?>
<?php endif; ?>
