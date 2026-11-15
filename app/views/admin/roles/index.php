<?php declare(strict_types=1);
$columns = [
    ['label', 'Libellé', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/roles/' . e((string) $r['id']) . '"><strong>' . e($r['label']) . '</strong></a>'],
    ['name', 'Identifiant', 'left', false, static fn (array $r): string => '<code>' . e($r['name']) . '</code>'],
    ['is_system', 'Type', 'left', false, static fn (array $r): string => (int) $r['is_system'] === 1 ? ui('badge', ['label' => 'Rôle système, non modifiable', 'kind' => 'info']) : '', 'status'],
    ['permissions', 'Permissions', 'right', true],
    ['users', 'Utilisateurs', 'right', true],
];
?>
<?= ui('page-header', ['title' => 'Rôles', 'subtitle' => 'Les rôles regroupent les permissions attribuées au personnel.'], ui('button', ['label' => 'Nouveau rôle', 'icon' => 'plus', 'href' => '/admin/roles/nouveau'])) ?>
<?= ui('table', ['caption' => 'Liste des rôles', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucun rôle']]) ?>
