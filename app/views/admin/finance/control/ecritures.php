<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var array $f @var array $accounts @var array $sources @var array $query */
use App\Core\Money;
$tab = 'ecritures'; $heading = 'Contrôle des écritures'; $subtitle = 'Toutes les écritures du grand livre, tous comptes.'; $dataset = 'ecritures';
include __DIR__ . '/_head.php';
$columns = [
    ['reference', 'Écriture', 'left', false, static fn (array $t): string => '<strong><code>' . e($t['reference']) . '</code></strong><br><span class="field-hint">' . e($t['account_name']) . '</span>'],
    ['direction', 'Sens', 'left', false, static fn (array $t): string => ui('badge', ['label' => $t['direction'] === 'recette' ? 'Entrée' : 'Sortie', 'kind' => $t['direction'] === 'recette' ? 'ok' : 'info']), 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $t): string => '<span class="nowrap">' . ($t['direction'] === 'recette' ? '+ ' : '− ') . e(Money::format(Money::fromDecimal((string) $t['amount']))) . '</span>'],
    ['occurred_at', 'Date', 'left', false, static fn (array $t): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime((string) $t['occurred_at']))) . '</span>'],
    ['category_name', 'Catégorie', 'left', false, static function (array $t): string {
        $h = e($t['category_name']);
        foreach ($t['files'] ?? [] as $f) {
            $h .= '<br><a class="cell-link" href="/admin/finances/ecritures/' . e((string) $t['id']) . '/justificatif/' . e((string) $f['file_id']) . '">Justificatif : ' . e($f['original_name']) . '</a>';
        }
        return $h;
    }],
    ['source_type', 'Origine', 'left', false, static fn (array $t): string => e($sources[$t['source_type']] ?? (string) $t['source_type'])],
    ['description', 'Contre-passation', 'left', false, static fn (array $t): string => ($t['reversal_of_id'] !== null ? ui('badge', ['label' => 'Contre-passation de ' . $t['reversal_of_reference'], 'kind' => 'warn']) : '')
        . ($t['reversed_by_id'] !== null ? ui('badge', ['label' => 'Contre-passée par ' . $t['reversed_by_reference'], 'kind' => 'neutral']) : '')],
];
?>
<form class="filter-row" method="get" action="/admin/controle" role="search">
<?= ui('field', ['name' => 'du', 'label' => 'Du', 'type' => 'date', 'value' => $f['du'] ?? '']) ?>
<?= ui('field', ['name' => 'au', 'label' => 'Au', 'type' => 'date', 'value' => $f['au'] ?? '']) ?>
<?= ui('field', ['name' => 'compte', 'label' => 'Compte', 'type' => 'select', 'options' => $accounts, 'value' => $f['compte'] ?? '']) ?>
<?= ui('field', ['name' => 'sens', 'label' => 'Sens', 'type' => 'select', 'options' => ['' => 'Tous', 'recette' => 'Entrées', 'depense' => 'Sorties'], 'value' => $f['sens'] ?? '']) ?>
<?= ui('field', ['name' => 'source', 'label' => 'Origine', 'type' => 'select', 'options' => ['' => 'Toutes'] + $sources, 'value' => $f['source'] ?? '']) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> écriture<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Écritures du grand livre', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucune écriture ne correspond']]) ?>
<?= ui('pagination', ['base' => '/admin/controle', 'page' => $page, 'pages' => $pages, 'query' => $query]) ?>
