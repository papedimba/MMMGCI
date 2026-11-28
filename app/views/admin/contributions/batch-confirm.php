<?php declare(strict_types=1);
/** @var array $values @var array $preview @var array $type @var string $banner @var string $csrfToken */
use App\Core\Money;
?>
<?= ui('page-header', ['title' => 'Confirmer le lot', 'subtitle' => 'Rien n\'a encore été créé.', 'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Confirmer le lot', null]]], '') ?>
<?= ui('alert', ['kind' => 'warn', 'message' => $banner]) ?>
<?= ui('panel', ['title' => $type['name'] . ' — ' . Money::format(Money::fromDecimal($type['amount']))],
    '<dl class="detail-list"><dt>Adhérents concernés</dt><dd>' . e((string) $preview['eligible']) . '</dd>'
    . '<dt>Déjà appelés pour cette période (ignorés)</dt><dd>' . e((string) $preview['already']) . '</dd>'
    . '<dt>Appels qui seront créés</dt><dd><strong>' . e((string) $preview['to_create']) . '</strong></dd></dl>') ?>
<form method="post" action="/admin/cotisations/appels/lot">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="type_id" value="<?= e($values['type_id']) ?>">
<input type="hidden" name="start" value="<?= e($values['start']) ?>">
<div class="form-actions">
<?= ui('button', ['label' => 'Créer ' . $preview['to_create'] . ' appel(s)', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => '/admin/cotisations/appels/lot']) ?>
</div>
</form>
