<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Session;

final class StartSession implements Middleware
{
    /** @param bool $httpSession false en CLI/tests : la session reste un tableau local, sans cookie */
    public function __construct(private readonly Session $session, private readonly bool $httpSession = true)
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if ($this->httpSession) {
            $this->session->start($r->isSecure());
        }
        $now = time();
        if ($this->session->isExpired($now)) {
            $this->session->invalidate();
            if ($this->httpSession) {
                $this->session->start($r->isSecure());
                $this->session->regenerate(); // nouvel identifiant, jamais celui de la session expirée
            }
        }
        $this->session->touch($now);
        return $next($r);
    }
}
