<?php declare(strict_types=1);
/** @var array $d @var array $values @var ?string $error @var string $key @var array $methods @var string $today @var string $csrfToken @var string $title */
use App\Core\Money;
$base = '/admin/decaissements/' . $d['id'];
$cents = Money::fromDecimal((string) $d['amount']);
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => 'Paiement unique : la dépense est inscrite au compte débité et la demande passe à « Payée ».',
    'breadcrumbs' => [['Décaissements', '/admin/decaissements'], [$d['reference'], $base], ['Payer', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?= ui('panel', ['title' => 'Demande telle que vous la voyez'], require __DIR__ . '/_summary.php') ?>
<form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/payer">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="key" value="<?= e($key) ?>">
<input type="hidden" name="seen_revision" value="<?= e((string) $d['revision']) ?>">
<input type="hidden" name="seen_amount" value="<?= e((string) $cents) ?>">
<?= ui('panel', ['title' => 'Paiement'],
    ui('field', ['name' => 'method', 'label' => 'Mode de paiement', 'type' => 'select', 'options' => $methods, 'value' => $values['method'], 'required' => true])
    . ui('field', ['name' => 'paid_on', 'label' => 'Date du paiement', 'type' => 'date', 'value' => $values['paid_on'], 'required' => true, 'attrs' => ['max' => $today]])
    . ui('field', ['name' => 'external_ref', 'label' => 'Référence externe (facultatif)', 'value' => $values['external_ref'], 'hint' => 'N° de transaction mobile money, de virement ou de chèque.', 'attrs' => ['maxlength' => 100]])
    . ui('file-input', ['name' => 'preuve', 'label' => 'Pièce justificative du paiement', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'Reçu signé, bordereau ou capture du transfert ; PDF, JPG ou PNG.', 'max_mb' => 5])
    . '<label class="check"><input type="checkbox" name="confirm" value="1" required><span>Je confirme le paiement de ' . e(Money::format($cents)) . ' à ' . e($d['beneficiary_name']) . '</span></label>') ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer le paiement', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => $base]) ?>
</div>
</form>
