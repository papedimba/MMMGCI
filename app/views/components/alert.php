<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$kind = in_array($props['kind'] ?? '', ['ok', 'warn', 'danger', 'info'], true) ? $props['kind'] : 'info';
$icon = ['ok' => 'check', 'warn' => 'alert', 'danger' => 'alert', 'info' => 'info'][$kind];
$label = ['ok' => 'Succès', 'warn' => 'Attention', 'danger' => 'Erreur', 'info' => 'Information'][$kind];
?>
<div class="alert alert-<?= $kind ?>" role="<?= $kind === 'danger' ? 'alert' : 'status' ?>">
<?= ui_icon($icon) ?><div><span class="visually-hidden"><?= $label ?> : </span>
<?php if (!empty($props['title'])): ?><strong class="alert-title"><?= $e($props['title']) ?></strong>
<?php endif; ?>
<p><?= $e($props['message'] ?? '') ?></p></div>
</div>
