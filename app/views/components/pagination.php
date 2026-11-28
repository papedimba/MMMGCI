<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$page = max(1, (int) ($props['page'] ?? 1));
$pages = (int) ($props['pages'] ?? 1);
if ($pages <= 1) { return; }
$page = min($page, $pages);
$url = static fn (int $n): string => e((string) ($props['base'] ?? '')) . '?' . e(http_build_query(array_merge((array) ($props['query'] ?? []), ['page' => $n])));
$shown = array_values(array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn (int $n): bool => $n >= 1 && $n <= $pages)));
sort($shown);
?>
<nav class="pagination" aria-label="Pagination"><ul>
<li><?= $page > 1 ? '<a href="' . $url($page - 1) . '" rel="prev">Précédent</a>' : '<span aria-disabled="true">Précédent</span>' ?></li>
<?php $prev = 0; foreach ($shown as $n): ?>
<?= $n - $prev > 1 ? '<li aria-hidden="true"><span>…</span></li>' : '' ?>
<li><a href="<?= $url($n) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?> aria-label="Page <?= $n ?>"><?= $n ?></a></li>
<?php $prev = $n; endforeach; ?>
<li><?= $page < $pages ? '<a href="' . $url($page + 1) . '" rel="next">Suivant</a>' : '<span aria-disabled="true">Suivant</span>' ?></li>
</ul></nav>
