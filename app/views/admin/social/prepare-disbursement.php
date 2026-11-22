<?php declare(strict_types=1);
/**
 * Préparation du décaissement d'une aide financière accordée : un BROUILLON seulement, qui suit ensuite le circuit à double validation.
 * @var array $req @var array $values @var ?string $error @var string $amount @var array $accountOptions @var array $categoryOptions @var string $csrfToken @var string $title
 */
use App\Core\Money;

$base = '/admin/demandes-sociales/' . $req['id'];
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => 'Crée un brouillon de décaissement ; il devra être soumis puis approuvé par deux personnes avant tout paiement.',
    'breadcrumbs' => [['Assistance sociale', null], [$req['reference'], $base], ['Décaissement', null]]], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="<?= e($base) ?>/decaissement">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Décaissement'],
    '<dl class="detail-list"><dt>Montant décidé</dt><dd>' . e($amount === '' ? '—' : Money::format(Money::fromDecimal($amount))) . '</dd>'
    . '<dt>Adhérent bénéficiaire</dt><dd>' . e($req['member_number'] . ' ' . $req['last_name'] . ' ' . $req['first_names']) . '</dd></dl>'
    . ui('field', ['name' => 'account_id', 'label' => 'Compte débité', 'type' => 'select', 'options' => $accountOptions, 'value' => $values['account_id'], 'required' => true,
        'hint' => 'Le fonds de solidarité est proposé en premier ; choisissez le compte prévu par la décision.'])
    . ui('field', ['name' => 'category_id', 'label' => 'Catégorie de dépense', 'type' => 'select', 'options' => $categoryOptions, 'value' => $values['category_id'], 'required' => true])
    . ui('field', ['name' => 'beneficiary_name', 'label' => 'Bénéficiaire', 'value' => $values['beneficiary_name'], 'required' => true, 'attrs' => ['maxlength' => 160],
        'hint' => 'Nom de l\'adhérent, ou de l\'ayant droit qui reçoit l\'aide. 160 caractères au maximum.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Préparer le brouillon', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => $base]) ?>
</div>
</form>
