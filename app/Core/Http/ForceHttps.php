<?php
declare(strict_types=1);

namespace App\Core\Http;

final class ForceHttps implements Middleware
{
    // Host plausible uniquement : évite qu'un Host forgé produise une redirection ouverte.
    private const VALID_HOST = '/^[A-Za-z0-9.\-:\[\]]+$/';

    public function __construct(private readonly string $appEnv)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if ($this->appEnv !== 'production' || $r->isSecure()) {
            return $next($r);
        }
        $host = $r->header('Host');
        if ($host === null || $host === '' || preg_match(self::VALID_HOST, $host) !== 1) {
            return new Response('Requête invalide : en-tête Host absent ou incorrect. Ouvrez le site depuis son adresse officielle.', 400);
        }
        $query = $r->queryString();
        return Response::redirect('https://' . $host . $r->path() . ($query !== '' ? '?' . $query : ''), 301);
    }
}
