<?php declare(strict_types=1); ?>
<h1>Rôles</h1>
<p><a href="/admin/roles/nouveau">Créer un rôle</a></p>
<table>
<thead><tr><th scope="col">Libellé</th><th scope="col">Identifiant</th><th scope="col">Permissions</th><th scope="col">Utilisateurs</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr data-role="<?= e((string) $row['id']) ?>">
<td><a href="/admin/roles/<?= e((string) $row['id']) ?>"><?= e($row['label']) ?></a><?= (int) $row['is_system'] === 1 ? ' (rôle système, non modifiable)' : '' ?></td>
<td><?= e($row['name']) ?></td>
<td><?= e((string) $row['permissions']) ?></td>
<td><?= e((string) $row['users']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
