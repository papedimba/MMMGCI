<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$variant = in_array($props['variant'] ?? '', ['primary', 'secondary', 'danger', 'ghost'], true) ? $props['variant'] : 'primary';
$size = ($props['size'] ?? '') === 'sm' ? ' btn-sm' : '';
$busy = !empty($props['busy']);
$off = $busy || !empty($props['disabled']);
$cls = 'btn btn-' . $variant . $size . ($busy ? ' is-busy' : '');
$inner = (isset($props['icon']) ? ui_icon((string) $props['icon']) : '') . '<span>' . $e($props['label'] ?? '') . '</span>';
$aria = ($busy ? ' aria-busy="true"' : '');
if (isset($props['href'])):
    // Un lien ne peut pas être « disabled » : on retire href et on l'annonce.
    echo $off ? '<a class="' . $cls . '" aria-disabled="true"' . $aria . '>' . $inner . '</a>'
        : '<a class="' . $cls . '" href="' . $e($props['href']) . '">' . $inner . '</a>';
else:
    $type = ($props['type'] ?? '') === 'submit' ? 'submit' : 'button';
    echo '<button class="' . $cls . '" type="' . $type . '"' . ($off ? ' disabled' : '') . $aria . '>' . $inner . '</button>';
endif;
