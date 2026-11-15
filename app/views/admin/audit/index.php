<?php declare(strict_types=1);
$sensitive = $tab === 'sensibles';
$query = $filters + ($sensitive ? ['onglet' => 'sensibles'] : []);
$err = static fn (string $k, string $prefix = ''): string => isset($errors[$k]) ? $prefix . $errors[$k] : '';
$userOptions = ['' => 'Tous'];
foreach ($users as $u) {
    $userOptions[(string) $u['id']] = $u['name'];
}
$who = static fn (array $r): string => $r['user_id'] === null ? 'système' : ($r['user_name'] ?? 'utilisateur n° ' . $r['user_id']);
$details = static function (array $r) use ($pretty): string {
    $html = '<details><summary>Détails</summary><dl class="detail-list"><dt>N°</dt><dd>' . e((string) $r['id']) . '</dd><dt>IP</dt><dd>' . e($r['ip'] ?? '—') . '</dd></dl>';
    foreach (['old_values' => 'Avant', 'new_values' => 'Après'] as $k => $label) {
        if ($r[$k] !== null) {
            $html .= '<p class="detail-label">' . $label . ' :</p><pre>' . e($pretty($r[$k])) . '</pre>';
        }
    }
    return $html . '</details>';
};
$columns = $sensitive
    ? [
        ['created_at', 'Date', 'left', false, fn (array $r): string => '<span class="nowrap">' . e($when($r['created_at'])) . '</span>'],
        ['user_name', 'Utilisateur', 'left', false, static fn (array $r): string => e($r['user_name'] ?? 'utilisateur n° ' . $r['user_id'])],
        ['subject_type', 'Objet consulté', 'left', false, static fn (array $r): string => e($r['subject_type'] . ' n° ' . $r['subject_id'])],
        ['field_group', 'Données'],
        ['ip', 'IP', 'left', false, static fn (array $r): string => e($r['ip'] ?? '—')],
    ]
    : [
        ['created_at', 'Date', 'left', false, fn (array $r): string => '<span class="nowrap">' . e($when($r['created_at'])) . '</span>'],
        ['user_name', 'Utilisateur', 'left', false, static fn (array $r): string => e($who($r))],
        ['action', 'Action', 'left', false, static fn (array $r): string => '<code>' . e($r['action']) . '</code>'],
        ['auditable_type', 'Objet', 'left', false, static fn (array $r): string => e(trim(($r['auditable_type'] ?? '') . ($r['auditable_id'] === null ? '' : ' n° ' . $r['auditable_id'])) ?: '—')],
        ['reference', 'Référence', 'left', false, static fn (array $r): string => e($r['reference'] ?? '')],
        ['old_values', 'Détails', 'left', false, $details],
    ];
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => $sensitive ? 'Consultations de données sensibles par le personnel.' : 'Trace des actions effectuées dans l\'administration.'],
    $sensitive ? '' : '<form method="post" action="/admin/journal/verification"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">' . ui('button', ['label' => 'Vérifier l\'intégrité', 'variant' => 'secondary', 'type' => 'submit', 'icon' => 'check']) . '</form>') ?>
<?= ui('tabs', ['label' => 'Sections du journal', 'items' => [['Journal', '/admin/journal', !$sensitive], ['Consultations sensibles', '/admin/journal?onglet=sensibles', $sensitive]]]) ?>
<details class="filters" open data-collapse-narrow="<?= $filters === [] && $errors === [] ? '1' : '0' ?>">
<summary>Filtres</summary>
<form class="filter-row" method="get" action="/admin/journal" role="search">
<?php if ($sensitive): ?><input type="hidden" name="onglet" value="sensibles"><?php endif; ?>
<?= ui('field', ['name' => 'du', 'label' => 'Du', 'type' => 'date', 'value' => $filters['du'] ?? '', 'error' => $err('du', 'Du : ')]) ?>
<?= ui('field', ['name' => 'au', 'label' => 'Au', 'type' => 'date', 'value' => $filters['au'] ?? '', 'error' => $err('au', 'Au : ')]) ?>
<?= ui('field', ['name' => 'utilisateur', 'label' => 'Utilisateur', 'type' => 'select', 'options' => $userOptions, 'value' => $filters['utilisateur'] ?? '', 'error' => $err('utilisateur')]) ?>
<?php if (!$sensitive): ?>
<?= ui('field', ['name' => 'action', 'label' => 'Action (début, ex. auth.)', 'value' => $filters['action'] ?? '', 'error' => $err('action')]) ?>
<?php endif; ?>
<?= ui('button', ['label' => 'Filtrer', 'type' => 'submit', 'icon' => 'search']) ?>
</form>
</details>
<?php if ($errors !== []): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => 'Filtres invalides : corrigez les champs signalés puis relancez la recherche.']) ?>
<?php else: ?>
<?php if ($rows !== []): ?><p class="count-line"><?= e((string) $total) ?> entrée(s).</p><?php endif; ?>
<?= ui('table', ['caption' => $sensitive ? 'Consultations sensibles' : 'Entrées du journal', 'columns' => $columns, 'rows' => $rows,
    'empty' => ['title' => 'Aucune entrée ne correspond à ces filtres.']]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/journal', 'query' => $query]) ?>
<?php endif; ?>
