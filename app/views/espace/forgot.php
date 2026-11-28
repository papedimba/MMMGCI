<?php declare(strict_types=1); ?>
<h1>Mot de passe oublié</h1>
<?php if ($message !== null): ?>
<?= ui('alert', ['kind' => 'ok', 'message' => $message]) ?>
<p class="auth-back"><a href="/espace/connexion">Retour à la connexion</a></p>
<?php else: ?>
<p class="auth-lead">Saisissez votre numéro de membre, votre téléphone ou votre adresse e-mail : si une adresse e-mail est enregistrée dans votre fiche, vous recevrez un lien pour choisir un nouveau mot de passe. Votre mot de passe actuel reste valable tant que le lien n'est pas utilisé.</p>
<form method="post" action="/espace/mot-de-passe-oublie">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('field', ['name' => 'identifiant', 'label' => 'Numéro de membre, téléphone ou e-mail', 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('button', ['label' => 'Recevoir le lien', 'type' => 'submit']) ?>
</form>
<p class="auth-alt">Sans adresse e-mail, adressez-vous à la Mutuelle : un gestionnaire vous remettra un nouveau code après vérification de votre identité.</p>
<p class="auth-alt"><a href="/espace/connexion">Retour à la connexion</a></p>
<?php endif; ?>
