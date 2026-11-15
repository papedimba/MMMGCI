<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var array $f @var array $accounts @var array $query */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
$tab = 'decaissements'; $heading = 'Contrôle des décaissements'; $subtitle = 'Toutes les demandes, avec leur historique complet.'; $dataset = 'decaissements';
include __DIR__ . '/_head.php';
$states = DisbursementController::STATES;
$kinds = ['brouillon' => 'neutral', 'soumis' => 'info', 'approuve_1' => 'info', 'approuve_2' => 'warn', 'paye' => 'ok', 'rejete' => 'danger', 'annule' => 'neutral'];
$columns = [
    ['beneficiary_name', 'Demande', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/controle/decaissements/' . e((string) $r['id']) . '"><strong>' . e($r['beneficiary_name']) . '</strong><span>' . e($r['reference']) . '</span></a>'],
    ['status', 'État', 'left', false, static fn (array $r): string => ui('badge', ['label' => $states[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal((string) $r['amount']))) . '</span>'],
    ['account_name', 'Compte', 'left', false, static fn (array $r): string => e($r['account_name'])],
    ['initiator_name', 'Initiée par', 'left', false, static fn (array $r): string => e((string) $r['initiator_name'])],
    ['created_at', 'Créée le', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
    ['revision', 'Révision', 'left', false, static fn (array $r): string => 'n° ' . e((string) $r['revision'])],
];
?>
<form class="filter-row" method="get" action="/admin/controle/decaissements" role="search">
<?= ui('field', ['name' => 'du', 'label' => 'Créée du', 'type' => 'date', 'value' => $f['du'] ?? '']) ?>
<?= ui('field', ['name' => 'au', 'label' => 'Au', 'type' => 'date', 'value' => $f['au'] ?? '']) ?>
<?= ui('field', ['name' => 'compte', 'label' => 'Compte', 'type' => 'select', 'options' => $accounts, 'value' => $f['compte'] ?? '']) ?>
<?= ui('field', ['name' => 'etat', 'label' => 'État', 'type' => 'select', 'options' => ['' => 'Tous'] + $states, 'value' => $f['etat'] ?? '']) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> demande<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Demandes de décaissement', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucune demande ne correspond']]) ?>
<?= ui('pagination', ['base' => '/admin/controle/decaissements', 'page' => $page, 'pages' => $pages, 'query' => $query]) ?>
