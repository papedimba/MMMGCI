<?php declare(strict_types=1); ?>
<main>
<h1>Connexion à l'administration</h1>
<?php if ($flash !== null): ?>
<p role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/admin/connexion">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="retour" value="<?= e($retour) ?>">
<p><label for="email">Adresse e-mail</label><br>
<input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></p>
<p><label for="password">Mot de passe</label><br>
<input type="password" id="password" name="password" autocomplete="current-password" required></p>
<p><button type="submit">Se connecter</button></p>
</form>
<p><a href="/admin/mot-de-passe-oublie">Mot de passe oublié ?</a></p>
</main>
