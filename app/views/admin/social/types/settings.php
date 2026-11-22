<?php declare(strict_types=1);
/** @var array $rows @var string $errorKey @var string $entered @var Closure $can @var string $csrfToken */
$validate = $can('settings.validate');
?>
<?= ui('page-header', ['title' => 'Paramètres généraux', 'subtitle' => 'Délais, conservation, éligibilité et format de référence : décidés par les instances, jamais présumés.',
    'breadcrumbs' => [['Types de demande', '/admin/demandes-sociales/types'], ['Paramètres généraux', null]]], '') ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Paramètre en attente de validation par les instances : tant qu\'il est « non configuré », le logiciel n\'en tire aucune règle.']) ?>
<?php foreach ($rows as $r): ?>
<?php
$status = $r['validated'] ? ui('badge', ['label' => 'Validé', 'kind' => 'ok']) : ui('badge', ['label' => 'Non configuré', 'kind' => 'warn']);
$current = $r['validated'] ? '<p>Valeur validée : <strong>' . e((string) $r['value']) . '</strong> (décision ' . e((string) $r['decision_ref']) . ')</p>' : '';
$form = '';
if ($validate) {
    $form = '<form method="post" action="/admin/demandes-sociales/parametres/' . e($r['key']) . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . ui('field', ['name' => 'value_' . $r['key'], 'label' => 'Valeur décidée', 'type' => $r['type'] === 'text' ? 'textarea' : 'text', 'hint' => $r['hint'], 'value' => $errorKey === $r['key'] ? $entered : '', 'required' => true])
        . ui('field', ['name' => 'decision_ref_' . $r['key'], 'label' => 'Référence de la décision', 'required' => true, 'attrs' => ['maxlength' => 100]])
        . ui('button', ['label' => 'Valider', 'type' => 'submit']) . '</form>';
}
?>
<?= ui('panel', ['title' => $r['label']], $status . $current . $form) ?>
<?php endforeach; ?>
