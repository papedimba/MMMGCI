<?php declare(strict_types=1);
/**
 * Formulaire de page avec éditeur de blocs SANS JavaScript obligatoire : chaque bloc est un fieldset ; Monter / Descendre / Supprimer,
 * Ajouter une ligne / Retirer la ligne et Ajouter un bloc sont des boutons d'envoi (name="op") qui réaffichent le formulaire modifié.
 * @var ?array<string,mixed> $page @var array<string,string> $values @var list<array<string,mixed>> $blocks @var array<string,string> $errors
 * @var array<int|string,string> $parents @var array<int|string,string> $pageChoices @var array<int,string> $images @var bool $mayPublish
 * @var list<string> $transitions @var ?array<string,mixed> $live @var list<array<string,mixed>> $history @var string $csrfToken
 */
use App\Modules\Cms\Blocks;
use App\Modules\Cms\PageService;

$base = '/admin/site/pages';
$edit = $page !== null;
$action = $edit ? $base . '/' . $page['id'] : $base . '/nouvelle';
$labels = PageService::STATUS_LABELS;
$kinds = ['brouillon' => 'neutral', 'en_relecture' => 'warn', 'publie' => 'ok', 'programme' => 'info', 'archive' => 'neutral'];
$fieldLabels = ['title' => 'Titre', 'slug' => 'Adresse', 'template' => 'Gabarit', 'parent_id' => 'Page parente', 'excerpt' => 'Extrait',
    'featured_media_id' => 'Image à la une', 'og_media_id' => 'Image de partage', 'seo_title' => 'Titre SEO', 'seo_description' => 'Description SEO',
    'canonical_url' => 'Adresse canonique', 'blocs' => 'Blocs', 'form' => 'Page'];
$imageOptions = ['' => '— Aucune —'] + $images;
$used = []; // erreurs déjà affichées à côté de leur champ

/** Bouton d'opération de l'éditeur : formaction ramène la page sur le bloc concerné ; formnovalidate, car un bloc encore vide ne doit pas bloquer l'opération. */
$opButton = static function (string $op, string $label, string $hidden, int $anchor, bool $disabled = false, string $variant = 'secondary') use ($action): string {
    return '<button class="btn btn-' . $variant . ' btn-sm" type="submit" name="op" value="' . e($op) . '" formaction="' . e($action . '#bloc-' . max(0, $anchor)) . '" formnovalidate'
        . ($disabled ? ' disabled' : '') . '><span>' . e($label) . '<span class="visually-hidden">' . e($hidden) . '</span></span></button>';
};

/** Un champ de bloc selon sa nature (Blocks::FIELDS). */
$blockField = static function (array $spec, string $name, string $path, string $value) use ($errors, &$used, $imageOptions, $pageChoices): string {
    [$kind, $label, $required, $param] = $spec;
    $error = $errors[$path] ?? '';
    if ($error !== '') {
        $used[$path] = true;
    }
    $common = ['name' => $name, 'label' => $label, 'value' => $value, 'error' => $error, 'required' => $required];
    return match ($kind) {
        'html' => ui('rich-editor', $common),
        'text' => ui('field', $common + ['type' => 'textarea', 'attrs' => ['maxlength' => $param]]),
        'media' => ui('field', $common + ['type' => 'select', 'options' => $imageOptions, 'hint' => 'Images de la médiathèque (Site web › Médias).']),
        'page' => ui('field', $common + ['type' => 'select', 'options' => $pageChoices]),
        'url' => ui('field', $common + ['hint' => 'https://…, mailto:…, tel:… ou chemin du site (/contact).', 'attrs' => ['maxlength' => $param]]),
        'int' => ui('field', $common + ['type' => 'number', 'inputmode' => 'numeric', 'hint' => "Entre {$param[0]} et {$param[1]}.", 'attrs' => ['min' => $param[0], 'max' => $param[1]]]),
        'enum' => ui('field', $common + ['type' => 'select', 'options' => $param]),
        default => ui('field', $common + ['attrs' => ['maxlength' => $param]]),
    };
};

