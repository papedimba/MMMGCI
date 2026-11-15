<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Vérification publique d'une carte (sans session). Tous les cas invalides rendent la même page neutre, en 200 :
 * aucune différence ne permet de distinguer jeton inconnu, carte révoquée, expirée, adhérent supprimé ou radié/décédé.
 */
final class VerifyController
{
    /** Statuts pour lesquels la carte n'est plus valable (cités par le cahier des charges ; aucun autre statut n'est jugé ici). */
    public const INVALID_STATUSES = ['radie', 'decede'];
    private const MAX_PER_MINUTE = 30;
    private const THROTTLE_MARK = 4; // login_attempts.succeeded : jamais compté par LoginThrottle (qui ne compte que 0) ; purgé avec le reste

    public function __construct(private View $view, private Database $db)
    {
    }

    public function show(Request $r): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];
        $now = time();
        $ip = mb_substr($r->ip(), 0, 45);
        $since = gmdate('Y-m-d H:i:s', $now - 60);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = ? AND created_at >= ?', [$ip, self::THROTTLE_MARK, $since]) >= self::MAX_PER_MINUTE) {
            return new Response('Trop de vérifications, réessayez dans une minute.', 429, $headers + ['Retry-After' => '60', 'Content-Type' => 'text/plain; charset=utf-8']);
        }
        $this->db->insert('login_attempts', ['email' => 'verification', 'ip' => $ip, 'succeeded' => self::THROTTLE_MARK, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);

        $token = (string) $r->attribute('jeton');
        $row = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $this->db->fetch(
            'SELECT m.last_name, m.first_names, m.member_number, m.status, s.label AS status_label, c.expires_at
             FROM membership_cards c JOIN members m ON m.id = c.member_id LEFT JOIN member_statuses s ON s.code = m.status
             WHERE c.verify_token = ? AND c.revoked_at IS NULL AND m.deleted_at IS NULL', [$token]) : null;
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Africa/Abidjan'))->format('Y-m-d');
        $valid = $row !== null && !in_array($row['status'], self::INVALID_STATUSES, true) && ($row['expires_at'] === null || $row['expires_at'] >= $today);
        return new Response($this->view->render('public/verify', ['title' => 'Vérification de carte', 'card' => $valid ? $row : null], 'layouts/public'), 200, $headers);
    }
}
