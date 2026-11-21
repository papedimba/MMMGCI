<?php declare(strict_types=1);
/** @var string $tab @var array $tabs @var array $rows @var ?int $edit @var ?string $error @var array $values @var ?array $parents @var array $zoneTypes @var string $csrfToken */
$base = '/admin/adherents/referentiels';
$tabItems = [];
foreach ($tabs as $key => [, $label]) {
    $tabItems[] = [$label, $base . '?onglet=' . $key, $key === $tab];
}
$label = $tabs[$tab][1];
?>
<?= ui('page-header', ['title' => 'Référentiels des adhérents', 'subtitle' => 'Listes proposées lors de la saisie d\'une fiche : on les désactive, on ne les supprime jamais.'], '') ?>
<?= ui('tabs', ['label' => 'Référentiels', 'items' => $tabItems]) ?>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<?php if ($tab === 'statuts'): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Statuts proposés : paramètre en attente de validation par les instances. Ils servent de base de travail et ne sont pas modifiables ici.']) ?>
<?= ui('table', ['caption' => 'Statuts des adhérents', 'columns' => [
    ['label', 'Statut'], ['code', 'Code'],
    ['is_proposed', 'Validation', 'left', false, static fn (array $r): string => ui('badge', ['label' => (int) $r['is_proposed'] === 1 ? 'Proposé' : 'Validé', 'kind' => (int) $r['is_proposed'] === 1 ? 'warn' : 'ok']), 'status'],
], 'rows' => $rows]) ?>
<?php return; endif; ?>
<form method="post" action="<?= e($base) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="onglet" value="<?= e($tab) ?>">
<?php if ($edit !== null): ?><input type="hidden" name="id" value="<?= e((string) $edit) ?>"><?php endif; ?>
<?php
$fields = ui('field', ['name' => 'name', 'label' => 'Libellé', 'value' => $values['name'] ?? '', 'required' => true, 'attrs' => ['maxlength' => 120]]);
if ($tab === 'zones') {
    $fields .= ui('field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'value' => $values['type'] ?? 'localite', 'options' => $zoneTypes]);
}
if ($parents !== null) {
    $opts = ['' => 'Aucun'];
    foreach ($parents as $p) {
        if ((int) $p['id'] !== $edit) {
            $opts[(string) $p['id']] = $p['name'];
        }
    }
    $fields .= ui('field', ['name' => 'parent_id', 'label' => $tab === 'specialites' ? 'Profession (facultatif)' : 'Parent (facultatif)', 'type' => 'select', 'value' => $values['parent_id'] ?? '', 'options' => $opts]);
}
?>
<?= ui('panel', ['title' => $edit === null ? 'Ajouter : ' . $label : 'Modifier'], '<div class="form-grid">' . $fields . '</div>'
    . '<div class="form-actions">' . ui('button', ['label' => $edit === null ? 'Ajouter' : 'Enregistrer', 'type' => 'submit'])
    . ($edit === null ? '' : ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base . '?onglet=' . $tab])) . '</div>') ?>
</form>
<?php
$columns = [
    ['name', 'Libellé', 'left', false, static fn (array $r): string => '<strong>' . e($r['name']) . '</strong>'],
];
if ($tab === 'zones') {
    $columns[] = ['type', 'Type', 'left', false, static fn (array $r): string => e($zoneTypes[$r['type']] ?? $r['type'])];
}
if ($parents !== null) {
    $columns[] = ['parent_name', 'Parent', 'left', false, static fn (array $r): string => e((string) ($r['parent_name'] ?? '')) ?: '<span class="muted">Aucun</span>'];
}
$columns[] = ['is_active', 'Statut', 'left', false, static fn (array $r): string => ui('badge', ['label' => (int) $r['is_active'] === 1 ? 'Actif' : 'Désactivé', 'kind' => (int) $r['is_active'] === 1 ? 'ok' : 'neutral']), 'status'];
$actions = static function (array $r) use ($base, $tab, $csrfToken): string {
    $on = (int) $r['is_active'] === 1;
    return '<a class="btn btn-ghost" href="' . e($base . '?onglet=' . $tab . '&modifier=' . $r['id']) . '"><span>Modifier</span></a>'
        . '<form method="post" action="' . e($base . '/activation') . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="onglet" value="' . e($tab) . '"><input type="hidden" name="id" value="' . e((string) $r['id']) . '">'
        . '<input type="hidden" name="active" value="' . ($on ? '0' : '1') . '">'
        . ui('button', ['label' => $on ? 'Désactiver' : 'Réactiver', 'variant' => 'secondary', 'type' => 'submit']) . '</form>';
};
?>
<?= ui('table', ['caption' => $label, 'columns' => $columns, 'rows' => $rows, 'row_actions' => $actions,
    'empty' => ['title' => 'Aucun élément pour le moment.', 'message' => 'Aucune valeur n\'est préremplie : les instances fixent cette liste. Ajoutez le premier élément ci-dessus.']]) ?>
