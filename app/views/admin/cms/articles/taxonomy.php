<?php declare(strict_types=1);
/** @var string $kind @var list<array<string,mixed>> $rows @var array<string,string> $errors @var array<string,string> $values @var ?int $editing @var string $csrfToken */
$base = '/admin/site/articles/' . $kind;
$cat = $kind === 'categories';
$one = $cat ? 'catégorie' : 'étiquette';
$hidden = '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '">';
$new = ui('panel', ['title' => 'Ajouter une ' . $one], '<form method="post" action="' . e($base) . '" class="page-form">' . $hidden . '<div class="form-grid">'
    . ui('field', ['name' => 'name', 'label' => 'Nom', 'required' => true, 'value' => $editing === null ? ($values['name'] ?? '') : '', 'error' => $editing === null ? ($errors['name'] ?? '') : '', 'attrs' => ['maxlength' => 120]])
    . ui('field', ['name' => 'slug', 'label' => 'Adresse', 'value' => $editing === null ? ($values['slug'] ?? '') : '', 'error' => $editing === null ? ($errors['slug'] ?? '') : '', 'hint' => 'Vide : proposée à partir du nom.', 'attrs' => ['maxlength' => 80, 'autocapitalize' => 'none']])
    . '</div><div class="form-actions"><button class="btn btn-primary" type="submit"><span>Ajouter</span></button></div></form>');
?>
<?= ui('page-header', ['title' => $cat ? 'Catégories' : 'Étiquettes', 'breadcrumbs' => [['Articles', '/admin/site/articles'], [$cat ? 'Catégories' : 'Étiquettes']]]) ?>
<?php if ($editing !== null && $errors !== []): ?>
<div class="alert alert-danger" role="alert"><?= ui_icon('alert') ?><div><ul class="error-list"><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?= $new ?>
<?php if ($rows === []): ?>
<?= ui('empty-state', ['title' => 'Aucune ' . $one, 'message' => 'Ajoutez la première ci-dessus.']) ?>
<?php else: ?>
<ul class="taxonomy-list">
<?php foreach ($rows as $row): $dialog = 'confirm-' . (int) $row['id']; ?>
<li><form method="post" action="<?= e($base . '/' . $row['id']) ?>" class="inline-form"><?= $hidden ?>
<?php $n = $editing === (int) $row['id'] ? ($values['name'] ?? '') : (string) $row['name']; $sl = $editing === (int) $row['id'] ? ($values['slug'] ?? '') : (string) $row['slug']; ?>
<div class="field"><label for="n-<?= (int) $row['id'] ?>">Nom</label><input class="control" type="text" id="n-<?= (int) $row['id'] ?>" name="name" value="<?= e($n) ?>" maxlength="120" required></div>
<div class="field"><label for="s-<?= (int) $row['id'] ?>">Adresse</label><input class="control" type="text" id="s-<?= (int) $row['id'] ?>" name="slug" value="<?= e($sl) ?>" maxlength="80" autocapitalize="none"></div>
<span class="field-hint"><?= (int) $row['used'] ?> article<?= (int) $row['used'] > 1 ? 's' : '' ?></span>
<button class="btn btn-secondary btn-sm" type="submit"><span>Enregistrer<span class="visually-hidden"> : <?= e($row['name']) ?></span></span></button>
</form>
<?php if ((int) $row['used'] === 0): ?>
<form method="post" action="<?= e($base . '/' . $row['id'] . '/suppression') ?>" class="inline-form"><?= $hidden ?>
<button class="btn btn-danger btn-sm" type="submit" data-confirm-open="<?= e($dialog) ?>"><span>Supprimer<span class="visually-hidden"> : <?= e($row['name']) ?></span></span></button></form>
<?= ui('confirm-dialog', ['id' => $dialog, 'title' => 'Supprimer cette ' . $one . ' ?', 'message' => '« ' . $row['name'] . ' » sera effacée définitivement.', 'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $row['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
