<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$items = array_values((array) ($props['items'] ?? []));
if ($items === []) { return; }
?>
<nav class="breadcrumbs" aria-label="Fil d'Ariane"><ol>
<?php foreach ($items as $i => $item): $last = $i === count($items) - 1; ?>
<li><?php if (!$last && isset($item[1])): ?><a href="<?= $e($item[1]) ?>"><?= $e($item[0]) ?></a><?php else: ?><span<?= $last ? ' aria-current="page"' : '' ?>><?= $e($item[0]) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ol></nav>
