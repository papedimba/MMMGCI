<?php declare(strict_types=1); ?>
<h1>Changer mon mot de passe</h1>
<?php if ($error !== null): ?>
<p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<p>Après le changement, vos autres sessions ouvertes seront fermées.</p>
<form method="post" action="/admin/mon-compte/mot-de-passe">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<p><label for="current_password">Mot de passe actuel</label><br>
<input type="password" id="current_password" name="current_password" autocomplete="current-password" required autofocus></p>
<p><label for="password">Nouveau mot de passe (au moins 12 caractères)</label><br>
<input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required></p>
<p><label for="password_confirmation">Confirmez le nouveau mot de passe</label><br>
<input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" required></p>
<p><button type="submit">Changer le mot de passe</button></p>
</form>
