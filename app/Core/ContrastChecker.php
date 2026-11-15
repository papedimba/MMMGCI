<?php
declare(strict_types=1);

namespace App\Core;

/** Rapport de contraste WCAG 2.x des paires de jetons (public/assets/css/tokens.css) ; utilisé par bin/check-contrast.php. */
final class ContrastChecker
{
    public const TEXT = 4.5;   // texte courant (WCAG 1.4.3)
    public const UI = 3.0;     // grands textes, bordures de champs, anneaux de focus (1.4.11)

    /** Paires déclarées : [premier plan, arrière-plan, seuil, usage]. Une valeur est un jeton (--nom) ou un #hex. */
    public const PAIRS = [
        ['--ink', '--sheet', self::TEXT, 'texte courant sur panneau'],
        ['--ink', '--paper', self::TEXT, 'texte courant sur papier'],
        ['--ink', '--frame', self::TEXT, 'texte courant sur cadre'],
        ['--ink-2', '--sheet', self::TEXT, 'texte secondaire sur panneau'],
        ['--ink-2', '--paper', self::TEXT, 'texte secondaire sur papier'],
        ['--ink-2', '--frame', self::TEXT, 'texte secondaire sur cadre'],
        ['--ink-3', '--sheet', self::TEXT, 'aide et légendes sur panneau'],
        ['--ink-3', '--paper', self::TEXT, 'aide et légendes sur papier'],
        ['--ink-3', '--frame', self::TEXT, 'aide et légendes sur cadre'],
        ['--ink-2', '--ocean-50', self::TEXT, 'texte secondaire sur fond clair'],
        ['--ink-3', '--ocean-50', self::TEXT, 'légendes sur fond clair'],
        ['--ocean-600', '--sheet', self::TEXT, 'liens sur panneau'],
        ['--ocean-600', '--paper', self::TEXT, 'liens sur papier'],
        ['--ocean-600', '--ocean-100', self::TEXT, 'texte d\'information'],
        ['--navy-900', '--ocean-100', self::TEXT, 'élément actif du menu'],
        ['--navy-900', '--ocean-50', self::TEXT, 'texte marine sur fond clair'],
        ['--gold-700', '--sheet', self::TEXT, 'accent doré sur panneau'],
        ['--gold-700', '--gold-100', self::TEXT, 'badge doré'],
        ['--ok', '--ok-bg', self::TEXT, 'succès'],
        ['--warn', '--warn-bg', self::TEXT, 'avertissement'],
        ['--danger', '--danger-bg', self::TEXT, 'erreur'],
        ['--danger', '--sheet', self::TEXT, 'message d\'erreur de champ'],
        ['#ffffff', '--navy-900', self::TEXT, 'bouton principal'],
        ['#ffffff', '--navy-800', self::TEXT, 'bouton principal au survol'],
        ['#ffffff', '--danger', self::TEXT, 'bouton destructeur'],
        ['--paper', '--navy-950', self::TEXT, 'texte clair sur marine (en-tête public)'],
        ['--gold-400', '--navy-950', self::TEXT, 'accent doré sur marine'],
        ['--ocean-100', '--navy-950', self::TEXT, 'texte secondaire sur marine'],
        ['--control-border', '--sheet', self::UI, 'bordure de champ sur panneau'],
        ['--control-border', '--paper', self::UI, 'bordure de champ sur papier'],
        ['--ocean-600', '--sheet', self::UI, 'anneau de focus'],
        ['--gold-400', '--navy-950', self::UI, 'anneau de focus sur marine'],
    ];

    /** @return array<string,string> jeton => valeur #hex, les alias var(--x) étant résolus */
    public static function parseTokens(string $css): array
    {
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $css, $m, PREG_SET_ORDER);
        $raw = [];
        foreach ($m as [, $name, $value]) {
            $raw[$name] = trim($value);
        }
        $out = [];
        foreach ($raw as $name => $value) {
            for ($i = 0; $i < 5 && preg_match('/^var\((--[\w-]+)\)$/', $value, $v) && isset($raw[$v[1]]); $i++) {
                $value = $raw[$v[1]];
            }
            if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
                $out[$name] = strtolower($value);
            }
        }
        return $out;
    }

    public static function ratio(string $hexA, string $hexB): float
    {
        [$a, $b] = [self::luminance($hexA), self::luminance($hexB)];
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $lin = static fn (int $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $lin((int) hexdec(substr($h, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($h, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($h, 4, 2)));
    }

    /**
     * @param list<array{0:string,1:string,2:float,3:string}>|null $pairs
     * @return list<array{fg:string,bg:string,min:float,use:string,ratio:?float,ok:bool}> ratio null : jeton introuvable (échec)
     */
    public static function check(string $css, ?array $pairs = null): array
    {
        $tokens = self::parseTokens($css);
        $rows = [];
        foreach ($pairs ?? self::PAIRS as [$fg, $bg, $min, $use]) {
            $a = $fg[0] === '#' ? strtolower($fg) : ($tokens[$fg] ?? null);
            $b = $bg[0] === '#' ? strtolower($bg) : ($tokens[$bg] ?? null);
            $ratio = $a !== null && $b !== null ? self::ratio($a, $b) : null;
            $rows[] = ['fg' => $fg, 'bg' => $bg, 'min' => $min, 'use' => $use, 'ratio' => $ratio, 'ok' => $ratio !== null && $ratio >= $min];
        }
        return $rows;
    }
}
