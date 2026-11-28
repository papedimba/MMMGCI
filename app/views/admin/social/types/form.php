<?php declare(strict_types=1);
/** @var int $id @var ?array $type @var array $values @var ?string $error @var array $kinds @var string $csrfToken */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$crumb = [['Types de demande', '/admin/demandes-sociales/types'], [$id > 0 ? 'Paramètres' : 'Nouveau', null]];
?>
<?= ui('page-header', ['title' => $id > 0 ? 'Paramètres : ' . $type['name'] : 'Nouveau type de demande',
    'subtitle' => 'Saisissez uniquement ce que les instances ont décidé, avec la référence de la décision. Un champ vide reste non configuré.', 'breadcrumbs' => $crumb], '') ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?php if ($id === 0): ?>
<form method="post" action="/admin/demandes-sociales/types/nouveau">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Type'],
    ui('field', ['name' => 'code', 'label' => 'Code', 'value' => $v('code'), 'required' => true, 'attrs' => ['maxlength' => 20], 'hint' => 'Minuscules, chiffres et tiret bas.'])
    . ui('field', ['name' => 'name', 'label' => 'Libellé', 'value' => $v('name'), 'required' => true, 'attrs' => ['maxlength' => 80]])
    . ui('field', ['name' => 'action_text', 'label' => 'Action', 'value' => $v('action_text'), 'required' => true, 'attrs' => ['maxlength' => 255]])
    . ui('field', ['name' => 'decision_ref', 'label' => 'Référence de la décision', 'value' => $v('decision_ref'), 'required' => true, 'attrs' => ['maxlength' => 60]])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Ajouter', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/demandes-sociales/types']) ?>
</div>
</form>
<?php else: ?>
<?php if ($type['params_decision_ref'] !== null): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Dernière validation : décision ' . $type['params_decision_ref'] . '.']) ?><?php endif; ?>
<form method="post" action="/admin/demandes-sociales/types/<?= (int) $id ?>/parametres">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php $kindChecks = ''; foreach ($kinds as $k => $label): $kindChecks .= '<label class="check"><input type="checkbox" name="allowed_aid_kinds[]" value="' . e($k) . '"' . (in_array($k, (array) ($values['allowed_aid_kinds'] ?? []), true) ? ' checked' : '') . '><span>' . e($label) . '</span></label>'; endforeach; ?>
<?= ui('panel', ['title' => 'Paramètres décidés par les instances'],
    ui('field', ['name' => 'criteria_text', 'label' => 'Critères d\'éligibilité', 'type' => 'textarea', 'value' => $v('criteria_text')])
    . ui('field', ['name' => 'ceiling_amount', 'label' => 'Plafond (XOF)', 'value' => $v('ceiling_amount'), 'inputmode' => 'decimal', 'hint' => 'Vide = non configuré (aucun plafond appliqué).'])
    . ui('field', ['name' => 'waiting_period_days', 'label' => 'Délai de carence (jours)', 'value' => $v('waiting_period_days'), 'inputmode' => 'numeric', 'hint' => 'Information affichée à l\'examen : la carence ne bloque jamais une demande, même une fois validée.'])
    . ui('field', ['name' => 'deciding_body', 'label' => 'Organe compétent', 'value' => $v('deciding_body'), 'attrs' => ['maxlength' => 160]])
    . '<fieldset class="field"><legend>Sortes d\'aide autorisées</legend><p class="field-hint">Aucune case cochée = non configuré (aucune restriction).</p>' . $kindChecks . '</fieldset>'
    . ui('field', ['name' => 'decision_ref', 'label' => 'Référence de la décision', 'value' => $v('decision_ref'), 'required' => true, 'attrs' => ['maxlength' => 60], 'hint' => 'Obligatoire : procès-verbal ou décision des instances.'])) ?>
<div class="form-actions">
<?= ui('button', ['label' => 'Valider les paramètres', 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'secondary', 'href' => '/admin/demandes-sociales/types']) ?>
</div>
</form>
<form method="post" action="/admin/demandes-sociales/types/<?= (int) $id ?>/activation">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="active" value="<?= $type['is_active'] ? '0' : '1' ?>">
<?= ui('panel', ['title' => $type['is_active'] ? 'Désactiver ce type' : 'Réactiver ce type'],
    '<p>' . ($type['is_active'] ? 'Un type désactivé n\'est plus proposé pour de nouvelles demandes ; les demandes existantes le conservent.' : 'Le type sera de nouveau proposé pour les nouvelles demandes.') . '</p>'
    . ($type['is_active'] ? '' : ui('field', ['name' => 'decision_ref', 'id' => 'f-activation-ref', 'label' => 'Référence de la décision', 'required' => true, 'attrs' => ['maxlength' => 60]]))) ?>
<div class="form-actions"><?php if ($type['is_active']): ?>
<button class="btn btn-secondary" type="submit" data-confirm-open="confirm-desactivation"><span>Désactiver</span></button>
<?php else: ?><?= ui('button', ['label' => 'Réactiver', 'type' => 'submit', 'variant' => 'secondary']) ?><?php endif; ?></div>
</form>
<?php if ($type['is_active']): ?><?= ui('confirm-dialog', ['id' => 'confirm-desactivation', 'title' => 'Désactiver ce type ?', 'message' => 'Il ne sera plus proposé pour de nouvelles demandes ; les demandes existantes le conservent. La décision est consignée au journal.',
    'confirm_label' => 'Désactiver', 'form_action' => '/admin/demandes-sociales/types/' . (int) $id . '/activation', 'csrf' => $csrfToken, 'fields' => ['active' => '0'],
    'extra_html' => ui('field', ['name' => 'decision_ref', 'id' => 'f-activation-ref', 'label' => 'Référence de la décision', 'required' => true, 'attrs' => ['maxlength' => 60]])]) ?><?php endif; ?>
<?php endif; ?>
