<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money;
use App\Core\Validator;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_parse_french_inputs(): void
    {
        $this->assertSame(15000000, Money::parse('150 000'));
        $this->assertSame(15000000, Money::parse('150000'));
        $this->assertSame(15000050, Money::parse('150 000,50'));
        $this->assertSame(125, Money::parse('1.25'));
        $this->assertSame(250000, Money::parse(" 2\u{00A0}500 "));
        $this->assertSame(250000, Money::parse("2\u{202F}500"));
        $this->assertSame(1050, Money::parse('10,5'));
        $this->assertSame(999999999999999, Money::parse('9 999 999 999 999,99'));
    }

    public function test_parse_rejects_scientific_negative_empty_and_three_decimals(): void
    {
        foreach (['1.5e3', '-500', '', '10,123', '10 000 000 000 000', 'abc', '10,'] as $in) {
            try {
                Money::parse($in);
                $this->fail("« $in » aurait dû être refusé");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Montant invalide : saisissez un nombre positif, par exemple 150 000 ou 150 000,50.', $e->getMessage());
            }
        }
    }

    public function test_format_uses_narrow_nbsp_and_hides_zero_cents(): void
    {
        $this->assertSame("150\u{202F}000 XOF", Money::format(15000000));
        $this->assertSame("150\u{202F}000,50 XOF", Money::format(15000050));
        $this->assertSame('0 XOF', Money::format(0));
        $this->assertSame('5,05 XOF', Money::format(505));
        $this->assertSame("1\u{202F}000 XOF", money_fmt(100000));
    }

    public function test_decimal_roundtrip(): void
    {
        $this->assertSame('150000.50', Money::toDecimal(Money::fromDecimal('150000.50')));
        $this->assertSame(15000000, Money::fromDecimal('150000.00'));
        $this->assertSame('0.05', Money::toDecimal(5));
    }

    public function test_add_and_overflow(): void
    {
        $this->assertSame(600, Money::add(100, 200, 300));
        $this->expectException(OverflowException::class);
        Money::add(PHP_INT_MAX, 1);
    }

    public function test_validator_amount_rule_uses_money_parse(): void
    {
        $ok = Validator::make(['m' => '150 000,50'], ['m' => 'required|amount']);
        $this->assertFalse($ok->fails());
        $this->assertSame(15000050, $ok->validated()['m']);
        $bad = Validator::make(['m' => '-5'], ['m' => 'amount']);
        $this->assertSame(Money::MESSAGE, $bad->errors()['m']);
    }
}
