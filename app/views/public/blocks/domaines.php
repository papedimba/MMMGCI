<?php declare(strict_types=1);
/** @var array<string,mixed> $b */
$items = array_filter(is_array($b['items'] ?? null) ? $b['items'] : [], 'is_array');
if ($items === []) {
    return;
}
?>
<ul class="pub-block pub-domains">
<?php foreach ($items as $it): ?>
<li>
<h3><?= e(plain($it['name'] ?? '')) ?></h3>
<?php if (plain($it['nature'] ?? '') !== ''): ?><p class="pub-domain-nature"><?= e(plain($it['nature'])) ?></p>
<?php endif; ?>
<?php if (plain($it['description'] ?? '') !== ''): ?><p><?= e_lines($it['description']) ?></p>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
