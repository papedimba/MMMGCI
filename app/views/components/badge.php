<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$kind = in_array($props['kind'] ?? '', ['ok', 'warn', 'danger', 'info'], true) ? $props['kind'] : 'neutral';
?>
<span class="badge badge-<?= $kind ?>"><?= $e($props['label'] ?? '') ?></span>
