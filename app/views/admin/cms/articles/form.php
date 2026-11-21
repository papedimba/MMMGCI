<?php declare(strict_types=1);
/**
 * Formulaire d'article, utilisable sans JavaScript. @var ?array<string,mixed> $article @var array<string,string> $values @var array<string,string> $errors
 * @var array<int|string,string> $categories @var array<int,string> $images @var bool $mayPublish @var list<string> $transitions @var ?array<string,mixed> $live
 * @var list<array<string,mixed>> $history @var string $csrfToken
 */
use App\Modules\Cms\ArticleService;

$base = '/admin/site/articles';
$edit = $article !== null;
$action = $edit ? $base . '/' . $article['id'] : $base . '/nouveau';
$labels = ArticleService::STATUS_LABELS;
$kinds = ['brouillon' => 'neutral', 'en_relecture' => 'warn', 'publie' => 'ok', 'programme' => 'info', 'archive' => 'neutral'];
$fieldLabels = ['title' => 'Titre', 'slug' => 'Adresse', 'body' => 'Contenu', 'excerpt' => 'Extrait', 'featured_media_id' => 'Image à la une', 'og_media_id' => 'Image de partage',
    'category_id' => 'Catégorie', 'tags' => 'Étiquettes', 'author_label' => 'Auteur affiché', 'seo_title' => 'Titre SEO', 'seo_description' => 'Description SEO', 'canonical_url' => 'Adresse canonique', 'form' => 'Article'];
$imageOptions = ['' => '— Aucune —'] + $images;
$err = static fn (string $k): string => $errors[$k] ?? '';
$errorList = '';
foreach ($errors as $key => $msg) {
    $errorList .= '<li>' . ($key === 'form' ? e($msg) : '<a href="#f-' . e($key) . '">' . e($msg) . '</a>') . '</li>';
}
// Article déjà en ligne : adresse, catégorie, étiquettes et auteur changent le site public sans publication, donc réservés aux publicateurs.
$locked = $edit && $article['published_by'] !== null && !$mayPublish;
$lock = $locked ? ['hint' => 'Article déjà en ligne : modifiable par un publicateur seulement.', 'attrs' => ['disabled' => true]] : [];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier l\'article' : 'Nouvel article', 'subtitle' => $edit ? (string) $article['title'] : '',
    'breadcrumbs' => [['Articles', $base], [$edit ? (string) $article['title'] : 'Nouvel article']]],
    $edit ? ui('button', ['label' => 'Aperçu', 'href' => $base . '/' . $article['id'] . '/apercu', 'variant' => 'secondary', 'icon' => 'eye']) : '') ?>
