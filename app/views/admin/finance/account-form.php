<?php declare(strict_types=1);
/** @var array $values @var ?string $error @var array $types @var string $csrfToken */
?>
<?= ui('page-header', ['title' => 'Nouveau compte', 'breadcrumbs' => [['Finances', '/admin/finances'], ['Nouveau compte', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="/admin/finances/comptes/nouveau">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Compte'],
    ui('field', ['name' => 'name', 'label' => 'Nom du compte', 'value' => $values['name'], 'required' => true, 'attrs' => ['maxlength' => 120]])
    . ui('field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => $values['type'], 'required' => true])
    . ui('field', ['name' => 'opening', 'label' => 'Solde d\'ouverture (XOF)', 'value' => $values['opening'], 'inputmode' => 'decimal',
        'hint' => 'Fixé à la création, jamais modifiable ensuite. Laissez vide pour 0. Ce n\'est pas une écriture du grand livre.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Créer le compte', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/finances']) ?>
</div>
</form>
