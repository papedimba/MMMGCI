<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
?>
<nav class="tabs" aria-label="<?= $e($props['label'] ?? 'Sections') ?>">
<?php foreach ((array) ($props['items'] ?? []) as $item): ?><a href="<?= $e($item[1] ?? '') ?>"<?= !empty($item[2]) ? ' aria-current="page"' : '' ?>><?= $e($item[0] ?? '') ?></a>
<?php endforeach; ?>
</nav>
