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

/** Adresse d'un fichier de /assets versionnée par l'empreinte de son contenu (?v=…, cache long côté serveur) ; inchangée si le fichier est absent. */
function asset_url(string $path): string
{
    static $memo = [];
    if (!isset($memo[$path])) {
        $file = dirname(__DIR__) . '/public' . $path;
        $memo[$path] = str_starts_with($path, '/assets/') && !str_contains($path, '..') && is_file($file) ? $path . '?v=' . substr((string) hash_file('sha256', $file), 0, 12) : $path;
    }
    return $memo[$path];
}

/**
 * Image publique responsive : srcset (variantes MediaVariants + original), sizes, width/height si connues, lazy sauf $eager.
 * @param array{url:string,alt?:?string,width?:?int,height?:?int,variants?:list<array{url:string,width:int}>} $m
 */
function responsive_img(array $m, string $sizes = '(min-width: 60em) 60em, 100vw', bool $eager = false): string
{
    $set = array_map(static fn (array $v): string => $v['url'] . ' ' . (int) $v['width'] . 'w', $m['variants'] ?? []);
    $w = $m['width'] ?? null;
    $h = $m['height'] ?? null;
    if ($set !== [] && $w !== null) {
        $set[] = $m['url'] . ' ' . (int) $w . 'w';
    }
    return '<img src="' . e($m['url']) . '"' . ($set !== [] && $w !== null ? ' srcset="' . e(implode(', ', $set)) . '" sizes="' . e($sizes) . '"' : '')
        . ' alt="' . e(plain($m['alt'] ?? '')) . '"' . ($w !== null && $h !== null ? ' width="' . (int) $w . '" height="' . (int) $h . '"' : '')
        . ($eager ? '' : ' loading="lazy"') . ' decoding="async">';
}
