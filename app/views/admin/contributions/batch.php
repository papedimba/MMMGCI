<?php declare(strict_types=1);
/** @var array $values @var ?string $error @var array $typeOptions @var string $banner @var string $csrfToken */
?>
<?= ui('page-header', ['title' => 'Générer un lot d\'appels', 'subtitle' => 'Vous verrez le nombre d\'adhérents concernés avant toute création.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Lot', null]]], '') ?>
<?= ui('alert', ['kind' => 'warn', 'message' => $banner]) ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="/admin/cotisations/appels/lot/confirmation">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Lot'],
    ui('field', ['name' => 'type_id', 'label' => 'Type de cotisation', 'type' => 'select', 'options' => $typeOptions, 'value' => $values['type_id'], 'required' => true])
    . ui('field', ['name' => 'start', 'label' => 'Un jour de la période visée', 'type' => 'date', 'value' => $values['start'], 'required' => true, 'hint' => 'Pas de période future.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Voir le nombre d\'adhérents concernés', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/cotisations/appels']) ?>
</div>
</form>
