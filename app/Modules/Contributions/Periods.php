<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Calcul pur des périodes d'appel. Le vocabulaire de périodicité est TECHNIQUE et « à valider par les instances » :
 * le cahier des charges ne fixe aucune périodicité, ce module ne fait que savoir découper le calendrier.
 */
final class Periods
{
    /** périodicité => nombre de mois */
    public const VOCABULARY = ['mensuelle' => 1, 'trimestrielle' => 3, 'semestrielle' => 6, 'annuelle' => 12];
    public const LABELS = ['mensuelle' => 'Mensuelle', 'trimestrielle' => 'Trimestrielle', 'semestrielle' => 'Semestrielle', 'annuelle' => 'Annuelle'];
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** @return array{label: string, start: string, end: string} période civile qui contient $date */
    public static function containing(string $periodicity, DateTimeImmutable $date): array
    {
        $months = self::VOCABULARY[$periodicity] ?? throw new InvalidArgumentException("Périodicité inconnue : $periodicity.");
        $year = (int) $date->format('Y');
        $first = intdiv((int) $date->format('n') - 1, $months) * $months + 1;
        $start = $date->setDate($year, $first, 1);
        $end = $start->modify('+' . $months . ' months -1 day');
        $label = match ($months) {
            1 => ucfirst(self::MONTHS[$first - 1]) . " $year",
            3 => 'T' . intdiv($first - 1, 3) + 1 . " $year",
            6 => 'S' . intdiv($first - 1, 6) + 1 . " $year",
            default => (string) $year,
        };
        return ['label' => $label, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    /** @return array{label: string, start: string, end: string} appel sans périodicité (droit d'adhésion, exceptionnelle) : un jour */
    public static function single(DateTimeImmutable $date): array
    {
        $d = $date->format('Y-m-d');
        return ['label' => 'Appel du ' . $date->format('d/m/Y'), 'start' => $d, 'end' => $d];
    }
}
