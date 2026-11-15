<?php declare(strict_types=1);
/** @var Closure $can @var ?Closure $routeExists @var string $current */
$items = (require __DIR__ . '/_nav.php');
$group = null;
$open = false;
foreach ($items as [$grp, $label, $href, $perm, $icon]) {
    if (($perm !== null && !$can($perm)) || ($routeExists ?? null) === null || !$routeExists($href)) {
        continue;
    }
    if ($grp !== $group) {
        echo $open ? "</ul>\n" : '';
        echo $grp !== '' ? '<p class="nav-group">' . e($grp) . "</p>\n" : '';
        echo "<ul class=\"nav-list\">\n";
        $group = $grp;
        $open = true;
    }
    echo '<li><a class="nav-link" href="' . e($href) . '"' . ($href === $current || ($href !== '/admin' && str_starts_with($current, $href . '/')) ? ' aria-current="page"' : '') . '>' . ui_icon($icon) . e($label) . "</a></li>\n";
}
echo $open ? "</ul>\n" : '';
