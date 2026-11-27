<?php declare(strict_types=1);
/** @var array $rows @var int $total @var int $page @var int $pages @var array $criteria @var array $applied @var array $types @var ?array $zones @var Closure $can */
use App\Modules\Social\SocialRequestService;
use App\Modules\Social\SocialWorkflow;

$kinds = ['recue' => 'info', 'en_instruction' => 'info', 'en_attente_pieces' => 'warn', 'soumise_decision' => 'warn', 'accordee' => 'ok', 'refusee' => 'danger', 'en_paiement' => 'info', 'cloturee' => 'neutral'];
$cap = static fn (string $s): string => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
$statuses = ['' => 'Tous'] + array_map($cap, SocialWorkflow::LABELS);
$columns = [
    ['reference', 'Référence', 'left', false, static fn (array $r): string => '<a class="cell-link" href="/admin/demandes-sociales/' . e((string) $r['id']) . '"><strong>' . e($r['reference']) . '</strong></a>'],
    ['received_at', 'Reçue le', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime((string) $r['received_at']))) . '</span>'],
    ['member', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="who" href="/admin/adherents/' . e((string) $r['member_id']) . '"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong><span>' . e($r['member_number']) . '</span></a>'],
    ['type_name', 'Type', 'left', false, static fn (array $r): string => e($r['type_name'])],
    ['priority', 'Priorité', 'left', false, static fn (array $r): string => ui('badge', ['label' => SocialRequestService::PRIORITIES[$r['priority']] ?? $r['priority'], 'kind' => $r['priority'] === 'urgente' ? 'danger' : 'neutral'])],
    ['status', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => $cap(SocialWorkflow::LABELS[$r['status']] ?? $r['status']), 'kind' => $kinds[$r['status']] ?? 'neutral']), 'status'],
    ['assignee_name', 'Responsable', 'left', false, static fn (array $r): string => e($r['assignee_name'] ?? 'Non affectée')],
];
$create = $can('social.create') ? ['label' => 'Nouvelle demande', 'icon' => 'plus', 'href' => '/admin/demandes-sociales/nouvelle'] : null;
$filtered = $applied !== [];
$query = $criteria;
?>
<?= ui('page-header', ['title' => 'Demandes sociales', 'subtitle' => 'Demandes d\'assistance des adhérents de votre périmètre.'], $create ? ui('button', $create) : '') ?>
<form class="toolbar" method="get" action="/admin/demandes-sociales" role="search">
<?= ui('field', ['name' => 'q', 'label' => 'Rechercher par référence, numéro ou nom d\'adhérent', 'type' => 'search', 'value' => $criteria['q'] ?? '']) ?>
<?= ui('button', ['label' => 'Rechercher', 'variant' => 'secondary', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
<details class="filters" open data-collapse-narrow="<?= array_diff_key($criteria, ['q' => 1]) === [] ? '1' : '0' ?>">
<summary>Filtres</summary>
<form class="filter-row" method="get" action="/admin/demandes-sociales" role="search">
<?php if (isset($criteria['q'])): ?><input type="hidden" name="q" value="<?= e($criteria['q']) ?>"><?php endif; ?>
<?= ui('field', ['name' => 'status', 'label' => 'Statut', 'type' => 'select', 'options' => $statuses, 'value' => $criteria['status'] ?? '']) ?>
<?= ui('field', ['name' => 'type_id', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => $criteria['type_id'] ?? '']) ?>
<?= ui('field', ['name' => 'priority', 'label' => 'Priorité', 'type' => 'select', 'options' => ['' => 'Toutes'] + SocialRequestService::PRIORITIES, 'value' => $criteria['priority'] ?? '']) ?>
<?= ui('field', ['name' => 'assignee', 'label' => 'Responsable', 'type' => 'select', 'options' => ['' => 'Tous', 'moi' => 'Moi', 'non' => 'Non affectées'], 'value' => $criteria['assignee'] ?? '']) ?>
<?php if ($zones !== null): ?><?= ui('field', ['name' => 'zone_id', 'label' => 'Zone', 'type' => 'select', 'options' => $zones, 'value' => $criteria['zone_id'] ?? '']) ?><?php endif; ?>
<?= ui('field', ['name' => 'from', 'label' => 'Reçues depuis le', 'type' => 'date', 'value' => $criteria['from'] ?? '']) ?>
<?= ui('field', ['name' => 'to', 'label' => 'Reçues jusqu\'au', 'type' => 'date', 'value' => $criteria['to'] ?? '']) ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
</details>
<?php if ($can('social.export')): $qs = $query === [] ? '' : '?' . http_build_query($query); ?>
<p class="export-links">
<?= ui('button', ['label' => 'Exporter en CSV', 'variant' => 'secondary', 'icon' => 'download', 'href' => '/admin/demandes-sociales/export.csv' . $qs]) ?>
<?= ui('button', ['label' => 'Exporter en Excel', 'variant' => 'secondary', 'icon' => 'download', 'href' => '/admin/demandes-sociales/export.xlsx' . $qs]) ?>
</p>
<?php endif; ?>
<p class="count-line"><?= e((string) $total) ?> demande<?= $total > 1 ? 's' : '' ?>.</p>
<?= ui('table', ['caption' => 'Liste des demandes sociales', 'columns' => $columns, 'rows' => $rows,
    'empty' => $filtered
        ? ['title' => 'Aucune demande ne correspond à cette recherche.', 'message' => 'Vérifiez l\'orthographe ou effacez les filtres.']
        : ['title' => 'Aucune demande pour le moment', 'message' => 'Les demandes enregistrées apparaîtront ici.'] + ($create ? ['action' => $create] : [])]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/demandes-sociales', 'query' => $query]) ?>
