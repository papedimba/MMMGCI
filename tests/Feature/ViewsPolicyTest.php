<?php
declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** La CSP (style-src 'self', script-src 'self') interdit tout style, script ou gestionnaire en ligne : on le vérifie à la source. */
final class ViewsPolicyTest extends TestCase
{
    public function test_no_inline_style_or_script_or_event_handler_in_any_view(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/views', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = $f->getPathname();
        }
        $files = array_merge($files, glob($root . '/public/assets/js/*.js') ?: []);
        $this->assertNotEmpty($files);
        $this->assertContains($root . '/app/views/layouts/public.php', $files, 'le gabarit public doit être couvert');
        $this->assertContains($root . '/app/views/home.php', $files);
        $bad = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $isJs = str_ends_with($file, '.js');
            $patterns = $isJs
                ? ['~setAttribute\(\s*[\'"]style~i', '~cssText~', '~\binnerHTML\b~']
                : ['~<[^>]*\sstyle\s*=~i', '~<style\b~i', '~<script\b(?![^>]*\ssrc=)~i', '~<[^>]*\son[a-z]+\s*=~i', '~javascript:~i'];
            foreach ($patterns as $p) {
                if (preg_match($p, $src) === 1) {
                    $bad[] = basename($file) . ' : ' . $p;
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
