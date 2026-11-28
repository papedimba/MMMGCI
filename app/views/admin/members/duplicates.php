<?php declare(strict_types=1);
/** @var array $data @var array $found @var bool $hadPhoto @var array $zones @var string $csrfToken */
$hidden = $found['hidden_count'];
?>
<?= ui('page-header', ['title' => 'Homonymes possibles', 'subtitle' => 'Vérifiez qu\'il ne s\'agit pas d\'une personne déjà enregistrée avant de créer cette fiche.',
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], ['Nouvel adhérent', '/admin/adherents/nouveau'], ['Homonymes', null]]], '') ?>
<?php if ($found['visible'] !== []): ?>
<?= ui('table', ['caption' => 'Adhérents ressemblant à votre saisie', 'rows' => $found['visible'], 'columns' => [
    ['last_name', 'Adhérent', 'left', false, static fn (array $r): string => '<a class="cell-link" href="/admin/adherents/' . e((string) $r['id']) . '"><strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong></a>'],
    ['member_number', 'Numéro'],
    ['zone_id', 'Zone', 'left', false, static fn (array $r): string => e($zones[$r['zone_id']] ?? '—')],
]]) ?>
<?php endif; ?>
<?php if ($hidden > 0): ?>
<?= ui('alert', ['kind' => 'info', 'message' => $hidden . ' homonyme' . ($hidden > 1 ? 's' : '') . ' hors de votre périmètre.']) ?>
<?php endif; ?>
<?php if ($hadPhoto): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'La photo choisie n\'est pas conservée à cette étape : ajoutez-la depuis « Modifier » après la création.']) ?>
<?php endif; ?>
<form method="post" action="/admin/adherents/nouveau">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php foreach ($data as $k => $v): ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) ($v ?? '')) ?>">
<?php endforeach; ?>
<input type="hidden" name="confirm_duplicate" value="1">
<div class="form-actions">
<button class="btn btn-primary" type="submit"><span>Créer quand même</span></button>
<button class="btn btn-secondary" type="submit" name="back" value="1" formnovalidate><span>Corriger la saisie</span></button>
</div>
</form>
