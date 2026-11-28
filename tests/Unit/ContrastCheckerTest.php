<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ContrastChecker as C;
use PHPUnit\Framework\TestCase;

final class ContrastCheckerTest extends TestCase
{
    private const TOKENS = __DIR__ . '/../../public/assets/css/tokens.css';

    public function test_ratio_matches_wcag_reference_values(): void
    {
        $this->assertEqualsWithDelta(21.0, C::ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, C::ratio('#777', '#777777'), 0.001);
        $this->assertEqualsWithDelta(4.48, C::ratio('#777777', '#ffffff'), 0.01);
    }

    public function test_parse_resolves_aliases_and_ignores_non_colours(): void
    {
        $t = C::parseTokens(':root { --a: #ABCDEF; --b: var(--a); --s1: 4px; }');
        $this->assertSame(['--a' => '#abcdef', '--b' => '#abcdef'], $t);
    }

    public function test_a_failing_and_a_missing_pair_are_reported(): void
    {
        $rows = C::check(':root { --fg: #777777; --bg: #fff; }', [['--fg', '--bg', 4.5, 'x'], ['--fg', '--absent', 4.5, 'y'], ['--fg', '#ffffff', 4.0, 'z']]);
        $this->assertSame([false, false, true], array_column($rows, 'ok'));
        $this->assertNull($rows[1]['ratio']);
    }

    public function test_every_declared_pair_of_the_real_tokens_passes(): void
    {
        foreach (C::check((string) file_get_contents(self::TOKENS)) as $r) {
            $this->assertTrue($r['ok'], sprintf('%s sur %s (%s) : %s, minimum %.1f', $r['fg'], $r['bg'], $r['use'], $r['ratio'] === null ? 'introuvable' : round($r['ratio'], 2) . ':1', $r['min']));
        }
    }

    public function test_cli_exits_zero_on_the_repository_tokens(): void
    {
        exec('php ' . escapeshellarg(__DIR__ . '/../../bin/check-contrast.php'), $out, $code);
        $this->assertSame(0, $code);
    }
}
