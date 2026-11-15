<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money_fmt(int $cents): string
{
    return \App\Core\Money::format($cents);
}

/** @param array<string,mixed> $props toutes échappées par le composant ; $slot : HTML déjà rendu et de confiance */
function ui(string $component, array $props = [], string $slot = ''): string
{
    return \App\Core\Ui::render($component, $props, $slot);
}

function ui_icon(string $name, string $label = ''): string
{
    return \App\Core\Ui::icon($name, $label);
}
