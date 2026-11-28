<?php declare(strict_types=1);
/** @var array $p @var string $reason @var ?string $error @var string $csrfToken */
use App\Core\Money;
?>
<?= ui('page-header', ['title' => 'Annuler un paiement', 'subtitle' => 'Une contre-passation est enregistrée au grand livre et le reçu est marqué annulé ; rien n\'est supprimé. Le solde du compte peut en devenir négatif.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Paiement', '/admin/cotisations/paiements/' . $p['id']], ['Annuler', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<p><?= e($p['reference']) ?> — <?= e($p['last_name'] . ' ' . $p['first_names']) ?>, <?= e(Money::format(Money::fromDecimal($p['amount']))) ?>, reçu <?= e((string) $p['receipt_number']) ?>.</p>
<form method="post" action="/admin/cotisations/paiements/<?= e((string) $p['id']) ?>/annuler">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Motif'], ui('field', ['name' => 'reason', 'label' => 'Motif de l\'annulation', 'type' => 'textarea', 'value' => $reason, 'required' => true, 'attrs' => ['maxlength' => 300]])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Annuler le paiement', 'variant' => 'danger', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => '/admin/cotisations/paiements/' . $p['id']]) ?>
</div>
</form>
