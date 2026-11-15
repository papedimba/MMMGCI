<?php declare(strict_types=1);
/** @var array $rows @var array $types @var Closure $can */
use App\Core\Money;
$columns = [
    ['name', 'Compte', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/finances/comptes/' . e((string) $r['id']) . '"><strong>' . e($r['name']) . '</strong></a>'],
    ['is_active', 'État', 'left', false, static fn (array $r): string => (int) $r['is_active'] === 1 ? ui('badge', ['label' => 'Actif', 'kind' => 'ok']) : ui('badge', ['label' => 'Désactivé', 'kind' => 'neutral']), 'status'],
    ['type', 'Type', 'left', false, static fn (array $r): string => e($types[$r['type']] ?? $r['type'])],
    ['balance', 'Solde', 'right', true, static fn (array $r): string => '<span class="nowrap">' . e(Money::format((int) $r['balance'])) . '</span>'],
];
$actions = '';
if ($can('finance.manage')) {
    $actions = ui('button', ['label' => 'Nouveau compte', 'icon' => 'plus', 'href' => '/admin/finances/comptes/nouveau'])
        . ui('button', ['label' => 'Enregistrer une ressource', 'variant' => 'secondary', 'href' => '/admin/finances/ressources']);
}
?>
<?= ui('page-header', ['title' => 'Finances', 'subtitle' => 'Comptes de la Mutuelle et leur solde. Toute sortie d\'argent passe par le circuit de décaissement.'], $actions) ?>
<?= ui('tabs', ['label' => 'Finances', 'items' => [['Comptes', '/admin/finances', true], ['Catégories', '/admin/finances/categories', false]]]) ?>
<?= ui('table', ['caption' => 'Comptes et soldes', 'columns' => $columns, 'rows' => $rows, 'empty' => ['title' => 'Aucun compte', 'message' => 'Créez un premier compte pour enregistrer des ressources.']]) ?>
