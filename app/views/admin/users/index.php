<?php declare(strict_types=1);
$roleBadges = static function (array $r): string {
    $out = '';
    foreach (array_filter(explode(', ', (string) ($r['roles'] ?? ''))) as $label) {
        $out .= ui('badge', ['label' => $label, 'kind' => 'neutral']);
    }
    return $out === '' ? '<span class="muted">Aucun rôle</span>' : '<span class="roles">' . $out . '</span>';
};
$columns = [
    ['name', 'Nom', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/utilisateurs/' . e((string) $r['id']) . '"><strong>' . e($r['name']) . '</strong><span>' . e($r['email']) . '</span></a>'],
    ['roles', 'Rôles', 'left', false, $roleBadges],
    ['is_active', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => (int) $r['is_active'] === 1 ? 'Actif' : 'Désactivé', 'kind' => (int) $r['is_active'] === 1 ? 'ok' : 'neutral']), 'status'],
    ['last_login_at', 'Dernière connexion', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['last_login_at'], 'Jamais')) . '</span>'],
];
?>
<?= ui('page-header', ['title' => 'Utilisateurs', 'subtitle' => 'Comptes du personnel de la Mutuelle et leurs rôles.'], ui('button', ['label' => 'Nouvel utilisateur', 'icon' => 'user-plus', 'href' => '/admin/utilisateurs/nouveau'])) ?>
<form class="toolbar" method="get" action="/admin/utilisateurs" role="search">
<?= ui('field', ['name' => 'q', 'label' => 'Rechercher par nom ou adresse e-mail', 'type' => 'search', 'value' => $q]) ?>
<?= ui('button', ['label' => 'Rechercher', 'variant' => 'secondary', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<?php if ($rows !== []): ?><p class="count-line"><?= e((string) $total) ?> utilisateur(s).</p><?php endif; ?>
<?= ui('table', ['caption' => 'Liste des utilisateurs', 'columns' => $columns, 'rows' => $rows,
    'empty' => ['title' => 'Aucun utilisateur ne correspond à cette recherche.', 'message' => 'Vérifiez l\'orthographe ou effacez la recherche.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/utilisateurs', 'query' => ['q' => $q]]) ?>
