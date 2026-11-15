<?php declare(strict_types=1);
/** @var string $autoBanner @var array $rows @var int $total @var int $page @var int $pages @var array $query @var array $statuses @var array $statusOptions @var array $typeOptions @var array $periodOptions @var array $values @var Closure $can */
use App\Core\Money;
$manage = $can('contributions.manage');
$kinds = ['appelee' => 'info', 'partielle' => 'warn', 'payee' => 'ok', 'annulee' => 'neutral'];
$columns = [
    ['name', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/' . e((string) $r['member_id']) . '?onglet=cotisations"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong><span>' . e($r['member_number']) . '</span></a>'],
    ['type_name', 'Type', 'left', false, static fn (array $r): string => e($r['type_name'])],
    ['period_label', 'Période', 'left', false, static fn (array $r): string => e($r['period_label'])],
    ['amount_due', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['amount_due']))) . '</span>'],
    ['due', 'Échéance', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($r['due_date']))) . '</span>'],
    ['rest', 'Reste dû', 'right', true, static fn (array $r): string => $r['status'] === 'annulee' ? '—' : '<span class="nowrap">' . e(Money::format(max(0, Money::fromDecimal($r['amount_due']) - Money::fromDecimal((string) $r['paid'])))) . '</span>'],
    ['status', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => $statuses[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
];
$rowActions = $manage ? static fn (array $r): string => $r['status'] === 'annulee' ? '' : ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'size' => 'sm', 'href' => '/admin/cotisations/appels/' . $r['id'] . '/annuler']) : null;
$actions = $manage ? ui('button', ['label' => 'Appel individuel', 'icon' => 'plus', 'href' => '/admin/cotisations/appels/individuel']) . ui('button', ['label' => 'Générer un lot', 'variant' => 'secondary', 'href' => '/admin/cotisations/appels/lot']) : '';
?>
<?= ui('page-header', ['title' => 'Cotisations', 'subtitle' => 'Appels de cotisation. Le montant est celui du type, jamais saisi.'], $actions) ?>
<?php $current = 'appels'; require __DIR__ . '/_tabs.php'; ?>
<?= ui('alert', ['kind' => 'info', 'message' => $autoBanner]) ?>
<form class="filter-row" method="get" action="/admin/cotisations/appels" role="search">
<?= ui('field', ['name' => 'statut', 'label' => 'Statut', 'type' => 'select', 'options' => $statusOptions, 'value' => $values['statut']]) ?>
<?= ui('field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $typeOptions, 'value' => $values['type']]) ?>
<?= ui('field', ['name' => 'periode', 'label' => 'Période', 'type' => 'select', 'options' => $periodOptions, 'value' => $values['periode']]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> appel<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Appels de cotisation', 'columns' => $columns, 'rows' => $rows, 'row_actions' => $rowActions,
    'empty' => $query !== [] ? ['title' => 'Aucun appel ne correspond à ces filtres.'] : ['title' => 'Aucun appel pour le moment', 'message' => 'Les appels apparaissent ici une fois un type actif avec un montant validé.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/cotisations/appels', 'query' => $query]) ?>
