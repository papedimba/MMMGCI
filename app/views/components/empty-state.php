<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
?>
<div class="empty-state">
<h2><?= $e($props['title'] ?? '') ?></h2>
<?php if (!empty($props['message'])): ?><p><?= $e($props['message']) ?></p>
<?php endif; ?>
<?= !empty($props['action']) ? ui('button', (array) $props['action']) : '' ?>
</div>
