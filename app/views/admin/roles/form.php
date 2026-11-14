<?php declare(strict_types=1);
$system = $target !== null && (int) $target['is_system'] === 1;
$double = ['disbursement.approve', 'disbursement.pay'];
?>
<p><a href="/admin/roles">Retour à la liste des rôles</a></p>
<h1><?= e($target === null ? 'Nouveau rôle' : $target['label']) ?></h1>
<?php if ($error !== null): ?>
<p role="alert"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($system): ?>
<p>Ce rôle système ne peut pas être modifié.</p>
<ul>
<?php foreach ($modules as $permissions): foreach ($permissions as $p): if (in_array($p['name'], $values['permissions'], true)): ?>
<li><?= e($p['label']) ?></li>
<?php endif; endforeach; endforeach; ?>
</ul>
<?php else: ?>
<form method="post" action="<?= e($target === null ? '/admin/roles/nouveau' : '/admin/roles/' . $target['id']) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if ($target === null): ?>
<p><label for="name">Identifiant (obligatoire, définitif : minuscules, chiffres et _, ex. gest_tresorerie)</label><br>
<input type="text" id="name" name="name" value="<?= e($values['name']) ?>" maxlength="60" pattern="[a-z][a-z0-9_]{2,59}" required></p>
<?php endif; ?>
<p><label for="label">Libellé (obligatoire)</label><br>
<input type="text" id="label" name="label" value="<?= e($values['label']) ?>" maxlength="120" required></p>
<p><label for="description">Description (facultative)</label><br>
<textarea id="description" name="description" maxlength="1000"><?= e($values['description']) ?></textarea></p>
<?php if ($target === null): ?>
<p>Le rôle est créé sans permission : vous les cocherez à l'étape suivante.</p>
<?php else: ?>
<input type="hidden" name="permissions_field" value="1">
<h2>Permissions</h2>
<p id="own-only">Vous ne pouvez attribuer que les droits que vous détenez.</p>
<?php foreach ($modules as $module => $permissions): ?>
<fieldset>
<legend><?= e($module) ?></legend>
<?php foreach ($permissions as $p): $off = $locked($p["name"]); ?>
<p><label><input type="checkbox" name="permissions[]" value="<?= e($p['name']) ?>"<?= $off ? ' disabled aria-describedby="own-only"' : '' ?><?= in_array($p['name'], $values['permissions'], true) ? ' checked' : '' ?>> <?= e($p['label']) ?></label>
<?php if (in_array($p['name'], $double, true)): ?> — Réservé aux personnes désignées par les instances (double validation).<?php endif; ?></p>
<?php endforeach; ?>
</fieldset>
<?php endforeach; ?>
<?php endif; ?>
<p><button type="submit"><?= $target === null ? 'Créer le rôle' : 'Enregistrer' ?></button></p>
</form>
<?php if ($target !== null): ?>
<h2>Supprimer le rôle</h2>
<p>Possible seulement si aucun compte ne porte ce rôle.</p>
<form method="post" action="/admin/roles/<?= e((string) $target['id']) ?>/suppression">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit">Supprimer ce rôle</button>
</form>
<?php endif; ?>
<?php endif; ?>
