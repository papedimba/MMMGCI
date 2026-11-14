<?php declare(strict_types=1); ?>
<main>
<h1>Choisir un nouveau mot de passe</h1>
<?php if ($invalid !== null): ?>
<p role="alert"><?= e($invalid) ?></p>
<p><a href="/admin/mot-de-passe-oublie">Demander un nouveau lien</a></p>
<?php else: ?>
<?php if ($error !== null): ?>
<p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/admin/reinitialiser/<?= e($token) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<p><label for="password">Nouveau mot de passe (au moins 12 caractères)</label><br>
<input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required autofocus></p>
<p><label for="password_confirmation">Confirmez le nouveau mot de passe</label><br>
<input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required></p>
<p><button type="submit">Enregistrer le mot de passe</button></p>
</form>
<?php endif; ?>
</main>
