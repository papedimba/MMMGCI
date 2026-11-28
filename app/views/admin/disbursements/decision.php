<?php declare(strict_types=1);
/** Rejet motivé ou annulation d'une demande. @var array $d @var string $kind @var string $reason @var ?string $error @var string $csrfToken @var string $title */
$base = '/admin/decaissements/' . $d['id'];
$reject = $kind === 'reject';
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => $reject ? 'Le rejet est définitif : la demande ne pourra plus être approuvée ni payée.' : 'L\'annulation est définitive ; elle reste dans l\'historique.',
    'breadcrumbs' => [['Décaissements', '/admin/decaissements'], [$d['reference'], $base], [$reject ? 'Rejeter' : 'Annuler', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?= ui('panel', ['title' => 'Demande telle que vous la voyez'], require __DIR__ . '/_summary.php') ?>
<form method="post" action="<?= e($base . ($reject ? '/rejeter' : '/annuler')) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if ($reject): ?>
<input type="hidden" name="seen_revision" value="<?= e((string) $d['revision']) ?>">
<?= ui('panel', ['title' => 'Motif'], ui('field', ['name' => 'reason', 'label' => 'Motif du rejet', 'type' => 'textarea', 'value' => $reason, 'required' => true, 'attrs' => ['maxlength' => 500]])) ?>
<?php endif; ?>
<div class="form-actions">
<?= ui('button', ['label' => $reject ? 'Rejeter la demande' : 'Annuler la demande', 'variant' => 'danger', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => $base]) ?>
</div>
</form>
