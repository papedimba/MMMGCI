<?php declare(strict_types=1);
use App\Modules\Roles\ModuleLabels;

/** @var bool $readOnly rôle système, ou protégé et hors de portée de l'acteur : affichage seul (les règles serveur restent appliquées à l'écriture) */
$system = $target !== null && (int) $target['is_system'] === 1;
$double = ['disbursement.approve', 'disbursement.pay'];
?>
<?= ui('page-header', ['title' => $target === null ? 'Nouveau rôle' : $target['label'],
    'subtitle' => $target === null ? 'Le rôle est créé sans permission : vous les cocherez à l\'étape suivante.' : ($target['description'] ?? '')], '') ?>
<p class="back"><a href="/admin/roles">Retour à la liste des rôles</a></p>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<?php if ($readOnly): ?>
<?= ui('alert', ['kind' => 'info', 'message' => $system ? 'Ce rôle système ne peut pas être modifié.' : 'Ce rôle est protégé : seul un super administrateur peut le modifier.']) ?>
<?php endif; ?>
<form method="post" action="<?= e($target === null ? '/admin/roles/nouveau' : '/admin/roles/' . $target['id']) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php $identity = ''; if ($target === null) {
    $identity .= ui('field', ['name' => 'name', 'label' => 'Identifiant', 'value' => $values['name'], 'required' => true,
        'hint' => 'Définitif : minuscules, chiffres et _, ex. gest_tresorerie.', 'attrs' => ['maxlength' => 60, 'pattern' => '[a-z][a-z0-9_]{2,59}']]);
}
$identity .= ui('field', ['name' => 'label', 'label' => 'Libellé', 'value' => $values['label'], 'required' => true, 'attrs' => ['maxlength' => 120] + ($readOnly ? ['disabled' => true] : [])])
    . ui('field', ['name' => 'description', 'label' => 'Description (facultative)', 'type' => 'textarea', 'value' => $values['description'], 'attrs' => ['maxlength' => 1000] + ($readOnly ? ['disabled' => true] : [])]); ?>
<?= ui('panel', ['title' => 'Identité'], '<div class="form-narrow">' . $identity . '</div>') ?>
<?php if ($target !== null): ?>
<?php if (!$readOnly): ?><input type="hidden" name="permissions_field" value="1">
<?php endif; ?>
<h2 class="section-title">Permissions</h2>
<?php if (!$readOnly): ?><p class="field-hint" id="own-only">Vous ne pouvez attribuer que les droits que vous détenez.</p>
<?php endif; ?>
<?php foreach ($modules as $module => $permissions): ?>
<section class="panel">
<fieldset class="panel-fieldset">
<legend><?= e(ModuleLabels::label((string) $module)) ?></legend>
<div class="panel-body"><div class="check-grid">
<?php foreach ($permissions as $p): $lock = !$readOnly && $locked($p['name']); ?>
<div class="check-item"><label class="check"><input type="checkbox" name="permissions[]" value="<?= e($p['name']) ?>"<?= $readOnly || $lock ? ' disabled' : '' ?><?= $lock ? ' aria-describedby="own-only"' : '' ?><?= in_array($p['name'], $values['permissions'], true) ? ' checked' : '' ?>><span><?= e($p['label']) ?></span></label>
<?php if (in_array($p['name'], $double, true)): ?><p class="field-hint">Réservé aux personnes désignées par les instances (double validation).</p><?php endif; ?></div>
<?php endforeach; ?>
</div></div>
</fieldset>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!$readOnly): ?>
<div class="form-actions">
<?= ui('button', ['label' => $target === null ? 'Créer le rôle' : 'Enregistrer', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => '/admin/roles']) ?>
</div>
<?php endif; ?>
</form>
<?php if ($target !== null && !$readOnly): ?>
<?= ui('panel', ['title' => 'Supprimer le rôle'],
    '<p>Possible seulement si aucun compte ne porte ce rôle.</p>'
    . '<form class="delete-form" method="post" action="/admin/roles/' . e((string) $target['id']) . '/suppression"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
    . '<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer ce rôle</span></button></form>') ?>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer ce rôle ?', 'message' => 'Cette action est définitive.',
    'confirm_label' => 'Supprimer', 'form_action' => '/admin/roles/' . $target['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
