<?php declare(strict_types=1);
/** @var array $acc @var array $rows @var array $files @var int $total @var int $page @var int $pages @var array $query @var string $from @var string $to @var int $cat @var array $categoryOptions @var Closure $can */
use App\Core\Money;
$base = '/admin/finances/comptes/' . $acc['id'];
$manage = $can('finance.manage');
$columns = [
    ['occurred_at', 'Date', 'left', false, static fn (array $t): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($t['occurred_at']))) . '</span>'],
    ['reference', 'Référence', 'left', false, static fn (array $t): string => '<code>' . e($t['reference']) . '</code>', 'status'],
    ['category_name', 'Catégorie', 'left', false, static fn (array $t): string => e($t['category_name'])],
    ['description', 'Détail', 'left', false, static function (array $t) use ($files): string {
        $h = e((string) $t['description']);
        $h .= $t['authorization_ref'] !== null ? '<br><span class="field-hint">Autorisation : ' . e($t['authorization_ref']) . '</span>' : '';
        $h .= $t['reversal_of_id'] !== null ? '<br>' . ui('badge', ['label' => 'Contre-passation de ' . $t['reversal_of_reference'], 'kind' => 'warn']) : '';
        $h .= (int) $t['reversed'] > 0 ? '<br>' . ui('badge', ['label' => 'Contre-passée', 'kind' => 'neutral']) : '';
        foreach ($files[(int) $t['id']] ?? [] as $f) {
            $h .= '<br><a href="/admin/finances/ecritures/' . e((string) $t['id']) . '/justificatif/' . e((string) $f['file_id']) . '">Justificatif : ' . e($f['original_name']) . '</a>';
        }
        return $h;
    }],
    ['amount', 'Montant', 'right', true, static fn (array $t): string => '<span class="nowrap">' . ($t['direction'] === 'recette' ? '+ ' : '− ') . e(Money::format(Money::fromDecimal((string) $t['amount']))) . '</span>'],
];
$rowActions = $manage ? static fn (array $t): string => $t['reversal_of_id'] === null && (int) $t['reversed'] === 0 && \App\Modules\Finance\Ledger::isManual($t['source_type'])
    ? ui('button', ['label' => 'Contre-passer', 'variant' => 'secondary', 'size' => 'sm', 'href' => '/admin/finances/ecritures/' . $t['id'] . '/contre-passation']) : '' : null;
?>
<form class="filter-row" method="get" action="<?= e($base) ?>" role="search">
<?= ui('field', ['name' => 'du', 'label' => 'Du', 'type' => 'date', 'value' => $from]) ?>
<?= ui('field', ['name' => 'au', 'label' => 'Au', 'type' => 'date', 'value' => $to]) ?>
<?= ui('field', ['name' => 'categorie', 'label' => 'Catégorie', 'type' => 'select', 'options' => $categoryOptions, 'value' => (string) ($cat ?: '')]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> écriture<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Écritures du compte', 'columns' => $columns, 'rows' => $rows, 'row_actions' => $rowActions, 'empty' => ['title' => 'Aucune écriture']]) ?>
<?= ui('pagination', ['base' => $base, 'page' => $page, 'pages' => $pages, 'query' => $query]) ?>
