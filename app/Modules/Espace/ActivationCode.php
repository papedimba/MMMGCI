<?php
declare(strict_types=1);

namespace App\Modules\Espace;

/** Codes d'activation (12 caractères sans ambiguïté, affichés XXXX-XXXX-XXXX) : seul leur HMAC est stocké. */
final class ActivationCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'; // ni 0/O, ni 1/I/L
    public const LENGTH = 12;

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $code;
    }

    public static function format(string $code): string
    {
        return implode('-', str_split(self::normalize($code), 4));
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    }

    /** HMAC SHA-256 de la forme normalisée (codes et jetons « oubli ») : une base volée ne suffit pas à retrouver un code. */
    public static function hash(string $code, string $appKey): string
    {
        return hash_hmac('sha256', self::normalize($code), $appKey);
    }
}
