<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_idle_expiry_after_configured_minutes(): void
    {
        $store = [];
        $s = new Session($store, 30);
        $this->assertFalse($s->isExpired(1000));
        $s->touch(1000);
        $this->assertFalse($s->isExpired(1000 + 1800));
        $this->assertTrue($s->isExpired(1000 + 1801));
    }

    public function test_flash_is_read_once(): void
    {
        $store = [];
        $s = new Session($store, 30);
        $s->flash('ok', 'Enregistré');
        $this->assertSame('Enregistré', $s->pullFlash('ok'));
        $this->assertNull($s->pullFlash('ok'));
    }

    public function test_get_set_forget_and_invalidate(): void
    {
        $store = [];
        $s = new Session($store, 30);
        $s->set('a', 1);
        $this->assertSame(1, $s->get('a'));
        $s->forget('a');
        $this->assertNull($s->get('a'));
        $s->set('b', 2);
        $s->invalidate();
        $this->assertSame([], $store);
    }

    public function test_cookie_name_and_path_come_from_the_constructor(): void
    {
        // Vraie session PHP dans un processus fils (la sortie de PHPUnit empêcherait session_start ici).
        $code = 'require "' . dirname(__DIR__, 2) . '/app/Core/Session.php"; ini_set("session.save_path", sys_get_temp_dir()); $s = []; '
            . '(new App\Core\Session($s, 30, "mmmgci_esp", "/espace"))->start(true); $p = session_get_cookie_params(); '
            . 'echo json_encode([session_name(), $p["path"], $p["secure"], $p["httponly"], $p["samesite"]]); session_destroy();';
        $this->assertSame(['mmmgci_esp', '/espace', true, true, 'Lax'], json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), true));
        $code = str_replace('"mmmgci_esp", "/espace"', '"mmmgci_sid"', $code);
        $this->assertSame(['mmmgci_sid', '/', true, true, 'Lax'], json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), true));
    }
}
