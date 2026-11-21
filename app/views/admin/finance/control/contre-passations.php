<?php declare(strict_types=1);
/** @var array $rows */
use App\Core\Money;
$tab = 'contre-passations'; $heading = 'Contre-passations'; $subtitle = 'Chaque correction est une écriture de sens opposé liée à l\'écriture d\'origine.'; $dataset = null; $query = [];
include __DIR__ . '/_head.php';
$columns = [
    ['reversal_reference', 'Contre-passation', 'left', false, static fn (array $t): string => '<strong><code>' . e($t['reversal_reference']) . '</code></strong><br><span class="field-hint">' . e($t['account_name']) . '</span>'],
    ['original_reference', 'Écriture d\'origine', 'left', false, static fn (array $t): string => $t['original_id'] === null ? ui('badge', ['label' => 'Origine absente', 'kind' => 'danger']) : '<code>' . e($t['original_reference']) . '</code>', 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $t): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal((string) $t['amount']))) . '</span>'],
    ['reversal_at', 'Date', 'left', false, static fn (array $t): string => '<span class="nowrap">' . e(date_fr($t['reversal_at'])) . '</span>'],
    ['recorded_by_name', 'Par', 'left', false, static fn (array $t): string => e((string) $t['recorded_by_name'])],
    ['description', 'Motif', 'left', false, static fn (array $t): string => e((string) $t['description'])],
];
?>
<?= ui('table', ['caption' => 'Paires écriture / contre-passation', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucune contre-passation']]) ?>
