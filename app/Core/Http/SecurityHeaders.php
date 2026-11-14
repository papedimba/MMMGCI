<?php
declare(strict_types=1);

namespace App\Core\Http;

final class SecurityHeaders implements Middleware
{
    public const HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Frame-Options' => 'DENY',
    ];

    public function handle(Request $r, callable $next): Response
    {
        $res = $next($r);
        foreach (self::HEADERS as $name => $value) {
            $res = $res->withHeader($name, $value);
        }
        // HSTS seulement sur HTTPS : sur HTTP il serait ignoré par le navigateur, et l'envoyer en clair n'a pas de sens.
        return $r->isSecure() ? $res->withHeader('Strict-Transport-Security', 'max-age=31536000') : $res;
    }
}
