<?php declare(strict_types=1); ?>
<h1>Activer mon accès</h1>
<p class="auth-lead">Saisissez le code reçu de la Mutuelle (par lettre ou par e-mail), puis choisissez votre mot de passe. Ce formulaire sert aussi après une réinitialisation faite au guichet.</p>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="/espace/activer">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'identifiant', 'label' => 'Numéro de membre, téléphone ou e-mail', 'value' => $identifier, 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('field', ['name' => 'date_naissance', 'label' => 'Date de naissance', 'type' => 'date', 'value' => $birthDate, 'required' => true, 'autocomplete' => 'bday']) ?>
<?= ui('field', ['name' => 'code', 'label' => 'Code reçu', 'value' => '', 'required' => true, 'autocomplete' => 'one-time-code', 'hint' => '12 caractères, par exemple ABCD-EFGH-JKMN (les tirets sont facultatifs).']) ?>
<?= ui('field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
    'hint' => 'Au moins 12 caractères. Évitez les mots de passe courants, votre nom et votre adresse e-mail.', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('field', ['name' => 'password_confirmation', 'label' => 'Confirmez le nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('button', ['label' => 'Activer mon accès', 'type' => 'submit']) ?>
</form>
<p class="auth-alt"><a href="/espace/connexion">Retour à la connexion</a></p>