$errorList = '';
foreach ($errors as $key => $msg) {
    $isBlock = str_starts_with($key, 'blocs[');
    $target = $isBlock && preg_match('/^blocs\[(\d+)\]/', $key, $m) === 1 ? '#bloc-' . $m[1] : ($key === 'blocs' ? '#blocs' : ($key === 'form' ? '' : '#f-' . $key));
    $where = $isBlock ? Blocks::describePath($key, $blocks) : ($fieldLabels[$key] ?? $key);
    // Blocs : chemin lisible puis message court ; champs de la page : le message nomme déjà le champ.
    $text = $isBlock ? $where . ' : ' . $msg : $msg;
    $errorList .= '<li>' . ($target !== '' ? '<a href="' . e($target) . '">' . e($text) . '</a>' : e($text)) . '</li>';
}
?>
<?= ui('page-header', ['title' => $edit ? 'Modifier la page' : 'Nouvelle page', 'subtitle' => $edit ? (string) $page['title'] : '',
    'breadcrumbs' => [['Pages', $base], [$edit ? (string) $page['title'] : 'Nouvelle page']]],
    $edit ? ui('button', ['label' => 'Aperçu', 'href' => $base . '/' . $page['id'] . '/apercu', 'variant' => 'secondary', 'icon' => 'eye']) : '') ?>
<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert"><?= ui_icon('alert') ?><div><strong class="alert-title"><?= count($errors) > 1 ? 'Corrigez les points suivants' : 'Corrigez le point suivant' ?></strong><ul class="error-list"><?= $errorList ?></ul></div></div>
<?php endif; ?>
<?php if ($edit): ?>
<p class="page-state"><?= ui('badge', ['kind' => $kinds[$page['status']] ?? 'neutral', 'label' => $labels[$page['status']] ?? (string) $page['status']]) ?>
<?php if ($live !== null && $page['status'] !== 'publie'): ?> <?= ui('badge', ['kind' => 'ok', 'label' => 'Une version est en ligne']) ?><?php endif; ?></p>
<?php if ($page['published_blocks'] !== null): ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Cette page est en ligne : le public voit la dernière version publiée. Vos modifications restent en brouillon jusqu\'à ce qu\'un publicateur republie la page.']) ?>
<?php endif; ?>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit" name="op" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Enregistrer</button>
<?php
// Page déjà en ligne : adresse, gabarit et parent changent le site public sans publication, donc réservés aux publicateurs.
$locked = $edit && $page['published_by'] !== null && !$mayPublish;
$lock = static fn (string $name): array => $locked ? ['hint' => 'Page déjà en ligne : modifiable par un publicateur seulement.', 'attrs' => ['disabled' => true]] : [];
if ($locked) {
    foreach (['slug', 'template', 'parent_id'] as $k) {
        echo '<input type="hidden" name="' . $k . '" value="' . e($values[$k] ?? '') . '">';
    }
}
?>
<?= ui('panel', ['title' => 'Page'], '<div class="form-grid">'
    . ui('field', ['name' => 'title', 'label' => 'Titre', 'value' => $values['title'] ?? '', 'required' => true, 'error' => $errors['title'] ?? '', 'attrs' => ['maxlength' => 200]])
    . ui('field', array_replace_recursive(['name' => 'slug', 'label' => 'Adresse', 'value' => $values['slug'] ?? '', 'error' => $errors['slug'] ?? '',
        'hint' => 'Minuscules, chiffres et tirets. Laissée vide, elle est proposée à partir du titre' . (($values['title'] ?? '') !== '' ? ' (« ' . \App\Modules\Cms\Slugs::fromTitle($values['title']) . ' »)' : '') . '.',
        'attrs' => ['maxlength' => 80, 'autocapitalize' => 'none', 'spellcheck' => 'false']], $lock('slug')))
    . ui('field', array_replace_recursive(['name' => 'template', 'label' => 'Gabarit', 'type' => 'select', 'value' => $values['template'] ?? 'standard', 'options' => PageService::TEMPLATES, 'error' => $errors['template'] ?? ''], $lock('template')))
    . ui('field', array_replace_recursive(['name' => 'parent_id', 'label' => 'Page parente', 'type' => 'select', 'value' => $values['parent_id'] ?? '', 'options' => $parents, 'error' => $errors['parent_id'] ?? ''], $lock('parent_id')))
    . '</div>') ?>
<section class="panel" id="blocs"><header class="panel-head"><h2>Contenu</h2></header><div class="panel-body">
<?php if ($blocks === []): ?><p class="field-hint">Aucun bloc : ajoutez-en un ci-dessous.</p>
<?php endif; ?>
<?php foreach ($blocks as $i => $b):
    $type = (string) $b['type'];
    $n = count($blocks);
    $title = 'Bloc ' . ($i + 1) . ' : ' . Blocks::TYPES[$type];
    ?>
