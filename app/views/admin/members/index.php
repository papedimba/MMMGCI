<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var array $criteria @var string $sort @var array $sorts @var array $query @var array $statuses @var array $categories @var array $zones @var array $professions @var array $statusLabels @var Closure $can */
$fmt = static fn (?string $d): string => $d === null || $d === '' ? '—' : date('d/m/Y', strtotime($d));
$kinds = ['actif' => 'ok', 'suspendu' => 'warn', 'radie' => 'danger', 'decede' => 'neutral', 'ancien_marin' => 'info'];
$columns = [
    ['name', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/' . e((string) $r['id']) . '"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong><span>' . e($r['member_number']) . '</span></a>'],
    ['status', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => $statusLabels[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
    ['category_name', 'Catégorie', 'left', false, static fn (array $r): string => e($r['category_name'] ?? '—')],
    ['zone_name', 'Zone', 'left', false, static fn (array $r): string => e($r['zone_name'] ?? '—')],
    ['joined_at', 'Adhésion', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e($fmt($r['joined_at'])) . '</span>'],
];
$filtered = $criteria !== [];
$create = $can('members.create') ? ['label' => 'Nouvel adhérent', 'icon' => 'user-plus', 'href' => '/admin/adherents/nouveau'] : null;
?>
<?= ui('page-header', ['title' => 'Adhérents', 'subtitle' => 'Fiches des adhérents de la Mutuelle.'], $create ? ui('button', $create) : '') ?>
<form class="toolbar" method="get" action="/admin/adherents" role="search">
<?= ui('field', ['name' => 'q', 'label' => 'Rechercher par nom, prénom, numéro ou téléphone', 'type' => 'search', 'value' => $criteria['q'] ?? '']) ?>
<?= ui('button', ['label' => 'Rechercher', 'variant' => 'secondary', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<details class="filters" open data-collapse-narrow="<?= array_diff_key($criteria, ['q' => 1]) === [] ? '1' : '0' ?>">
<summary>Filtres</summary>
<form class="filter-row" method="get" action="/admin/adherents" role="search">
<?php if (isset($criteria['q'])): ?><input type="hidden" name="q" value="<?= e($criteria['q']) ?>"><?php endif; ?>
<?= ui('field', ['name' => 'status', 'label' => 'Statut', 'type' => 'select', 'options' => $statuses, 'value' => $criteria['status'] ?? '']) ?>
<?= ui('field', ['name' => 'category_id', 'label' => 'Catégorie', 'type' => 'select', 'options' => $categories, 'value' => $criteria['category_id'] ?? '']) ?>
<?= ui('field', ['name' => 'zone_id', 'label' => 'Zone', 'type' => 'select', 'options' => $zones, 'value' => $criteria['zone_id'] ?? '']) ?>
<?= ui('field', ['name' => 'profession_id', 'label' => 'Profession', 'type' => 'select', 'options' => $professions, 'value' => $criteria['profession_id'] ?? '']) ?>
<?= ui('field', ['name' => 'tri', 'label' => 'Trier par', 'type' => 'select', 'options' => $sorts, 'value' => $sort]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
</details>
<p class="count-line"><?= e((string) $total) ?> adhérent<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Liste des adhérents', 'columns' => $columns, 'rows' => $rows,
    'empty' => $filtered
        ? ['title' => 'Aucun adhérent ne correspond à cette recherche.', 'message' => 'Vérifiez l\'orthographe ou effacez les filtres.']
        : ['title' => 'Aucun adhérent pour le moment', 'message' => 'Les fiches créées apparaîtront ici.'] + ($create ? ['action' => ['label' => 'Nouvel adhérent', 'icon' => 'user-plus', 'href' => '/admin/adherents/nouveau']] : [])]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/adherents', 'query' => $query]) ?>
