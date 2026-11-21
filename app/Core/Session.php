<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    /** @param array<string,mixed> $store $_SESSION en production, tableau local en test */
    public function __construct(private array &$store, private readonly int $idleMinutes, private readonly string $name = 'mmmgci_sid')
    {
    }

    public function start(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Le ramasse-miettes ne doit pas supprimer une session encore dans son délai d'inactivité.
        ini_set('session.gc_maxlifetime', (string) max((int) ini_get('session.gc_maxlifetime'), $this->idleMinutes * 60));
        session_name($this->name);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $this->store = &$_SESSION;
    }

    public function get(string $k): mixed { return $this->store[$k] ?? null; }

    public function set(string $k, mixed $v): void { $this->store[$k] = $v; }

    public function forget(string $k): void { unset($this->store[$k]); }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function touch(int $now): void { $this->store['_last_activity'] = $now; }

    public function isExpired(int $now): bool
    {
        $last = $this->store['_last_activity'] ?? null;
        return $last !== null && $now - $last > $this->idleMinutes * 60;
    }

    public function flash(string $k, mixed $v): void { $this->store['_flash'][$k] = $v; }

    public function pullFlash(string $k): mixed
    {
        $v = $this->store['_flash'][$k] ?? null;
        unset($this->store['_flash'][$k]);
        return $v;
    }

    public function invalidate(): void
    {
        $this->store = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            session_destroy();
            if (!headers_sent()) {
                unset($params['lifetime']);
                setcookie($this->name, '', ['expires' => 1] + $params);
            }
        }
    }
}
