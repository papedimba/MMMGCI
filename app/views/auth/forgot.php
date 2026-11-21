<?php declare(strict_types=1); ?>
<h1>Mot de passe oublié</h1>
<?php if ($sent): ?>
<?= ui('alert', ['kind' => 'ok', 'message' => $message]) ?>
<p class="auth-back"><a href="/admin/connexion">Retour à la connexion</a></p>
<?php else: ?>
<p class="auth-lead">Saisissez l'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
<form method="post" action="/admin/mot-de-passe-oublie">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'email', 'label' => 'Adresse e-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('button', ['label' => 'Recevoir le lien', 'type' => 'submit']) ?>
</form>
<p class="auth-alt"><a href="/admin/connexion">Retour à la connexion</a></p>
<?php endif; ?>
