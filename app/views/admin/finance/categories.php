<?php declare(strict_types=1);
/** @var array $rows @var array $values @var ?string $error @var Closure $can @var string $csrfToken */
$manage = $can('finance.manage');
$columns = [
    ['name', 'Catégorie', 'left'],
    ['direction', 'Sens', 'left', false, static fn (array $r): string => e($r['direction'] === 'recette' ? 'Recette' : 'Dépense')],
    ['is_active', 'État', 'left', false, static fn (array $r): string => (int) $r['is_active'] === 1 ? ui('badge', ['label' => 'Active', 'kind' => 'ok']) : ui('badge', ['label' => 'Désactivée', 'kind' => 'neutral']), 'status'],
];
$rowActions = $manage ? static fn (array $r): string => '<form method="post" action="/admin/finances/categories/' . e((string) $r['id']) . '/activation"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
    . '<input type="hidden" name="active" value="' . ((int) $r['is_active'] === 1 ? '0' : '1') . '">'
    . ui('button', ['label' => (int) $r['is_active'] === 1 ? 'Désactiver' : 'Réactiver', 'variant' => 'secondary', 'size' => 'sm', 'type' => 'submit']) . '</form>' : null;
?>
<?= ui('page-header', ['title' => 'Catégories financières', 'subtitle' => 'Rubriques des écritures. Une catégorie portée par des écritures se désactive, elle ne se supprime pas.'], '') ?>
<?= ui('tabs', ['label' => 'Finances', 'items' => [['Comptes', '/admin/finances', false], ['Catégories', '/admin/finances/categories', true]]]) ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?= ui('table', ['caption' => 'Catégories', 'columns' => $columns, 'rows' => $rows, 'row_actions' => $rowActions, 'empty' => ['title' => 'Aucune catégorie']]) ?>
<?php if ($manage): ?>
<form method="post" action="/admin/finances/categories">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Nouvelle catégorie'],
    ui('field', ['name' => 'name', 'label' => 'Nom', 'value' => $values['name'], 'required' => true, 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'direction', 'label' => 'Sens', 'type' => 'select', 'options' => ['recette' => 'Recette', 'depense' => 'Dépense'], 'value' => $values['direction'], 'required' => true])) ?>
<div class="form-actions"><?= ui('button', ['label' => 'Créer la catégorie', 'type' => 'submit']) ?></div>
</form>
<?php endif; ?>
