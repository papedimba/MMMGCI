<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\RichText;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Barrière XSS du site public : chaque sortie est aussi vérifiée idempotente et sans vecteur actif. */
final class RichTextTest extends TestCase
{
    private static function assertInert(string $out, string $label = ''): void
    {
        self::assertDoesNotMatchRegularExpression('~<\s*/?\s*(script|style|iframe|object|embed|svg|math|form|noscript|template|textarea|select|input|button|link|meta|base|table|div|span|font)\b~i', $out, $label);
        // Le texte sort toujours échappé (aucun « < » brut) : les vecteurs ne peuvent vivre que dans une balise.
        self::assertDoesNotMatchRegularExpression('~<[^>]*\son[a-z]+\s*=~i', $out, $label);
        self::assertDoesNotMatchRegularExpression('~<[^>]*((java|vb)script\s*:|data\s*:)~i', $out, $label);
        self::assertDoesNotMatchRegularExpression('~<[^>]*\s(style|class|id|title|target|srcset|sizes|data-[a-z-]*)\s*=~i', $out, $label);
        self::assertDoesNotMatchRegularExpression("~<[^>]*='~", $out, $label);
        self::assertSame($out, RichText::sanitize($out), 'idempotence : ' . $label);
    }

    public function test_script_tags_and_handlers_are_removed(): void
    {
        $this->assertSame('<p>a</p>', RichText::sanitize('<p onclick="x()" onmouseover=y>a<script>alert(1)</script></p>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<p>a</p><ScRiPt src="//evil.js"></ScRiPt>'));
        $this->assertSame('<strong>b</strong>', RichText::sanitize('<strong ONCLICK="x" data-x="1" style="color:red" class="c" id="i" title="t">b</strong>'));
    }

