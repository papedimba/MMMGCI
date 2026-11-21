<?php declare(strict_types=1);
/** Chiffres clés : seulement ceux saisis par la Mutuelle. @var array<string,mixed> $b */
$items = array_filter(is_array($b['items'] ?? null) ? $b['items'] : [], 'is_array');
if ($items === []) {
    return;
}
?>
<dl class="pub-block pub-facts">
<?php foreach ($items as $it): ?>
<div><dt><?= e(plain($it['label'] ?? '')) ?></dt><dd><?= e(plain($it['value'] ?? '')) ?></dd></div>
<?php endforeach; ?>
</dl>
