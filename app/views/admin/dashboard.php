<?php declare(strict_types=1);
/** @var array $user @var Closure $can @var ?Closure $routeExists */
$first = preg_split('/\s+/u', trim((string) $user['name']))[0] ?? '';
$shortcuts = array_filter(
    (array) (require __DIR__ . '/_nav.php'),
    static fn (array $i): bool => $i[2] !== '/admin' && ($i[3] === null || array_filter((array) $i[3], $can) !== []) && ($routeExists ?? null) !== null && $routeExists($i[2]),
);
?>
<?= ui('page-header', ['title' => 'Bonjour ' . $first, 'subtitle' => 'Voici votre espace d\'administration.']) ?>
<?= ui('panel', ['title' => 'À traiter'], ui('empty-state', ['title' => 'Rien à traiter pour le moment', 'message' => 'Les demandes qui attendent votre action apparaîtront ici.'])) ?>
<?php if ($shortcuts !== []): ?>
<section aria-labelledby="raccourcis">
<h2 id="raccourcis" class="section-title">Raccourcis</h2>
<ul class="shortcuts">
<?php foreach ($shortcuts as [, $label, $href, , $icon]): ?>
<li><a class="shortcut" href="<?= e($href) ?>"><?= ui_icon($icon) ?><span><?= e($label) ?></span><?= ui_icon('chevron-right') ?></a></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>
