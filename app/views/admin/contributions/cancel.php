<?php declare(strict_types=1);
/** @var array $c @var string $reason @var ?string $error @var string $csrfToken */
use App\Core\Money;
?>
<?= ui('page-header', ['title' => 'Annuler un appel', 'subtitle' => 'L\'appel reste dans l\'historique, marqué annulé. Impossible s\'il a reçu un paiement.',
    'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Annuler un appel', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<p><?= e($c['last_name'] . ' ' . $c['first_names']) ?> — <?= e($c['type_name']) ?>, <?= e($c['period_label']) ?>, <?= e(Money::format(Money::fromDecimal($c['amount_due']))) ?></p>
<form method="post" action="/admin/cotisations/appels/<?= e((string) $c['id']) ?>/annuler">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Motif'], ui('field', ['name' => 'reason', 'label' => 'Motif de l\'annulation', 'type' => 'textarea', 'value' => $reason, 'required' => true, 'attrs' => ['maxlength' => 1000]])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Annuler l\'appel', 'variant' => 'danger', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => '/admin/cotisations/appels']) ?>
</div>
</form>