<fieldset class="block-editor" id="bloc-<?= $i ?>">
<legend><?= e($title) ?></legend>
<input type="hidden" name="blocs[<?= $i ?>][type]" value="<?= e($type) ?>">
<div class="block-tools">
<?= $opButton("up:$i", 'Monter', ' le bloc ' . ($i + 1), $i - 1, $i === 0) ?>
<?= $opButton("down:$i", 'Descendre', ' le bloc ' . ($i + 1), $i + 1, $i === $n - 1) ?>
<?= $opButton("del:$i", 'Supprimer', ' le bloc ' . ($i + 1), $i - 1, false, 'ghost') ?>
</div>
<?php
    $inner = '';
    foreach (Blocks::FIELDS[$type] as $name => $spec) {
        if ($spec[0] !== 'items') {
            $inner .= $blockField($spec, "blocs[$i][$name]", "blocs[$i].$name", (string) ($b[$name] ?? ''));
            continue;
        }
        $rows = (array) ($b[$name] ?? []);
        $inner .= '<div class="block-items"><p class="block-items-title">' . e($spec[1]) . '</p>';
        foreach ($rows as $j => $row) {
            $inner .= '<fieldset class="block-item"><legend>' . e(rtrim($spec[1], 's') . ' ' . ($j + 1)) . '</legend><div class="block-item-fields">';
            foreach ($spec[3] as $sub => $subSpec) {
                $inner .= $blockField($subSpec, "blocs[$i][$name][$j][$sub]", "blocs[$i].$name" . "[$j].$sub", (string) ($row[$sub] ?? ''));
            }
            $inner .= '</div>' . $opButton("item-del:$i:$j", 'Retirer la ligne', ' ' . ($j + 1) . ' du bloc ' . ($i + 1), $i, false, 'ghost') . '</fieldset>';
        }
        $inner .= $opButton("item-add:$i", 'Ajouter une ligne', ' au bloc ' . ($i + 1), $i) . '</div>';
    }
    // Erreurs du bloc sans champ propre (type, liste vide…)
    $rest = '';
    foreach ($errors as $key => $msg) {
        if (preg_match('/^blocs\[' . $i . '\](?=[.\[]|$)/', $key) === 1 && !isset($used[$key])) {
            $rest .= '<p class="field-error" role="alert">' . ui_icon('alert') . e($msg) . '</p>';
        }
    }
    echo $rest . $inner;
    ?>
</fieldset>
<?php endforeach; ?>
<div class="block-add">
<?= ui('field', ['name' => 'add_type', 'label' => 'Type du bloc à ajouter', 'type' => 'select', 'value' => '', 'options' => ['' => '— Choisir —'] + Blocks::TYPES, 'error' => $errors['blocs'] ?? '']) ?>
<?= $opButton('add', 'Ajouter un bloc', '', count($blocks)) ?>
</div>
</div></section>
<?= ui('panel', ['title' => 'Résumé et référencement'], '<div class="form-grid">'
    . ui('field', ['name' => 'excerpt', 'label' => 'Extrait', 'type' => 'textarea', 'value' => $values['excerpt'] ?? '', 'error' => $errors['excerpt'] ?? '', 'hint' => 'Texte court affiché dans les listes (500 caractères au maximum).', 'attrs' => ['maxlength' => 500]])
    . ui('field', ['name' => 'featured_media_id', 'label' => 'Image à la une', 'type' => 'select', 'value' => $values['featured_media_id'] ?? '', 'options' => $imageOptions, 'error' => $errors['featured_media_id'] ?? ''])
    . ui('field', ['name' => 'seo_title', 'label' => 'Titre SEO', 'value' => $values['seo_title'] ?? '', 'error' => $errors['seo_title'] ?? '', 'hint' => '70 caractères au maximum ; vide = titre de la page.', 'attrs' => ['maxlength' => 70]])
    . ui('field', ['name' => 'seo_description', 'label' => 'Description SEO', 'type' => 'textarea', 'value' => $values['seo_description'] ?? '', 'error' => $errors['seo_description'] ?? '', 'hint' => '160 caractères au maximum, affichés par les moteurs de recherche.', 'attrs' => ['maxlength' => 160]])
    . ui('field', ['name' => 'og_media_id', 'label' => 'Image de partage (réseaux sociaux)', 'type' => 'select', 'value' => $values['og_media_id'] ?? '', 'options' => $imageOptions, 'error' => $errors['og_media_id'] ?? ''])
    . ui('field', ['name' => 'canonical_url', 'label' => 'Adresse canonique', 'type' => 'url', 'value' => $values['canonical_url'] ?? '', 'error' => $errors['canonical_url'] ?? '', 'hint' => 'Facultatif : https://… si ce contenu existe ailleurs.', 'attrs' => ['maxlength' => 255]])
    . '</div>') ?>
