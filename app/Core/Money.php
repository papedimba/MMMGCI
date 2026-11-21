<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use OverflowException;

/** Montants en centimes entiers ; aucun flottant. */
final class Money
{
    public const MESSAGE = 'Montant invalide : saisissez un nombre positif, par exemple 150 000 ou 150 000,50.';

    public static function parse(string $input): int
    {
        $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $input);
        if ($s === null || !preg_match('/^(\d{1,13})(?:[.,](\d{1,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException(self::MESSAGE);
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0'); // <= 13 chiffres : tient dans un int 64 bits
    }

    public static function fromDecimal(string $dbValue): int
    {
        if (!preg_match('/^(\d{1,13})(?:\.(\d{1,2})0*)?$/', $dbValue, $m)) {
            throw new InvalidArgumentException("Valeur DECIMAL invalide : $dbValue");
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = ltrim((string) $cents, '-');
        $abs = str_pad($abs, 3, '0', STR_PAD_LEFT);
        return $sign . substr($abs, 0, -2) . '.' . substr($abs, -2);
    }

    public static function format(int $cents): string
    {
        [$units, $dec] = explode('.', self::toDecimal($cents));
        $sign = '';
        if ($units[0] === '-') {
            $sign = '-';
            $units = substr($units, 1);
        }
        $units = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $units);
        return $sign . $units . ($dec === '00' ? '' : ',' . $dec) . ' XOF';
    }

    public static function add(int ...$cents): int
    {
        $sum = 0;
        foreach ($cents as $c) {
            $sum += $c;
            if (is_float($sum)) {
                throw new OverflowException('Dépassement de capacité lors de l\'addition de montants.');
            }
        }
        return $sum;
    }
}
