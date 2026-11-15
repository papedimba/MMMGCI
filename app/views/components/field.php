<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$type = in_array($props['type'] ?? '', ['email', 'password', 'tel', 'number', 'date', 'search', 'url', 'textarea', 'select'], true) ? $props['type'] : 'text';
$id = 'f-' . ($props['name'] ?? '');
$error = (string) ($props['error'] ?? '');
$hint = (string) ($props['hint'] ?? '');
$required = !empty($props['required']);
$describedBy = trim(($hint !== '' ? $id . '-hint ' : '') . ($error !== '' ? $id . '-err' : ''));
$attrs = ' id="' . $e($id) . '" name="' . $e($props['name'] ?? '') . '" class="control"'
    . ($describedBy !== '' ? ' aria-describedby="' . $e($describedBy) . '"' : '')
    . ($error !== '' ? ' aria-invalid="true"' : '')
    . ($required ? ' required' : '')
    . (isset($props['autocomplete']) ? ' autocomplete="' . $e($props['autocomplete']) . '"' : '')
    . (isset($props['inputmode']) ? ' inputmode="' . $e($props['inputmode']) . '"' : '');
// Attributs HTML supplémentaires (minlength, maxlength, pattern, disabled, autofocus…) : nom en minuscules, valeur échappée ; true = attribut booléen.
foreach ((array) ($props['attrs'] ?? []) as $k => $v) {
    // Jamais de gestionnaire d'événement, de style en ligne ni de formaction (CSP, et pas de détournement du formulaire).
    if (is_string($k) && preg_match('/^[a-z][a-z-]*$/', $k) === 1 && preg_match('/^(on|style$|formaction$)/', $k) !== 1 && $v !== false) {
        $attrs .= ' ' . $k . ($v === true ? '' : '="' . $e((string) $v) . '"');
    }
}
$value = (string) ($props['value'] ?? '');
?>
<div class="field<?= $error !== '' ? ' has-error' : '' ?>">
<label for="<?= $e($id) ?>"><?= $e($props['label'] ?? '') ?><?= $required ? ' <span class="req" aria-hidden="true">*</span><span class="visually-hidden"> (obligatoire)</span>' : '' ?></label>
<?php if ($hint !== ''): ?><p class="field-hint" id="<?= $e($id) ?>-hint"><?= $e($hint) ?></p>
<?php endif; ?>
<?php if ($type === 'textarea'): ?><textarea<?= $attrs ?> rows="4"><?= $e($value) ?></textarea>
<?php elseif ($type === 'select'): ?><select<?= $attrs ?>>
<?php foreach ((array) ($props['options'] ?? []) as $k => $label): ?><option value="<?= $e($k) ?>"<?= (string) $k === $value ? ' selected' : '' ?>><?= $e($label) ?></option>
<?php endforeach; ?></select>
<?php else: ?><input type="<?= $type ?>"<?= $attrs ?><?= $type !== 'password' ? ' value="' . $e($value) . '"' : '' ?>>
<?php endif; ?>
<?php if ($error !== ''): ?><p class="field-error" id="<?= $e($id) ?>-err" role="alert"><?= ui_icon('alert') ?><span class="visually-hidden">Erreur : </span><?= $e($error) ?></p>
<?php endif; ?>
</div>
