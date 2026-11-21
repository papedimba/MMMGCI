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

/** Date du CMS (heure d'Abidjan) → « 21 novembre 2026 » (ou « … à 14 h 30 ») ; $empty si absente ou illisible. */
function date_long_fr(?string $local, bool $withTime = false, string $empty = ''): string
{
    $d = $local === null || $local === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d' . (strlen($local) > 10 ? ' H:i:s' : ''), $local, new DateTimeZone('Africa/Abidjan'));
    if ($d === false) {
        return $empty;
    }
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $day = (int) $d->format('j');
    $s = ($day === 1 ? '1er' : (string) $day) . ' ' . $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
    return $withTime ? $s . ' à ' . $d->format('G') . ' h ' . $d->format('i') : $s;
}

/** Valeur lue d'un JSON ou d'une ligne de base, en chaîne (tableau ou objet inattendu → '') : à échapper ensuite. */
function plain(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** Texte multiligne échappé, sauts de ligne en <br>. */
function e_lines(mixed $v): string
{
    return nl2br(e(plain($v)), false);
}
