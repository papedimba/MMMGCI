<?php declare(strict_types=1); ?>
<h1>Choisir un nouveau mot de passe</h1>
<?php if ($invalid !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $invalid]) ?>
<p class="auth-back"><a href="/admin/mot-de-passe-oublie">Demander un nouveau lien</a></p>
<?php else: ?>
<form method="post" action="/admin/reinitialiser/<?= e($token) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
    'hint' => 'Au moins 12 caractères. Évitez les mots de passe courants, votre nom et votre adresse e-mail.', 'error' => (string) $error, 'attrs' => ['minlength' => 12, 'autofocus' => true]]) ?>
<?= ui('field', ['name' => 'password_confirmation', 'label' => 'Confirmez le nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('button', ['label' => 'Enregistrer le mot de passe', 'type' => 'submit']) ?>
</form>
<?php endif; ?>
