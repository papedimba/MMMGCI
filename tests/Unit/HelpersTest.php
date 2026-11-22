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

    public function test_responsive_img_escapes_every_value(): void
    {
        $x = '"><script>alert(1)</script>';
        $html = responsive_img(['url' => '/media/a.jpg' . $x, 'alt' => $x, 'width' => 1600, 'height' => 900, 'variants' => [['url' => '/media/a-480.jpg' . $x, 'width' => 480]]], $x);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(1, substr_count($html, '<'), 'une seule balise');
        $this->assertStringContainsString(' width="1600" height="900" loading="lazy" decoding="async">', $html);
        $this->assertStringNotContainsString('srcset', responsive_img(['url' => '/media/a.jpg', 'alt' => 'a', 'width' => null, 'height' => null, 'variants' => []]));
    }
}
