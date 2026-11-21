<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function test_e_escapes_html_and_keeps_ivorian_names(): void
    {
        $this->assertSame('&lt;script&gt;', e('<script>'));
        $this->assertSame('N&#039;Guessan Kouamé Aïcha', e("N'Guessan Kouamé Aïcha"));
        $this->assertSame('', e(null));
    }
}
