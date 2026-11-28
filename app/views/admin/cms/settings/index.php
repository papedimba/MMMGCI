<?php declare(strict_types=1);
/**
 * @var list<array<string,mixed>> $rows @var array<string,string> $entered @var array<string,string> $errors @var string $error @var bool $canManage @var bool $canValidate
 * @var string $base @var string $csrfToken
 */
$show = static fn (array $r): string => $r['value'] === null ? '' : ($r['type'] === 'bool' ? ($r['value'] ? 'true' : 'false') : (string) $r['value']);
$label = static fn (array $r): string => $r['value'] === null ? '—' : ($r['type'] === 'bool' ? ($r['value'] ? 'Oui' : 'Non') : (string) $r['value']);
?>
<?= ui('page-header', ['title' => 'Réglages du site', 'subtitle' => 'Coordonnées, réseaux et décisions utilisés par le site public.']) ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Une valeur saisie reste en attente : le site public ne l\'utilise qu\'une fois validée par les instances (référence de décision obligatoire). Modifier une valeur déjà validée la remet en attente et la retire du site jusqu\'à une nouvelle validation.']) ?>
<?= $error !== '' ? ui('alert', ['kind' => 'danger', 'message' => $error]) : '' ?>
<?php foreach ($rows as $r):
    $k = $r['key'];
    $valid = $r['status'] === 'valide';
    $badge = $valid ? ui('badge', ['kind' => 'ok', 'label' => 'Validé']) : ($r['value'] === null ? ui('badge', ['kind' => 'neutral', 'label' => 'Non renseigné']) : ui('badge', ['kind' => 'warn', 'label' => 'En attente de validation']));
    $input = ['name' => $k, 'label' => $r['label'], 'hint' => $r['hint'], 'error' => $errors[$k] ?? '', 'value' => $entered[$k] ?? $show($r)];
    if ($r['type'] === 'bool') {
        $input += ['type' => 'select', 'options' => ['' => 'Non défini', 'true' => 'Oui', 'false' => 'Non']];
    } elseif (in_array($r['type'], ['email', 'tel', 'url', 'textarea'], true)) {
        $input += ['type' => $r['type']];
    }
    $input['attrs'] = ['autocapitalize' => 'none'] + ($r['type'] === 'textarea' ? ['maxlength' => 4000] : ['maxlength' => 300]);
    $body = '<p>Valeur actuelle : <strong>' . e($label($r)) . '</strong>'
        . ($valid ? ' <span class="muted">Validée' . ($r['decision_ref'] ? ' (décision : ' . e($r['decision_ref']) . ')' : '') . '</span>' : '') . '</p>';
    if ($canManage) {
        $body .= '<form method="post" action="' . e($base . '/' . $k) . '" class="page-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
            . ui('field', $input) . '<div class="form-actions"><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button></div></form>';
    }
    if ($canValidate && !$valid && $r['value'] !== null) {
        $body .= '<form method="post" action="' . e($base . '/' . $k . '/validation') . '" class="page-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
            . '<input type="hidden" name="seen_' . e($k) . '" value="' . e($r['seen']) . '">'
            . ui('field', ['name' => 'decision_ref_' . $k, 'label' => 'Référence de la décision des instances', 'required' => true, 'attrs' => ['maxlength' => 100]])
            . '<div class="form-actions"><button class="btn btn-secondary" type="submit"><span>Valider ce réglage</span></button></div></form>';
    }
?>
<div id="r-<?= e($k) ?>"><?= ui('panel', ['title' => $r['label'], 'actions' => $badge], $body) ?></div>
<?php endforeach; ?>
