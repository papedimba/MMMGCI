<?php
declare(strict_types=1);

namespace App\Core;

/** Clé des compteurs par adresse IP (formulaires publics, adhésion en ligne) : une seule définition partagée. */
final class IpKey
{
    /** IPv4 telle quelle ; IPv4 mappée (::ffff:a.b.c.d) = la clé de l'IPv4 ; IPv6 regroupée par /64 (une personne dispose de 2^64 adresses). */
    public static function of(string $ip): string
    {
        $ip = mb_substr($ip, 0, 45);
        $bin = str_contains($ip, ':') ? @inet_pton($ip) : false;
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }
        if (str_starts_with($bin, str_repeat("\0", 8))) {
            return (string) inet_ntop($bin); // ::1, :: et apparentées : pas de regroupement (un seul « /64 » les réunirait toutes)
        }
        return bin2hex(substr($bin, 0, 8)) . '/64';
    }
}
