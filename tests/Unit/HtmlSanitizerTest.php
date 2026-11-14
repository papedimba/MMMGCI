<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function test_script_and_event_handlers_are_removed(): void
    {
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p onclick="x()">a<script>b</script></p>'));
    }

    public function test_javascript_and_data_urls_are_removed(): void
    {
        $this->assertSame('<a>x</a>', HtmlSanitizer::clean('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame('', HtmlSanitizer::clean('<img src="data:text/html;base64,AAAA">'));
    }

    public function test_allowed_markup_and_accents_survive(): void
    {
        $html = '<h2>Fonds de solidarité</h2><p>N\'Guessan</p>';
        $this->assertSame($html, HtmlSanitizer::clean($html));
    }

    public function test_unknown_tag_keeps_text(): void
    {
        $this->assertSame('texte', HtmlSanitizer::clean('<span>texte</span>'));
    }

    #[DataProvider('hostileUrls')]
    public function test_hostile_urls_drop_the_attribute(string $html): void
    {
        $this->assertSame('<a>x</a>', HtmlSanitizer::clean($html));
    }

    public static function hostileUrls(): array
    {
        return [
            'mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'tab entity' => ['<a href="java&#x09;script:alert(1)">x</a>'],
            'newline entity' => ['<a href="jav&#10;ascript:alert(1)">x</a>'],
            'leading space' => ['<a href=" javascript:alert(1)">x</a>'],
            'protocol relative' => ['<a href="//evil.com">x</a>'],
            'backslash' => ['<a href="/\\evil.com">x</a>'],
            'vbscript' => ['<a href="vbscript:x">x</a>'],
        ];
    }

    public function test_img_onerror_and_missing_src(): void
    {
        $this->assertSame('<img src="/a.png">', HtmlSanitizer::clean('<img src=/a.png onerror=alert(1)>'));
        $this->assertSame('', HtmlSanitizer::clean('<img src=x onerror=alert(1)>'));
        $this->assertSame('', HtmlSanitizer::clean('<img alt="a">'));
    }

    public function test_img_dimensions_digits_only(): void
    {
        $this->assertSame('<img src="/a.png" width="120">', HtmlSanitizer::clean('<img src="/a.png" width="120" height="100%">'));
        $this->assertSame('<img src="/a.png">', HtmlSanitizer::clean('<img src="/a.png" width="12345">'));
    }

    public function test_svg_math_and_dangerous_elements_removed_with_content(): void
    {
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p>a</p><svg><script>alert(1)</script><circle/></svg>'));
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p>a</p><math><mi>alert(1)</mi></math>'));
        foreach (['style', 'iframe', 'object', 'embed', 'form', 'template', 'noscript', 'textarea', 'select', 'button'] as $t) {
            $this->assertSame('<p>a</p>', HtmlSanitizer::clean("<p>a</p><$t>evil</$t>"), $t);
        }
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p>a</p><input value="x"><link rel="x"><meta name="x"><base href="x">'));
    }

    public function test_nested_and_mixed_case_and_comments(): void
    {
        $this->assertSame('<ul><li><p>a</p></li></ul>', HtmlSanitizer::clean('<ul><li><p>a<script>x</script></p></li></ul>'));
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p>a</p><ScRiPt>alert(1)</sCrIpT>'));
        $this->assertSame('<p>ab</p>', HtmlSanitizer::clean('<p>a<!-- x -->b</p>'));
    }

    public function test_style_attribute_and_unknown_attributes_removed(): void
    {
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p style="x" class="y" id="z">a</p>'));
    }

    public function test_external_links_get_rel(): void
    {
        $this->assertSame(
            '<a href="https://example.org/" title="t" rel="noopener noreferrer">x</a>',
            HtmlSanitizer::clean('<a href="https://example.org/" title="t" target="_blank">x</a>')
        );
        $this->assertSame('<a href="/page">x</a>', HtmlSanitizer::clean('<a href="/page">x</a>'));
        $this->assertSame('<a href="mailto:a@b.ci">x</a>', HtmlSanitizer::clean('<a href="mailto:a@b.ci">x</a>'));
    }

    public function test_text_is_escaped_and_empty_input(): void
    {
        $this->assertSame('', HtmlSanitizer::clean(''));
        $this->assertSame('<p>1 &lt; 2 &amp; 3</p>', HtmlSanitizer::clean('<p>1 &lt; 2 &amp; 3</p>'));
    }
}
