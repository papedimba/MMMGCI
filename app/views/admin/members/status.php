<?php declare(strict_types=1);
/** @var array $m @var array $values @var array $errors @var array $options @var string $currentLabel @var int $maxReason @var int $maxRef @var string $csrfToken */
$base = '/admin/adherents/' . $m['id'];
$name = $m['last_name'] . ' ' . $m['first_names'];
?>
<?= ui('page-header', ['title' => 'Changer le statut', 'subtitle' => $name . ' · N° ' . $m['member_number'],
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], [$name, $base], ['Changer le statut', null]]], '') ?>
<?php if ($errors !== []): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => 'Le statut n\'a pas été modifié : corrigez les champs signalés.']) ?>
<?php endif; ?>
<form method="post" action="<?= e($base . '/statut') ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Nouveau statut'],
    '<p>Statut actuel : <strong>' . e($currentLabel) . '</strong></p>'
    . ui('field', ['name' => 'to', 'label' => 'Nouveau statut', 'type' => 'select', 'options' => $options, 'value' => $values['to'], 'required' => true, 'error' => $errors['to'] ?? ''])
    . ui('field', ['name' => 'reason', 'label' => 'Motif', 'type' => 'textarea', 'value' => $values['reason'], 'required' => true, 'error' => $errors['reason'] ?? '', 'attrs' => ['maxlength' => $maxReason]])
    . ui('field', ['name' => 'decision_ref', 'label' => 'Référence de la décision', 'value' => $values['decision_ref'], 'required' => true, 'error' => $errors['decision_ref'] ?? '',
        'hint' => 'Par exemple le numéro de la décision ou du procès-verbal.', 'attrs' => ['maxlength' => $maxRef]])
    . '<p class="field-hint">Le changement est enregistré dans l\'historique avec son motif et son auteur. Les conséquences d\'un changement de statut (carte, cotisations, accès) seront définies par les instances.</p>') ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer le changement', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => $base]) ?>
</div>
</form>