<div class="form-actions form-actions-sticky">
<button class="btn btn-primary" type="submit" name="op" value="save"><span>Enregistrer</span></button>
<?= ui('button', ['label' => 'Annuler', 'variant' => 'ghost', 'href' => $base]) ?>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ : son absence révèle un envoi tronqué par max_input_vars */ ?>
</form>
<?php if ($edit): ?>
<?php
$transitionLabels = ['en_relecture' => 'Soumettre pour relecture', 'publie' => $page['published_blocks'] !== null ? 'Republier' : 'Publier', 'archive' => 'Archiver',
    'brouillon' => ['en_relecture' => 'Retirer de la relecture', 'publie' => 'Dépublier', 'programme' => 'Annuler la programmation', 'archive' => 'Restaurer en brouillon'][$page['status']] ?? 'Brouillon'];
$forms = '';
foreach ($transitions as $to) {
    $form = '<form method="post" action="' . e($base . '/' . $page['id'] . '/etat') . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="' . e($to) . '"><input type="hidden" name="version" value="' . (int) $page['content_version'] . '">';
    if ($to === 'programme') {
        $form .= ui('field', ['name' => 'at', 'label' => 'Date et heure de publication (heure d\'Abidjan)', 'type' => 'datetime-local', 'required' => true]) . ui('button', ['label' => 'Programmer', 'type' => 'submit', 'variant' => 'secondary']);
    } else {
        $form .= ui('button', ['label' => $transitionLabels[$to], 'type' => 'submit', 'variant' => $to === 'publie' ? 'primary' : 'secondary']);
    }
    $forms .= $form . '</form>';
}
if ($mayPublish && $page['published_blocks'] !== null && in_array($page['status'], ['brouillon', 'en_relecture', 'programme'], true)) {
    $forms .= '<form method="post" action="' . e($base . '/' . $page['id'] . '/etat') . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="retirer">' . ui('button', ['label' => 'Retirer la version en ligne', 'type' => 'submit', 'variant' => 'danger']) . '</form>';
}
$state = '<p>État : <strong>' . e($labels[$page['status']] ?? (string) $page['status']) . '</strong>'
    . ($page['status'] === 'programme' && $page['published_at'] !== null ? ' — publication prévue le ' . e((new DateTimeImmutable((string) $page['published_at']))->format('d/m/Y à H:i')) . ' (heure d\'Abidjan)' : '')
    . '.</p>' . (!$mayPublish ? '<p class="field-hint">La publication est réservée aux publicateurs : soumettez la page pour relecture.</p>' : '');
?>
<?= ui('panel', ['title' => 'Publication'], $state . ($forms !== '' ? '<div class="transition-list">' . $forms . '</div>' : '')) ?>
<?php if ($page['published_by'] === null && in_array($page['status'], ['brouillon', 'en_relecture'], true)): ?>
<form method="post" action="<?= e($base . '/' . $page['id'] . '/suppression') ?>" class="delete-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer cette page</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer cette page ?', 'message' => 'Ce brouillon n\'a jamais été publié ; il sera effacé définitivement.',
    'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $page['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
<?php
$actions = ['cms.page_created' => 'Création', 'cms.page_updated' => 'Modification', 'cms.page_submitted' => 'Soumise pour relecture', 'cms.page_withdrawn' => 'Retirée de la relecture',
    'cms.page_published' => 'Publiée', 'cms.page_scheduled' => 'Programmée', 'cms.page_unpublished' => 'Dépubliée', 'cms.page_archived' => 'Archivée',
    'cms.page_restored' => 'Restaurée en brouillon', 'cms.page_moved' => 'Déplacée', 'cms.page_reordered' => 'Ordre modifié'];
$items = '';
foreach ($history as $h) {
    $new = json_decode((string) ($h['new_values'] ?? ''), true) ?: [];
    $detail = isset($new['at']) ? ' pour le ' . (new DateTimeImmutable((string) $new['at']))->format('d/m/Y à H:i') : '';
    if ($h['action'] === 'cms.page_updated' && ($new['status'] ?? '') !== (json_decode((string) ($h['old_values'] ?? ''), true)['status'] ?? '')) {
        $detail = ' (retour en brouillon)';
    }
    $items .= '<li><time datetime="' . e((string) $h['created_at']) . '">' . e((new DateTimeImmutable((string) $h['created_at']))->format('d/m/Y H:i')) . '</time> — '
        . e(($actions[$h['action']] ?? $h['action']) . $detail) . ' — ' . e((string) ($h['user_name'] ?? 'système')) . '</li>';
}
?>
<?= ui('panel', ['title' => 'Historique'], $items === '' ? '<p>Aucun évènement.</p>' : '<ul class="history-list">' . $items . '</ul>') ?>
<?php endif; ?>
