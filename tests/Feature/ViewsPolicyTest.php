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

    /**
     * Site public (surface exposée) : tous ses gabarits sont couverts par la règle CSP ci-dessus, et chaque sortie « <?= »
     * passe par un échappement connu, un HTML déjà nettoyé (RichText::sanitize) ou un fragment rendu par le code (liste fermée).
     */
    public function test_public_layout_obeys_csp_policy_rules(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/app/views/layouts/public.php', $root . '/app/views/home.php'];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/views/public', \FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = $f->getPathname();
        }
        foreach (['texte', 'titre', 'intro', 'faits', 'domaines', 'liste', 'appel', 'image', 'documents', 'articles', 'evenements', 'formulaire', 'dossier'] as $type) {
            $this->assertContains($root . "/app/views/public/blocks/$type.php", $files, "bloc $type");
        }
        $this->assertSame(array_keys(\App\Modules\Cms\Blocks::TYPES), array_values(array_filter(array_keys(\App\Modules\Cms\Blocks::TYPES),
            static fn (string $t): bool => in_array($root . "/app/views/public/blocks/$t.php", $files, true))), 'chaque type de bloc a son gabarit public couvert');
        foreach (['standard', 'accueil', 'liste', 'not-found', 'article', 'evenement', 'galerie'] as $page) {
            $this->assertContains($root . "/app/views/public/pages/$page.php", $files, "gabarit $page");
        }
        foreach (['frame', 'step1', 'step2', 'step3', 'step4', 'step5', 'done', 'closed'] as $apply) {
            $this->assertContains($root . "/app/views/public/apply/$apply.php", $files, "adhésion en ligne : $apply");
        }
        $this->assertFileDoesNotExist($root . '/app/views/public/_menu.php', 'menu codé en dur supprimé : il vient de PublishedContent::menu');
        $allowed = '~^<\?=\s*(?:e\(|e_lines\(|ui\(|ui_icon\(|\(int\)|RichText::sanitize\(|\$body\s*\?>|\$content\s*\?>|\$link\(|responsive_img\(|\$tag\s*\?>|\$external \? \' rel="noopener noreferrer"\' : \'\'|'
            . '\$m\[\'width\'\] !== null && \$m\[\'height\'\] !== null \? \' width="\' \. \(int\)|\$card\[\'expires_at\'\] === null \?)~';
        $bad = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            foreach (['~<[^>]*\sstyle\s*=~i', '~<style\b~i', '~<script\b(?![^>]*\ssrc=)~i', '~<[^>]*\son[a-z]+\s*=~i', '~javascript:~i'] as $p) {
                if (preg_match($p, $src) === 1) {
                    $bad[] = basename($file) . ' : ' . $p;
                }
            }
            preg_match_all('~<\?=.*?(?:\?>|$)~m', $src, $m);
            foreach ($m[0] as $echo) {
                if (preg_match($allowed, $echo) !== 1) {
                    $bad[] = basename($file) . ' : sortie non échappée ' . $echo;
                }
            }
        }
        $this->assertSame([], $bad);
    }
}
