<?php declare(strict_types=1);
/** @var array $rows */
use App\Core\Money;
$tab = 'annulations'; $heading = 'Paiements et reçus annulés'; $subtitle = 'Annulations de paiements de cotisation, avec leur reçu et leur contre-passation.'; $dataset = null; $query = [];
include __DIR__ . '/_head.php';
$columns = [
    ['reference', 'Paiement', 'left', false, static fn (array $p): string => '<strong><code>' . e($p['reference']) . '</code></strong><br><span class="field-hint">' . e($p['last_name'] . ' ' . $p['first_names']) . ' · ' . e($p['member_number']) . '</span>'],
    ['cancelled_at', 'Annulé le', 'left', false, static fn (array $p): string => $p['cancelled_at'] === null ? ui('badge', ['label' => 'Paiement non annulé, reçu annulé', 'kind' => 'danger']) : '<span class="nowrap">' . e(date_fr($p['cancelled_at'])) . '</span>', 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $p): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal((string) $p['amount']))) . '</span>'],
    ['receipt_number', 'Reçu', 'left', false, static fn (array $p): string => e((string) ($p['receipt_number'] ?? '—')) . ($p['receipt_cancelled_at'] !== null ? ' (annulé)' : '')],
    ['reversal_reference', 'Contre-passation', 'left', false, static fn (array $p): string => e((string) ($p['reversal_reference'] ?? '—'))],
    ['cancel_reason', 'Motif', 'left', false, static fn (array $p): string => e((string) $p['cancel_reason'])],
];
?>
<?= ui('table', ['caption' => 'Paiements et reçus annulés', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucune annulation']]) ?>
