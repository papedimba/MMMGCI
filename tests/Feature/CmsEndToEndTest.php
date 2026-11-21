<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Sequence;
use App\Core\Settings;
use App\Core\Uploads;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\BannerService;
use App\Modules\Cms\CmsScheduler;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\FormInbox;
use App\Modules\Cms\GalleryService;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\SiteSettings;
use App\Modules\Cms\TestimonialService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

/** Parcours complet : rédacteur → refus de publication → publicateur → lecture publique → réédition → programmation (cron) → formulaire reçu. */
final class CmsEndToEndTest extends AdminTestCase
{
    private int $writer;
    private int $publisher;
    private int $validator;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_testimonials',
            'cms_article_tag', 'cms_articles', 'cms_form_submissions'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query("DELETE FROM login_attempts WHERE succeeded IN (5, 6)");
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
        $this->writer = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('com@x.test', ['resp_communication']);
        $this->validator = $this->createUser('pca@x.test', ['pca']);
    }

    private function future(string $m): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    public function test_full_cms_journey(): void
    {
        $this->app();
        $a = $this->services['audit']();
        $g = $this->services['gate']();
        $pages = new PageService($this->db, $a, $g);
        $menus = new MenuService($this->db, $a, $g, $pages);
        $articles = new ArticleService($this->db, $a, $g);
        $events = new EventService($this->db, $a, $g);
        $docs = new DocumentService($this->db, $a, $g);
        $pub = new PublishedContent($this->db, $pages, $menus, $articles, $events, $docs, new BannerService($this->db, $a, $g), new GalleryService($this->db, $a, $g),
            new TestimonialService($this->db, $a, $g, new Settings($this->db), new Uploads($this->db, sys_get_temp_dir(), sys_get_temp_dir(), 1048576, true)), time(...));
        $fresh = function () use ($pages, $menus, $articles, $events, $docs, $a, $g): PublishedContent {
            return new PublishedContent($this->db, $pages, $menus, $articles, $events, $docs, new BannerService($this->db, $a, $g), new GalleryService($this->db, $a, $g),
                new TestimonialService($this->db, $a, $g, new Settings($this->db), new Uploads($this->db, sys_get_temp_dir(), sys_get_temp_dir(), 1048576, true)), time(...));
        };

        // 1. Le rédacteur crée une page et la soumet ; il ne peut pas la publier (service et écran).
        $id = $pages->create(['title' => 'Qui sommes-nous', 'blocks' => [['type' => 'titre', 'text' => 'Version un', 'level' => '2']]], $this->writer);
        $slug = (string) $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$id]);
        $this->assertNull($fresh()->page("/$slug"), 'un brouillon n\'est jamais public');
        $pages->submit($id, $this->writer);
        try {
            $pages->publish($id, $this->writer);
            $this->fail('le rédacteur ne publie pas');
        } catch (\DomainException) {
        }
        $this->actingAs($this->writer);
        $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'publie']);
        $this->assertNull($fresh()->page("/$slug"));

        // 2. Le publicateur publie : la lecture publique la renvoie.
        $pages->publish($id, $this->publisher, null, (int) $pages->get($id)['content_version']);
        $this->assertStringContainsString('Version un', json_encode($fresh()->page("/$slug"), JSON_UNESCAPED_UNICODE));

        // 3. Modification : la version publiée reste servie ; la republication met à jour.
        $pages->update($id, ['title' => 'Qui sommes-nous', 'blocks' => [['type' => 'titre', 'text' => 'Version deux', 'level' => '2']]], $this->writer);
        $served = json_encode($fresh()->page("/$slug"), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Version un', $served);
        $this->assertStringNotContainsString('Version deux', $served);
        $pages->submit($id, $this->writer);
        $pages->publish($id, $this->publisher, null, (int) $pages->get($id)['content_version']);
        $this->assertStringContainsString('Version deux', json_encode($fresh()->page("/$slug"), JSON_UNESCAPED_UNICODE));

        // 4. Menu : le publicateur (les deux droits) l'enregistre ; le rédacteur seul ne le peut pas.
        try {
            $menus->save('header', [['label' => 'Nous', 'page_id' => $id, 'url' => '', 'depth' => 0, 'is_active' => 1]], $this->writer);
            $this->fail('menu sans droit de publication');
        } catch (\DomainException) {
        }
        $menus->save('header', [['label' => 'Nous', 'page_id' => $id, 'url' => '', 'depth' => 0, 'is_active' => 1]], $this->publisher);
        $this->assertSame('/' . $slug, $fresh()->menu('header')[0]['href']);

        // 5. Article programmé : publié par le cron seulement une fois l'échéance passée.
        $art = $articles->create(['title' => 'Assemblée générale', 'body' => '<p>Texte</p>'], $this->writer);
        $articles->transition($art, 'en_relecture', $this->writer);
        $articles->transition($art, 'programme', $this->publisher, $this->future('+1 day'), (int) $articles->get($art)['content_version']);
        $cron = new CmsScheduler($a, [$pages, $articles, $events]);
        $this->assertSame(0, $cron->publishDue($this->future('+1 hour')));
        $this->assertSame([], $fresh()->articles());
        $this->assertSame(1, $cron->publishDue($this->future('+2 days')));
        $this->assertSame('publie', $this->db->fetchValue('SELECT status FROM cms_articles WHERE id = ?', [$art]));

        // 6. Document publié.
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/e2e.pdf', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $media = $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Statuts', 'alt' => null]);
        $doc = $docs->create(['title' => 'Statuts 2026', 'category' => 'Statuts', 'document_date' => '2026-01-15', 'media_id' => (string) $media], $this->writer);
        $this->assertSame([], $fresh()->documents());
        $docs->transition($doc, 'publie', $this->publisher, (int) $docs->get($doc)['content_version']);
        $this->assertCount(1, $fresh()->documents());

        // 7. Formulaire reçu puis traité ; réglage de contact non public tant qu'il n'est pas validé.
        $settings = new SiteSettings($this->db, $a, $g);
        $settings->save('contact_email', 'contact@exemple.ci', $this->publisher);
        $this->assertNull($settings->publicValue('contact_email'));
        $seen = '';
        foreach ($settings->all() as $r) {
            $seen = $r['key'] === 'contact_email' ? $r['seen'] : $seen;
        }
        $settings->validate('contact_email', $this->validator, 'PV-2026-01', $seen);
        $this->assertSame('contact@exemple.ci', $settings->publicValue('contact_email'));
        $inbox = new FormInbox($this->db, $a, $g, new Sequence($this->db), $settings, $this->services['mailer'], static fn (callable $job) => $job(), 'https://mmmgci.exemple.ci');
        $sid = $inbox->submit('contact', ['name' => 'Awa', 'email' => 'awa@exemple.ci', 'message' => 'Bonjour'], '10.9.0.1');
        $inbox->transition($sid, 'en_cours', $this->publisher);
        $inbox->transition($sid, 'traite', $this->publisher, 'Répondu');
        $this->assertSame('traite', $inbox->get($sid)['status']);
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE auditable_type = 'cms_form_submissions' AND (new_values LIKE '%awa@exemple.ci%' OR new_values LIKE '%Bonjour%')"));
        $this->assertGreaterThan(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.page_published' OR action LIKE 'cms.page_%'"));
    }

    public function test_publishing_without_the_seen_version_is_refused_for_pages_articles_events(): void
    {
        $this->app();
        $a = $this->services['audit']();
        $g = $this->services['gate']();
        $pages = new PageService($this->db, $a, $g);
        $articles = new ArticleService($this->db, $a, $g);
        $events = new EventService($this->db, $a, $g);
        $ids = [
            [$pages, $pages->create(['title' => 'P', 'blocks' => [['type' => 'titre', 'text' => 'T', 'level' => '2']]], $this->writer)],
            [$articles, $articles->create(['title' => 'A', 'body' => '<p>x</p>'], $this->writer)],
            [$events, $events->create(['title' => 'E', 'starts_at' => $this->future('+3 days')->format('Y-m-d\TH:i')], $this->writer)],
        ];
        foreach ($ids as [$svc, $id]) {
            foreach ([['publie', null], ['programme', $this->future('+1 day')]] as [$to, $at]) {
                try {
                    $svc->transition($id, $to, $this->publisher, $at);
                    $this->fail($svc::class . " $to sans version");
                } catch (\App\Modules\Cms\ContentTransitionInvalid) {
                    $this->addToAssertionCount(1);
                }
            }
            $svc->transition($id, 'publie', $this->publisher, null, (int) $svc->get($id)['content_version']);
        }
    }

    /** Mesure (affichée) avec 1 000 pages et 1 000 articles en ligne ; garde-fous larges pour ne pas être fragile. */
    public function test_performance_with_a_thousand_pages_and_articles(): void
    {
        $this->app();
        $a = $this->services['audit']();
        $g = $this->services['gate']();
        $blocks = json_encode([['type' => 'titre', 'text' => 'T', 'level' => '2']]);
        $this->db->atomic(function () use ($blocks): void {
            for ($i = 1; $i <= 1000; $i++) {
                $this->db->insert('cms_pages', ['title' => "Page $i", 'slug' => "perf-page-$i", 'blocks' => $blocks, 'status' => 'publie', 'published_at' => '2026-01-01 10:00:00', 'sort_order' => $i]);
                $this->db->insert('cms_articles', ['title' => "Article $i", 'slug' => "perf-article-$i", 'body' => '<p>Corps</p>', 'status' => 'publie', 'published_at' => sprintf('2026-01-%02d 10:%02d:00', 1 + $i % 28, $i % 60)]);
            }
        });
        $pages = new PageService($this->db, $a, $g);
        $menus = new MenuService($this->db, $a, $g, $pages);
        $pub = new PublishedContent($this->db, $pages, $menus, new ArticleService($this->db, $a, $g), new EventService($this->db, $a, $g), new DocumentService($this->db, $a, $g),
            new BannerService($this->db, $a, $g), new GalleryService($this->db, $a, $g),
            new TestimonialService($this->db, $a, $g, new Settings($this->db), new Uploads($this->db, sys_get_temp_dir(), sys_get_temp_dir(), 1048576, true)), time(...));
        $first = $this->db->fetchValue("SELECT id FROM cms_pages WHERE slug = 'perf-page-1'");
        $menuRows = [];
        for ($i = 1; $i <= 7; $i++) {
            $menuRows[] = ['label' => "M$i", 'page_id' => (int) $first + $i, 'url' => '', 'depth' => 0, 'is_active' => 1];
        }
        $menus->save('header', $menuRows, $this->publisher);
        $time = static function (callable $f): array {
            $t = hrtime(true);
            $r = $f();
            return [$r, (hrtime(true) - $t) / 1e6];
        };
        [$page, $msPage] = $time(fn () => $pub->page('/perf-page-500'));
        [$menu, $msMenu] = $time(fn () => $pub->menu('header'));
        [$list, $msList] = $time(fn () => $pub->articles(['limit' => 20]));
        [$urls, $msSitemap] = $time(fn () => $pub->sitemapUrls());
        fwrite(STDERR, sprintf("\nPERF page=%.1f ms menu=%.1f ms articles(20)=%.1f ms sitemapUrls=%.1f ms (%d urls)\n", $msPage, $msMenu, $msList, $msSitemap, count($urls)));
        $this->assertSame('/perf-page-500', $page['path']);
        $this->assertCount(7, $menu);
        $this->assertCount(20, $list);
        $this->assertCount(2000, $urls);
        $this->assertLessThan(1000, $msPage);
        $this->assertLessThan(1000, $msMenu);
        $this->assertLessThan(1000, $msList);
        $this->assertLessThan(10000, $msSitemap);
    }
}
