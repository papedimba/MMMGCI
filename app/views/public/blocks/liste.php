<?php declare(strict_types=1);
/** Liste : rangées d'une feuille imprimée. @var array<string,mixed> $b */
$items = array_filter(is_array($b['items'] ?? null) ? $b['items'] : [], 'is_array');
if ($items === []) {
    return;
}
?>
<ul class="pub-block pub-list">
<?php foreach ($items as $it): ?>
<li><?= e(plain($it['text'] ?? '')) ?></li>
<?php endforeach; ?>
</ul>
