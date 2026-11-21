<?php
declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Session;

final class StartSession implements Middleware
{
    /**
     * @param bool $httpSession false en CLI/tests : la session reste un tableau local, sans cookie
     * @param list<string> $except chemins servis sans session (sondes) ; un chemin terminé par « / » vaut pour tout ce qu'il contient
     * @param list<string> $only si non vide, SEULS ces chemins (même forme) ouvrent une session : le site public reste sans cookie
     */
    public function __construct(private readonly Session $session, private readonly bool $httpSession = true, private readonly array $except = [], private readonly array $only = [])
    {
    }

    public function handle(Request $r, callable $next): Response
    {
        if (self::matches($r->path(), $this->except) || ($this->only !== [] && !self::matches($r->path(), $this->only))) {
            return $next($r);
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

    /** @param list<string> $paths */
    private static function matches(string $path, array $paths): bool
    {
        foreach ($paths as $p) {
            if ($path === $p || (str_ends_with($p, '/') && str_starts_with($path, $p))) {
                return true;
            }
        }
        return false;
    }
}
