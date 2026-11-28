<?php declare(strict_types=1);
/**
 * Enregistrement d'une demande : 1) recherche de l'adhérent dans le périmètre (MemberSearch), 2) formulaire. Le service contrôle tout au POST.
 * @var string $q @var list<array> $found @var ?array $member @var array $beneficiaries @var ?string $error @var array $values @var array $typeOptions
 * @var bool $canWriteMedical @var bool $referencePending @var string $today @var string $csrfToken @var string $title
 */
use App\Modules\Social\SocialRequestService;
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => 'La référence AS- est attribuée à l\'enregistrement.',
    'breadcrumbs' => [['Assistance sociale', null], ['Nouvelle demande', null]]], '') ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Les aides de l\'article 9 sont développées sous réserve des ressources disponibles, des textes applicables et des critères adoptés. Aucune demande n\'ouvre de droit automatique : toute aide est soumise à des critères écrits, à une procédure d\'examen et à la validation de l\'organe compétent.']) ?>
<?php if ($referencePending): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Paramètre en attente de validation par les instances : le format de la référence des demandes (AS-AAAA-NNNN, technique par défaut).']) ?>
<?php endif; ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?php if ($member === null): ?>
<form method="get" action="/admin/demandes-sociales/nouvelle" role="search">
<?= ui('panel', ['title' => '1. Adhérent concerné'], ui('field', ['name' => 'q', 'label' => 'Rechercher un adhérent', 'type' => 'search', 'value' => $q, 'attrs' => ['maxlength' => 100],
    'hint' => 'Nom, prénoms, numéro d\'adhérent ou téléphone. Seuls les adhérents de votre périmètre apparaissent.'])
    . '<div class="form-actions">' . ui('button', ['label' => 'Rechercher', 'type' => 'submit', 'icon' => 'search']) . '</div>') ?>
</form>
<?php if ($q !== ''): ?>
<?= ui('table', ['caption' => 'Adhérents trouvés', 'rows' => $found, 'columns' => [
    ['last_name', 'Adhérent', 'left', false, static fn (array $m): string => '<a class="cell-link" href="/admin/demandes-sociales/nouvelle?membre=' . (int) $m['id'] . '">' . e($m['last_name'] . ' ' . $m['first_names']) . '</a>'],
    ['member_number', 'Numéro', 'left', false, static fn (array $m): string => e((string) $m['member_number'])],
    ['zone_name', 'Zone', 'left', false, static fn (array $m): string => e((string) $m['zone_name'])],
], 'empty' => ['title' => 'Aucun adhérent trouvé dans votre périmètre', 'message' => 'Vérifiez l\'orthographe ou le numéro.']]) ?>
<?php endif; ?>
<?php else: ?>
<form method="post" enctype="multipart/form-data" action="/admin/demandes-sociales/nouvelle">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
<?= ui('panel', ['title' => '1. Adhérent concerné', 'actions' => ui('button', ['label' => 'Changer', 'variant' => 'secondary', 'href' => '/admin/demandes-sociales/nouvelle'])],
    '<dl class="detail-list"><dt>Adhérent</dt><dd>' . e($member['last_name'] . ' ' . $member['first_names']) . '</dd><dt>Numéro</dt><dd>' . e((string) $member['member_number']) . '</dd></dl>'
    . ui('field', ['name' => 'beneficiary_id', 'label' => 'Ayant droit concerné (facultatif)', 'type' => 'select', 'options' => $beneficiaries, 'value' => $values['beneficiary_id']])) ?>
<?= ui('panel', ['title' => '2. Demande'],
    ui('field', ['name' => 'type_id', 'label' => 'Type de demande', 'type' => 'select', 'options' => $typeOptions, 'value' => $values['type_id'], 'required' => true])
    . ui('field', ['name' => 'received_at', 'label' => 'Date de réception', 'type' => 'date', 'value' => $values['received_at'], 'required' => true, 'attrs' => ['max' => $today]])
    . ui('field', ['name' => 'channel', 'label' => 'Canal de réception', 'type' => 'select', 'options' => ['' => 'Choisir'] + SocialRequestService::CHANNELS, 'value' => $values['channel'], 'required' => true])
    . ui('field', ['name' => 'priority', 'label' => 'Priorité', 'type' => 'select', 'options' => SocialRequestService::PRIORITIES, 'value' => $values['priority']])
    . ui('field', ['name' => 'description', 'label' => 'Description de la demande', 'type' => 'textarea', 'value' => $values['description'], 'required' => true, 'attrs' => ['maxlength' => 5000],
        'hint' => 'Pas de donnée de santé ici (diagnostic, traitement, certificat) : utilisez la case ci-dessous. 5 000 caractères au maximum.'])
    . '<label class="check"><input type="checkbox" name="contains_medical_data" value="1"' . (($values['contains_medical_data'] ?? '') === '1' ? ' checked' : '') . '><span>La demande contient des données de santé</span></label>'
    . ($canWriteMedical ? ui('field', ['name' => 'medical_details', 'label' => 'Données de santé (facultatif)', 'type' => 'textarea', 'value' => $values['medical_details'], 'attrs' => ['maxlength' => 5000],
        'hint' => 'Lues seulement par les personnes habilitées ; chaque consultation est journalisée. 5 000 caractères au maximum.']) : '')) ?>
<?= ui('panel', ['title' => '3. Pièce (facultatif)'],
    ui('file-input', ['name' => 'piece', 'label' => 'Pièce justificative', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG ; d\'autres pièces pourront être déposées ensuite.', 'max_mb' => 5])
    . ui('field', ['name' => 'label', 'label' => 'Libellé de la pièce (facultatif)', 'value' => $values['label'], 'attrs' => ['maxlength' => 160], 'hint' => '160 caractères au maximum.'])
    . '<label class="check"><input type="checkbox" name="is_medical" value="1"><span>Pièce médicale (accès restreint)</span></label>') ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Enregistrer la demande', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/demandes-sociales/nouvelle']) ?>
</div>
</form>
<?php endif; ?>
