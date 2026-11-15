<?php declare(strict_types=1);
/** @var array $rows @var Closure $can */
use App\Core\Money;
use App\Modules\Contributions\ContributionTypeService;
use App\Modules\Contributions\Periods;
$manage = $can('contributions.manage');
$pending = static fn (): string => ui('badge', ['label' => 'À valider', 'kind' => 'warn']);
$columns = [
    ['name', 'Type', 'left', false, static fn (array $r): string => '<strong>' . e($r['name']) . '</strong>'],
    ['kind', 'Nature', 'left', false, static fn (array $r): string => e(ContributionTypeService::KINDS[$r['kind']] ?? $r['kind'])],
    ['amount', 'Montant', 'right', true, static fn (array $r): string => $r['amount'] === null ? $pending() : '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['amount']))) . '</span>'],
    ['periodicity', 'Périodicité', 'left', false, static fn (array $r): string => $r['periodicity'] === null ? ($r['kind'] === 'periodique' ? $pending() : '—') : e(Periods::LABELS[$r['periodicity']] ?? $r['periodicity'])],
    ['authorization_ref', 'Autorisation', 'left', false, static fn (array $r): string => $r['authorization_ref'] === null ? $pending() : e($r['authorization_ref'])],
    ['status', 'État', 'left', false, static fn (array $r): string => $r['status'] === 'actif' ? ui('badge', ['label' => 'Actif', 'kind' => 'ok']) : ui('badge', ['label' => 'Non configuré', 'kind' => 'neutral']), 'status'],
];
$rowActions = $manage ? static fn (array $r): string => ui('button', ['label' => 'Modifier', 'variant' => 'secondary', 'size' => 'sm', 'href' => '/admin/cotisations/types/' . $r['id'] . '/modifier']) : null;
?>
<?= ui('page-header', ['title' => 'Cotisations', 'subtitle' => 'Types de cotisation. Montants, périodicité et autorisation sont fixés par les instances ; tant qu\'ils manquent, aucun appel n\'est possible. Un type se désactive, il ne se supprime pas.'],
    $manage ? ui('button', ['label' => 'Nouveau type', 'icon' => 'plus', 'href' => '/admin/cotisations/types/nouveau']) : '') ?>
<?php $current = 'types'; require __DIR__ . '/_tabs.php'; ?>
<?= ui('table', ['caption' => 'Types de cotisation', 'columns' => $columns, 'rows' => $rows, 'row_actions' => $rowActions,
    'empty' => ['title' => 'Aucun type de cotisation', 'message' => 'Créez un type ; il restera non configuré jusqu\'à validation par les instances.']]) ?>
