<?php declare(strict_types=1); ?>
<h1><?= e($title) ?></h1>
<nav aria-label="Onglets du journal">
<ul>
<li><a href="/admin/journal"<?= $tab === 'journal' ? ' aria-current="page"' : '' ?>>Journal d'audit</a></li>
<li><a href="/admin/journal?onglet=sensibles"<?= $tab === 'sensibles' ? ' aria-current="page"' : '' ?>>Consultations sensibles</a></li>
</ul>
</nav>
<?php if ($tab === 'journal'): ?>
<form method="post" action="/admin/journal/verification">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit">Vérifier l'intégrité du journal</button>
</form>
<?php endif; ?>
<form method="get" action="/admin/journal" role="search">
<?php if ($tab === 'sensibles'): ?><input type="hidden" name="onglet" value="sensibles"><?php endif; ?>
<p><label for="du">Du (AAAA-MM-JJ)</label><br>
<input type="date" id="du" name="du" value="<?= e($filters['du'] ?? '') ?>"<?= isset($errors['du']) ? ' aria-invalid="true" aria-describedby="du-erreur"' : '' ?>>
<?php if (isset($errors['du'])): ?><br><span id="du-erreur">Du : <?= e($errors['du']) ?></span><?php endif; ?></p>
<p><label for="au">Au (AAAA-MM-JJ)</label><br>
<input type="date" id="au" name="au" value="<?= e($filters['au'] ?? '') ?>"<?= isset($errors['au']) ? ' aria-invalid="true" aria-describedby="au-erreur"' : '' ?>>
<?php if (isset($errors['au'])): ?><br><span id="au-erreur">Au : <?= e($errors['au']) ?></span><?php endif; ?></p>
<p><label for="utilisateur">Utilisateur</label><br>
<select id="utilisateur" name="utilisateur"<?= isset($errors['utilisateur']) ? ' aria-invalid="true" aria-describedby="utilisateur-erreur"' : '' ?>>
<option value="">Tous</option>
<?php foreach ($users as $u): ?>
<option value="<?= e((string) $u['id']) ?>"<?= ($filters['utilisateur'] ?? '') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
<?php endforeach; ?>
</select>
<?php if (isset($errors['utilisateur'])): ?><br><span id="utilisateur-erreur"><?= e($errors['utilisateur']) ?></span><?php endif; ?></p>
<?php if ($tab === 'journal'): ?>
<p><label for="action">Action commençant par (ex. auth.)</label><br>
<input type="text" id="action" name="action" value="<?= e($filters['action'] ?? '') ?>"<?= isset($errors['action']) ? ' aria-invalid="true" aria-describedby="action-erreur"' : '' ?>>
<?php if (isset($errors['action'])): ?><br><span id="action-erreur"><?= e($errors['action']) ?></span><?php endif; ?></p>
<?php endif; ?>
<p><button type="submit">Filtrer</button></p>
</form>
<?php if ($errors !== []): ?>
<p role="alert">Filtres invalides : corrigez les champs signalés puis relancez la recherche.</p>
<?php elseif ($rows === []): ?>
<p>Aucune entrée ne correspond à ces filtres.</p>
<?php else: ?>
<p><?= e((string) $total) ?> entrée(s).</p>
<table>
<?php if ($tab === 'journal'): ?>
<thead><tr><th scope="col">N°</th><th scope="col">Date</th><th scope="col">Utilisateur</th><th scope="col">Action</th><th scope="col">Objet</th><th scope="col">Référence</th><th scope="col">IP</th><th scope="col">Valeurs</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= e((string) $row['id']) ?></td>
<td><?= e($when($row['created_at'])) ?></td>
<td><?= e($row['user_id'] === null ? 'système' : ($row['user_name'] ?? 'utilisateur n° ' . $row['user_id'])) ?></td>
<td><?= e($row['action']) ?></td>
<td><?= e(trim(($row['auditable_type'] ?? '') . ($row['auditable_id'] === null ? '' : ' n° ' . $row['auditable_id'])) ?: '—') ?></td>
<td><?= e($row['reference'] ?? '—') ?></td>
<td><?= e($row['ip'] ?? '—') ?></td>
<td><?php if ($row['old_values'] !== null || $row['new_values'] !== null): ?><details><summary>Afficher</summary>
<?php if ($row['old_values'] !== null): ?><p>Avant :</p><pre><?= e($pretty($row['old_values'])) ?></pre><?php endif; ?>
<?php if ($row['new_values'] !== null): ?><p>Après :</p><pre><?= e($pretty($row['new_values'])) ?></pre><?php endif; ?>
</details><?php else: ?>—<?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<?php else: ?>
<thead><tr><th scope="col">Date</th><th scope="col">Utilisateur</th><th scope="col">Objet consulté</th><th scope="col">Données</th><th scope="col">IP</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= e($when($row['created_at'])) ?></td>
<td><?= e($row['user_name'] ?? 'utilisateur n° ' . $row['user_id']) ?></td>
<td><?= e($row['subject_type'] . ' n° ' . $row['subject_id']) ?></td>
<td><?= e($row['field_group']) ?></td>
<td><?= e($row['ip'] ?? '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<?php endif; ?>
</table>
<?php endif; ?>
<?php if ($pages > 1): ?>
<nav aria-label="Pagination">
<p>Page <?= e((string) $page) ?> sur <?= e((string) $pages) ?>
<?php if ($page > 1): ?> · <a href="/admin/journal?<?= e(http_build_query($filters + ($tab === 'sensibles' ? ['onglet' => 'sensibles'] : []) + ['page' => $page - 1])) ?>">Page précédente</a><?php endif; ?>
<?php if ($page < $pages): ?> · <a href="/admin/journal?<?= e(http_build_query($filters + ($tab === 'sensibles' ? ['onglet' => 'sensibles'] : []) + ['page' => $page + 1])) ?>">Page suivante</a><?php endif; ?>
</p>
</nav>
<?php endif; ?>
