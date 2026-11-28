<?php declare(strict_types=1);
$active = $target !== null && (int) $target['is_active'] === 1;
?>
<?= ui('page-header', ['title' => $target === null ? 'Nouvel utilisateur' : $target['name'],
    'subtitle' => $target === null ? 'Le nouvel utilisateur recevra par e-mail un lien valable 72 heures pour choisir son mot de passe.' : ''], '') ?>
<p class="back"><a href="/admin/utilisateurs">Retour à la liste des utilisateurs</a></p>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<form method="post" action="<?= e($target === null ? '/admin/utilisateurs/nouveau' : '/admin/utilisateurs/' . $target['id']) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Identité'], '<div class="form-grid">'
    . ui('field', ['name' => 'name', 'label' => 'Nom', 'value' => $values['name'], 'required' => true, 'autocomplete' => 'name', 'attrs' => ['maxlength' => 120]])
    . ui('field', ['name' => 'email', 'label' => 'Adresse e-mail', 'type' => 'email', 'value' => $values['email'], 'required' => true, 'autocomplete' => 'email', 'attrs' => ['maxlength' => 190]])
    . ui('field', ['name' => 'phone', 'label' => 'Téléphone (facultatif)', 'type' => 'tel', 'value' => $values['phone'], 'hint' => '10 chiffres.', 'autocomplete' => 'tel', 'inputmode' => 'tel'])
    . ui('field', ['name' => 'zone_id', 'label' => 'Zone de rattachement (facultatif)', 'type' => 'select', 'value' => (string) $values['zone_id'],
        'hint' => 'Sans accès national aux adhérents, le compte ne voit que les adhérents de cette zone (sous-zones non incluses).',
        'options' => ['' => 'Aucune'] + array_column($zones, 'name', 'id')])
    . '</div>') ?>
<section class="panel">
<fieldset class="panel-fieldset">
<legend>Rôles</legend>
<div class="panel-body">
<?php if ($self): ?>
<p class="field-hint">Vous ne pouvez pas modifier vos propres rôles : demandez-le à un autre administrateur.</p>
<?php else: ?>
<input type="hidden" name="roles_field" value="1">
<?php endif; ?>
<div class="check-grid">
<?php foreach ($roles as $role): ?>
<label class="check"><input type="checkbox" name="roles[]" value="<?= e($role['name']) ?>"<?= in_array($role['name'], $values['roles'], true) ? ' checked' : '' ?><?= $self ? ' disabled' : '' ?>><span><?= e($role['label']) ?></span></label>
<?php endforeach; ?>
</div>
</div>
</fieldset>
</section>
<div class="form-actions">
<?= ui('button', ['label' => $target === null ? 'Créer et envoyer l\'invitation' : 'Enregistrer', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => '/admin/utilisateurs']) ?>
</div>
</form>
<?php if ($target !== null): ?>
<?= ui('panel', ['title' => 'État du compte'],
    '<p class="state-line">' . ui('badge', ['label' => $active ? 'Actif' : 'Désactivé', 'kind' => $active ? 'ok' : 'neutral']) . '<span>Compte ' . ($active ? 'actif' : 'désactivé') . ' · dernière connexion : ' . e(date_fr($target['last_login_at'], 'jamais')) . '</span></p>'
    . ($self ? '' : '<form method="post" action="/admin/utilisateurs/' . e((string) $target['id']) . '/activation">'
        . '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="active" value="' . ($active ? '0' : '1') . '">'
        . ($active
            ? '<button class="btn btn-danger" type="submit" data-confirm-open="confirm-desactivation"><span>Désactiver le compte (ferme ses sessions)</span></button>'
            : ui('button', ['label' => 'Réactiver le compte', 'type' => 'submit', 'variant' => 'secondary']))
        . '</form>')) ?>
<?php if ($active && !$self): ?>
<?= ui('confirm-dialog', ['id' => 'confirm-desactivation', 'title' => 'Désactiver ce compte ?', 'message' => 'La personne ne pourra plus se connecter et ses sessions ouvertes seront fermées.',
    'confirm_label' => 'Désactiver', 'form_action' => '/admin/utilisateurs/' . $target['id'] . '/activation', 'csrf' => $csrfToken, 'fields' => ['active' => '0']]) ?>
<?php endif; ?>
<?php endif; ?>
