<?php declare(strict_types=1);
/** @var array $m @var array $rows @var callable $fmt @var callable $can @var string $csrfToken @var ?string $bError @var ?array $bValues */
$canEdit = $can('members.update');
$base = '/admin/adherents/' . $m['id'] . '/ayants-droit';
$columns = [
    ['last_name', 'Nom', 'left', false, static fn (array $r): string => '<strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong>'],
    ['relationship', 'Lien'],
    ['birth_date', 'Naissance', 'left', false, static fn (array $r): string => e($fmt($r['birth_date']))],
    ['phone', 'Téléphone', 'left', false, static fn (array $r): string => e($r['phone'] ?? '—')],
];
echo ui('table', ['caption' => 'Ayants droit', 'rows' => $rows, 'columns' => $columns,
    'row_actions' => $canEdit ? static fn (array $r): string => '<form method="post" action="' . e($base . '/' . $r['id'] . '/supprimer') . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<button class="btn btn-danger btn-sm" type="submit" data-confirm-open="confirm-b-' . e((string) $r['id']) . '"><span>Supprimer<span class="visually-hidden"> ' . e($r['last_name'] . ' ' . $r['first_names']) . '</span></span></button></form>' : null,
    'empty' => ['title' => 'Aucun ayant droit enregistré', 'message' => 'Les conjoints et enfants déclarés apparaîtront ici.']]);
if (!$canEdit) {
    return;
}
foreach ($rows as $r) {
    echo ui('confirm-dialog', ['id' => 'confirm-b-' . $r['id'], 'title' => 'Supprimer cet ayant droit ?', 'message' => $r['last_name'] . ' ' . $r['first_names'] . ' sera retiré(e) de la fiche. L\'opération est consignée au journal.',
        'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $r['id'] . '/supprimer', 'csrf' => $csrfToken]);
}
$v = $bValues ?? [];
$f = static fn (string $n, string $l, array $x = []): string => ui('field', ['name' => $n, 'label' => $l, 'value' => $v[$n] ?? ''] + $x);
if (($bError ?? null) !== null) {
    echo ui('alert', ['kind' => 'danger', 'message' => $bError]);
}
echo ui('panel', ['title' => 'Ajouter un ayant droit'], '<form method="post" action="' . e($base) . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '"><div class="form-grid">'
    . $f('last_name', 'Nom', ['required' => true, 'attrs' => ['maxlength' => 120]]) . $f('first_names', 'Prénoms', ['required' => true, 'attrs' => ['maxlength' => 160]])
    . $f('relationship', 'Lien de parenté', ['required' => true, 'attrs' => ['maxlength' => 60]]) . $f('birth_date', 'Date de naissance', ['type' => 'date'])
    . $f('phone', 'Téléphone', ['type' => 'tel', 'inputmode' => 'tel']) . '</div>'
    . '<div class="form-actions">' . ui('button', ['label' => 'Ajouter', 'type' => 'submit']) . '</div></form>');
