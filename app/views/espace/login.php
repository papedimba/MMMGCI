<?php declare(strict_types=1);
$exists = $routeExists ?? static fn (string $h): bool => false;
?>
<h1>Espace adhérent</h1>
<p class="auth-lead">Connectez-vous avec votre numéro de membre, votre téléphone ou votre adresse e-mail.</p>
<form method="post" action="/espace/connexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="retour" value="<?= e($retour) ?>">
<?= ui('field', ['name' => 'identifiant', 'label' => 'Numéro de membre, téléphone ou e-mail', 'value' => $identifier, 'required' => true, 'autocomplete' => 'username', 'attrs' => ['autofocus' => true]]) ?>
<?= ui('field', ['name' => 'password', 'label' => 'Mot de passe', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password', 'error' => (string) $error]) ?>
<?= ui('button', ['label' => 'Se connecter', 'type' => 'submit']) ?>
</form>
<?php if ($exists('/espace/activer')): ?><p class="auth-alt"><a href="/espace/activer">J'ai reçu un code d'activation</a></p><?php endif; ?>
<?php if ($exists('/espace/mot-de-passe-oublie')): ?><p class="auth-alt"><a href="/espace/mot-de-passe-oublie">Mot de passe oublié ?</a></p><?php endif; ?>
