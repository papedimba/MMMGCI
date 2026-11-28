<?php declare(strict_types=1);
/** @var array $values @var ?string $error @var string $exceptional @var array $accountOptions @var array $categoryOptions @var string $csrfToken */
?>
<?= ui('page-header', ['title' => 'Enregistrer une ressource', 'subtitle' => 'Don, legs, subvention, contribution de partenaire, contribution exceptionnelle ou produit d\'activité : une recette hors cotisations.',
    'breadcrumbs' => [['Finances', '/admin/finances'], ['Enregistrer une ressource', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" enctype="multipart/form-data" action="/admin/finances/ressources">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Ressource'],
    ui('field', ['name' => 'account_id', 'label' => 'Compte crédité', 'type' => 'select', 'options' => $accountOptions, 'value' => $values['account_id'], 'required' => true])
    . ui('field', ['name' => 'category_id', 'label' => 'Nature de la ressource', 'type' => 'select', 'options' => $categoryOptions, 'value' => $values['category_id'], 'required' => true])
    . ui('field', ['name' => 'amount', 'label' => 'Montant (XOF)', 'value' => $values['amount'], 'required' => true, 'inputmode' => 'decimal', 'hint' => 'Par exemple 12 500 ou 12 500,50.'])
    . ui('field', ['name' => 'occurred_on', 'label' => 'Date de la recette', 'type' => 'date', 'value' => $values['occurred_on'], 'required' => true])
    . ui('field', ['name' => 'description', 'label' => 'Origine et détail', 'type' => 'textarea', 'value' => $values['description'], 'attrs' => ['maxlength' => 500], 'hint' => 'Donateur, partenaire, objet de la recette.'])
    . ui('field', ['name' => 'authorization_ref', 'label' => 'Référence de l\'autorisation', 'value' => $values['authorization_ref'], 'attrs' => ['maxlength' => 100],
        'hint' => 'Obligatoire pour « ' . $exceptional . ' » (numéro de la décision ou du procès-verbal).'])
    . ui('file-input', ['name' => 'justificatif', 'label' => 'Justificatif (facultatif)', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer la ressource', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/finances']) ?>
</div>
</form>
