<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var string $slot @var Closure $e */
?>
<div class="page-header">
<?= isset($props['breadcrumbs']) ? ui('breadcrumbs', ['items' => $props['breadcrumbs']]) : '' ?>
<div class="page-header-row">
<div><h1><?= $e($props['title'] ?? '') ?></h1>
<?php if (!empty($props['subtitle'])): ?><p class="page-subtitle"><?= $e($props['subtitle']) ?></p>
<?php endif; ?></div>
<?= $slot !== '' ? '<div class="page-actions">' . $slot . '</div>' : '' ?>
</div>
</div>
