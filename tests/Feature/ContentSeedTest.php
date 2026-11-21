<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cms\Blocks;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Site\ContentSeed;
use Tests\Support\AdminTestCase;

/** Semis du site depuis le cahier des charges (L4, tâche 3) : brouillons seulement, idempotent, sources citées, rien d'inventé. */
final class ContentSeedTest extends AdminTestCase
{
    private int $admin;
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_gallery_items', 'cms_galleries', 'cms_documents', 'cms_events', 'cms_article_tag', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM users');
        $this->admin = $this->createUser('admin@x.test', ['admin_general']);
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    private function seed(): ContentSeed
    {
        $this->app();
        $audit = $this->services['audit']();
        $gate = $this->services['gate']();
        $pages = new PageService($this->db, $audit, $gate);
        return new ContentSeed($this->db, $pages, new MenuService($this->db, $audit, $gate, $pages), $gate);
    }

    private function pages(): PageService
    {
        return new PageService($this->db, $this->services['audit'](), $this->services['gate']());
    }

    /** @return array<string,array<string,mixed>> slug => page */
    private function seeded(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, slug FROM cms_pages') as $r) {
            $out[$r['slug']] = $this->pages()->get((int) $r['id']);
        }
        return $out;
    }

    private function publishAll(): void
    {
        foreach ($this->seeded() as $p) {
            $this->pages()->publish((int) $p['id'], $this->publisher, null, (int) $p['content_version']);
        }
    }

    public function test_seed_creates_only_drafts_and_never_publishes(): void
    {
        $r = $this->seed()->run($this->admin);
        $this->assertSame(['created' => 13, 'skipped' => 0], $r); // 11 pages + 2 menus
        $this->assertSame(11, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_pages WHERE status <> 'brouillon' OR published_by IS NOT NULL OR published_at IS NOT NULL"));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action IN ('cms.page_published','cms.page_scheduled','cms.menu_saved')"));
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.menu_seeded'"));
        $this->assertSame('la-mutuelle', $this->db->fetchValue('SELECT p.slug FROM cms_pages p JOIN cms_pages c ON c.parent_id = p.id WHERE c.slug = ?', ['missions']));
    }

    public function test_seed_is_idempotent_and_never_overwrites_edited_pages(): void
    {
        $this->seed()->run($this->admin);
        $p = $this->seeded()['formation'];
        $this->pages()->update((int) $p['id'], ['title' => 'Titre modifié par un humain', 'slug' => 'formation', 'template' => 'standard', 'blocks' => [['type' => 'titre', 'text' => 'Mon texte', 'level' => '2']]], $this->author);
        $this->db->query('DELETE FROM cms_menu_items WHERE menu_id = (SELECT id FROM cms_menus WHERE location = ?) AND label = ?', ['footer', 'Partenaires']);
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_menu_items');
        $r = $this->seed()->run($this->admin);
        $this->assertSame(['created' => 0, 'skipped' => 13], $r);
        $this->assertSame('Titre modifié par un humain', $this->seeded()['formation']['title']);
        $this->assertSame('Mon texte', $this->seeded()['formation']['blocks'][0]['text']);
        $this->assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_menu_items'), 'un menu non vide n\'est jamais complété ni écrasé');
        $this->assertSame(11, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
    }

    public function test_every_text_block_has_a_charter_source(): void
    {
        $this->seed()->run($this->admin);
        $n = 0;
        foreach ($this->seeded() as $slug => $p) {
            foreach ($p['blocks'] as $i => $b) {
                $this->assertNotSame('', (string) ($b['source'] ?? ''), "$slug bloc $i");
                $this->assertLessThanOrEqual(80, mb_strlen($b['source']));
                $n++;
            }
        }
        $this->assertGreaterThan(30, $n);
    }

    public function test_source_field_is_never_rendered_publicly(): void
    {
        $this->seed()->run($this->admin);
        $this->publishAll();
        foreach (['/', '/adhesion', '/la-mutuelle', '/actions-sociales', '/partenaires', '/contact'] as $path) {
            $r = $this->request('GET', $path);
            $this->assertSame(200, $r->status(), $path);
            $this->assertStringNotContainsString('CDC', $r->body(), $path);
            $this->assertStringNotContainsString('Source interne', $r->body(), $path);
        }
        // Aussi pour un bloc dont la source est saisie à la main.
        $p = $this->seeded()['contact'];
        $this->pages()->update((int) $p['id'], ['title' => 'Contact', 'slug' => 'contact', 'blocks' => [['type' => 'titre', 'text' => 'Section', 'level' => '2', 'source' => 'SOURCE-SECRETE']]], $this->author);
        $this->pages()->publish((int) $p['id'], $this->publisher, null, (int) $this->pages()->get((int) $p['id'])['content_version']);
        $r = $this->request('GET', '/contact');
        $this->assertStringContainsString('Section', $r->body());
        $this->assertStringNotContainsString('SOURCE-SECRETE', $r->body());
        // Visible dans l'administration.
        $this->actingAs($this->author);
        $this->assertStringContainsString('SOURCE-SECRETE', $this->request('GET', '/admin/site/pages/' . $p['id'])->body());
    }

    public function test_pages_without_source_are_not_created_and_checklist_shown(): void
    {
        $this->seed()->run($this->admin);
        foreach (['mot-du-president', 'historique', 'mentions-legales', 'confidentialite', 'galerie', 'galeries', 'actualites', 'evenements', 'documents'] as $slug) {
            $this->assertNull($this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]), $slug);
        }
        $this->actingAs($this->author);
        $body = $this->request('GET', '/admin/site/pages')->body();
        $this->assertStringContainsString('À fournir par la Mutuelle', $body);
        foreach (array_keys(ContentSeed::TO_PROVIDE) as $label) {
            $this->assertStringContainsString($label, $body);
        }
    }

    public function test_menu_items_point_to_drafts_and_are_hidden_publicly(): void
    {
        $this->seed()->run($this->admin);
        $menus = new MenuService($this->db, $this->services['audit'](), $this->services['gate'](), $this->pages());
        $header = $menus->items('header');
        $this->assertSame(['La Mutuelle', 'Adhésion', 'Actions sociales', 'Emploi', 'Formation', 'Actualités', 'Contact'], array_column($header, 'label'));
        $this->assertCount(2, $header[0]['children']);
        foreach ([...$header, ...$menus->items('footer')] as $it) {
            if ($it['page_id'] !== null) {
                $this->assertSame('brouillon', $this->db->fetchValue('SELECT status FROM cms_pages WHERE id = ?', [$it['page_id']]));
                $this->assertFalse($it['target_live']);
            }
        }
        $this->assertSame([], $menus->publicItems('header'));
        $this->assertSame([], $menus->publicItems('footer'));
        $this->assertSame(['Documents', 'Partenaires'], array_column($menus->items('footer'), 'label'), 'pas de lien « Fonds de solidarité » sans sa réserve');
        $this->publishAll();
        $this->assertSame(['La Mutuelle', 'Adhésion', 'Actions sociales', 'Emploi', 'Formation', 'Contact'], array_column($menus->publicItems('header'), 'label'), 'Actualités reste inactif');
    }

    public function test_seeded_public_pages_404_until_published(): void
    {
        $this->seed()->run($this->admin);
        foreach (['/adhesion', '/la-mutuelle', '/missions', '/contact'] as $path) { // « / » garde son accueil par défaut tant qu'aucune accueil n'est publiée
            $this->assertSame(404, $this->request('GET', $path)->status(), $path);
        }
        $this->publishAll();
        foreach (['/adhesion', '/la-mutuelle', '/missions', '/contact', '/'] as $path) {
            $this->assertSame(200, $this->request('GET', $path)->status(), $path);
        }
        $this->assertStringContainsString('Livret maritime disponible', $this->request('GET', '/adhesion')->body());
    }

    public function test_domains_block_keeps_the_charter_reservations(): void
    {
        $this->seed()->run($this->admin);
        foreach (['accueil', 'actions-sociales'] as $slug) {
            $blocks = $this->seeded()[$slug]['blocks'];
            $text = json_encode($blocks, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('Sous réserve des ressources disponibles, des textes applicables et des critères adoptés', $text, $slug);
            $domains = array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === 'domaines'))[0];
            $this->assertSame(['Santé', 'Décès', 'Maladie', 'Famille', 'Emploi', 'Formation', 'Retraite', 'Urgences'], array_column($domains['items'], 'name'));
            $this->assertStringContainsString('après examen du dossier', $text);
            $this->assertStringContainsString('dans les limites des règles adoptées', $text);
            $this->assertStringContainsString('selon les conditions prévues', $text);
        }
        $this->assertStringContainsString('critères écrits', json_encode($this->seeded()['fonds-de-solidarite']['blocks'], JSON_UNESCAPED_UNICODE));
        // Le fonds n'est jamais présenté comme existant : « peut constituer » accompagne toute mention (page actions sociales comprise).
        $this->assertStringContainsString('peut constituer un fonds de solidarité', json_encode($this->seeded()['actions-sociales']['blocks'], JSON_UNESCAPED_UNICODE));
    }

    public function test_no_forbidden_invented_tokens(): void
    {
        $this->seed()->run($this->admin);
        $charter = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/conception/cahier-des-charges-2026-2027.txt');
        preg_match_all('/Article (\d+)/', $charter, $m);
        $allowed = array_merge($m[1], ['2026', '2027']);
        $strings = [];
        $walk = function (mixed $v) use (&$walk, &$strings): void {
            is_array($v) ? array_map($walk, $v) : (is_string($v) ? $strings[] = html_entity_decode(strip_tags($v)) : null);
        };
        foreach ($this->seeded() as $p) {
            $walk([$p['title'], $p['excerpt'], $p['blocks']]);
        }
        $this->assertNotEmpty($strings);
        foreach ($strings as $s) {
            preg_match_all('/\d+/', $s, $d);
            foreach ($d[0] as $n) {
                $this->assertContains($n, $allowed, "chiffre « $n » dans : $s");
            }
            $this->assertDoesNotMatchRegularExpression('/@|https?:|www\.|\+\d/i', $s);
        }
        $this->assertNotSame('', $this->seeded()['accueil']['excerpt']);
        $this->assertStringContainsString($this->seeded()['accueil']['excerpt'], $charter === '' ? '' : str_replace(["\n", '  '], ' ', preg_replace('/\s+/', ' ', $charter)));
    }

    public function test_script_refuses_publish_flag_and_unknown_actor(): void
    {
        $seed = $this->seed();
        [$code, $msg] = $seed->cli(['--actor=admin@x.test', '--publish']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('action humaine', $msg);
        $this->assertSame(1, $seed->cli(['--publish', '--actor=admin@x.test'])[0]);
        $this->assertSame(1, $seed->cli(['--actor=nobody@x.test'])[0]);
        $this->assertSame(1, $seed->cli([])[0]);
        $this->createUser('inactif@x.test', ['admin_general'], 'phrase secrète longue 2026', false);
        $this->assertSame(1, $seed->cli(['--actor=inactif@x.test'])[0]);
        $this->assertSame(1, $seed->cli(['--actor=admin@x.test', '--verbose'])[0]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'), 'rien n\'est créé en cas de refus');
        [$code] = $seed->cli(['--actor=admin@x.test']);
        $this->assertSame(0, $code);
        $this->assertSame(11, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
    }

    public function test_block_source_field_is_validated_and_kept(): void
    {
        $ok = Blocks::validate([['type' => 'titre', 'text' => 'A', 'level' => '2', 'source' => ' CDC art. 9 ']]);
        $this->assertSame('CDC art. 9', $ok[0]['source']);
        $this->assertArrayNotHasKey('source', Blocks::validate([['type' => 'titre', 'text' => 'A', 'level' => '2']])[0]);
        $this->expectException(\App\Modules\Cms\BlockInvalid::class);
        Blocks::validate([['type' => 'titre', 'text' => 'A', 'level' => '2', 'source' => str_repeat('x', 81)]]);
    }
}
