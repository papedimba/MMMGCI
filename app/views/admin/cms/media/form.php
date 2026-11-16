<?php declare(strict_types=1);
/** @var ?array<string,mixed> $media @var list<array{type:string,id:int,label:string}> $usages @var ?string $error @var array<string,string> $values @var list<string> $folders @var string $csrfToken */
$base = '/admin/site/medias';
$edit = $media !== null;
$labels = ['page' => 'Page', 'article' => 'Article', 'evenement' => 'Évènement', 'galerie' => 'Galerie', 'banniere' => 'Bannière', 'document' => 'Document', 'menu' => 'Menu'];
$isImage = !$edit || $media['is_image'];
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier le média' : 'Ajouter un média', 'breadcrumbs' => [['Médias', $base], [$edit ? (string) $media['title'] : 'Ajouter']]], '') ?>
<?php if ($error !== null): ?>
<?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?>
<?php endif; ?>
<form method="post" action="<?= e($edit ? $base . '/' . $media['id'] : $base . '/nouveau') ?>"<?= $edit ? '' : ' enctype="multipart/form-data"' ?>>
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?php
$fields = '';
if ($edit) {
    $fields .= '<div class="media-preview">' . ($media['is_image']
        ? '<img src="' . e($media['url']) . '" alt="' . e((string) $media['alt']) . '"' . ($media['width'] ? ' width="' . (int) $media['width'] . '" height="' . (int) $media['height'] . '"' : '') . ' loading="lazy">'
        : '<a href="' . e($media['url']) . '">' . ui_icon('file') . ' Ouvrir le document</a>') . '<p class="field-hint">Adresse publique : ' . e($media['url']) . '</p></div>';
} else {
    $fields .= ui('file-input', ['name' => 'fichier', 'label' => 'Fichier', 'required' => true, 'accept' => '.jpg,.jpeg,.png,.webp,.pdf', 'hint' => 'JPG, PNG, WebP ou PDF. Le nom du fichier n\'est pas conservé.', 'max_mb' => 5]);
}
$fields .= '<div class="form-grid">'
    . ui('field', ['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'alt', 'label' => 'Texte alternatif', 'value' => $values['alt'] ?? '', 'required' => $isImage, 'hint' => $isImage ? 'Décrit l\'image pour les lecteurs d\'écran (obligatoire).' : 'Facultatif pour un document.', 'attrs' => ['maxlength' => 255]])
    . ui('field', ['name' => 'caption', 'label' => 'Légende', 'value' => $values['caption'] ?? '', 'attrs' => ['maxlength' => 255]])
    . ui('field', ['name' => 'credit', 'label' => 'Crédit', 'value' => $values['credit'] ?? '', 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'folder', 'label' => 'Dossier', 'value' => $values['folder'] ?? '', 'hint' => 'Minuscules, chiffres et tirets ; vide = racine. Existants : ' . ($folders === [] ? 'aucun' : implode(', ', $folders)) . '.', 'attrs' => ['maxlength' => 60]])
    . '</div>';
?>
<?= ui('panel', ['title' => 'Informations'], $fields . '<div class="form-actions">' . ui('button', ['label' => $edit ? 'Enregistrer' : 'Ajouter', 'type' => 'submit'])
    . ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) . '</div>') ?>
</form>
<?php if ($edit): ?>
<?php
$list = '';
foreach ($usages as $u) {
    $list .= '<li>' . e($labels[$u['type']] ?? $u['type']) . ' : ' . e($u['label']) . '</li>';
}
?>
<?= ui('panel', ['title' => 'Utilisation'], $usages === []
    ? '<p>Ce média n\'est utilisé nulle part : il peut être supprimé.</p>'
    : '<p>Ce média est utilisé ; il ne peut pas être supprimé tant qu\'il l\'est.</p><ul>' . $list . '</ul>') ?>
<?php if ($usages === []): ?>
<form method="post" action="<?= e($base . '/' . $media['id'] . '/suppression') ?>" class="delete-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer ce média</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer ce média ?', 'message' => 'Le fichier sera effacé définitivement.',
    'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $media['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
<?php endif; ?>
