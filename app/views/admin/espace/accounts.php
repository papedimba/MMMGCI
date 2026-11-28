<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var string $state @var int $zone @var bool $scopeAll @var array $zones @var array $query @var Closure $can */
use App\Modules\Espace\AccountAdminController;

$kinds = ['sans_acces' => 'neutral', 'en_attente' => 'info', 'code_expire' => 'warn', 'actif' => 'ok', 'desactive' => 'danger'];
$columns = [
    ['name', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/' . e((string) $r['id']) . '?onglet=espace"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong><span>' . e($r['member_number']) . '</span></a>'],
    ['state', 'État', 'left', false, static fn (array $r): string => ui('badge', ['label' => AccountAdminController::STATES[$r['state']], 'kind' => $kinds[$r['state']]]), 'status'],
    ['zone_name', 'Zone', 'left', false, static fn (array $r): string => e($r['zone_name'] ?? '—')],
    ['expires', 'Expiration du code', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(in_array($r['state'], ['en_attente', 'code_expire'], true) ? 'Code : ' . date_fr($r['expires']) : '—') . '</span>'],
    ['last_login_at', 'Dernière connexion', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e($r['last_login_at'] === null ? '—' : 'Connexion : ' . date_fr($r['last_login_at'])) . '</span>'],
];
$settings = $can('espace.accounts.manage') || $can('settings.validate') ? ui('button', ['label' => 'Paramètres', 'variant' => 'secondary', 'href' => '/admin/espace/parametres']) : '';
?>
<?= ui('page-header', ['title' => 'Accès à l\'Espace adhérent', 'subtitle' => 'État des accès des adhérents de votre périmètre. Les codes ne sont jamais affichés ici.'], $settings) ?>
<form class="filter-row" method="get" action="/admin/espace/comptes" role="search">
<?= ui('field', ['name' => 'etat', 'label' => 'État', 'type' => 'select', 'options' => ['' => 'Tous'] + AccountAdminController::STATES, 'value' => $state]) ?>
<?php if ($scopeAll): ?><?= ui('field', ['name' => 'zone', 'label' => 'Zone', 'type' => 'select', 'options' => $zones, 'value' => $zone > 0 ? (string) $zone : '']) ?><?php endif; ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<p class="count-line"><?= e((string) $total) ?> adhérent<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Accès à l\'Espace adhérent', 'columns' => $columns, 'rows' => $rows,
    'empty' => ['title' => 'Aucun adhérent ne correspond à ce filtre.', 'message' => 'Choisissez un autre état ou une autre zone.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/espace/comptes', 'query' => $query]) ?>
