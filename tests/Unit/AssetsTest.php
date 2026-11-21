<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../../public/assets';

    /** @return list<string> */
    private function cssFiles(): array
    {
        return glob(self::ASSETS . '/css/*.css') ?: [];
    }

    public function test_all_css_files_exist_and_forbid_external_urls(): void
    {
        $this->assertFileExists(self::ASSETS . '/css/tokens.css');
        $this->assertFileExists(self::ASSETS . '/css/base.css');
        foreach ($this->cssFiles() as $file) {
            $css = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('~url\(\s*[\'"]?(https?:)?//~i', $css, basename($file));
            $this->assertDoesNotMatchRegularExpression('~@import\s+(url\(\s*)?[\'"]?(https?:)?//~i', $css, basename($file));
        }
    }

    public function test_base_css_sizes_icons_because_the_print_layout_loads_nothing_else(): void
    {
        $this->assertMatchesRegularExpression('/^\.icon\s*\{[^}]*width:\s*18px/m', (string) file_get_contents(self::ASSETS . '/css/base.css'));
        $this->assertDoesNotMatchRegularExpression('/^\.icon\s*\{/m', (string) file_get_contents(self::ASSETS . '/css/components.css'));
    }

    public function test_filters_reopen_when_the_viewport_widens(): void
    {
        $js = (string) file_get_contents(self::ASSETS . '/js/app.js');
        $this->assertStringContainsString("addEventListener('change', reopen)", $js);
    }

    public function test_tokens_define_every_variable_used_by_other_css_files(): void
    {
        $all = '';
        foreach ($this->cssFiles() as $file) {
            $all .= file_get_contents($file);
        }
        preg_match_all('~(--[a-z0-9-]+)\s*:~i', $all, $defined);
        preg_match_all('~var\(\s*(--[a-z0-9-]+)~i', $all, $used);
        $this->assertSame([], array_values(array_diff(array_unique($used[1]), $defined[1])));
        $tokens = (string) file_get_contents(self::ASSETS . '/css/tokens.css');
        foreach (['--navy-900', '--frame', '--s9', '--r-lg', '--control-h', '--focus-ring', '--ci-green', '--text-3xl'] as $v) {
            $this->assertStringContainsString($v . ':', $tokens);
        }
    }

    public function test_icon_sprite_has_every_icon_listed_in_the_plan(): void
    {
        $svg = (string) file_get_contents(self::ASSETS . '/img/icons.svg');
        $names = 'home users user-plus inbox coins shield heart anchor handshake landmark chart file search check x info alert menu plus chevron-down chevron-right logout lock key eye download print upload calendar clock';
        foreach (explode(' ', $names) as $name) {
            $this->assertStringContainsString('<symbol id="' . $name . '" ', $svg, $name);
        }
        $this->assertStringNotContainsString('style=', $svg);
    }

    public function test_emblem_pngs_exist_with_transparency_and_expected_sizes(): void
    {
        foreach ([40, 80, 160, 320] as $size) {
            $file = self::ASSETS . "/img/emblem-$size.png";
            $this->assertFileExists($file);
            $this->assertSame([$size, $size], array_slice(getimagesize($file), 0, 2));
            $im = imagecreatefrompng($file);
            $this->assertSame(127, (imagecolorat($im, 0, 0) >> 24) & 0x7F, "corner of $size");
            $mid = intdiv($size, 2);
            $this->assertSame(0, (imagecolorat($im, $mid, $mid) >> 24) & 0x7F, "centre of $size");
        }
        $this->assertSame(104, getimagesize(self::ASSETS . '/img/logo-complet.jpg')[1]);
        $this->assertSame(208, getimagesize(self::ASSETS . '/img/logo-complet@2x.jpg')[1]);
    }

    public function test_font_face_declarations_point_to_existing_files_or_are_absent(): void
    {
        $css = (string) file_get_contents(self::ASSETS . '/css/base.css');
        preg_match_all('~url\(\s*[\'"]?([^\'")]+)~', $css, $m);
        foreach ($m[1] as $url) {
            $this->assertFileExists(self::ASSETS . '/css/' . $url, $url);
        }
    }
}
