<?php declare(strict_types=1);
/** @var array $m @var array $cards @var ?array $active @var ?string $conditions @var array $errors @var int $maxReason @var string $csrfToken */
$base = '/admin/adherents/' . $m['id'];
$name = $m['last_name'] . ' ' . $m['first_names'];
$date = static fn (?string $d): string => $d === null ? 'Validité non définie' : (new DateTimeImmutable($d))->format('d/m/Y');
?>
<?= ui('page-header', ['title' => 'Carte de membre', 'subtitle' => $name . ' · N° ' . $m['member_number'],
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], [$name, $base], ['Carte de membre', null]]],
    $active !== null ? ui('button', ['label' => 'Imprimer la carte', 'variant' => 'secondary', 'href' => $base . '/carte/imprimer']) : '') ?>
<?php if ($errors !== []): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => implode(' ', $errors)]) ?>
<?php endif; ?>
<?php if ($conditions === null): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Conditions de délivrance en attente de validation par les instances : la carte est délivrée manuellement.']) ?>
<?php endif; ?>
<?= ui('panel', ['title' => 'Carte active'], $active === null
    ? '<p>Aucune carte active.</p>'
    : '<dl class="detail-list"><dt>N° de carte</dt><dd>' . e($active['card_number']) . '</dd><dt>Délivrée le</dt><dd>' . e(date_fr($active['issued_at'])) . '</dd><dt>Validité</dt><dd>' . e($active['expires_at'] === null ? 'Validité non définie' : 'Jusqu\'au ' . $date($active['expires_at'])) . '</dd></dl>') ?>
<form method="post" action="<?= e($base . '/carte') ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => $active === null ? 'Délivrer une carte' : 'Délivrer une nouvelle carte'],
    ($active !== null ? '<p class="field-hint">La carte active sera révoquée : son QR ne sera plus reconnu.</p>' : '')
    . ($conditions !== null ? '<p><strong>Conditions de délivrance</strong> (décidées par les instances) : ' . e($conditions) . '</p>'
        . '<label class="check"><input type="checkbox" name="conditions_checked" value="1" required><span>Conditions de délivrance vérifiées</span></label>' : '')
    . '<div class="form-actions">' . ui('button', ['label' => 'Délivrer la carte', 'type' => 'submit']) . '</div>') ?>
</form>
<?php if ($active !== null): ?>
<form method="post" action="<?= e($base . '/carte/' . $active['id'] . '/revoquer') ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Révoquer la carte active'],
    ui('field', ['name' => 'reason', 'label' => 'Motif de la révocation', 'type' => 'textarea', 'required' => true, 'error' => $errors['reason'] ?? '', 'attrs' => ['maxlength' => $maxReason]])
    . '<div class="form-actions">' . ui('button', ['label' => 'Révoquer la carte', 'variant' => 'danger', 'type' => 'submit']) . '</div>') ?>
</form>
<?php endif; ?>
<?= ui('panel', ['title' => 'Historique des cartes'], ui('table', ['caption' => 'Cartes délivrées', 'columns' => [
    ['card_number', 'N° de carte'],
    ['issued_at', 'Délivrée le', 'left', false, fn (array $c): string => e(date_fr($c['issued_at']))],
    ['expires_at', 'Validité', 'left', false, fn (array $c): string => e($date($c['expires_at']))],
    ['revoked_at', 'État', 'left', false, fn (array $c): string => $c['revoked_at'] === null ? ui('badge', ['label' => 'Active', 'kind' => 'ok']) : e('Révoquée le ' . date_fr($c['revoked_at']))],
], 'rows' => $cards, 'empty' => ['title' => 'Aucune carte délivrée']])) ?>
