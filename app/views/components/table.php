<?php declare(strict_types=1);
/**
 * columns : [key, label, align?, numeric?, render?] ; render(array $row): string = HTML de confiance (ex. ui('badge')) ; row_actions(array $row): string idem.
 * @var array<string,mixed> $props @var Closure $e
 */
$columns = array_values((array) ($props['columns'] ?? []));
$rows = (array) ($props['rows'] ?? []);
if ($rows === []) {
    echo ui('empty-state', (array) ($props['empty'] ?? ['title' => 'Aucun résultat']));
    return;
}
$actions = $props['row_actions'] ?? null;
$cls = static fn (array $c): string => trim(((!empty($c[3]) || ($c[2] ?? '') === 'right') ? 'num ' : '') . (($c[2] ?? '') === 'center' ? 'center' : ''));
?>
<div class="table-wrap"><table class="table">
<?php if (!empty($props['caption'])): ?><caption><?= $e($props['caption']) ?></caption>
<?php endif; ?>
<thead><tr>
<?php foreach ($columns as $c): ?><th scope="col" class="<?= $cls($c) ?>"><?= $e($c[1] ?? '') ?></th>
<?php endforeach; ?>
<?php if ($actions !== null): ?><th scope="col"><span class="visually-hidden">Actions</span></th>
<?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $row): ?><tr>
<?php foreach ($columns as $c): ?><td class="<?= $cls($c) ?>" data-label="<?= $e($c[1] ?? '') ?>"><?= isset($c[4]) && is_callable($c[4]) ? $c[4]($row) : $e($row[$c[0] ?? ''] ?? '') ?></td>
<?php endforeach; ?>
<?php if ($actions !== null): ?><td class="row-actions"><?= is_callable($actions) ? $actions($row) : '' ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div>
