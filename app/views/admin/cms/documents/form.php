<?php declare(strict_types=1);
/** @var string $base @var ?array<string,mixed> $row @var array<string,string> $values @var array<string,string> $errors @var string $errorSummary @var bool $locked @var string $publication @var string $csrfToken
 * @var list<string> $categories @var array<int|string,string> $files */
$edit = $row !== null;
$err = static fn (string $k): string => $errors[$k] ?? '';
$lock = $locked ? ['attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier le document' : 'Nouveau document officiel', 'subtitle' => $edit ? (string) $row['title'] : '', 'breadcrumbs' => [['Documents officiels', $base], [$edit ? (string) $row['title'] : 'Nouveau']]]) ?>
<?= $errorSummary ?>
<?php if ($locked): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Ce document est publié : seul un publicateur peut le modifier.']) ?><?php endif; ?>
<form method="post" action="<?= e($edit ? $base . '/' . $row['id'] : $base . '/nouveau') ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Document'], '<div class="form-grid">'
    . ui('field', array_replace_recursive(['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $err('title'), 'attrs' => ['maxlength' => 200]], $lock))
    . ui('field', array_replace_recursive(['name' => 'category', 'label' => 'Catégorie', 'value' => $values['category'] ?? '', 'error' => $err('category'), 'hint' => 'Texte libre (80 caractères) ; les catégories déjà utilisées sont proposées.',
        'attrs' => ['maxlength' => 80, 'list' => 'categories-existantes']], $lock))
    . ui('field', array_replace_recursive(['name' => 'document_date', 'label' => 'Date du document', 'type' => 'date', 'value' => $values['document_date'] ?? '', 'required' => true, 'error' => $err('document_date')], $lock))
    . ui('field', array_replace_recursive(['name' => 'media_id', 'label' => 'Fichier', 'type' => 'select', 'value' => $values['media_id'] ?? '', 'options' => $files, 'required' => true, 'error' => $err('media_id'),
        'hint' => 'PDF, JPG ou PNG de la médiathèque (Site web › Médias). Le fichier est public dès que le document est publié.'], $lock))
    . '</div>') ?>
<datalist id="categories-existantes"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<?php if ($locked): foreach (['title', 'category', 'document_date', 'media_id'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<div class="form-actions form-actions-sticky">
<?php if (!$locked): ?><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button><?php endif; ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ : son absence révèle un envoi tronqué */ ?>
</form>
<?= $publication ?>
