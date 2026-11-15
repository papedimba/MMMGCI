<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var string $etat @var Closure $can */
use App\Modules\Members\ApplicationService;
$kinds = ['recue' => 'info', 'en_verification' => 'info', 'complements_demandes' => 'warn', 'validee' => 'ok', 'rejetee' => 'danger'];
$tabs = ['' => 'Toutes'] + ApplicationService::STATES;
$items = [];
foreach ($tabs as $k => $label) {
    $items[] = [$label, '/admin/adherents/demandes' . ($k === '' ? '' : '?etat=' . $k), $k === $etat];
}
$columns = [
    ['reference', 'Demande', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/demandes/' . e((string) $r['id']) . '"><strong>' . e(trim(($r['last_name'] ?? '') . ' ' . ($r['first_names'] ?? ''))) . '</strong><span>' . e($r['reference']) . '</span></a>'],
    ['status', 'État', 'left', false, static fn (array $r): string => ui('badge', ['label' => ApplicationService::STATES[$r['status']] ?? $r['status'], 'kind' => $kinds[$r['status']] ?? 'neutral'])],
    ['source', 'Origine', 'left', false, static fn (array $r): string => e(['saisie' => 'Saisie', 'delegue' => 'Délégué', 'en_ligne' => 'En ligne'][$r['source']] ?? $r['source'])],
    ['created_at', 'Reçue le', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
];
$create = $can('members.create') ? ['label' => 'Nouvelle demande', 'icon' => 'user-plus', 'href' => '/admin/adherents/demandes/nouvelle'] : null;
?>
<?= ui('page-header', ['title' => 'Demandes d\'adhésion', 'subtitle' => 'Instruction des demandes avant la création de la fiche adhérent.',
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], ['Demandes d\'adhésion', null]]], $create ? ui('button', $create) : '') ?>
<?= ui('tabs', ['label' => 'États des demandes', 'items' => $items]) ?>
<p class="count-line"><?= e((string) $total) ?> demande<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Demandes d\'adhésion', 'columns' => $columns, 'rows' => $rows,
    'empty' => ['title' => 'Aucune demande', 'message' => $etat !== '' ? 'Aucune demande dans cet état.' : 'Les demandes saisies apparaîtront ici.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/adherents/demandes', 'query' => $etat !== '' ? ['etat' => $etat] : []]) ?>
