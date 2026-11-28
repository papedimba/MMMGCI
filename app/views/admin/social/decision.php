<?php declare(strict_types=1);
/**
 * Décision motivée et immuable. Aucun montant pré-rempli ; plafond, sortes d'aide et organe affichés/imposés seulement s'ils sont validés.
 * @var array $req @var ?string $error @var array $values @var ?int $ceiling @var ?string $body @var array $kindOptions @var string $today @var string $csrfToken @var string $title
 */
use App\Core\Money;

$base = '/admin/demandes-sociales/' . $req['id'];
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => 'La décision est définitive : elle ne pourra être ni modifiée ni supprimée.',
    'breadcrumbs' => [['Assistance sociale', null], [$req['reference'], $base], ['Décision', null]]], '') ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Aucune demande n\'ouvre de droit automatique : le montant est saisi par la décision de l\'organe compétent, sous réserve des ressources disponibles.']) ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<form method="post" action="<?= e($base) ?>/decision" id="decision-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Décision'],
    ui('field', ['name' => 'decision', 'label' => 'Décision', 'type' => 'select', 'options' => ['' => 'Choisir', 'accordee' => 'Accordée', 'refusee' => 'Refusée'], 'value' => $values['decision'], 'required' => true])
    . ui('field', ['name' => 'aid_kind', 'label' => 'Sorte d\'aide accordée', 'type' => 'select', 'options' => $kindOptions, 'value' => $values['aid_kind'],
        'hint' => 'Laissez « Aucune » pour un refus.'])
    . ui('field', ['name' => 'amount', 'label' => 'Montant de l\'aide financière (XOF)', 'value' => $values['amount'], 'inputmode' => 'decimal',
        'hint' => 'Seulement pour une aide financière ; laissez vide sinon. ' . ($ceiling !== null ? 'Plafond validé pour ce type : ' . Money::format($ceiling) . '.' : 'Aucun plafond validé pour ce type : montant libre, à motiver.')])
    . ui('field', ['name' => 'reason', 'label' => 'Motif de la décision', 'type' => 'textarea', 'value' => $values['reason'], 'required' => true, 'attrs' => ['maxlength' => 5000],
        'hint' => 'Interne, jamais montré à l\'adhérent. 5 000 caractères au maximum.'])
    . ui('field', ['name' => 'member_message', 'label' => 'Message à l\'adhérent', 'type' => 'textarea', 'value' => $values['member_message'], 'required' => true, 'attrs' => ['maxlength' => 2000],
        'hint' => 'Affiché dans l\'espace de l\'adhérent. Pas de donnée de santé. 2 000 caractères au maximum.'])) ?>
<?= ui('panel', ['title' => 'Organe et référence'],
    ($body !== null ? '<dl class="detail-list"><dt>Organe compétent (validé)</dt><dd>' . e($body) . '</dd></dl>'
        : ui('field', ['name' => 'deciding_body', 'label' => 'Organe qui a pris la décision', 'value' => $values['deciding_body'], 'required' => true, 'attrs' => ['maxlength' => 160],
            'hint' => 'Aucun organe validé pour ce type : indiquez-le. 160 caractères au maximum.']))
    . ui('field', ['name' => 'decision_ref', 'label' => 'Référence de la décision', 'value' => $values['decision_ref'], 'required' => true, 'attrs' => ['maxlength' => 60],
        'hint' => 'Numéro du procès-verbal ou de la délibération, par exemple PV-CA-2026-12. N\'y mettez aucune donnée de santé ni nom de personne : elle figure au journal et dans l\'objet du décaissement. 60 caractères au maximum.'])
    . ui('field', ['name' => 'decided_at', 'label' => 'Date de la décision', 'type' => 'date', 'value' => $values['decided_at'], 'attrs' => ['max' => $today]])) ?>
<div class="form-actions">
<button class="btn btn-primary" type="submit" data-confirm-open="confirm-decision"><span>Enregistrer la décision</span></button>
<?= ui('button', ['label' => 'Retour', 'variant' => 'secondary', 'href' => $base]) ?>
</div>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-decision', 'form' => 'decision-form', 'title' => 'Enregistrer cette décision ?',
    'message' => 'La décision est définitive : elle ne pourra être ni modifiée ni supprimée. L\'opération est consignée au journal.', 'confirm_label' => 'Enregistrer']) ?>
