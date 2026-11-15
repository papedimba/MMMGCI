<?php declare(strict_types=1);
/** @var array $t @var string $reason @var ?string $error @var string $csrfToken */
use App\Core\Money;
$base = '/admin/finances/ecritures/' . $t['id'] . '/contre-passation';
?>
<?= ui('page-header', ['title' => 'Contre-passer une écriture', 'subtitle' => $t['reference'] . ' · ' . $t['account_name'],
    'breadcrumbs' => [['Finances', '/admin/finances'], [$t['account_name'], '/admin/finances/comptes/' . $t['account_id']], ['Contre-passation', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="<?= e($base) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Écriture à contre-passer'],
    '<p>' . e($t['category_name']) . ' · ' . ($t['direction'] === 'recette' ? 'recette' : 'dépense') . ' de <strong>' . e(Money::format(Money::fromDecimal((string) $t['amount']))) . '</strong> le ' . e(date('d/m/Y', strtotime($t['occurred_at']))) . '.</p>'
    . '<p class="field-hint">Une écriture de sens opposé et de même montant sera ajoutée ; l\'écriture d\'origine reste visible et ne peut être contre-passée qu\'une fois.</p>'
    . ui('field', ['name' => 'reason', 'label' => 'Motif', 'type' => 'textarea', 'value' => $reason, 'required' => true, 'attrs' => ['maxlength' => 300]])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Contre-passer', 'variant' => 'danger', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/finances/comptes/' . $t['account_id']]) ?>
</div>
</form>
