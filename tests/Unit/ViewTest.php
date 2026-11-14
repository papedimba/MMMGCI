<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mmmgci-views-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/layouts', 0777, true);
        file_put_contents($this->dir . '/hello.php', '<p><?= e($nom) ?></p>');
        file_put_contents($this->dir . '/layouts/base.php', '<main><?= $content ?></main>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/layouts/*') ?: []);
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        rmdir($this->dir . '/layouts');
        rmdir($this->dir);
    }

    public function test_render_escapes_by_default_via_e(): void
    {
        $out = (new View($this->dir))->render('hello', ['nom' => "<b>N'Guessan</b>"], null);
        $this->assertSame('<p>&lt;b&gt;N&#039;Guessan&lt;/b&gt;</p>', $out);
    }

    public function test_layout_wraps_content(): void
    {
        $out = (new View($this->dir))->render('hello', ['nom' => 'A']);
        $this->assertSame('<main><p>A</p></main>', $out);
    }

    public function test_null_layout_returns_bare_view(): void
    {
        $this->assertSame('<p>A</p>', (new View($this->dir))->render('hello', ['nom' => 'A'], null));
    }

    public function test_partial_renders_without_layout(): void
    {
        $this->assertSame('<p>A</p>', (new View($this->dir))->partial('hello', ['nom' => 'A']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNames')]
    public function test_bad_names_are_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new View($this->dir))->render($name);
    }

    public static function badNames(): array
    {
        return [['../../etc/passwd'], ['/etc/passwd'], ["hello\0"], ['absent']];
    }

    public function test_real_layout_renders_error_and_home_views(): void
    {
        $v = new View(dirname(__DIR__, 2) . '/app/views');
        $this->assertStringContainsString('<html lang="fr">', $v->render('home'));
        foreach (['404' => 'Page introuvable', '403' => 'Accès refusé', '419' => 'Votre session a expiré.'] as $c => $t) {
            $this->assertStringContainsString($t, $v->render("errors/$c"));
        }
        $this->assertStringNotContainsString('<script', $v->render('errors/404'));
    }
}
