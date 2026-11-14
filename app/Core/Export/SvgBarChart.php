<?php
declare(strict_types=1);

namespace App\Core\Export;

/** Histogramme empilé en SVG, suivi d'un tableau de données accessible. */
final class SvgBarChart
{
    private const COLORS = ['#1A3F86', '#D2AE55'];
    private const W = 600;
    private const H = 300;

    /** @param string[] $labels @param array<string, array<int, int|float>> $series nom de série => valeurs alignées sur $labels */
    public static function render(array $labels, array $series, string $title): string
    {
        $t = self::e($title);
        $id = 'chart-title-' . substr(md5($title), 0, 8);
        if ($labels === [] || $series === []) {
            return '<figure><figcaption>' . $t . '</figcaption><p>Aucune donnée pour cette période.</p></figure>';
        }

        $labels = array_values($labels);
        $names = array_keys($series);
        $series = array_values(array_map(fn($v) => array_values($v), $series));
        $count = count($labels);
        $max = 0;
        for ($i = 0; $i < $count; $i++) {
            $max = max($max, array_sum(array_map(fn($v) => max(0, $v[$i] ?? 0), $series)));
        }
        $max = $max > 0 ? $max : 1; // toutes valeurs à zéro : pas de division par zéro
        $slot = self::W / $count;
        $bw = round($slot * 0.6, 2);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-labelledby="' . $id . '">'
            . '<title id="' . $id . '">' . $t . '</title>';
        for ($i = 0; $i < $count; $i++) {
            $y = self::H - 20;
            $x = round($i * $slot + ($slot - $bw) / 2, 2);
            foreach ($series as $k => $values) {
                $h = round(max(0, $values[$i] ?? 0) / $max * (self::H - 40), 2);
                $y = round($y - $h, 2);
                $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . $h . '" fill="' . self::COLORS[$k % 2] . '"/>';
            }
        }
        $svg .= '</svg>';

        $table = '<table><caption>' . $t . '</caption><thead><tr><th scope="col">Période</th>';
        foreach ($names as $n) {
            $table .= '<th scope="col">' . self::e((string) $n) . '</th>';
        }
        $table .= '</tr></thead><tbody>';
        foreach ($labels as $i => $label) {
            $table .= '<tr><th scope="row">' . self::e((string) $label) . '</th>';
            foreach ($series as $values) {
                $table .= '<td>' . ($values[$i] ?? 0) . '</td>';
            }
            $table .= '</tr>';
        }
        return '<figure>' . $svg . $table . '</tbody></table></figure>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
