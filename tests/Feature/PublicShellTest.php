<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Env;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class PublicShellTest extends TestCase
{
    private function home(): string
    {
        $env = ['APP_ENV' => 'testing'] + Env::load(dirname(__DIR__, 2) . '/.env');
        $res = (require dirname(__DIR__, 2) . '/app/kernel.php')(new Config($env), false)->handle(new Request('GET', '/'));
        $this->assertSame(200, $res->status());
        return $res->body();
    }

    public function test_public_page_has_logo_flag_line_menu_and_footer(): void
    {
        $html = $this->home();
        $this->assertMatchesRegularExpression('~<img[^>]+logo-complet[^>]+alt="[^"]{10,}"[^>]+width="118"[^>]+height="104"~', $html);
        $this->assertStringContainsString('class="flag"', $html);
        foreach (['flag-orange', 'flag-white', 'flag-green'] as $c) {
            $this->assertStringContainsString($c, $html);
        }
        $this->assertStringContainsString('<footer class="pub-foot">', $html);
        $this->assertStringContainsString('class="cordage"', $html);
        $this->assertStringContainsString('href="/admin"', $html);
    }

    public function test_without_published_menu_only_the_logo_is_shown(): void
    {
        // Le menu vient de PublishedContent::menu('header') (PublicPagesTest) : sans menu publié, aucun lien inventé ni bouton « Menu ».
        $html = $this->home();
        $this->assertStringNotContainsString('aria-label="Menu principal"', $html);
        $this->assertStringNotContainsString('data-menu-toggle', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
        $this->assertDoesNotMatchRegularExpression('~href=""|href="#"~', $html);
    }

    public function test_public_home_contains_no_invented_content(): void
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(head|header|footer)\b.*?</\1>~s', '', $this->home())), ENT_QUOTES);
        $this->assertStringContainsString('Le site de la Mutuelle est en cours de construction', $text);
        $this->assertDoesNotMatchRegularExpression('~\d~', $text);
        $this->assertDoesNotMatchRegularExpression('~@|\+\d|tél~i', $text);
        $this->assertStringNotContainsString('<img class="photo', $this->home());
    }

    public function test_gold_focus_ring_is_not_applied_over_the_light_paper_card(): void
    {
        // Le bouton doré est sur le papier clair : l'anneau doré (1,99:1) y est interdit ; seul un fond marine peut porter .on-dark.
        $this->assertStringNotContainsString('on-dark', $this->home());
    }
}
