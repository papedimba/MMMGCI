<?php declare(strict_types=1);
/** @var string $base @var ?array<string,mixed> $row @var array<string,string> $values @var array<string,string> $errors @var string $errorSummary @var bool $locked @var bool $mayPublish @var bool $enabled @var bool $hasConsent
 * @var string $publication @var string $csrfToken */
$edit = $row !== null;
$err = static fn (string $k): string => $errors[$k] ?? '';
$lock = $locked ? ['attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier le témoignage' : 'Nouveau témoignage', 'subtitle' => $edit ? (string) $row['author_name'] : '', 'breadcrumbs' => [['Témoignages', $base], [$edit ? 'Modifier' : 'Nouveau']]]) ?>
<?= $errorSummary ?>
<?php if (!$enabled): ?><?= ui('alert', ['kind' => 'warn', 'message' => 'Les témoignages sont désactivés : le réglage « site.testimonials_enabled » n\'est pas validé par les instances. Un témoignage peut être rédigé, pas publié.']) ?><?php endif; ?>
<?php if ($locked): ?><?= ui('alert', ['kind' => 'info', 'message' => 'Ce témoignage est publié : seul un publicateur peut le modifier.']) ?><?php endif; ?>
<form method="post" action="<?= e($edit ? $base . '/' . $row['id'] : $base . '/nouveau') ?>" class="page-form" enctype="multipart/form-data">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= ui('panel', ['title' => 'Témoignage'], '<div class="form-grid">'
    . ui('field', array_replace_recursive(['name' => 'body', 'label' => 'Témoignage', 'type' => 'textarea', 'value' => $values['body'] ?? '', 'required' => true, 'error' => $err('body'), 'hint' => '600 caractères au maximum, en texte simple.', 'attrs' => ['maxlength' => 600]], $lock))
    . ui('field', array_replace_recursive(['name' => 'author_name', 'label' => 'Nom de la personne', 'value' => $values['author_name'] ?? '', 'required' => true, 'error' => $err('author_name'), 'attrs' => ['maxlength' => 80]], $lock))
    . ui('field', array_replace_recursive(['name' => 'author_role', 'label' => 'Fonction', 'value' => $values['author_role'] ?? '', 'error' => $err('author_role'), 'attrs' => ['maxlength' => 80]], $lock))
    . '</div>') ?>
<?php if (!$locked): ?>
<?= ui('panel', ['title' => 'Consentement'], '<p class="field-hint">Document signé (PDF, JPG ou PNG) conservé sur le disque privé : jamais publié sur le site. '
    . ($hasConsent ? 'Un fichier est déjà enregistré ; en choisir un autre le remplace.' : 'Obligatoire pour publier.') . '</p>'
    . ui('file-input', ['name' => 'consent', 'label' => 'Fichier de consentement', 'accept' => '.pdf,.jpg,.jpeg,.png', 'error' => $err('consent'), 'max_mb' => 5])
    . ($hasConsent && $mayPublish ? '<p><a class="btn btn-secondary" href="' . e($base . '/' . $row['id'] . '/consentement') . '"><span>Télécharger le consentement</span></a></p>' : '')) ?>
<?php else: foreach (['body', 'author_name', 'author_role'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<div class="form-actions form-actions-sticky">
<?php if (!$locked): ?><button class="btn btn-primary" type="submit"><span>Enregistrer</span></button><?php endif; ?>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ */ ?>
</form>
<?= $publication ?>
