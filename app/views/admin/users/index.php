<?php declare(strict_types=1); ?>
<h1>Utilisateurs</h1>
<p><a href="/admin/utilisateurs/nouveau">Créer un utilisateur</a></p>
<form method="get" action="/admin/utilisateurs" role="search">
<p><label for="q">Rechercher par nom ou adresse e-mail</label><br>
<input type="search" id="q" name="q" value="<?= e($q) ?>">
<button type="submit">Rechercher</button></p>
</form>
<?php if ($rows === []): ?>
<p>Aucun utilisateur ne correspond à cette recherche.</p>
<?php else: ?>
<p><?= e((string) $total) ?> utilisateur(s).</p>
<table>
<thead><tr><th scope="col">Nom</th><th scope="col">Adresse e-mail</th><th scope="col">Rôles</th><th scope="col">État</th><th scope="col">Dernière connexion</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr data-user="<?= e((string) $row['id']) ?>">
<td><a href="/admin/utilisateurs/<?= e((string) $row['id']) ?>"><?= e($row['name']) ?></a></td>
<td><?= e($row['email']) ?></td>
<td><?= e($row['roles'] ?? '—') ?></td>
<td><?= (int) $row['is_active'] === 1 ? 'Actif' : 'Désactivé' ?></td>
<td><?= e($row['last_login_at'] ?? 'Jamais') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<?php if ($pages > 1): ?>
<nav aria-label="Pagination">
<p>Page <?= e((string) $page) ?> sur <?= e((string) $pages) ?>
<?php if ($page > 1): ?> · <a href="/admin/utilisateurs?<?= e(http_build_query(['q' => $q, 'page' => $page - 1])) ?>">Page précédente</a><?php endif; ?>
<?php if ($page < $pages): ?> · <a href="/admin/utilisateurs?<?= e(http_build_query(['q' => $q, 'page' => $page + 1])) ?>">Page suivante</a><?php endif; ?>
</p>
</nav>
<?php endif; ?>
