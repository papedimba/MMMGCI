<?php declare(strict_types=1);
/** @var array $values @var ?string $error @var array $typeOptions @var string $csrfToken */
?>
<?= ui('page-header', ['title' => 'Appel individuel', 'subtitle' => 'Le montant est celui du type choisi ; il ne se saisit pas.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Appel individuel', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?php if (count($typeOptions) < 2): ?><?= ui('alert', ['kind' => 'warn', 'message' => 'Aucun type actif avec un montant validé : aucun appel n\'est possible pour le moment.']) ?><?php endif; ?>
<form method="post" action="/admin/cotisations/appels/individuel">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Appel'],
    ui('field', ['name' => 'member_number', 'label' => 'Numéro d\'adhérent', 'value' => $values['member_number'], 'required' => true])
    . ui('field', ['name' => 'type_id', 'label' => 'Type de cotisation', 'type' => 'select', 'options' => $typeOptions, 'value' => $values['type_id'], 'required' => true])
    . ui('field', ['name' => 'start', 'label' => 'Un jour de la période visée', 'type' => 'date', 'value' => $values['start'], 'required' => true, 'hint' => 'La période (mois, trimestre…) qui contient cette date ; pas de période future.'])
    . ui('field', ['name' => 'due_date', 'label' => 'Échéance (facultatif)', 'type' => 'date', 'value' => $values['due_date'], 'hint' => 'Par défaut : fin de la période.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Créer l\'appel', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/cotisations/appels']) ?>
</div>
</form>
