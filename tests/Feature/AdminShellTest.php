<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class AdminShellTest extends AdminTestCase
{
    private function dashboard(array $roles = ['super_admin']): string
    {
        $this->actingAs($this->createUser(uniqid('shell') . '@example.test', $roles));
        return $this->request('GET', '/admin')->body();
    }

    public function test_menu_shows_only_links_the_user_may_use(): void
    {
        $ro = $this->dashboard(['lecture_seule']);
        $this->assertStringContainsString('href="/admin"', $ro);
        foreach (['/admin/utilisateurs', '/admin/roles', '/admin/journal'] as $href) {
            $this->assertStringNotContainsString('class="nav-link" href="' . $href . '"', $ro);
        }
        $this->forgetApp();
        $sa = $this->dashboard();
        foreach (['/admin/utilisateurs', '/admin/roles', '/admin/journal'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $sa);
        }
    }

    public function test_menu_hides_links_whose_route_does_not_exist(): void
    {
        $nav = require dirname(__DIR__, 2) . '/app/views/admin/_nav.php';
        $this->assertNotEmpty($nav);
        $html = (new \App\Core\View(dirname(__DIR__, 2) . '/app/views'))->partial('admin/_nav-links', [
            'can' => static fn (string $p): bool => true, 'routeExists' => static fn (string $h): bool => $h === '/admin', 'current' => '/admin',
        ]);
        $this->assertStringContainsString('href="/admin"', $html);
        $this->assertStringNotContainsString('/admin/roles', $html);
    }

    public function test_current_page_link_has_aria_current(): void
    {
        $this->actingAs($this->createUser('cur@example.test', ['super_admin']));
        $html = $this->request('GET', '/admin/roles')->body();
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="/admin/roles"[^>]*aria-current="page"|aria-current="page"[^>]*href="/admin/roles"#', $html);
    }

    public function test_logout_is_a_post_form_with_csrf(): void
    {
        $html = $this->dashboard();
        $this->assertMatchesRegularExpression('#<form method="post" action="/admin/deconnexion">\s*<input type="hidden" name="_csrf" value="[^"]+">#', $html);
        $this->assertStringContainsString('href="/admin/mon-compte/mot-de-passe"', $html);
    }

    public function test_skip_link_and_single_h1_and_lang_fr(): void
    {
        $html = $this->dashboard();
        $this->assertStringContainsString('<html lang="fr">', $html);
        $this->assertStringContainsString('href="#contenu">Aller au contenu', $html);
        $this->assertStringContainsString('<main class="sheet" id="contenu"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringNotContainsString('style=', $html);
    }

    public function test_flash_message_is_rendered_with_role_status_and_escaped(): void
    {
        $this->actingAs($this->createUser('fl@example.test', ['super_admin']));
        $this->services['session']->flash('status', 'Fait <b>bien</b>');
        $html = $this->request('GET', '/admin')->body();
        $this->assertMatchesRegularExpression('#role="status".*Fait &lt;b&gt;bien&lt;/b&gt;#s', $html);
        $this->assertStringNotContainsString('<b>bien', $html);
    }

    public function test_menu_toggle_has_accessible_name_and_aria_expanded(): void
    {
        $html = $this->dashboard();
        $this->assertMatchesRegularExpression('#<button[^>]*data-menu-toggle[^>]*>#', $html);
        preg_match('#<button[^>]*data-menu-toggle[^>]*>#', $html, $m);
        $this->assertStringContainsString('aria-expanded="false"', $m[0]);
        $this->assertStringContainsString('aria-controls="menu-admin"', $m[0]);
        $this->assertStringContainsString('aria-label="Ouvrir le menu"', $m[0]);
        $this->assertStringContainsString('id="menu-admin"', $html);
    }

    public function test_section_link_is_current_on_sub_pages_and_topbar_is_a_banner(): void
    {
        $this->actingAs($this->createUser(uniqid('sub') . '@example.test', ['super_admin']));
        $html = $this->request('GET', '/admin/utilisateurs/nouveau')->body();
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#href="/admin/utilisateurs" aria-current="page"#', $html);
        $this->assertStringContainsString('<header class="topbar">', $html);
        $this->assertStringContainsString('/assets/js/js-flag.js', $html);
    }

    public function test_view_share_is_overridden_by_controller_data_and_replaced_per_call(): void
    {
        $dir = sys_get_temp_dir() . '/views' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/v.php', '<?= $a ?>-<?= $b ?? "none" ?>');
        $v = new \App\Core\View($dir);
        $v->share(['a' => 'shared', 'b' => 'B']);
        $this->assertSame('ctrl-B', $v->partial('v', ['a' => 'ctrl']));
        $v->share(['a' => 'new']);
        $this->assertSame('new-B', $v->partial('v'));
        unlink($dir . '/v.php');
        rmdir($dir);
    }

    public function test_share_layout_data_middleware_exposes_path_and_get_route_check(): void
    {
        $dir = sys_get_temp_dir() . '/views' . uniqid();
        mkdir($dir);
        $view = new \App\Core\View($dir);
        $router = new \App\Core\Http\Router();
        $router->get('/x', static fn () => new \App\Core\Http\Response('ok'));
        $router->post('/y', static fn () => new \App\Core\Http\Response('ok'));
        (new \App\Core\Http\ShareLayoutData($view, $router))->handle(
            new \App\Core\Http\Request('GET', '/x'),
            function () use ($view): \App\Core\Http\Response {
                file_put_contents(sys_get_temp_dir() . '/shared.php', '');
                return new \App\Core\Http\Response('');
            },
        );
        file_put_contents($dir . '/v.php', '<?= $currentPath ?>|<?= var_export($routeExists("/x"), true) ?>|<?= var_export($routeExists("/y"), true) ?>');
        $this->assertSame('/x|true|false', $view->partial('v'));
        unlink($dir . '/v.php');
        rmdir($dir);
    }
}
