<?php declare(strict_types=1);
/**
 * columns : [key, label, align?, numeric?, render?, status?] ; la 1re colonne est le titre de la ligne, celle marquée 'status' (5e index) l'accompagne sur la 1re ligne en téléphone, les autres forment la 2e ligne.
 * HTML DE CONFIANCE (jamais une saisie) : les callables render(array $row): string (ex. ui('badge')) et row_actions(array $row): string ; tout le reste est échappé.
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
<div class="table-wrap"><table class="table" role="table">
<?php if (!empty($props['caption'])): ?><caption><?= $e($props['caption']) ?></caption>
<?php endif; ?>
<thead role="rowgroup"><tr role="row">
<?php foreach ($columns as $c): ?><th role="columnheader" scope="col" class="<?= $cls($c) ?>"><?= $e($c[1] ?? '') ?></th>
<?php endforeach; ?>
<?php if ($actions !== null): ?><th scope="col"><span class="visually-hidden">Actions</span></th>
<?php endif; ?>
</tr></thead>
<tbody role="rowgroup">
<?php foreach ($rows as $row): ?><tr role="row">
<?php foreach ($columns as $i => $c): ?><td role="cell" class="<?= trim($cls($c) . ($i === 0 ? ' cell-primary' : (($c[5] ?? '') === 'status' ? ' cell-status' : ' cell-meta'))) ?>"><?= isset($c[4]) && is_callable($c[4]) ? $c[4]($row) : $e($row[$c[0] ?? ''] ?? '') ?></td>
<?php endforeach; ?>
<?php if ($actions !== null): ?><td role="cell" class="row-actions"><?= is_callable($actions) ? $actions($row) : '' ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody></table></div>
