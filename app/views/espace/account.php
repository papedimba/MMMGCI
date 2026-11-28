<?php declare(strict_types=1); /** @var list<string> $identifiers */ ?>
<h1>Mon compte</h1>
<?= ui('panel', ['title' => 'Vos identifiants de connexion'], '<p>Vous pouvez vous connecter avec :</p><ul>'
    . implode('', array_map(static fn (string $v): string => '<li>' . e($v) . '</li>', $identifiers)) . '</ul>') ?>
<h2>Changer mon mot de passe</h2>
<form method="post" action="/espace/compte">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'current_password', 'label' => 'Mot de passe actuel', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
<?= ui('field', ['name' => 'password', 'label' => 'Nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
    'hint' => 'Au moins 12 caractères. Évitez les mots de passe courants, votre nom et votre adresse e-mail.', 'attrs' => ['minlength' => 12]]) ?>
<?= ui('field', ['name' => 'password_confirmation', 'label' => 'Confirmez le nouveau mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
    'error' => (string) $error, 'attrs' => ['minlength' => 12]]) ?>
<?= ui('button', ['label' => 'Changer le mot de passe', 'type' => 'submit']) ?>
</form>
<p>Le changement ferme vos autres sessions ouvertes (autre téléphone, autre ordinateur).</p>
