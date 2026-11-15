<?php declare(strict_types=1); ?>
<?= ui('page-header', ['title' => 'Changer mon mot de passe', 'subtitle' => 'Après le changement, vos autres sessions ouvertes seront fermées.']) ?>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<form class="form-narrow" method="post" action="/admin/mon-compte/mot-de-passe">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'current_password', 'label' => 'Mot de passe actuel', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
    'hint' => 'Au moins 12 caractères. Évitez les mots de passe courants, votre nom et votre adresse e-mail.', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('field', ['name' => 'password_confirmation', 'label' => 'Confirmez le nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('button', ['label' => 'Changer le mot de passe', 'type' => 'submit']) ?>
</form>
