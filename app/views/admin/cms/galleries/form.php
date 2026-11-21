<?php declare(strict_types=1);
/** @var string $base @var ?array<string,mixed> $row @var array<string,string> $values @var array<string,string> $errors @var string $errorSummary @var bool $locked @var string $publication @var string $csrfToken
 * @var list<array<string,mixed>> $items @var array<int|string,string> $images */
$edit = $row !== null;
$err = static fn (string $k): string => $errors[$k] ?? '';
$lock = $locked ? ['hint' => 'Album publié : modifiable par un publicateur seulement.', 'attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier l\'album' : 'Nouvel album', 'subtitle' => $edit ? (string) $row['title'] : '', 'breadcrumbs' => [['Galeries', $base], [$edit ? (string) $row['title'] : 'Nouvel album']]]) ?>
<?= $errorSummary ?>
<form method="post" action="<?= e($edit ? $base . '/' . $row['id'] : $base . '/nouveau') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if ($locked): foreach (['title', 'slug', 'description'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<?= ui('panel', ['title' => 'Album'], '<div class="form-grid">'
    . ui('field', array_replace_recursive(['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $err('title'), 'attrs' => ['maxlength' => 200]], $lock))
    . ui('field', array_replace_recursive(['name' => 'slug', 'label' => 'Adresse', 'value' => $values['slug'] ?? '', 'error' => $err('slug'),
        'hint' => 'Minuscules, chiffres et tirets. Laissée vide, elle est proposée à partir du titre.', 'attrs' => ['maxlength' => 80, 'autocapitalize' => 'none', 'spellcheck' => 'false']], $lock))
    . '</div>' . ($locked ? '<div class="rich-readonly">' . ($values['description'] ?? '') . '</div>' : ui('rich-editor', ['name' => 'description', 'label' => 'Description', 'value' => $values['description'] ?? '', 'error' => $err('description')]))) ?>
<div class="form-actions form-actions-sticky">
<?php if (!$locked): ?><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button><?php endif; ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ */ ?>
</form>
<?php if ($edit): ?>
<?php if ($locked): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Cet album est publié : seul un publicateur peut modifier l\'album et ses images.']) ?><?php endif; ?>
<section id="elements" aria-labelledby="elements-titre">
<h2 id="elements-titre">Images de l'album (<?= count($items) ?>)</h2>
<?php if ($items === []): ?><?= ui('empty-state', ['title' => 'Aucune image', 'message' => 'Ajoutez des images de la médiathèque ; chacune doit avoir un texte alternatif.']) ?><?php endif; ?>
<?php foreach ($items as $i => $it): $url = $base . '/' . $row['id'] . '/elements/' . $it['id']; $n = $i + 1; ?>
<div class="block-editor" id="element-<?= (int) $it['id'] ?>">
<img class="media-thumb" src="<?= e((string) $it['url']) ?>" alt="<?= e((string) $it['alt']) ?>" loading="lazy" width="120">
<p><strong><?= e((string) $it['title']) ?></strong><br><span class="field-hint">Texte alternatif : <?= e((string) $it['alt']) ?></span></p>
<?php if (!$locked): ?>
<form method="post" action="<?= e($url . '/legende') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<div class="field"><label for="caption-<?= (int) $it['id'] ?>">Légende (image <?= $n ?>)</label><input class="control" type="text" id="caption-<?= (int) $it['id'] ?>" name="caption" value="<?= e((string) $it['caption']) ?>" maxlength="200"></div>
<div class="field"><label for="credit-<?= (int) $it['id'] ?>">Crédit (image <?= $n ?>)</label><input class="control" type="text" id="credit-<?= (int) $it['id'] ?>" name="credit" value="<?= e((string) $it['credit']) ?>" maxlength="120"></div>
<button class="btn btn-secondary btn-sm" type="submit" formnovalidate><span>Enregistrer la légende<span class="visually-hidden"> de l'image <?= $n ?></span></span></button>
</form>
<div class="block-tools">
<?php foreach (['monter' => ['Monter', $i === 0], 'descendre' => ['Descendre', $i === count($items) - 1], 'retrait' => ['Retirer', false]] as $op => [$label, $off]): ?>
<form method="post" action="<?= e($url . '/' . $op) ?>"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-secondary btn-sm" type="submit" formnovalidate<?= $off ? ' disabled' : '' ?>><span><?= e($label) ?><span class="visually-hidden"> : image <?= $n ?></span></span></button></form>
<?php endforeach; ?>
</div>
<?php else: ?><p><?= e((string) $it['caption']) ?> <?= e((string) $it['credit']) ?></p><?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$locked): ?>
<form method="post" action="<?= e($base . '/' . $row['id'] . '/elements') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Ajouter une image'], '<div class="form-grid">'
    . ui('field', ['name' => 'media_id', 'label' => 'Image', 'type' => 'select', 'options' => $images, 'required' => true, 'hint' => 'Images de la médiathèque avec texte alternatif (Site web › Médias).'])
    . ui('field', ['name' => 'caption', 'label' => 'Légende', 'attrs' => ['maxlength' => 200]])
    . ui('field', ['name' => 'credit', 'label' => 'Crédit', 'attrs' => ['maxlength' => 120]])
    . '</div><button class="btn btn-primary" type="submit"><span>Ajouter</span></button>') ?>
</form>
<?php endif; ?>
</section>
<?php endif; ?>
<?= $publication ?>
