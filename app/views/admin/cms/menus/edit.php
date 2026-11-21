<?php declare(strict_types=1);
/**
 * Éditeur de menu sans JavaScript : une ligne par élément ; Monter / Descendre / Supprimer / Ajouter sont des boutons d'envoi (name="op")
 * qui réaffichent le formulaire modifié sans rien enregistrer.
 * @var string $location @var list<array<string,mixed>> $rows @var array<string,string> $errors @var array<int|string,string> $pageChoices @var string $csrfToken
 */
use App\Modules\Cms\MenuService;

$base = '/admin/site/menus';
$action = $base . '/' . $location;
$tabs = [];
foreach (MenuService::LOCATIONS as $k => $label) {
    $tabs[] = [$label, $base . '/' . $k, $k === $location];
}
$op = static function (string $op, string $label, string $hidden, int $i, bool $disabled = false) use ($action): string {
    return '<button class="btn btn-secondary btn-sm" type="submit" name="op" value="' . e($op) . '" formaction="' . e($action . '#element-' . max(0, $i)) . '" formnovalidate'
        . ($disabled ? ' disabled' : '') . '><span>' . e($label) . '<span class="visually-hidden"> : ' . e($hidden) . '</span></span></button>';
};
$list = '';
foreach ($errors as $key => $msg) {
    $list .= '<li>' . (preg_match('/^items\[(\d+)\]/', $key, $m) === 1 ? '<a href="#element-' . (int) $m[1] . '">Élément ' . ((int) $m[1] + 1) . ' : ' . e($msg) . '</a>' : e($msg)) . '</li>';
}
?>
<?= ui('page-header', ['title' => 'Menus du site', 'subtitle' => 'Les menus du site public. L\'enregistrement est immédiat : le public voit le menu tout de suite.']) ?>
<?= ui('tabs', ['label' => 'Emplacements', 'items' => $tabs]) ?>
<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert"><?= ui_icon('alert') ?><div><strong class="alert-title"><?= count($errors) > 1 ? 'Corrigez les points suivants' : 'Corrigez le point suivant' ?></strong><ul class="error-list"><?= $list ?></ul></div></div>
<?php endif; ?>
<p class="field-hint">Premier niveau : <?= e((string) MenuService::MAX_TOP[$location]) ?> éléments au maximum ; un sous-élément dépend de l'élément de premier niveau qui le précède. Un élément qui pointe une page non publiée reste ici mais n'apparaît pas sur le site.</p>
<form method="post" action="<?= e($action) ?>" class="page-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button type="submit" name="op" value="save" class="visually-hidden" tabindex="-1" aria-hidden="true">Enregistrer</button>
<?php if ($rows === []): ?><?= ui('empty-state', ['title' => 'Menu vide', 'message' => 'Ajoutez un premier élément.']) ?><?php endif; ?>
<?php foreach ($rows as $i => $it): $n = $i + 1; $p = "items[$i]"; ?>
<fieldset class="block-editor" id="element-<?= $i ?>">
<legend>Élément <?= $n ?><?= $it['depth'] === '1' ? ' (sous-élément)' : '' ?></legend>
<div class="block-tools">
<?= $op('up:' . $i, 'Monter', 'élément ' . $n, $i, $i === 0) ?>
<?= $op('down:' . $i, 'Descendre', 'élément ' . $n, $i, $i === count($rows) - 1) ?>
<?= $op('del:' . $i, 'Supprimer', 'élément ' . $n, $i - 1) ?>
<?php if ($it['kind'] === 'page' && $it['live'] === false): ?><?= ui('badge', ['kind' => 'warn', 'label' => 'Page non publiée']) ?><?php endif; ?>
<?php if (!$it['is_active']): ?><?= ui('badge', ['kind' => 'neutral', 'label' => 'Masqué']) ?><?php endif; ?>
</div>
<div class="block-item-fields">
<?= ui('field', ['name' => $p . '[label]', 'label' => 'Libellé', 'value' => (string) $it['label'], 'required' => true, 'error' => $errors[$p . '.label'] ?? '', 'attrs' => ['maxlength' => MenuService::MAX_LABEL]]) ?>
<?= ui('field', ['name' => $p . '[depth]', 'label' => 'Niveau', 'type' => 'select', 'value' => (string) $it['depth'], 'options' => ['0' => 'Premier niveau', '1' => 'Sous-élément']]) ?>
<?= ui('field', ['name' => $p . '[kind]', 'label' => 'Cible', 'type' => 'select', 'value' => (string) $it['kind'], 'options' => ['page' => 'Une page du site', 'url' => 'Une adresse (URL)']]) ?>
<?= ui('field', ['name' => $p . '[page_id]', 'label' => 'Page', 'type' => 'select', 'value' => (string) $it['page_id'], 'options' => $pageChoices, 'error' => $it['kind'] === 'page' ? ($errors[$p . '.target'] ?? '') : '']) ?>
<?= ui('field', ['name' => $p . '[url]', 'label' => 'Adresse', 'value' => (string) $it['url'], 'hint' => 'https://…, mailto:…, tel:… ou chemin du site (/contact).', 'error' => $it['kind'] === 'url' ? ($errors[$p . '.target'] ?? '') : '', 'attrs' => ['maxlength' => 255]]) ?>
<?= ui('field', ['name' => $p . '[target]', 'label' => 'Ouverture', 'type' => 'select', 'value' => (string) $it['target'], 'options' => ['' => 'Même onglet', '_blank' => 'Nouvel onglet']]) ?>
</div>
<label class="check"><input type="checkbox" name="<?= e($p) ?>[is_active]" value="1"<?= $it['is_active'] ? ' checked' : '' ?>><span>Afficher cet élément sur le site</span></label>
</fieldset>
<?php endforeach; ?>
<div class="block-add"><?= $op('add', 'Ajouter un élément', '', count($rows)) ?></div>
<div class="form-actions form-actions-sticky">
<button class="btn btn-primary" type="submit" name="op" value="save"><span>Enregistrer le menu</span></button>
</div>
<input type="hidden" name="_form_complete" value="1"><?php /* DOIT rester le dernier champ : son absence révèle un envoi tronqué par max_input_vars */ ?>
</form>
