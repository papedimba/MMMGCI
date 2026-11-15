<?php declare(strict_types=1);
/** @var ?array $summary @var array $latest @var bool $canSeeDisbursements @var Closure $can @var string $csrfToken */
use App\Core\Money;
$months = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];
$money = static fn (int $c): string => '<span class="nowrap">' . e(Money::format($c)) . '</span>';
$actions = $summary !== null && $can('reports.export')
    ? ui('button', ['label' => 'Exporter en CSV', 'icon' => 'file', 'variant' => 'secondary', 'href' => '/admin/solidarite/export.csv'])
        . ui('button', ['label' => 'Exporter en XLSX', 'icon' => 'file', 'variant' => 'secondary', 'href' => '/admin/solidarite/export.xlsx']) : '';
?>
<?= ui('page-header', ['title' => 'Fonds de solidarité', 'subtitle' => 'Solde, entrées et sorties du fonds, recalculés depuis le grand livre.'], $actions) ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Les critères et plafonds d\'intervention du fonds sont en attente de validation par les instances.']) ?>
<?php if ($summary === null): ?>
<?php
$form = '';
if ($can('solidarity.manage') || $can('finance.manage')) {
    $form = '<form method="post" action="/admin/solidarite/compte"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . ui('button', ['label' => 'Créer le compte du fonds', 'icon' => 'plus', 'type' => 'submit']) . '</form>';
}
?>
<?= ui('panel', ['title' => 'Aucun compte de fonds'], '<p>Aucun compte de fonds de solidarité actif n\'existe pour le moment : aucun chiffre n\'est affiché.</p>' . $form) ?>
<?php else: ?>
<?= ui('panel', ['title' => 'Situation du fonds'], '<dl class="detail-list"><dt>Solde</dt><dd><strong>' . $money($summary['balance']) . '</strong></dd>'
    . '<dt>Entrées cumulées (contre-passations déduites)</dt><dd>' . $money($summary['receipts_total']) . '</dd>'
    . '<dt>Sorties cumulées (décaissements payés, contre-passations déduites)</dt><dd>' . $money($summary['disbursed_total']) . '</dd></dl>'
    . '<p class="field-hint">Solde = ouverture (' . e(Money::format($summary['opening'])) . ') + entrées − sorties.</p>') ?>
<?= ui('panel', ['title' => 'Entrées (+) et sorties (−) des 12 derniers mois'], ui('table', ['caption' => 'Entrées et sorties par mois', 'columns' => [
    ['month', 'Mois', 'left', false, static fn (array $m): string => e(($months[substr($m['month'], 5)] ?? '') . ' ' . substr($m['month'], 0, 4))],
    ['receipts', 'Entrées', 'right', true, static fn (array $m): string => '+ ' . $money($m['receipts'])],
    ['disbursed', 'Sorties', 'right', true, static fn (array $m): string => '− ' . $money($m['disbursed'])],
], 'rows' => array_reverse($summary['by_month'])])) ?>
<?= ui('panel', ['title' => 'Dernières opérations'], ui('table', ['caption' => 'Les 20 dernières opérations', 'columns' => [
    ['occurred_at', 'Date', 'left', false, static fn (array $t): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime((string) $t['occurred_at']))) . '</span>'],
    ['category_name', 'Libellé', 'left', false, static function (array $t) use ($canSeeDisbursements): string {
        $label = e($t['category_name']) . ($t['reversal_of_id'] !== null ? ' (contre-passation)' : '') . ' <code>' . e($t['reference']) . '</code>';
        return $t['source_type'] === 'disbursement' && $canSeeDisbursements ? '<a href="/admin/decaissements/' . e((string) $t['source_id']) . '">' . $label . '</a>' : $label;
    }],
    ['direction', 'Sens', 'left', false, static fn (array $t): string => $t['direction'] === 'recette' ? 'Entrée' : 'Sortie', 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $t): string => $money(Money::fromDecimal((string) $t['amount']))],
], 'rows' => $latest, 'empty' => ['title' => 'Aucune opération', 'message' => 'Le fonds n\'a encore enregistré aucune entrée ni sortie.']])) ?>
<?php endif; ?>
