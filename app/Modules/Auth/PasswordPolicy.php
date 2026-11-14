<?php
declare(strict_types=1);

namespace App\Modules\Auth;

/** Longueur minimale, refus des mots de passe courants et de ceux dérivés de l'identité. */
final class PasswordPolicy
{
    private const MIN_LENGTH = 12;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    public static function check(string $password, string $email = '', string $name = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Mot de passe trop court : utilisez au moins 12 caractères (une phrase facile à retenir convient).';
        }
        self::$common ??= array_flip(array_map('mb_strtolower', file(__DIR__ . '/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
        $lower = mb_strtolower($password);
        $tokens = array_filter(
            [...preg_split('/\s+/u', mb_strtolower(trim($name))), explode('@', mb_strtolower(trim($email)))[0]],
            static fn (string $t): bool => mb_strlen($t) >= 4,
        );
        $weak = isset(self::$common[$lower]);
        foreach ($tokens as $t) {
            $weak = $weak || str_contains($lower, $t);
        }
        return $weak ? 'Mot de passe trop facile à deviner : évitez les mots de passe courants, votre nom et votre adresse e-mail.' : null;
    }

    public static function hash(string $p): string
    {
        return password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
