<?php declare(strict_types=1);
/** @var int $id @var array $values @var ?string $error @var array $kinds @var array $periodicities @var array $accountOptions @var string $csrfToken */
$action = $id > 0 ? "/admin/cotisations/types/$id/modifier" : '/admin/cotisations/types/nouveau';
?>
<?= ui('page-header', ['title' => $id > 0 ? 'Modifier le type' : 'Nouveau type de cotisation', 'subtitle' => 'Laissez montant et périodicité vides tant que les instances ne les ont pas validés.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/types'], [$id > 0 ? 'Modifier' : 'Nouveau', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Périodicités proposées : vocabulaire technique, en attente de validation par les instances.']) ?>
<?= ui('alert', ['kind' => 'info', 'message' => App\Modules\Contributions\CallService::AUTO_BANNER]) ?>
<form method="post" action="<?= e($action) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Type'],
    ui('field', ['name' => 'name', 'label' => 'Nom', 'value' => $values['name'], 'required' => true, 'attrs' => ['maxlength' => 120]])
    . ui('field', ['name' => 'kind', 'label' => 'Nature', 'type' => 'select', 'options' => $kinds, 'value' => $values['kind'], 'required' => true])
    . ui('field', ['name' => 'amount', 'label' => 'Montant (XOF)', 'value' => $values['amount'], 'inputmode' => 'decimal', 'hint' => 'Vide = non configuré.'])
    . ui('field', ['name' => 'periodicity', 'label' => 'Périodicité', 'type' => 'select', 'options' => $periodicities, 'value' => $values['periodicity']])
    . ui('field', ['name' => 'authorization_ref', 'label' => 'Référence de l\'autorisation', 'value' => $values['authorization_ref'], 'attrs' => ['maxlength' => 100], 'hint' => 'Décision ou procès-verbal. Obligatoire pour une cotisation exceptionnelle et pour activer.'])
    . ui('field', ['name' => 'affects_account_id', 'label' => 'Compte crédité', 'type' => 'select', 'options' => $accountOptions, 'value' => $values['affects_account_id']])
    . ui('field', ['name' => 'status', 'label' => 'État', 'type' => 'select', 'options' => ['non_configure' => 'Non configuré', 'actif' => 'Actif'], 'value' => $values['status'], 'hint' => 'Actif exige montant, autorisation et compte actif.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/cotisations/types']) ?>
</div>
</form>
