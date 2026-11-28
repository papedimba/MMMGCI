<?php declare(strict_types=1);
/** @var ?array $member @var array $values @var ?string $error @var array $errors @var bool $docs @var bool $scopeAll @var array $zones @var string $zoneName @var array $categories @var array $professions @var array $specialties @var array $idTypes @var string $csrfToken */
$f = static fn (string $name, string $label, array $extra = []): string => ui('field', ['name' => $name, 'label' => $label, 'value' => $values[$name] ?? ''] + $extra);
$app = $application ?? false; // formulaire réutilisé pour la saisie d'une demande d'adhésion (sans photo)
$back = $app ? '/admin/adherents/demandes' : ($member === null ? '/admin/adherents' : '/admin/adherents/' . $member['id']);
?>
<?= $app
    ? ui('page-header', ['title' => 'Nouvelle demande d\'adhésion', 'subtitle' => 'La fiche adhérent sera créée à la validation de la demande.',
        'breadcrumbs' => [['Adhérents', '/admin/adherents'], ['Demandes d\'adhésion', '/admin/adherents/demandes'], ['Nouvelle demande', null]]], '')
    : ui('page-header', ['title' => $member === null ? 'Nouvel adhérent' : $member['last_name'] . ' ' . $member['first_names'],
    'subtitle' => $member === null ? 'Le numéro d\'adhérent est attribué à l\'enregistrement.' : 'N° ' . $member['member_number'],
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], [$member === null ? 'Nouvel adhérent' : 'Modifier', null]]], '') ?>
<?php if ($numberPending ?? false): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Paramètre en attente de validation par les instances : le format du numéro de membre (technique par défaut).']) ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" action="<?= e($app ? '/admin/adherents/demandes/nouvelle' : ($member === null ? '/admin/adherents/nouveau' : '/admin/adherents/' . $member['id'] . '/modifier')) ?>">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Identité'], '<div class="form-grid">'
    . $f('last_name', 'Nom', ['required' => true, 'autocomplete' => 'family-name', 'attrs' => ['maxlength' => 100]])
    . $f('first_names', 'Prénoms', ['required' => true, 'autocomplete' => 'given-name', 'attrs' => ['maxlength' => 100]])
    . $f('sex', 'Sexe', ['attrs' => ['maxlength' => 10]])
    . $f('birth_date', 'Date de naissance', ['type' => 'date'])
    . $f('birth_place', 'Lieu de naissance', ['attrs' => ['maxlength' => 120]])
    . $f('nationality', 'Nationalité', ['attrs' => ['maxlength' => 80]])
    . '</div>') ?>
<?= ui('panel', ['title' => 'Coordonnées'], '<div class="form-grid">'
    . $f('phone', 'Téléphone', ['type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel'])
    . $f('phone_alt', 'Autre téléphone', ['type' => 'tel', 'inputmode' => 'tel'])
    . $f('email', 'Adresse e-mail', ['type' => 'email', 'autocomplete' => 'email', 'attrs' => ['maxlength' => 190]])
    . $f('address', 'Adresse', ['attrs' => ['maxlength' => 255]])
    . '</div>') ?>
<?= ui('panel', ['title' => 'Profession et rattachement'], '<div class="form-grid">'
    . $f('profession_id', 'Profession', ['type' => 'select', 'options' => $professions])
    . $f('specialty_id', 'Spécialité', ['type' => 'select', 'options' => $specialties])
    . $f('employment_status', 'Situation professionnelle', ['attrs' => ['maxlength' => 60]])
    . $f('employer', 'Employeur', ['attrs' => ['maxlength' => 160]])
    . $f('category_id', 'Catégorie', ['type' => 'select', 'options' => $categories])
    . $f('joined_at', 'Date d\'adhésion', ['type' => 'date'])
    . ($scopeAll ? $f('zone_id', 'Zone', ['type' => 'select', 'options' => $zones])
        : '<div class="field"><p class="detail-label">Zone</p><p>' . e($zoneName !== '' ? $zoneName : 'Votre zone') . '</p><p class="field-hint">Les adhérents que vous créez sont rattachés à votre zone.</p></div>')
    . '</div>') ?>
<?php if ($docs): ?>
<?= ui('panel', ['title' => 'Pièce d\'identité et livret maritime'], '<div class="form-grid">'
    . $f('id_document_type', 'Type de pièce d\'identité', ['type' => 'select', 'options' => $idTypes])
    . $f('id_document_number', 'Numéro de pièce d\'identité', ['attrs' => ['maxlength' => 60, 'autocomplete' => 'off']])
    . $f('seaman_book_number', 'Numéro de livret maritime', ['attrs' => ['maxlength' => 60, 'autocomplete' => 'off']])
    . $f('seaman_book_expiry', 'Échéance du livret maritime', ['type' => 'date'])
    . '</div>') ?>
<?php endif; ?>
<?php if (!$app): ?>
<?= ui('panel', ['title' => 'Photo'], ui('file-input', ['name' => 'photo', 'label' => $member !== null && $member['photo_file_id'] !== null ? 'Remplacer la photo (facultatif)' : 'Photo (facultatif)',
    'accept' => '.jpg,.jpeg,.png,.webp', 'hint' => 'JPG, PNG ou WebP.', 'max_mb' => 5, 'error' => $errors['photo'] ?? ''])) ?>
<?php endif; ?>
<div class="form-actions">
<?= ui('button', ['label' => $app ? 'Enregistrer la demande' : ($member === null ? 'Enregistrer l\'adhérent' : 'Enregistrer'), 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $back]) ?>
</div>
</form>
