<?php declare(strict_types=1); ?>
<main>
<h1>Mot de passe oublié</h1>
<?php if ($sent): ?>
<p role="status"><?= e($message) ?></p>
<p><a href="/admin/connexion">Retour à la connexion</a></p>
<?php else: ?>
<p>Saisissez l'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
<form method="post" action="/admin/mot-de-passe-oublie">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<p><label for="email">Adresse e-mail</label><br>
<input type="email" id="email" name="email" autocomplete="username" required autofocus></p>
<p><button type="submit">Recevoir le lien</button></p>
</form>
<p><a href="/admin/connexion">Retour à la connexion</a></p>
<?php endif; ?>
</main>
