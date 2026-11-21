<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class RichEditorTest extends AdminTestCase
{
    private const JS = __DIR__ . '/../../public/assets/js/editor.js';

    public function test_editor_js_is_external_and_csp_clean(): void
    {
        $src = (string) file_get_contents(self::JS);
        $this->assertNotSame('', $src);
        $this->assertLessThanOrEqual(12 * 1024, strlen($src), 'editor.js ≤ 12 Ko');
        foreach ([
            '~\binnerHTML\b~', '~\bouterHTML\b~', '~insertAdjacentHTML~', '~\beval\s*\(~', '~new\s+Function~', '~document\.write~',
            '~setAttribute\(\s*[\'"]on~i', '~setAttribute\(\s*[\'"]style~i', '~insertHTML~', '~\.on[a-z]+\s*=~', '~javascript:~i', '~setTimeout\(\s*[\'"]~',
        ] as $p) {
            $this->assertDoesNotMatchRegularExpression($p, $src, $p);
        }
        $this->assertStringContainsString('DOMParser', $src, 'contenu externe analysé puis reconstruit');
    }

    public function test_admin_layout_loads_editor_js_deferred(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../../app/views/layouts/admin.php');
        $this->assertMatchesRegularExpression('~<script src="/assets/js/editor\.js" defer></script>~', $layout);
    }

    public function test_component_works_without_js_and_escapes_value(): void
    {
        $html = ui('rich-editor', ['name' => 'corps', 'label' => 'Contenu', 'value' => '<p>a</p></textarea><script>alert(1)</script>', 'hint' => 'Aide', 'error' => 'Trop long', 'required' => true]);
        $this->assertStringContainsString('data-rich-editor', $html);
        $this->assertMatchesRegularExpression('~<textarea id="f-corps" name="corps" class="control rich-source"[^>]* required>&lt;p&gt;a&lt;/p&gt;&lt;/textarea&gt;&lt;script&gt;alert\(1\)&lt;/script&gt;</textarea>~', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(1, substr_count($html, '</textarea>'));
        $this->assertStringContainsString('<label for="f-corps" id="f-corps-label">', $html);
        $this->assertStringContainsString('aria-describedby="f-corps-hint f-corps-help f-corps-err"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('id="f-corps-err" role="alert"', $html);
        $this->assertStringContainsString('id="f-corps-help">Balises autorisées', $html, 'aide visible pour le mode sans JS');
        $this->assertStringNotContainsString('contenteditable', $html, 'la zone éditable n\'existe qu\'avec JS');
    }

    public function test_gallery_shows_the_rich_editor_example(): void
    {
        $this->actingAs($this->createUser('admin-rte@example.test', ['super_admin']));
        $this->env = ['APP_ENV' => 'local'];
        $res = $this->request('GET', '/admin/composants');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('data-rich-editor', $res->body());
        $this->assertStringContainsString('<script src="/assets/js/editor.js" defer></script>', $res->body());
    }

    public function test_href_never_keeps_raw_space_or_control(): void
    {
        foreach (["/a b", "https://x.test/a\x01b", "/a\x00b", " /ok b", "/a%20b c"] as $href) {
            $out = \App\Modules\Cms\RichText::sanitize('<p><a href="' . $href . '">texte</a></p>');
            $this->assertSame(0, preg_match('/href="[^"]*[\x00-\x20]/', $out), $href); // jamais d'espace ni de contrôle brut dans un href
            $this->assertStringContainsString('texte', $out);
        }
        $this->assertStringContainsString('href="/ok"', \App\Modules\Cms\RichText::sanitize('<p><a href=" /ok ">t</a></p>'));
    }
}
