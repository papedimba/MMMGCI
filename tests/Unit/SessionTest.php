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
}