    public function test_javascript_and_data_urls_are_removed(): void
    {
        $this->assertSame('x', RichText::sanitize('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame('x', RichText::sanitize('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'));
        $this->assertSame('', RichText::sanitize('<img src="data:image/png;base64,AAAA" alt="a">'));
    }

    public function test_svg_math_and_style_are_removed(): void
    {
        $this->assertSame('<p>a</p>', RichText::sanitize('<p>a</p><svg onload=alert(1)><script>alert(1)</script></svg>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<p>a</p><math><mtext><img src=x onerror=alert(1)></mtext></math>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<style>p{background:url(javascript:x)}</style><p>a</p>'));
    }

    #[DataProvider('obfuscatedSchemes')]
    public function test_obfuscated_javascript_scheme_is_removed(string $href): void
    {
        $this->assertSame('x', RichText::sanitize('<a href="' . $href . '">x</a>'));
    }

    /** @return array<string,array{string}> */
    public static function obfuscatedSchemes(): array
    {
        return [
            'majuscules' => ['JaVaScRiPt:alert(1)'],
            'entité décimale' => ['&#106;avascript:alert(1)'],
            'entité hexa' => ['&#x6A;avascript:alert(1)'],
            'entité sans ;' => ['&#x6Aavascript:alert(1)'],
            'tabulation' => ["java\tscript:alert(1)"],
            'tabulation entité' => ['java&#x09;script:alert(1)'],
            'saut de ligne' => ["java\nscript:alert(1)"],
            'espace devant' => ['  javascript:alert(1)'],
            'caractère de contrôle' => ["\x01javascript:alert(1)"],
            '&Tab;' => ['&Tab;javascript:alert(1)'],
            'double encodage' => ['&amp;#106;avascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    public function test_h1_is_demoted_or_removed(): void
    {
        $this->assertSame('<h2>Titre</h2><h3>a</h3><h4>b</h4><h4>c</h4><h4>d</h4>', RichText::sanitize('<h1>Titre</h1><h3>a</h3><h4>b</h4><h5>c</h5><H6 class="x">d</H6>'));
        $this->assertStringNotContainsString('<h1', RichText::sanitize('<H1>x</H1><h1 >y</h1 >'));
    }

    public function test_external_links_get_rel_noopener(): void
    {
        $this->assertSame('<a href="https://exemple.org/a" rel="noopener noreferrer">x</a>', RichText::sanitize('<a href="https://exemple.org/a" target="_blank" title="t">x</a>'));
        $this->assertSame('<a href="http://exemple.org/" rel="noopener noreferrer">x</a>', RichText::sanitize('<a href="http://exemple.org/" rel="opener">x</a>'));
        $this->assertSame('<a href="/adhesion">x</a>', RichText::sanitize('<a href="/adhesion" rel="opener" target="_top">x</a>'));
        $this->assertSame('<a href="mailto:contact@exemple.ci">x</a>', RichText::sanitize('<a href="mailto:contact@exemple.ci">x</a>'));
        $this->assertSame('<a href="tel:+2250700000000">x</a>', RichText::sanitize('<a href="tel:+2250700000000">x</a>'));
        $this->assertSame('x', RichText::sanitize('<a href="#ancre">x</a>'), 'seuls http(s), mailto, tel et /… sont admis');
    }

    public function test_img_must_be_a_site_media_with_alt(): void
    {
        $this->assertSame('<img src="/media/2026/11/ab12.jpg" alt="Assemblée générale" width="800" height="600">',
            RichText::sanitize('<img src="/media/2026/11/ab12.jpg" alt="  Assemblée générale " width="800" height="600" srcset="/x.png 2x" sizes="1px" class="c" title="t">'));
        $this->assertSame('', RichText::sanitize('<img src="/media/a.png">'), 'alt absent');
        $this->assertSame('', RichText::sanitize('<img src="/media/a.png" alt="   ">'), 'alt vide');
        $this->assertSame('', RichText::sanitize('<img src="/uploads/a.png" alt="a">'), 'hors /media/');
        $this->assertSame('', RichText::sanitize('<img src="https://exemple.org/media/a.png" alt="a">'), 'externe');
        $this->assertSame('<img src="/media/a.webp" alt="a">', RichText::sanitize('<img src="/media/a.webp" alt="a" width="4001" height="0">'));
        $this->assertSame('<img src="/media/a.png" alt="a" height="4000">', RichText::sanitize('<img src="/media/a.png" alt="a" width="12px" height="4000">'));
    }

    #[DataProvider('refusedImgSrc')]
    public function test_img_src_variants_are_refused(string $src): void
    {
        $this->assertSame('<p>t</p>', RichText::sanitize('<p>t</p><img src="' . $src . '" alt="a">'));
    }

    /** @return array<string,array{string}> */
    public static function refusedImgSrc(): array
    {
        return [
            'data' => ['data:image/png;base64,iVBORw0KGgo='],
            'protocole relatif' => ['//evil.com/media/a.png'],
            'traversée' => ['/media/../config/a.png'],
            'traversée encodée' => ['/media/%2e%2e/a.png'],
            'segment point' => ['/media/./a.png'],
            'double barre' => ['/media//a.png'],
            'php' => ['/media/a.php'],
            'php déguisé' => ['/media/a.php.png?x=1'],
            'svg' => ['/media/a.svg'],
            'requête' => ['/media/a.png?x=1'],
            'fragment' => ['/media/a.png#x'],
            'majuscules schéma' => ['HTTPS://evil.com/media/a.png'],
            'majuscules chemin' => ['/MEDIA/a.png'],
            'javascript' => ['javascript:alert(1)//a.png'],
            'espace' => ['/media/a b.png'],
            'antislash' => ['/media/a\\..\\b.png'],
            'fichier caché' => ['/media/.htaccess.png'],
        ];
    }

    public function test_whitespace_and_control_chars_in_img_src_never_survive(): void
    {
        // Les navigateurs ignorent tabulations et retours dans une URL : HtmlSanitizer les retire, le chemin résultant est revérifié.
        foreach (["/media/a\t.png", "/media/a\n.png", "/media/a\x01.png", " /media/a.png "] as $src) {
            $out = RichText::sanitize('<img src="' . $src . '" alt="a">');
            $this->assertContains($out, ['', '<img src="/media/a.png" alt="a">'], addcslashes($src, "\0..\37"));
        }
        $this->assertSame('', RichText::sanitize("<img src=\"/media/a\x7f.png\" alt=\"a\">"));
    }

    #[DataProvider('refusedLinks')]
    public function test_links_variants_are_refused(string $href): void
    {
        $this->assertSame('<p>x</p>', RichText::sanitize('<p><a href="' . $href . '">x</a></p>'));
    }

    /** @return array<string,array{string}> */
    public static function refusedLinks(): array
    {
        return [
            'java\tscript' => ["java\tscript:alert(1)"],
            'casse mélangée' => ['JaVaScRiPt:alert(1)'],
            'entité hexa' => ['&#x6A;avascript:alert(1)'],
            'vbscript' => ['vbscript:x'],
            'file' => ['file:///etc/passwd'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'protocole relatif' => ['//evil.com'],
            'protocole relatif antislash' => ['/\\evil.com'],
            'ftp' => ['ftp://exemple.org/'],
            'relatif sans barre' => ['page.html'],
            'vide' => [''],
        ];
    }

    public function test_malformed_html_does_not_survive_as_markup_injection(): void
    {
        foreach ([
            '<p>a<img src=x onerror=alert(1)//</p>',
            '<a href="/x"onclick="alert(1)">x</a>',
            '<<script>script>alert(1)<</script>/script>',
            '<p <script>alert(1)</script>>x</p>',
            '<img """><script>alert(1)</script>">',
            '<a href="/x" <img src=x onerror=alert(1)>>y</a>',
            '<p>a</p><!--<img src=x onerror=alert(1)>-->',
            '<p title="&quot;><script>alert(1)</script>">t</p>',
            "<p>a\0<script>alert(1)</script></p>",
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
        ] as $in) {
            $out = RichText::sanitize($in);
            self::assertInert($out, $in);
            $this->assertStringNotContainsString('<script', strtolower($out), $in);
        }
    }

    public function test_deeply_nested_input_fails_closed(): void
    {
        $in = str_repeat('<div><em>', 600) . '<script>alert(1)</script><img src=x onerror=alert(1)>texte' . str_repeat('</em></div>', 600) . '<p>fin</p>';
        $out = RichText::sanitize($in);
        self::assertInert($out);
        $this->assertStringNotContainsString('alert', $out);
        $deep = str_repeat('<blockquote>', 300) . 'x' . str_repeat('</blockquote>', 300);
        self::assertInert(RichText::sanitize($deep));
    }

    public function test_comments_cdata_and_conditional_comments_are_removed(): void
    {
        $this->assertSame('<p>ab</p>', RichText::sanitize('<p>a<!-- <script>alert(1)</script> -->b</p>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<p>a</p><!--[if IE]><script>alert(1)</script><![endif]-->'));
        $out = RichText::sanitize('<p>a<![CDATA[<img src=x onerror=alert(1)>]]>b</p>');
        self::assertInert($out);
        $this->assertStringNotContainsString('<img', $out);
        $out = RichText::sanitize('<p>a</p><?php echo 1; ?><?xml-stylesheet href="x"?>');
        self::assertInert($out);
        $this->assertStringNotContainsString('<?', $out);
    }

    public function test_unclosed_and_misnested_tags_are_normalised(): void
    {
        $this->assertSame('<p><strong>a<em>b</em></strong>c</p>', RichText::sanitize('<p><strong>a<em>b</strong>c</em></p>'));
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', RichText::sanitize('<ul><li>a<li>b</ul>'));
        $this->assertSame('<p>a</p><p>b</p>', RichText::sanitize('<p>a<p>b'));
        self::assertInert(RichText::sanitize('<blockquote><h2>a<p>b</h2></blockquote></li></ul>c'));
    }

    public function test_attribute_injection_via_quotes_is_neutralised(): void
    {
        $out = RichText::sanitize('<img src="/media/a.png" alt="x&quot; onerror=&quot;alert(1)">');
        $this->assertSame('<img src="/media/a.png" alt="x&quot; onerror=&quot;alert(1)">', $out);
        self::assertSame($out, RichText::sanitize($out));
        $out = RichText::sanitize("<img src=\"/media/a.png\" alt='a\" onerror=\"alert(1)'>");
        $this->assertSame('<img src="/media/a.png" alt="a&quot; onerror=&quot;alert(1)">', $out);
        $out = RichText::sanitize('<a href="/x&quot; onclick=&quot;alert(1)">x</a>');
        $this->assertDoesNotMatchRegularExpression('~"\s+onclick~', $out);
        $this->assertSame($out, RichText::sanitize($out));
        $out = RichText::sanitize('<a href=\'https://e.org/"><script>alert(1)</script>\'>x</a>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertSame($out, RichText::sanitize($out));
    }

    public function test_script_and_style_contents_are_dropped_entirely(): void
    {
        $this->assertSame('<p>ab</p>', RichText::sanitize('<p>a<script>var secret = 1;</script>b</p>'));
        $this->assertSame('<p>ab</p>', RichText::sanitize('<p>a<style>.x{color:red}</style>b</p>'));
        $this->assertSame('', RichText::sanitize('<script>alert(1)</script><style>p{}</style><iframe>x</iframe><object>y</object><embed><form>z</form><textarea>t</textarea>'));
        $this->assertSame('<p>a</p>', RichText::sanitize('<p>a<noscript>n</noscript><template>t</template></p>'));
    }

    public function test_cms_whitelist_unwraps_other_elements_keeping_text(): void
    {
        $this->assertSame('<p>a b c</p>', RichText::sanitize('<p>a <span>b</span> <font color=red>c</font></p>'));
        $this->assertSame('cellule', RichText::sanitize('<table><tr><td>cellule</td></tr></table>'));
        $this->assertSame('<p>é 😀 1 &lt; 2 &amp; 3</p>', RichText::sanitize('<p>é 😀 1 &lt; 2 &amp; 3</p>'));
        $this->assertSame('<p>a<br>b</p><blockquote><p>c</p></blockquote><ol><li>d</li></ol>', RichText::sanitize('<p>a<br>b</p><blockquote><p>c</p></blockquote><ol><li>d</li></ol>'));
        $this->assertSame('', RichText::sanitize('   '));
    }

    /** @return list<string> */
    private static function mxssPayloads(): array
    {
        return [
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)></style></mglyph></table></mtext></math>',
            '<math><mtext><h1><a><h6></a></h6><mglyph><svg><mtext><style><a title="</style><img src onerror=alert(1)>"></style></h1>',
            '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
            '<math><mi><mglyph><svg><mtext><textarea><path id="</textarea><img onerror=alert(1) src=1>">',
            '<svg><style><img src=x onerror=alert(1)></style></svg>',
            '<a><table><a></table><p>x</p></a>',
            '<a href="/a"><table><a href="javascript:alert(1)"></table>y</a>',
            '<table><td><a href="/x"><table><td>z</td></table></a></td></table>',
            '<select><style></select><img src=x onerror=alert(1)></style>',
            '<select><template><style><!--</style><a rel="--></style></template></select><img src onerror=alert(1)>">',
            '<form><input name="x"><select><option><style>a{}</style></option></select></form>',
            '<listing>&lt;img src=x onerror=alert(1)&gt;</listing>',
            '<xmp><img src=x onerror=alert(1)></xmp>',
            '<plaintext><img src=x onerror=alert(1)>',
            '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
            '<object data="javascript:alert(1)"></object>',
            '<embed src="data:text/html,<script>alert(1)</script>">',
            '<a href="javas&#99;ript:alert(1)">x</a>',
            '<a href="&#0000106&#0000097&#0000118&#0000097&#0000115&#0000099&#0000114&#0000105&#0000112&#0000116&#0000058alert(1)">x</a>',
            '<img src="/media/a.png" alt="a" onerror="alert(1)">',
            '<img/src="/media/a.png"/alt="a"/onerror=alert(1)>',
            '<p style="background:url(javascript:alert(1))">x</p>',
            '<base href="javascript:/"><a href="/x">y</a>',
            '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
            '<details open ontoggle=alert(1)>x</details>',
            '<video><source onerror="alert(1)"></video>',
            '<body onload=alert(1)><p>x</p>',
            '<div id="x" data-bind="html: y" class="c">x</div>',
            '<!--><img src=x onerror=alert(1)>-->',
            '<?xml version="1.0"?><x:script xmlns:x="http://www.w3.org/1999/xhtml">alert(1)</x:script>',
            '<a href="/x" xlink:href="javascript:alert(1)">x</a>',
            "<p>\u{2028}<script>alert(1)</script></p>",
            '<noembed><img src=x onerror=alert(1)></noembed>',
            '<noframes><img src=x onerror=alert(1)></noframes>',
        ];
    }

    public function test_mutation_xss_vectors(): void
    {
        $this->assertGreaterThanOrEqual(25, count(self::mxssPayloads()));
        foreach (self::mxssPayloads() as $in) {
            $out = RichText::sanitize($in);
            self::assertInert($out, $in);
            $this->assertStringNotContainsStringIgnoringCase('alert(1)<', $out, $in); // jamais un contenu de script promu en balisage
            $this->assertDoesNotMatchRegularExpression('~<img(?![^>]*src="/media/)~i', $out, $in);
        }
    }

    public function test_sanitize_is_idempotent_on_a_corpus(): void
    {
        $corpus = array_merge(self::mxssPayloads(), array_column(self::obfuscatedSchemes(), 0), array_column(self::refusedImgSrc(), 0), [
            '<h1>T</h1><p>Texte <strong>gras <em>italique</em></strong> <a href="https://e.org">lien</a></p>',
            '<ul><li>a<ul><li>b</li></ul></li></ul><ol><li>c</ol>',
            '<blockquote>citation<p>para</p></blockquote>',
            '<p>a<br><br/>b</p><img src="/media/x/y_z-1.jpeg" alt="&lt;b&gt;" width="10">',
            '<p><p><p>x</p></p></p>',
            '<a href="/a"><a href="/b">imbriqués</a></a>',
            '<strong><p>bloc dans inline</p></strong>',
            '<em><ul><li>x</li></ul></em>',
            '<p><h2>titre dans para</h2></p>',
            '<li>orphelin</li>texte<li>2</li>',
            '<h2><img src="/media/a.png" alt="a"></h2>',
            "texte nu\n\navec   espaces &nbsp; insécables",
            '<p>&amp;lt;script&amp;gt;</p>',
            '<a href="mailto:a@b.ci?subject=x&amp;body=y">m</a>',
            '<a href="https://e.org/?a=1&b=2">q</a>',
            '<a href="/chemin avec espaces/é">u</a>',
            '<p>a</p></body></html><p>b</p>',
            '<table><tr><td><p>c</p></td></tr></table>',
        ]);
        foreach ($corpus as $in) {
            $once = RichText::sanitize($in);
            $this->assertSame($once, RichText::sanitize($once), $in);
        }
    }

    public function test_to_text_truncates_without_cutting_utf8(): void
    {
        $this->assertSame('Fonds de solidarité', RichText::toText('<h2>Fonds de</h2>  <p>solidarité<script>alert(1)</script></p>', 100));
        $text = RichText::toText('<p>Élan éducatif à Abidjan : entraide, épargne et été</p>', 25);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertSame('Élan éducatif à Abidjan…', $text);
        $this->assertLessThanOrEqual(25, mb_strlen($text));
        $this->assertSame('ééééééééé…', RichText::toText(str_repeat('é', 30), 10), 'mot unique : coupe au caractère');
        $this->assertSame('a < b & c', RichText::toText('<p>a &lt; b &amp; c</p>', 50));
        $this->assertSame('a b', RichText::toText("<p>a</p><p>b</p>", 3), 'pas de points de suspension sans troncature');
        $this->assertSame('', RichText::toText('', 10));
        $this->assertSame('😀😀…', RichText::toText('😀😀😀😀😀😀', 3));
    }

    public function test_from_request_field(): void
    {
        $this->assertSame('', RichText::fromRequestField(null));
        $this->assertSame('', RichText::fromRequestField(['<p>x</p>']));
        $this->assertSame('<p>x</p>', RichText::fromRequestField("  <p>x</p><script>y</script>\n"));
        $this->expectException(DomainException::class);
        RichText::fromRequestField(str_repeat('a', 101), 100);
    }
}
