<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cms\MenuInvalid;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use Tests\Support\AdminTestCase;

final class MenuTest extends AdminTestCase
{
    private const EVIL = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    /** Les envois du formulaire de menu portent le champ sentinelle final, sauf '_form_complete' => null. */
    protected function request(string $method, string $path, array $post = [], array $files = []): \App\Core\Http\Response
    {
        if (strtoupper($method) === 'POST' && str_starts_with($path, '/admin/site/menus/')) {
            $post += ['_form_complete' => '1'];
            if ($post['_form_complete'] === null) {
                unset($post['_form_complete']);
            }
        }
        return parent::request($method, $path, $post, $files);
    }

    private function service(): MenuService
    {
        $this->app();
        $gate = $this->services['gate']();
        $audit = $this->services['audit']();
        return new MenuService($this->db, $audit, $gate, new PageService($this->db, $audit, $gate));
    }

    private function page(string $title, bool $publish): int
    {
        $this->app();
        $pages = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        $id = $pages->create(['title' => $title, 'blocks' => [['type' => 'titre', 'text' => $title, 'level' => '2']]], $this->author);
        if ($publish) {
            $pages->publish($id, $this->publisher, null, (int) $pages->get($id)['content_version']);
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function item(string $label, array $extra = []): array
    {
        return $extra + ['label' => $label, 'url' => '/contact', 'depth' => 0, 'is_active' => 1];
    }

    private function save(string $loc, array $items): void
    {
        $this->service()->save($loc, $items, $this->publisher);
    }

    private function refused(string $loc, array $items): MenuInvalid
    {
        try {
            $this->save($loc, $items);
        } catch (MenuInvalid $e) {
            return $e;
        }
        $this->fail('Menu accepté à tort.');
    }

    public function test_header_menu_is_limited_to_seven_top_level_items(): void
    {
        $seven = array_map(fn (int $i): array => $this->item("L$i"), range(1, 7));
        $this->save('header', $seven);
        $this->assertCount(7, $this->service()->items('header'));
        $this->assertArrayHasKey('menu', $this->refused('header', [...$seven, $this->item('Huitième')])->errors);
        $this->assertCount(7, $this->service()->items('header'), 'ancien menu intact');
        $twelve = array_map(fn (int $i): array => $this->item("F$i"), range(1, 12));
        $this->save('footer', $twelve);
        $this->assertArrayHasKey('menu', $this->refused('footer', [...$twelve, $this->item('13')])->errors);
        // Sous-éléments : ne comptent pas parmi les 7, mais le total est borné à 40.
        $subs = array_map(fn (int $i): array => $this->item("S$i", ['depth' => 1]), range(1, 33));
        $this->save('header', [...$seven, ...$subs]);
        $this->assertArrayHasKey('menu', $this->refused('header', [...$seven, ...$subs, $this->item('S34', ['depth' => 1])])->errors);
    }

    public function test_depth_limit(): void
    {
        $this->assertArrayHasKey('items[0].depth', $this->refused('header', [$this->item('Sous', ['depth' => 1])])->errors);
        $this->assertArrayHasKey('items[1].depth', $this->refused('header', [$this->item('A'), $this->item('B', ['depth' => 2])])->errors);
        $this->save('header', [$this->item('A'), $this->item('B', ['depth' => 1])]);
        $tree = $this->service()->items('header');
        $this->assertCount(1, $tree);
        $this->assertSame('B', $tree[0]['children'][0]['label']);
    }

    public function test_item_pointing_to_unpublished_page_is_hidden_publicly(): void
    {
        $live = $this->page('Qui sommes-nous', true);
        $draft = $this->page('Brouillon', false);
        $this->save('header', [
            ['label' => 'Qui', 'page_id' => $live, 'url' => '', 'depth' => 0, 'is_active' => 1],
            ['label' => 'Caché', 'page_id' => $draft, 'url' => '', 'depth' => 0, 'is_active' => 1],
            $this->item('Enfant du caché', ['depth' => 1]),
            $this->item('Inactif', ['is_active' => 0]),
            $this->item('Externe', ['url' => 'https://example.org/x', 'target' => '_blank']),
        ]);
        $public = $this->service()->publicItems('header');
        $this->assertSame(['Qui', 'Externe'], array_column($public, 'label'));
        $this->assertSame('/' . $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$live]), $public[0]['href']);
        $this->assertSame('https://example.org/x', $public[1]['href']);
        $this->assertSame('noopener noreferrer', $public[1]['rel']);
        // Administration : tout reste visible, avec l'indicateur.
        $admin = $this->service()->items('header');
        $this->assertCount(4, $admin);
        $this->assertTrue($admin[0]['target_live']);
        $this->assertFalse($admin[1]['target_live']);
        $this->assertNull($admin[3]['target_live']);
        $this->assertCount(1, $admin[1]['children']);
        $this->actingAs($this->publisher);
        $this->assertStringContainsString('Page non publiée', $this->request('GET', '/admin/site/menus/header')->body());
    }

