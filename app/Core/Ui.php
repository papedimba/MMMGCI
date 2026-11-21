<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Kit de composants : rend app/views/components/<nom>.php. Les composants échappent toutes les props ; seul $slot est du HTML de confiance. */
final class Ui
{
    /** Liste blanche = symboles de public/assets/img/icons.svg. */
    public const ICONS = ['home', 'users', 'user-plus', 'inbox', 'coins', 'shield', 'heart', 'anchor', 'handshake', 'landmark', 'chart', 'file',
        'search', 'check', 'x', 'info', 'alert', 'menu', 'plus', 'chevron-down', 'chevron-right', 'logout', 'lock', 'key', 'eye', 'download',
        'print', 'upload', 'calendar', 'clock'];

    /** @param array<string,mixed> $props */
    public static function render(string $component, array $props = [], string $slot = ''): string
    {
        $file = dirname(__DIR__) . '/views/components/' . $component . '.php';
        if (preg_match('/^[a-z][a-z-]*$/', $component) !== 1 || !is_file($file)) {
            throw new InvalidArgumentException("Composant inconnu : « $component ».");
        }
        $run = static function (string $__file, array $props, string $slot): string {
            $e = static fn (mixed $v): string => e((string) $v);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        return $run($file, $props, $slot);
    }

    public static function icon(string $name, string $label = ''): string
    {
        if (!in_array($name, self::ICONS, true)) {
            throw new InvalidArgumentException("Icône hors liste blanche : « $name ».");
        }
        $a = $label === '' ? 'aria-hidden="true"' : 'role="img" aria-label="' . e($label) . '"';
        return '<svg class="icon" ' . $a . '><use href="/assets/img/icons.svg#' . $name . '"/></svg>';
    }
}
