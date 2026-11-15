<?php declare(strict_types=1);
/** @var array $m @var array $calls @var array $payments @var ?array $situation @var Closure $can */
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
$situation = $situation ?? null;
?>
<?php if ($situation !== null): ?><p class="count-line"><?= e((string) $situation['unpaid_calls']) ?> appel<?= $situation['unpaid_calls'] > 1 ? 's' : '' ?> échu<?= $situation['unpaid_calls'] > 1 ? 's' : '' ?> impayé<?= $situation['unpaid_calls'] > 1 ? 's' : '' ?>, reste dû : <strong><?= e(Money::format($situation['amount_due'])) ?></strong>.
<?php if (isset($situation['up_to_date'])): ?> Situation : <?= $situation['up_to_date'] ? 'à jour' : 'en retard' ?> (règle validée par les instances).<?php endif; ?>
 <a href="/admin/cotisations/impayes">Voir tous les impayés</a></p><?php endif; ?>
<?php if ($can('contributions.manage')): ?><p><?= ui('button', ['label' => 'Nouvel appel', 'icon' => 'plus', 'size' => 'sm', 'href' => '/admin/cotisations/appels/individuel?adherent=' . $m['id']]) ?></p><?php endif; ?>
<?= ui('table', ['caption' => 'Appels de cotisation', 'columns' => $columns, 'rows' => $calls,
    'row_actions' => $can('contributions.manage') ? static fn (array $r): string => in_array($r['status'], ['appelee', 'partielle'], true) ? ui('button', ['label' => 'Encaisser', 'size' => 'sm', 'href' => '/admin/cotisations/appels/' . $r['id'] . '/encaisser']) : '' : null,
    'empty' => ['title' => 'Aucun appel de cotisation', 'message' => 'Aucune cotisation n\'a été appelée pour cet adhérent.']]) ?>
<?= ui('table', ['caption' => 'Paiements', 'columns' => [
        ['reference', 'Référence', 'left', false, static fn (array $r): string => '<a href="/admin/cotisations/paiements/' . e((string) $r['id']) . '">' . e($r['reference']) . '</a>'],
        ['paid_at', 'Date', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime($r['paid_at']))) . '</span>'],
        ['amount', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['amount']))) . '</span>'],
        ['cancelled_at', 'État', 'left', false, static fn (array $r): string => $r['cancelled_at'] === null ? ui('badge', ['label' => 'Valide', 'kind' => 'ok']) : ui('badge', ['label' => 'Annulé', 'kind' => 'neutral']), 'status'],
    ], 'rows' => $payments, 'empty' => ['title' => 'Aucun paiement enregistré']]) ?>
