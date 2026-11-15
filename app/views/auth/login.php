<?php declare(strict_types=1); ?>
<h1>Connexion à l'administration</h1>
<p class="auth-lead">Connectez-vous avec votre adresse e-mail.</p>
<?php if ($flash !== null): ?>
<?= ui('alert', ['kind' => 'ok', 'message' => $flash]) ?>
<?php endif; ?>
<form method="post" action="/admin/connexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="retour" value="<?= e($retour) ?>">
<?= ui('field', ['name' => 'email', 'label' => 'Adresse e-mail', 'type' => 'email', 'value' => $email, 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('field', ['name' => 'password', 'label' => 'Mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => (string) $error]) ?>
<?= ui('button', ['label' => 'Se connecter', 'type' => 'submit']) ?>
</form>
<p class="auth-alt"><a href="/admin/mot-de-passe-oublie">Mot de passe oublié ?</a></p>