    public function test_hostile_url_schemes_are_refused(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', '//evil.test', '/\\evil.test', 'ftp://x.test', 'https://a b.test', "/ok\nx", "https://x.test/\x01", 'mailto:', 'x'] as $url) {
            $this->assertArrayHasKey('items[0].target', $this->refused('header', [$this->item('X', ['url' => $url])])->errors, $url);
        }
        foreach (['https://x.test/a?b=1', 'http://x.test', 'mailto:a@x.test', 'tel:+225 01 02 03', '/contact#haut'] as $url) {
            $this->save('header', [$this->item('X', ['url' => $url])]);
        }
        $this->assertArrayHasKey('items[0].target', $this->refused('header', [$this->item('X', ['url' => '/a', 'page_id' => 1])])->errors, 'page ET url');
        $this->assertArrayHasKey('items[0].target', $this->refused('header', [$this->item('X', ['url' => ''])])->errors, 'ni page ni url');
        $this->assertArrayHasKey('items[0].target', $this->refused('header', [$this->item('X', ['url' => '', 'page_id' => 999999])])->errors);
        $this->assertArrayHasKey('items[0].target_window', $this->refused('header', [$this->item('X', ['target' => '_top'])])->errors);
        $this->assertArrayHasKey('items[0].label', $this->refused('header', [$this->item(str_repeat('é', 61))])->errors);
        $this->assertArrayHasKey('items[0].label', $this->refused('header', [$this->item("\x01\x02")])->errors);
    }

    public function test_deleting_a_top_item_with_sub_items_is_refused_and_route_needs_both_permissions(): void
    {
        $this->actingAs($this->publisher);
        $row = fn (string $l, string $d): array => ['label' => $l, 'kind' => 'url', 'url' => '/' . $l, 'is_active' => '1', 'depth' => $d, 'target' => ''];
        $items = ['items' => [$row('a', '0'), $row('b', '1'), $row('c', '0')]];
        $r = $this->request('POST', '/admin/site/menus/header', $items + ['op' => 'del:0']);
        $this->assertStringContainsString('sous-éléments', $r->body());
        $this->assertSame(3, preg_match_all('/name="items\[\d\]\[label\]"/', $r->body()), 'rien n\'a été retiré');
        $r = $this->request('POST', '/admin/site/menus/header', $items + ['op' => 'del:2']);
        $this->assertSame(2, preg_match_all('/name="items\[\d\]\[label\]"/', $r->body()));
        $only = $this->createUser('seulpub@x.test', []);
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'redacteur'", [$only]);
        $this->actingAs($only);
        $this->assertSame(403, $this->request('GET', '/admin/site/menus/header')->status());
    }

    public function test_reorder_by_buttons(): void
    {
        $this->actingAs($this->publisher);
        $rows = fn (array $labels): array => ['items' => array_map(fn (string $l): array => ['label' => $l, 'kind' => 'url', 'url' => '/' . $l, 'is_active' => '1', 'depth' => '0', 'target' => ''], $labels)];
        $r = $this->request('POST', '/admin/site/menus/header', $rows(['a', 'b', 'c']) + ['op' => 'down:0']);
        $this->assertSame(200, $r->status());
        $this->assertSame([], $this->service()->items('header'), 'une opération n\'enregistre rien');
        preg_match_all('/name="items\[(\d)\]\[label\]"[^>]*value="(\w)"|value="(\w)"[^>]*name="items\[(\d)\]\[label\]"/', $r->body(), $m);
        $this->assertSame(['b', 'a', 'c'], array_values(array_filter(array_merge($m[2], $m[3]))));
        $this->request('POST', '/admin/site/menus/header', $rows(['b', 'a', 'c']) + ['op' => 'up:2']);
        $audits = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.menu_saved'");
        $r = $this->request('POST', '/admin/site/menus/header', $rows(['b', 'c', 'a']) + ['op' => 'save']);
        $this->assertSame(302, $r->status());
        $this->assertSame(['b', 'c', 'a'], array_column($this->service()->items('header'), 'label'));
        $this->assertSame([0, 1, 2], array_map('intval', array_column($this->db->fetchAll('SELECT sort_order FROM cms_menu_items ORDER BY sort_order'), 'sort_order')));
        $this->assertSame($audits + 1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.menu_saved'"));
        // Ajouter / supprimer ; la sentinelle absente refuse l'envoi sans rien modifier.
        $r = $this->request('POST', '/admin/site/menus/header', $rows(['b']) + ['op' => 'add']);
        $this->assertStringContainsString('name="items[1][label]"', $r->body());
        $r = $this->request('POST', '/admin/site/menus/header', $rows(['seul']) + ['op' => 'save', '_form_complete' => null]);
        $this->assertSame(422, $r->status());
        $this->assertSame(['b', 'c', 'a'], array_column($this->service()->items('header'), 'label'));
    }

    public function test_atomic_replace_keeps_old_menu_on_failure(): void
    {
        $this->save('header', [$this->item('Ancien')]);
        $this->refused('header', [$this->item('Bon'), $this->item('Mauvais', ['url' => 'javascript:x'])]);
        $this->assertSame(['Ancien'], array_column($this->service()->items('header'), 'label'));
        // Panne en cours d'insertion (page supprimée entre la validation et l'écriture, simulée par un FK sans cible).
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $ghost = $this->page('Fantôme', false);
        $svc = $this->service();
        try {
            $this->db->atomic(function () use ($svc, $ghost): void {
                $this->db->query('DELETE FROM cms_pages WHERE id = ?', [$ghost]);
                $svc->save('header', [$this->item('Nouveau'), ['label' => 'P', 'page_id' => $ghost, 'url' => '', 'depth' => 0]], $this->publisher);
            });
        } catch (MenuInvalid) {
        }
        $this->assertSame(['Ancien'], array_column($this->service()->items('header'), 'label'));
    }

    public function test_requires_publish_permission(): void
    {
        $this->actingAs($this->author);
        $this->assertSame(403, $this->request('GET', '/admin/site/menus/header')->status());
        $this->assertSame(403, $this->request('POST', '/admin/site/menus/header', ['items' => [['label' => 'X', 'kind' => 'url', 'url' => '/x']], 'op' => 'save'])->status());
        $this->assertStringNotContainsString('/admin/site/menus', $this->request('GET', '/admin')->body());
        $this->expectException(\DomainException::class);
        $this->service()->save('header', [$this->item('X')], $this->author);
    }

    public function test_publisher_can_save_and_nav_entry_is_visible(): void
    {
        $this->actingAs($this->publisher);
        $this->assertStringContainsString('href="/admin/site/menus"', $this->request('GET', '/admin')->body());
        $this->assertSame(302, $this->request('GET', '/admin/site/menus')->status());
        $this->assertSame(404, $this->request('GET', '/admin/site/menus/inconnu')->status());
        $r = $this->request('POST', '/admin/site/menus/footer', ['items' => [['label' => 'Contact', 'kind' => 'url', 'url' => '/contact', 'is_active' => '1', 'depth' => '0']], 'op' => 'save']);
        $this->assertSame(302, $r->status());
        $this->assertSame(['Contact'], array_column($this->service()->items('footer'), 'label'));
    }

    public function test_csrf_is_required(): void
    {
        $this->actingAs($this->publisher);
        $app = $this->app();
        $r = $app->handle(new \App\Core\Http\Request('POST', '/admin/site/menus/header', [], ['items' => [['label' => 'X', 'kind' => 'url', 'url' => '/x']], 'op' => 'save', '_form_complete' => '1'], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit', 'QUERY_STRING' => '']));
        $this->assertSame(419, $r->status());
        $this->assertSame([], $this->service()->items('header'));
    }

    public function test_labels_are_escaped(): void
    {
        $this->actingAs($this->publisher);
        $r = $this->request('POST', '/admin/site/menus/header', ['items' => [['label' => self::EVIL, 'kind' => 'url', 'url' => 'javascript:alert(1)', 'depth' => '0']], 'op' => 'save']);
        $this->assertSame(422, $r->status());
        $this->assertStringNotContainsString('<script>alert(1)', $r->body());
        $this->assertStringNotContainsString('<img src=x', $r->body());
        $this->assertStringContainsString('&lt;script&gt;', $r->body());
        $this->save('header', [$this->item(mb_substr(self::EVIL, 0, 60))]);
        $body = $this->request('GET', '/admin/site/menus/header')->body();
        $this->assertStringNotContainsString('<img src=x', $body);
    }
}
