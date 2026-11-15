<?php declare(strict_types=1);
/** @var array $c @var int $rest @var array $values @var ?string $error @var array $methods @var string $today @var string $csrfToken */
use App\Core\Money;
?>
<?= ui('page-header', ['title' => 'Encaisser une cotisation', 'subtitle' => 'Le paiement est crédité au compte du type de cotisation ; un reçu numéroté est émis.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Encaisser', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<p><?= e($c['last_name'] . ' ' . $c['first_names']) ?> (<?= e($c['member_number']) ?>) — <?= e($c['type_name']) ?>, <?= e($c['period_label']) ?>.
Montant dû : <?= e(Money::format(Money::fromDecimal($c['amount_due']))) ?>, reste dû : <strong><?= e(Money::format($rest)) ?></strong>.</p>
<form method="post" action="/admin/cotisations/appels/<?= e((string) $c['id']) ?>/encaisser">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="key" value="<?= e($values['key']) ?>">
<?= ui('panel', ['title' => 'Paiement'],
    ui('field', ['name' => 'amount', 'label' => 'Montant (XOF)', 'value' => $values['amount'], 'required' => true, 'inputmode' => 'decimal', 'hint' => 'Par exemple 12 500 ou 12 500,50 ; au plus le reste dû.'])
    . ui('field', ['name' => 'method', 'label' => 'Mode de paiement', 'type' => 'select', 'options' => $methods, 'value' => $values['method'], 'required' => true])
    . ui('field', ['name' => 'paid_on', 'label' => 'Date du paiement', 'type' => 'date', 'value' => $values['paid_on'], 'required' => true, 'attrs' => ['max' => $today]])
    . ui('field', ['name' => 'external_ref', 'label' => 'Référence externe (facultatif)', 'value' => $values['external_ref'], 'hint' => 'N° de transaction mobile money, de virement ou de chèque.', 'attrs' => ['maxlength' => 100]])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer le paiement', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/adherents/' . $c['member_id'] . '?onglet=cotisations']) ?>
</div>
</form>
