<?php
declare(strict_types=1);

namespace App\Core\Export;

use InvalidArgumentException;

/**
 * CSV pour Excel en paramètres régionaux français : BOM UTF-8, `;`, CRLF.
 * shortcut: la chaîne est construite en mémoire (environ 87 Mo pour 200 000 lignes XLSX), passer à l'écriture en flux au-delà.
 */
final class Csv
{
    public static function build(array $headers, iterable $rows): string
    {
        $out = "\xEF\xBB\xBF" . self::line($headers);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }
        return $out;
    }

    private static function line(iterable $cells): string
    {
        $parts = [];
        foreach ($cells as $cell) {
            $parts[] = self::cell($cell);
        }
        return implode(';', $parts) . "\r\n";
    }

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v) && !is_finite($v)) {
            return '';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (!is_string($v)) {
            throw new InvalidArgumentException('Valeur d\'export non prise en charge : un texte, un nombre, un booléen ou null est attendu.');
        }
        // Un vrai nombre (même négatif) n'est pas une formule : on le laisse tel quel.
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !preg_match('/^-?\d+(?:[.,]\d+)?$/D', $v)) {
            $v = "'" . $v;
        }
        return strpbrk($v, ";\"\r\n") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
    }
}
