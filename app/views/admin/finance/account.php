<?php declare(strict_types=1);
/** @var array $acc @var array $types @var int $balance @var Closure $can @var string $csrfToken */
use App\Core\Money;
$manage = $can('finance.manage');
$base = '/admin/finances/comptes/' . $acc['id'];
$actions = '';
if ($manage) {
    $actions = ui('button', ['label' => 'Enregistrer une ressource', 'icon' => 'plus', 'href' => '/admin/finances/ressources?compte=' . $acc['id']])
        . '<form method="post" action="' . e($base . '/activation') . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="active" value="' . ((int) $acc['is_active'] === 1 ? '0' : '1') . '">'
        . ui('button', ['label' => (int) $acc['is_active'] === 1 ? 'Désactiver le compte' : 'Réactiver le compte', 'variant' => 'secondary', 'type' => 'submit']) . '</form>';
}
?>
<?= ui('page-header', ['title' => $acc['name'], 'subtitle' => ($types[$acc['type']] ?? $acc['type']) . ((int) $acc['is_active'] === 1 ? '' : ' · désactivé'),
    'breadcrumbs' => [['Finances', '/admin/finances'], [$acc['name'], null]]], $actions) ?>
<?= ui('panel', ['title' => 'Solde'], '<p><strong>' . e(Money::format($balance)) . '</strong></p><p class="field-hint">Ouverture : ' . e(Money::format(Money::fromDecimal((string) $acc['opening_balance']))) . ' + recettes − dépenses (contre-passations incluses).</p>') ?>
<?php require __DIR__ . '/transactions.php'; ?>
