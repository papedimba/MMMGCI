<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\Scheduler;

/** Purges planifiées des tables d'authentification (enregistrées dans bin/cron.php). */
final class AuthMaintenance
{
    public const RETENTION_SECONDS = 86400;

    public static function register(Scheduler $scheduler, Database $db): void
    {
        $scheduler->register('login_attempts.purge', 60, static fn () => (new LoginThrottle($db))->purge(time()));
        $scheduler->register('password_resets.purge', 60, static fn () => self::purgePasswordResets($db, time()));
        $scheduler->register('member_forgot_tokens.purge', 60, static fn () => self::purgeMemberForgotTokens($db, time()));
    }

    /** Jetons « oubli » de l'Espace adhérent expirés ou utilisés depuis plus de 24 h ; les codes d'activation restent (historique). */
    public static function purgeMemberForgotTokens(Database $db, int $now): void
    {
        $limit = gmdate('Y-m-d H:i:s', $now - self::RETENTION_SECONDS);
        $db->query("DELETE FROM member_activation_codes WHERE purpose = 'oubli' AND (expires_at < ? OR used_at < ?)", [$limit, $limit]);
    }

    /** Supprime les jetons expirés ou utilisés depuis plus de 24 h. */
    public static function purgePasswordResets(Database $db, int $now): void
    {
        $limit = gmdate('Y-m-d H:i:s', $now - self::RETENTION_SECONDS);
        $db->query('DELETE FROM password_resets WHERE expires_at < ? OR used_at < ?', [$limit, $limit]);
    }
}
