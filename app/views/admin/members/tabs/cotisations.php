<?php declare(strict_types=1);
/** @var array $m @var array $calls @var array $payments @var Closure $can */
use App\Core\Money;
$statuses = ['appelee' => 'Appelée', 'partielle' => 'Partiellement payée', 'payee' => 'Payée', 'annulee' => 'Annulée'];
$kinds = ['appelee' => 'info', 'partielle' => 'warn', 'payee' => 'ok', 'annulee' => 'neutral'];
$columns = [
    ['type_name', 'Cotisation', 'left'],
    ['period_label', 'Période', 'left'],
    ['due', 'Échéance', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($r['due_date']))) . '</span>'],
    ['amount_due', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['amount_due']))) . '</span>'],
    ['rest', 'Reste dû', 'right', true, static fn (array $r): string => $r['status'] === 'annulee' ? '—' : '<span class="nowrap">' . e(Money::format(max(0, Money::fromDecimal($r['amount_due']) - Money::fromDecimal((string) $r['paid'])))) . '</span>'],
    ['status', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => $statuses[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
];
?>
<?php if ($can('contributions.manage')): ?><p><?= ui('button', ['label' => 'Nouvel appel', 'icon' => 'plus', 'size' => 'sm', 'href' => '/admin/cotisations/appels/individuel?adherent=' . $m['id']]) ?></p><?php endif; ?>
<?= ui('table', ['caption' => 'Appels de cotisation', 'columns' => $columns, 'rows' => $calls,
    'empty' => ['title' => 'Aucun appel de cotisation', 'message' => 'Aucune cotisation n\'a été appelée pour cet adhérent.']]) ?>
<?= ui('table', ['caption' => 'Paiements', 'columns' => [
        ['reference', 'Référence', 'left'],
        ['paid_at', 'Date', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($r['paid_at']))) . '</span>'],
        ['amount', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['amount']))) . '</span>'],
        ['cancelled_at', 'État', 'left', false, static fn (array $r): string => $r['cancelled_at'] === null ? ui('badge', ['label' => 'Valide', 'kind' => 'ok']) : ui('badge', ['label' => 'Annulé', 'kind' => 'neutral']), 'status'],
    ], 'rows' => $payments, 'empty' => ['title' => 'Aucun paiement enregistré']]) ?>
