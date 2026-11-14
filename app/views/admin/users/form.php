<?php declare(strict_types=1); ?>
<p><a href="/admin/utilisateurs">Retour à la liste des utilisateurs</a></p>
<h1><?= e($target === null ? 'Nouvel utilisateur' : $target['name']) ?></h1>
<?php if ($error !== null): ?>
<p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($target === null): ?>
<p>Le nouvel utilisateur recevra par e-mail un lien valable 72 heures pour choisir son mot de passe.</p>
<?php endif; ?>
<form method="post" action="<?= e($target === null ? '/admin/utilisateurs/nouveau' : '/admin/utilisateurs/' . $target['id']) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<p><label for="name">Nom (obligatoire)</label><br>
<input type="text" id="name" name="name" value="<?= e($values['name']) ?>" maxlength="120" required></p>
<p><label for="email">Adresse e-mail (obligatoire)</label><br>
<input type="email" id="email" name="email" value="<?= e($values['email']) ?>" maxlength="190" required></p>
<p><label for="phone">Téléphone (facultatif, 10 chiffres)</label><br>
<input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>"></p>
<fieldset>
<legend>Rôles</legend>
<?php if ($self): ?>
<p>Vous ne pouvez pas modifier vos propres rôles : demandez-le à un autre administrateur.</p>
<?php else: ?>
<input type="hidden" name="roles_field" value="1">
<?php endif; ?>
<?php foreach ($roles as $role): ?>
<p><label><input type="checkbox" name="roles[]" value="<?= e($role['name']) ?>"<?= in_array($role['name'], $values['roles'], true) ? ' checked' : '' ?><?= $self ? ' disabled' : '' ?>> <?= e($role['label']) ?></label></p>
<?php endforeach; ?>
</fieldset>
<p><button type="submit"><?= $target === null ? 'Créer et envoyer l\'invitation' : 'Enregistrer' ?></button></p>
</form>
<?php if ($target !== null): ?>
<h2>État du compte</h2>
<p>Compte <?= (int) $target['is_active'] === 1 ? 'actif' : 'désactivé' ?> · dernière connexion : <?= e($target['last_login_at'] ?? 'jamais') ?></p>
<?php if (!$self): ?>
<form method="post" action="/admin/utilisateurs/<?= e((string) $target['id']) ?>/activation">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="active" value="<?= (int) $target['is_active'] === 1 ? '0' : '1' ?>">
<button type="submit"><?= (int) $target['is_active'] === 1 ? 'Désactiver le compte (ferme ses sessions)' : 'Réactiver le compte' ?></button>
</form>
<?php endif; ?>
<?php endif; ?>
