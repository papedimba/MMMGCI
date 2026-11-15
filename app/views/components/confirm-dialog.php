<?php declare(strict_types=1);
/**
 * Ouverture : un bouton type=submit du vrai formulaire portant data-confirm-open="<id>" (sans JS, le formulaire part directement).
 * @var array<string,mixed> $props @var Closure $e
 */
$id = (string) ($props['id'] ?? '');
if ((string) ($props['csrf'] ?? '') === '') {
    throw new \InvalidArgumentException('confirm-dialog : la prop csrf est obligatoire (le formulaire interne poste en POST).');
}
?>
<dialog class="dialog" id="<?= $e($id) ?>" aria-labelledby="<?= $e($id) ?>-title" aria-describedby="<?= $e($id) ?>-msg">
<form method="post" action="<?= $e($props['form_action'] ?? '') ?>">
<input type="hidden" name="_csrf" value="<?= $e($props['csrf']) ?>">
<h2 id="<?= $e($id) ?>-title"><?= $e($props['title'] ?? '') ?></h2>
<p id="<?= $e($id) ?>-msg"><?= $e($props['message'] ?? '') ?></p>
<div class="dialog-actions">
<button class="btn btn-secondary" type="button" data-confirm-close><span>Annuler</span></button>
<button class="btn btn-danger" type="submit"><span><?= $e($props['confirm_label'] ?? 'Confirmer') ?></span></button>
</div>
</form>
</dialog>
