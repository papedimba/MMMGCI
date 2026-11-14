<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private array $store = [];

    private function csrf(): Csrf
    {
        return new Csrf(new Session($this->store, 30));
    }

    public function test_token_is_stable_and_64_hex(): void
    {
        $c = $this->csrf();
        $t = $c->token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        $this->assertSame($t, $c->token());
    }

    public function test_check_rejects_null_and_wrong_token(): void
    {
        $c = $this->csrf();
        $this->assertTrue($c->check($c->token()));
        $this->assertFalse($c->check(null));
        $this->assertFalse($c->check(str_repeat('0', 64)));
    }

    public function test_one_time_token_cannot_be_replayed(): void
    {
        $c = $this->csrf();
        $t = $c->oneTimeToken('pay');
        $this->assertFalse($c->consumeOneTime('other', $t));
        $this->assertTrue($c->consumeOneTime('pay', $t));
        $this->assertFalse($c->consumeOneTime('pay', $t));
    }

    public function test_failed_attempt_burns_the_token(): void
    {
        $c = $this->csrf();
        $t = $c->oneTimeToken('pay');
        $this->assertFalse($c->consumeOneTime('pay', 'wrong'));
        $this->assertFalse($c->consumeOneTime('pay', $t));
    }

    public function test_keeps_at_most_20_pending_tokens(): void
    {
        $c = $this->csrf();
        $first = $c->oneTimeToken('f0');
        for ($i = 1; $i <= 20; $i++) {
            $c->oneTimeToken("f$i");
        }
        $this->assertFalse($c->consumeOneTime('f0', $first));
        $this->assertCount(20, $this->store['_csrf_once']);
    }
}
