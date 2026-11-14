<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const MAX_ONCE = 20;

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->get('_csrf');
        if (!is_string($t)) {
            $t = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $t);
        }
        return $t;
    }

    public function check(?string $t): bool
    {
        return $t !== null && hash_equals($this->token(), $t);
    }

    public function oneTimeToken(string $form): string
    {
        $all = $this->session->get('_csrf_once') ?? [];
        unset($all[$form]); // réinsérer en fin pour que « le plus ancien » reste le plus ancien émis
        $all[$form] = bin2hex(random_bytes(32));
        $this->session->set('_csrf_once', array_slice($all, -self::MAX_ONCE, null, true));
        return $all[$form];
    }

    public function consumeOneTime(string $form, ?string $t): bool
    {
        $all = $this->session->get('_csrf_once') ?? [];
        $expected = $all[$form] ?? null;
        unset($all[$form]); // supprimé que la comparaison réussisse ou non
        $this->session->set('_csrf_once', $all);
        return $expected !== null && $t !== null && hash_equals($expected, $t);
    }
}
