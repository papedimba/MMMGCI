<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var array $values @var array $query @var bool $operational @var Closure $can */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
$states = DisbursementController::STATES;
$kinds = ['brouillon' => 'neutral', 'soumis' => 'info', 'approuve_1' => 'info', 'approuve_2' => 'warn', 'paye' => 'ok', 'rejete' => 'danger', 'annule' => 'neutral'];
$columns = [
    ['beneficiary_name', 'Bénéficiaire', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/decaissements/' . e((string) $r['id']) . '"><strong>' . e($r['beneficiary_name']) . '</strong><span>' . e($r['reference']) . '</span></a>'],
    ['status', 'État', 'left', false, static fn (array $r): string => ui('badge', ['label' => $states[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format(Money::fromDecimal((string) $r['amount']))) . '</span>'],
    ['initiator_name', 'Initiée par', 'left', false, static fn (array $r): string => e((string) $r['initiator_name'])],
    ['created_at', 'Créée le', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
];
$actions = $can('disbursement.initiate') ? ui('button', ['label' => 'Nouvelle demande', 'icon' => 'plus', 'href' => '/admin/decaissements/nouveau']) : '';
?>
<?= ui('page-header', ['title' => 'Décaissements', 'subtitle' => 'Demandes de décaissement : deux approbations distinctes, puis un paiement unique avec justificatif.'], $actions) ?>
<?php if (!$operational): ?>
<?= ui('alert', ['kind' => 'warn', 'title' => 'Circuit inopérant', 'message' => 'Les approbations et les paiements sont bloqués tant que deux personnes distinctes ne sont pas autorisées à approuver.']) ?>
<p><a class="cell-link" href="/admin/decaissements/inoperant">Ce qu'il faut configurer</a></p>
<?php endif; ?>
<form class="filter-row" method="get" action="/admin/decaissements" role="search">
<?= ui('field', ['name' => 'etat', 'label' => 'État', 'type' => 'select', 'options' => ['' => 'Tous'] + $states, 'value' => $values['etat']]) ?>
<?= ui('field', ['name' => 'vue', 'label' => 'Afficher', 'type' => 'select', 'options' => ['' => 'Toutes les demandes', 'a_approuver' => 'À approuver par moi'], 'value' => $values['vue']]) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> demande<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Demandes de décaissement', 'columns' => $columns, 'rows' => $rows,
    'empty' => $query !== [] ? ['title' => 'Aucune demande ne correspond à ces filtres.'] : ['title' => 'Aucune demande pour le moment', 'message' => 'Les demandes de décaissement apparaîtront ici.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/decaissements', 'query' => $query]) ?>
