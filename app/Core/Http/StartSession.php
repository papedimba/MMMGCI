<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Session;

final class StartSession implements Middleware
{
    /**
     * @param bool $httpSession false en CLI/tests : la session reste un tableau local, sans cookie
     * @param list<string> $except chemins servis sans session (sondes) ; un chemin terminé par « / » vaut pour tout ce qu'il contient
     */
    public function __construct(private readonly Session $session, private readonly bool $httpSession = true, private readonly array $except = [])
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        foreach ($this->except as $path) {
            if ($r->path() === $path || (str_ends_with($path, '/') && str_starts_with($r->path(), $path))) {
                return $next($r);
            }
        }
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
