<?php declare(strict_types=1);
/** @var array $headers @var array $rows @var list<string> $summary */
?>
<p><?= e((string) count($rows)) ?> adhérent<?= count($rows) > 1 ? 's' : '' ?>.</p>
<?php if ($summary !== []): ?><p>Filtres appliqués : <?= e(implode(' ; ', $summary)) ?>.</p>
<?php endif; ?>
<table class="list-print">
<thead><tr><?php foreach ($headers as $h): ?><th scope="col"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?><tr><?php foreach ($row as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody>
</table>
