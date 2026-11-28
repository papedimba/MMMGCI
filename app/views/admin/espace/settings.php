<?php declare(strict_types=1);
/** @var array $rows @var array $statuses @var string $errorKey @var string|array $entered @var Closure $can @var string $csrfToken */
use App\Modules\Espace\EspaceSettingsService;

$levels = EspaceSettingsService::LEVEL_LABELS;
$validate = $can('settings.validate');
/** Valeur appliquée, lisible. */
$show = static function (array $r) use ($levels, $statuses): string {
    $v = $r['value'];
    if ($r['type'] === 'map') {
        $out = '<dl class="detail-list">';
        foreach ($statuses as $code => $label) {
            $level = is_array($v) && isset($v[$code]) && is_string($v[$code]) ? ($levels[$v[$code]] ?? 'Aucun accès (valeur illisible)') : 'Consultation seule (statut non couvert)';
            $out .= '<dt>' . e($label) . '</dt><dd>' . e($level) . '</dd>';
        }
        return $out . '</dl>';
    }
    return '<p><strong>' . e(match ($r['type']) {
        'int' => is_int($v) ? $v . ' jours' : 'Aucune valeur',
        default => $v === true ? 'Ouvert' : 'Fermé',
    }) . '</strong></p>';
};
?>
<?= ui('page-header', ['title' => 'Paramètres de l\'Espace adhérent', 'subtitle' => 'Règles d\'accès décidées par les instances, jamais présumées.',
    'breadcrumbs' => [['Espace adhérent', '/admin/espace/comptes'], ['Paramètres', null]]], '') ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Tout paramètre « non configuré » est un paramètre en attente de validation par les instances : son défaut technique, affiché ci-dessous, s\'applique en attendant.']) ?>
<?php foreach ($rows as $r): ?>
<?php
$k = $r['key'];
$html = ($r['validated'] ? ui('badge', ['label' => 'Validé', 'kind' => 'ok']) : ui('badge', ['label' => 'Non configuré', 'kind' => 'warn']))
    . '<p>' . e($r['validated'] ? 'Valeur validée (décision ' . $r['decision_ref'] . ') :' : 'Défaut technique appliqué :') . '</p>' . $show($r);
if ($validate) {
    $again = $errorKey === $k ? $entered : ($r['type'] === 'map' ? [] : '');
    $html .= '<form method="post" action="/admin/espace/parametres/' . e($k) . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">';
    if ($r['type'] === 'map') {
        $html .= '<fieldset class="choice-group"><legend>Niveau décidé pour chaque statut</legend><p class="field-hint">' . e($r['hint']) . '</p>';
        foreach ($statuses as $code => $label) {
            $html .= ui('field', ['name' => "status_access[$code]", 'id' => "f-status-$code", 'label' => $label, 'type' => 'select', 'required' => true,
                'options' => ['' => 'Choisir un niveau'] + $levels, 'value' => is_array($again) ? (string) ($again[$code] ?? '') : '']);
        }
        $html .= '</fieldset>';
    } elseif ($r['type'] === 'bool') {
        $html .= ui('field', ['name' => "value_$k", 'label' => 'Valeur décidée', 'type' => 'select', 'required' => true, 'hint' => $r['hint'],
            'options' => ['' => 'Choisir', 'oui' => 'Oui', 'non' => 'Non'], 'value' => is_string($again) ? $again : '']);
    } else {
        $html .= ui('field', ['name' => "value_$k", 'label' => 'Valeur décidée', 'required' => true, 'hint' => $r['hint'], 'inputmode' => 'numeric',
            'value' => is_string($again) ? $again : '', 'attrs' => ['maxlength' => 2]]);
    }
    $html .= ui('field', ['name' => "decision_ref_$k", 'label' => 'Référence de la décision', 'required' => true, 'attrs' => ['maxlength' => 100]])
        . ui('button', ['label' => 'Valider', 'type' => 'submit']) . '</form>';
}
?>
<?= ui('panel', ['title' => $r['label']], $html) ?>
<?php endforeach; ?>
