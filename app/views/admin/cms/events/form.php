<?php declare(strict_types=1);
/** @var string $base @var ?array<string,mixed> $event @var array<string,string> $values @var array<string,string> $errors @var string $errorSummary @var array<int|string,string> $images
 * @var bool $mayPublish @var bool $locked @var list<string> $transitions @var string $csrfToken */
use App\Modules\Cms\EventService;

$edit = $event !== null;
$err = static fn (string $k): string => $errors[$k] ?? '';
$labels = EventService::STATUS_LABELS;
$lock = $locked ? ['attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier l\'évènement' : 'Nouvel évènement', 'subtitle' => $edit ? (string) $event['title'] : '', 'breadcrumbs' => [['Évènements', $base], [$edit ? (string) $event['title'] : 'Nouvel évènement']]]) ?>
<?= $errorSummary ?>
<?php if ($locked): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Cet évènement a déjà été en ligne : seul un publicateur peut le modifier (la modification est visible tout de suite).']) ?><?php endif; ?>
<?php if ($edit && $mayPublish && $event['published_by'] !== null): ?><?= ui('alert', ['kind' => 'warn', 'message' => 'Cet évènement a déjà été en ligne : vos modifications sont visibles du public dès l\'enregistrement s\'il est publié.']) ?><?php endif; ?>
<form method="post" action="<?= e($edit ? $base . '/' . $event['id'] : $base . '/nouveau') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Évènement'], '<div class="form-grid">'
    . ui('field', array_replace_recursive(['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $err('title'), 'attrs' => ['maxlength' => 200]], $lock))
    . ui('field', array_replace_recursive(['name' => 'slug', 'label' => 'Adresse', 'value' => $values['slug'] ?? '', 'error' => $err('slug'),
        'hint' => 'Minuscules, chiffres et tirets. Laissée vide, elle est proposée à partir du titre.', 'attrs' => ['maxlength' => 80, 'autocapitalize' => 'none', 'spellcheck' => 'false']], $lock))
    . ui('field', array_replace_recursive(['name' => 'starts_at', 'label' => 'Début (heure d\'Abidjan)', 'type' => 'datetime-local', 'value' => $values['starts_at'] ?? '', 'required' => true, 'error' => $err('starts_at')], $lock))
    . ui('field', array_replace_recursive(['name' => 'ends_at', 'label' => 'Fin (heure d\'Abidjan)', 'type' => 'datetime-local', 'value' => $values['ends_at'] ?? '', 'error' => $err('ends_at'), 'hint' => 'Facultative ; jamais avant le début.'], $lock))
    . ui('field', array_replace_recursive(['name' => 'location', 'label' => 'Lieu', 'value' => $values['location'] ?? '', 'error' => $err('location'), 'attrs' => ['maxlength' => 160]], $lock))
    . ui('field', array_replace_recursive(['name' => 'featured_media_id', 'label' => 'Image', 'type' => 'select', 'value' => $values['featured_media_id'] ?? '', 'options' => $images, 'error' => $err('featured_media_id'),
        'hint' => 'Images de la médiathèque (Site web › Médias).'], $lock))
    . '</div>' . ($locked ? '' : ui('rich-editor', ['name' => 'description', 'label' => 'Description', 'value' => $values['description'] ?? '', 'error' => $err('description')]))) ?>
<?php if ($locked): foreach (['title', 'slug', 'starts_at', 'ends_at', 'location', 'featured_media_id', 'description'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<div class="form-actions form-actions-sticky">
<?php if (!$locked): ?><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button><?php endif; ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ */ ?>
</form>
<?php if ($edit): ?>
<?php
$tl = ['en_relecture' => 'Soumettre pour relecture', 'publie' => 'Publier', 'archive' => 'Archiver',
    'brouillon' => ['en_relecture' => 'Retirer de la relecture', 'publie' => 'Dépublier', 'programme' => 'Annuler la programmation', 'archive' => 'Restaurer en brouillon'][$event['status']] ?? 'Brouillon'];
$forms = '';
foreach ($transitions as $to) {
    $f = '<form method="post" action="' . e($base . '/' . $event['id'] . '/etat') . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="' . e($to) . '"><input type="hidden" name="version" value="' . (int) $event['content_version'] . '">';
    if ($to === 'programme') {
        $f .= ui('field', ['name' => 'at', 'label' => 'Date et heure de publication (heure d\'Abidjan)', 'type' => 'datetime-local', 'required' => true]) . ui('button', ['label' => 'Programmer', 'type' => 'submit', 'variant' => 'secondary']);
    } else {
        $f .= ui('button', ['label' => $tl[$to], 'type' => 'submit', 'variant' => $to === 'publie' ? 'primary' : 'secondary']);
    }
    $forms .= $f . '</form>';
}
$state = '<p>État : <strong>' . e($labels[$event['status']] ?? (string) $event['status']) . '</strong>'
    . ($event['status'] === 'programme' && $event['published_at'] !== null ? ' — publication prévue le ' . e((new DateTimeImmutable((string) $event['published_at']))->format('d/m/Y à H:i')) . ' (heure d\'Abidjan)' : '')
    . '.</p>' . (!$mayPublish ? '<p class="field-hint">La publication est réservée aux publicateurs : soumettez l\'évènement pour relecture.</p>' : '');
?>
<?= ui('panel', ['title' => 'Publication'], $state . ($forms !== '' ? '<div class="transition-list">' . $forms . '</div>' : '')) ?>
<?php if ($event['published_by'] === null && in_array($event['status'], ['brouillon', 'en_relecture'], true)): ?>
<form method="post" action="<?= e($base . '/' . $event['id'] . '/suppression') ?>" class="delete-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer cet évènement</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer cet évènement ?', 'message' => 'Cet évènement n\'a jamais été publié ; il sera effacé définitivement.',
    'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $event['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
<?php endif; ?>
