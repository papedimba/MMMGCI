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

/** Date UTC de la base → « JJ/MM/AAAA HH:MM » à l'heure configurée (Africa/Abidjan) ; $empty si absente. */
function date_fr(?string $utc, string $empty = '—'): string
{
    return $utc === null || $utc === ''
        ? $empty
        : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i');
}
