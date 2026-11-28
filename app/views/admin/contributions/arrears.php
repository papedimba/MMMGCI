<?php declare(strict_types=1);
/** @var array $res @var array $query @var array $rule @var array $values @var array $zones @var array $types @var Closure $can */
use App\Core\Money;
$columns = [
    ['name', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/' . e((string) $r['member_id']) . '?onglet=cotisations"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong><span>' . e($r['member_number']) . '</span></a>'],
    ['type_name', 'Type', 'left', false, static fn (array $r): string => e($r['type_name'])],
    ['period_label', 'Période', 'left', false, static fn (array $r): string => e($r['period_label'])],
    ['due', 'Échéance', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($r['due_date']))) . '</span>'],
    ['days_late', 'Retard', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e((string) $r['days_late']) . ' j</span>'],
    ['rest', 'Reste dû', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal((string) $r['rest']))) . '</span>'],
];
$total = $res['total'];
$qs = $query === [] ? '' : '?' . http_build_query($query);
?>
<?= ui('page-header', ['title' => 'Impayés de cotisation', 'subtitle' => 'Appels échus restant à payer.']) ?>
<?php $current = 'impayes'; require __DIR__ . '/_tabs.php'; ?>
<?php if ($rule['state'] === 'pending'): ?><?= ui('alert', ['kind' => 'info', 'message' => 'La règle « membre à jour » est en attente de validation par les instances : seuls le nombre d\'impayés et les montants sont affichés.']) ?>
<?php elseif ($rule['state'] === 'invalid'): ?><?= ui('alert', ['kind' => 'warn', 'message' => 'La règle « membre à jour » est mal configurée (un nombre entier positif ou nul est attendu) : elle n\'est pas appliquée.']) ?>
<?php endif; ?>
<details class="filters" open data-collapse-narrow="<?= $query === [] ? '1' : '0' ?>">
<summary>Filtres</summary>
<form class="filter-row" method="get" action="/admin/cotisations/impayes" role="search">
<?= ui('field', ['name' => 'q', 'label' => 'Adhérent (nom ou numéro)', 'type' => 'search', 'value' => $values['q']]) ?>
<?= ui('field', ['name' => 'zone_id', 'label' => 'Zone', 'type' => 'select', 'options' => $zones, 'value' => $values['zone_id']]) ?>
<?= ui('field', ['name' => 'type_id', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => $values['type_id']]) ?>
<?= ui('field', ['name' => 'min_age_days', 'label' => 'Retard d\'au moins (jours)', 'type' => 'number', 'value' => $values['min_age_days']]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
</details>
<?php if ($can('contributions.export')): ?>
<p class="export-links">
<?= ui('button', ['label' => 'Exporter en CSV', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'href' => '/admin/cotisations/impayes/export.csv' . $qs]) ?>
<?= ui('button', ['label' => 'Exporter en Excel', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'download', 'href' => '/admin/cotisations/impayes/export.xlsx' . $qs]) ?>
</p>
<?php endif; ?>
<p class="count-line"><?= e((string) $total) ?> appel<?= $total > 1 ? 's' : '' ?> impayé<?= $total > 1 ? 's' : '' ?> échu<?= $total > 1 ? 's' : '' ?>, reste dû total : <strong><?= e(Money::format($res['sum'])) ?></strong>.</p>
<?= ui('table', ['caption' => 'Appels échus impayés', 'columns' => $columns, 'rows' => $res['rows'],
    'empty' => $query !== [] ? ['title' => 'Aucun impayé ne correspond à ces filtres.'] : ['title' => 'Aucun impayé échu', 'message' => 'Aucun appel échu ne reste à payer dans votre périmètre.']]) ?>
<?= ui('pagination', ['page' => $res['page'], 'pages' => $res['pages'], 'base' => '/admin/cotisations/impayes', 'query' => $query]) ?>