<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert"><?= ui_icon('alert') ?><div><strong class="alert-title"><?= count($errors) > 1 ? 'Corrigez les points suivants' : 'Corrigez le point suivant' ?></strong><ul class="error-list"><?= $errorList ?></ul></div></div>
<?php endif; ?>
<?php if ($edit): ?>
<p class="page-state"><?= ui('badge', ['kind' => $kinds[$article['status']] ?? 'neutral', 'label' => $labels[$article['status']] ?? (string) $article['status']]) ?>
<?php if ($live !== null && $article['status'] !== 'publie'): ?> <?= ui('badge', ['kind' => 'ok', 'label' => 'Une version est en ligne']) ?><?php endif; ?></p>
<?php if ($article['published_body'] !== null): ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Cet article est en ligne : le public voit la dernière version publiée. Vos modifications restent en brouillon jusqu\'à ce qu\'un publicateur republie l\'article.']) ?>
<?php endif; ?>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php if ($locked): foreach (['slug', 'category_id', 'tags', 'author_label'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($values[$k] ?? '') ?>">
<?php endforeach; endif; ?>
<?= ui('panel', ['title' => 'Article'], '<div class="form-grid">'
    . ui('field', ['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $err('title'), 'attrs' => ['maxlength' => 200]])
    . ui('field', array_replace_recursive(['name' => 'slug', 'label' => 'Adresse', 'value' => $values['slug'] ?? '', 'error' => $err('slug'),
        'hint' => 'Minuscules, chiffres et tirets. Laissée vide, elle est proposée à partir du titre.', 'attrs' => ['maxlength' => 80, 'autocapitalize' => 'none', 'spellcheck' => 'false']], $lock))
    . ui('field', array_replace_recursive(['name' => 'category_id', 'label' => 'Catégorie', 'type' => 'select', 'value' => $values['category_id'] ?? '', 'options' => $categories, 'error' => $err('category_id')], $lock))
    . ui('field', array_replace_recursive(['name' => 'tags', 'label' => 'Étiquettes', 'value' => $values['tags'] ?? '', 'error' => $err('tags'),
        'hint' => 'Séparées par des virgules (10 au plus).', 'attrs' => ['maxlength' => 600]], $lock))
    . ui('field', array_replace_recursive(['name' => 'author_label', 'label' => 'Auteur affiché', 'value' => $values['author_label'] ?? '', 'error' => $err('author_label'),
        'hint' => 'Vide : le nom du rédacteur est affiché.', 'attrs' => ['maxlength' => 80]], $lock))
    . '</div>') ?>
<?= ui('panel', ['title' => 'Contenu'], ui('rich-editor', ['name' => 'body', 'label' => 'Contenu', 'value' => $values['body'] ?? '', 'error' => $err('body')])
    . ui('field', ['name' => 'excerpt', 'label' => 'Extrait', 'type' => 'textarea', 'value' => $values['excerpt'] ?? '', 'error' => $err('excerpt'),
        'hint' => '300 caractères au maximum, en texte simple. Vide : le début du contenu est utilisé à l\'affichage.', 'attrs' => ['maxlength' => 300]])
    . ui('field', ['name' => 'featured_media_id', 'label' => 'Image à la une', 'type' => 'select', 'value' => $values['featured_media_id'] ?? '', 'options' => $imageOptions, 'error' => $err('featured_media_id'),
        'hint' => 'Images de la médiathèque (Site web › Médias).'])) ?>
<?= ui('panel', ['title' => 'Référencement'], '<div class="form-grid">'
    . ui('field', ['name' => 'seo_title', 'label' => 'Titre SEO', 'value' => $values['seo_title'] ?? '', 'error' => $err('seo_title'), 'hint' => '70 caractères au maximum ; vide = titre de l\'article.', 'attrs' => ['maxlength' => 70]])
    . ui('field', ['name' => 'seo_description', 'label' => 'Description SEO', 'type' => 'textarea', 'value' => $values['seo_description'] ?? '', 'error' => $err('seo_description'), 'hint' => '160 caractères au maximum.', 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'og_media_id', 'label' => 'Image de partage (réseaux sociaux)', 'type' => 'select', 'value' => $values['og_media_id'] ?? '', 'options' => $imageOptions, 'error' => $err('og_media_id')])
    . ui('field', ['name' => 'canonical_url', 'label' => 'Adresse canonique', 'type' => 'url', 'value' => $values['canonical_url'] ?? '', 'error' => $err('canonical_url'), 'hint' => 'Facultatif : https://… si ce contenu existe ailleurs.', 'attrs' => ['maxlength' => 255]])
    . '</div>') ?>
<div class="form-actions form-actions-sticky">
<button class="btn btn-primary" type="submit"><span>Enregistrer</span></button>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ : son absence révèle un envoi tronqué par max_input_vars */ ?>
</form>
<?php if ($edit): ?>
<?php
$transitionLabels = ['en_relecture' => 'Soumettre pour relecture', 'publie' => $article['published_body'] !== null ? 'Republier' : 'Publier', 'archive' => 'Archiver',
    'brouillon' => ['en_relecture' => 'Retirer de la relecture', 'publie' => 'Dépublier', 'programme' => 'Annuler la programmation', 'archive' => 'Restaurer en brouillon'][$article['status']] ?? 'Brouillon'];
$forms = '';
$stateUrl = e($base . '/' . $article['id'] . '/etat');
foreach ($transitions as $to) {
    $form = '<form method="post" action="' . $stateUrl . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="' . e($to) . '"><input type="hidden" name="version" value="' . (int) $article['content_version'] . '">';
    if ($to === 'programme') {
        $form .= ui('field', ['name' => 'at', 'label' => 'Date et heure de publication (heure d\'Abidjan)', 'type' => 'datetime-local', 'required' => true]) . ui('button', ['label' => 'Programmer', 'type' => 'submit', 'variant' => 'secondary']);
    } else {
        $form .= ui('button', ['label' => $transitionLabels[$to], 'type' => 'submit', 'variant' => $to === 'publie' ? 'primary' : 'secondary']);
    }
    $forms .= $form . '</form>';
}
if ($mayPublish && $article['published_body'] !== null && in_array($article['status'], ['brouillon', 'en_relecture', 'programme'], true)) {
    $forms .= '<form method="post" action="' . $stateUrl . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="retirer">' . ui('button', ['label' => 'Retirer la version en ligne', 'type' => 'submit', 'variant' => 'danger']) . '</form>';
}
$state = '<p>État : <strong>' . e($labels[$article['status']] ?? (string) $article['status']) . '</strong>'
    . ($article['status'] === 'programme' && $article['published_at'] !== null ? ' — publication prévue le ' . e((new DateTimeImmutable((string) $article['published_at']))->format('d/m/Y à H:i')) . ' (heure d\'Abidjan)' : '')
    . '.</p>' . (!$mayPublish ? '<p class="field-hint">La publication est réservée aux publicateurs : soumettez l\'article pour relecture.</p>' : '');
?>
<?= ui('panel', ['title' => 'Publication'], $state . ($forms !== '' ? '<div class="transition-list">' . $forms . '</div>' : '')) ?>
<?php if ($article['published_by'] === null && in_array($article['status'], ['brouillon', 'en_relecture'], true)): ?>
<form method="post" action="<?= e($base . '/' . $article['id'] . '/suppression') ?>" class="delete-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer cet article</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer cet article ?', 'message' => 'Ce brouillon n\'a jamais été publié ; il sera effacé définitivement.',
    'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $article['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
<?php
$actions = ['cms.article_created' => 'Création', 'cms.article_updated' => 'Modification', 'cms.article_submitted' => 'Soumis pour relecture', 'cms.article_withdrawn' => 'Retiré de la relecture',
    'cms.article_published' => 'Publié', 'cms.article_scheduled' => 'Programmé', 'cms.article_unpublished' => 'Dépublié', 'cms.article_archived' => 'Archivé',
    'cms.article_restored' => 'Restauré en brouillon', 'cms.scheduled_published' => 'Publié automatiquement (programmation)'];
$items = '';
foreach ($history as $h) {
    $new = json_decode((string) ($h['new_values'] ?? ''), true) ?: [];
    $detail = isset($new['at']) ? ' pour le ' . (new DateTimeImmutable((string) $new['at']))->format('d/m/Y à H:i') : '';
    $items .= '<li><time datetime="' . e((string) $h['created_at']) . '">' . e((new DateTimeImmutable((string) $h['created_at']))->format('d/m/Y H:i')) . '</time> — '
        . e(($actions[$h['action']] ?? $h['action']) . $detail) . ' — ' . e((string) ($h['user_name'] ?? 'système')) . '</li>';
}
?>
<?= ui('panel', ['title' => 'Historique'], $items === '' ? '<p>Aucun évènement.</p>' : '<ul class="history-list">' . $items . '</ul>') ?>
<?php endif; ?>
