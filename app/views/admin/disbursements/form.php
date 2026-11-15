<?php declare(strict_types=1);
/** @var ?array $d @var array $values @var ?string $error @var array $attachments @var array $accountOptions @var array $categoryOptions @var string $csrfToken @var string $title */
$draft = $d === null || $d['status'] === 'brouillon';
$action = $d === null ? '/admin/decaissements/nouveau' : '/admin/decaissements/' . $d['id'] . '/modifier';
$removable = '';
foreach ($attachments as $a) {
    $removable .= '<label class="check"><input type="checkbox" name="remove[]" value="' . e((string) $a['id']) . '"><span>Retirer « ' . e($a['label'] ?? $a['original_name']) . ' » (' . e($a['original_name']) . ')</span></label>';
}
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => $draft
    ? 'Brouillon : vous pouvez le modifier librement ; joignez au moins un justificatif avant de le soumettre.'
    : 'Demande soumise : modifier le montant, le bénéficiaire, le compte, le motif ou les justificatifs crée une nouvelle révision et annule les approbations déjà données.',
    'breadcrumbs' => [['Décaissements', '/admin/decaissements'], [$d === null ? 'Nouvelle demande' : $d['reference'], $d === null ? null : '/admin/decaissements/' . $d['id']]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" enctype="multipart/form-data" action="<?= e($action) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Demande'],
    ui('field', ['name' => 'amount', 'label' => 'Montant (XOF)', 'value' => $values['amount'], 'required' => true, 'inputmode' => 'decimal', 'hint' => 'Par exemple 150 000 ou 150 000,50.'])
    . ui('field', ['name' => 'beneficiary_name', 'label' => 'Bénéficiaire', 'value' => $values['beneficiary_name'], 'required' => true, 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'purpose', 'label' => 'Motif', 'type' => 'textarea', 'value' => $values['purpose'], 'required' => true, 'attrs' => ['maxlength' => 2000],
        'hint' => 'Objet de la dépense. N\'indiquez aucune donnée médicale.'])
    . ui('field', ['name' => 'account_id', 'label' => 'Compte débité', 'type' => 'select', 'options' => $accountOptions, 'value' => $values['account_id'], 'required' => true])
    . ui('field', ['name' => 'category_id', 'label' => 'Catégorie de dépense', 'type' => 'select', 'options' => $categoryOptions, 'value' => $values['category_id'], 'required' => true])
    . ui('field', ['name' => 'internal_note', 'label' => 'Note interne (facultatif)', 'type' => 'textarea', 'value' => $values['internal_note'], 'attrs' => ['maxlength' => 2000]])) ?>
<?= ui('panel', ['title' => 'Justificatifs'],
    $removable
    . ui('file-input', ['name' => 'justificatif', 'label' => $attachments === [] ? 'Justificatif' : 'Ajouter un justificatif', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG ; un fichier par enregistrement.', 'max_mb' => 5])
    . ui('field', ['name' => 'label', 'label' => 'Libellé du justificatif (facultatif)', 'value' => $values['label'], 'attrs' => ['maxlength' => 160], 'hint' => 'Par exemple : facture, acte de décès, devis.'])) ?>
<?php if (!$draft): ?>
<?= ui('panel', ['title' => 'Motif de la modification'], ui('field', ['name' => 'reason', 'label' => 'Motif', 'type' => 'textarea', 'value' => $values['reason'], 'attrs' => ['maxlength' => 500],
    'hint' => 'Obligatoire pour une modification qui crée une nouvelle révision ; il est conservé dans l\'historique.'])) ?>
<?php endif; ?>
<div class="form-actions">
<?= ui('button', ['label' => $d === null ? 'Enregistrer le brouillon' : 'Enregistrer les modifications', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => $d === null ? '/admin/decaissements' : '/admin/decaissements/' . $d['id']]) ?>
</div>
</form>
