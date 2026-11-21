<?php declare(strict_types=1);
/** @var string $base @var ?array<string,mixed> $row @var array<string,string> $values @var array<string,string> $errors @var string $errorSummary @var bool $locked @var string $publication @var string $csrfToken */
$edit = $row !== null;
$err = static fn (string $k): string => $errors[$k] ?? '';
$lock = $locked ? ['attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier la bannière' : 'Nouvelle bannière', 'subtitle' => $edit ? (string) $row['title'] : '', 'breadcrumbs' => [['Bannières', $base], [$edit ? 'Modifier' : 'Nouvelle']]]) ?>
<?= $errorSummary ?>
<?php if ($locked): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Cette bannière est publiée : seul un publicateur peut la modifier.']) ?><?php endif; ?>
<form method="post" action="<?= e($edit ? $base . '/' . $row['id'] : $base . '/nouveau') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Bannière'], '<div class="form-grid">'
    . ui('field', array_replace_recursive(['name' => 'title', 'label' => 'Texte', 'type' => 'textarea', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $err('title'), 'hint' => '160 caractères au maximum, en texte simple.', 'attrs' => ['maxlength' => 160]], $lock))
    . ui('field', array_replace_recursive(['name' => 'link_url', 'label' => 'Lien', 'value' => $values['link_url'] ?? '', 'error' => $err('link_url'), 'hint' => 'Facultatif : https://…, /page-du-site, mailto: ou tel:.', 'attrs' => ['maxlength' => 255, 'autocapitalize' => 'none']], $lock))
    . ui('field', array_replace_recursive(['name' => 'starts_at', 'label' => 'Début d\'affichage (heure d\'Abidjan)', 'type' => 'datetime-local', 'value' => $values['starts_at'] ?? '', 'error' => $err('starts_at'), 'hint' => 'Vide : dès la publication.'], $lock))
    . ui('field', array_replace_recursive(['name' => 'ends_at', 'label' => 'Fin d\'affichage (heure d\'Abidjan)', 'type' => 'datetime-local', 'value' => $values['ends_at'] ?? '', 'error' => $err('ends_at'), 'hint' => 'Vide : sans limite.'], $lock))
    . '</div>') ?>
<?php if ($locked): foreach (['title', 'link_url', 'starts_at', 'ends_at'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<div class="form-actions form-actions-sticky">
<?php if (!$locked): ?><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button><?php endif; ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ */ ?>
</form>
<?= $publication ?>
