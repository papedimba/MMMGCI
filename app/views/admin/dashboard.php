<?php declare(strict_types=1); ?>
<h1>Bonjour <?= e($user['name']) ?></h1>
<ul>
<?php if ($can('users.manage')): ?><li><a href="/admin/utilisateurs">Gérer les utilisateurs</a></li>
<?php endif; ?>
<?php if ($can('roles.manage')): ?><li><a href="/admin/roles">Gérer les rôles</a></li>
<?php endif; ?>
<?php if ($can('audit.view')): ?><li><a href="/admin/journal">Consulter le journal d'audit</a></li>
<?php endif; ?>
</ul>
