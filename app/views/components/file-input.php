<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$id = 'f-' . ($props['name'] ?? '');
$error = (string) ($props['error'] ?? '');
$hint = trim((string) ($props['hint'] ?? '') . (!empty($props['max_mb']) ? ' Taille maximale : ' . (int) $props['max_mb'] . ' Mo.' : ''));
$describedBy = trim(($hint !== '' ? $id . '-hint ' : '') . ($error !== '' ? $id . '-err' : '') . ' ' . $id . '-name');
?>
<div class="field file-input<?= $error !== '' ? ' has-error' : '' ?>">
<label for="<?= $e($id) ?>"><?= $e($props['label'] ?? '') ?></label>
<?php if ($hint !== ''): ?><p class="field-hint" id="<?= $e($id) ?>-hint"><?= $e($hint) ?></p>
<?php endif; ?>
<input type="file" class="control" id="<?= $e($id) ?>" name="<?= $e($props['name'] ?? '') ?>" data-file-input<?= isset($props['accept']) ? ' accept="' . $e($props['accept']) . '"' : '' ?> aria-describedby="<?= $e($describedBy) ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?>>
<output class="file-name" id="<?= $e($id) ?>-name" for="<?= $e($id) ?>" data-file-name>Aucun fichier choisi</output>
<?php if ($error !== ''): ?><p class="field-error" id="<?= $e($id) ?>-err" role="alert"><?= ui_icon('alert') ?><span class="visually-hidden">Erreur : </span><?= $e($error) ?></p>
<?php endif; ?>
</div>
