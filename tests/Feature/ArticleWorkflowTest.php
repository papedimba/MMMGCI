<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduler;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\CmsScheduler;
use App\Modules\Cms\ContentTransitionInvalid;
use App\Modules\Cms\PageInvalid;
use App\Modules\Cms\PageService;
use App\Modules\Cms\ScheduledContent;
use App\Modules\Cms\TaxonomyService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

final class ArticleWorkflowTest extends AdminTestCase
{
    private const EVIL = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_page_redirects', 'cms_menu_items', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_article_tag', 'cms_articles', 'cms_tags', 'cms_categories'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    protected function request(string $method, string $path, array $post = [], array $files = []): \App\Core\Http\Response
    {
        if (strtoupper($method) === 'POST' && preg_match('~^/admin/site/articles/(nouveau|\d+)$~', $path) === 1) {
            $post += ['_form_complete' => '1'];
            if ($post['_form_complete'] === null) {
                unset($post['_form_complete']);
            }
        }
        return parent::request($method, $path, $post, $files);
    }

    private function audit(): \App\Core\Audit
    {
        $this->app();
        return $this->services['audit']();
    }

    private function service(): ArticleService
    {
        $this->app();
        return new ArticleService($this->db, $this->audit(), $this->services['gate']());
    }

    /** @param array<string,mixed> $extra */
    private function article(string $title, array $extra = [], ?int $actor = null): int
    {
        return $this->service()->create($extra + ['title' => $title, 'body' => '<p>Corps ' . $title . '</p>'], $actor ?? $this->author);
    }

    private function row(int $id): array
    {
        return $this->db->fetch('SELECT * FROM cms_articles WHERE id = ?', [$id]);
    }

    private function future(string $m = '+2 days'): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    private function publish(int $id): void
    {
        $this->service()->transition($id, 'publie', $this->publisher);
    }

    private function seen(int $id): string
    {
        preg_match('/name="version" value="(\d+)"/', $this->request('GET', "/admin/site/articles/$id")->body(), $m);
        return $m[1] ?? '';
    }

    private function image(): int
    {
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.png', 'original_name' => 'x', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        return $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Quai', 'alt' => 'Quai']);
    }

    public function test_scheduled_article_is_published_by_cron_at_the_right_minute(): void
    {
        $id = $this->article('Programmé');
        $at = $this->future('+1 day')->setTime(10, 0);
        $this->service()->transition($id, 'programme', $this->publisher, $at);
        $cron = new CmsScheduler($this->audit(), [$this->service()]);
        $this->assertSame(0, $cron->publishDue($at->modify('-1 minute')));
        $this->assertSame('programme', $this->row($id)['status']);
        $this->assertSame(1, $cron->publishDue($at));
        $this->assertSame('publie', $this->row($id)['status']);
        $audit = $this->db->fetch("SELECT user_id, action FROM audit_logs WHERE auditable_type = 'cms_articles' AND auditable_id = ? AND action = 'cms.scheduled_published'", [$id]);
        $this->assertNotNull($audit);
        $this->assertNull($audit['user_id']);
    }

    public function test_cron_is_idempotent_and_ignores_drafts_and_future(): void
    {
        $draft = $this->article('Brouillon');
        $later = $this->article('Plus tard');
        $this->service()->transition($later, 'programme', $this->publisher, $this->future('+3 days'));
        $due = $this->article('Échu');
        $this->service()->transition($due, 'programme', $this->publisher, $this->future('+1 day'));
        $now = $this->future('+2 days');
        $cron = new CmsScheduler($this->audit(), [$this->service()]);
        $this->assertSame(1, $cron->publishDue($now));
        $this->assertSame(0, $cron->publishDue($now));
        $this->assertSame('brouillon', $this->row($draft)['status']);
        $this->assertSame('programme', $this->row($later)['status']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.scheduled_published' AND auditable_type = 'cms_articles' AND auditable_id = $due"));
    }

    public function test_scheduler_handles_pages_and_articles_and_clears_saved_live_version(): void
    {
        $pages = new PageService($this->db, $this->audit(), $this->services['gate']());
        $p = $pages->create(['title' => 'Page', 'blocks' => [['type' => 'titre', 'text' => 'V1', 'level' => '2']]], $this->author);
        $pages->transition($p, 'publie', $this->publisher);
        $pages->update($p, ['title' => 'Page', 'blocks' => [['type' => 'titre', 'text' => 'V2', 'level' => '2']]], $this->author);
        $pages->transition($p, 'programme', $this->publisher, $this->future('+1 day'));
        $a = $this->article('Art V1');
        $this->publish($a);
        $this->service()->update($a, ['title' => 'Art V2', 'body' => '<p>V2</p>'], $this->author);
        $this->service()->transition($a, 'programme', $this->publisher, $this->future('+1 day'));
        $this->assertNotNull($this->row($a)['published_body']);
        $this->assertNotNull($this->db->fetchValue('SELECT published_blocks FROM cms_pages WHERE id = ?', [$p]));
        $n = (new CmsScheduler($this->audit(), [$pages, $this->service()]))->publishDue($this->future('+2 days'));
        $this->assertSame(2, $n);
        $this->assertSame('publie', $this->row($a)['status']);
        $this->assertNull($this->row($a)['published_body']);
        $this->assertNull($this->row($a)['published_title']);
        $this->assertNull($this->db->fetchValue('SELECT published_blocks FROM cms_pages WHERE id = ?', [$p]));
        $this->assertSame('publie', $this->db->fetchValue('SELECT status FROM cms_pages WHERE id = ?', [$p]));
        $this->assertSame('Art V2', $this->service()->liveVersion($a)['title']);
    }

    public function test_cron_failure_on_one_item_does_not_block_others(): void
    {
        $ok = $this->article('Bon');
        $this->service()->transition($ok, 'programme', $this->publisher, $this->future('+1 day'));
        $real = $this->service();
        $bad = new class implements ScheduledContent {
            public function scheduledTable(): string { return 'cms_pages'; }
            public function dueIds(DateTimeImmutable $now): array { return [987654, 987655]; }
            public function publishDue(int $id, DateTimeImmutable $now): void { throw new \RuntimeException('boum'); }
        };
        $prev = ini_set('error_log', '/dev/null');
        $n = (new CmsScheduler($this->audit(), [$bad, $real]))->publishDue($this->future('+2 days'));
        ini_set('error_log', (string) $prev);
        $this->assertSame(1, $n);
        $this->assertSame('publie', $this->row($ok)['status']);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.schedule_failed'"));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.schedule_failed' AND new_values LIKE '%boum%'"));
    }

    public function test_cron_task_is_registered_every_minute(): void
    {
        $this->app();
        $id = $this->article('Cron');
        $this->service()->transition($id, 'programme', $this->publisher, $this->future('+1 hour'));
        $this->db->query("UPDATE cms_articles SET published_at = '2000-01-01 00:00:00' WHERE id = ?", [$id]);
        $this->db->query("DELETE FROM scheduled_runs WHERE task = 'cms.publish_scheduled'");
        $s = new Scheduler($this->db);
        CmsScheduler::register($s, $this->db, $this->audit());
        $t = time();
        $this->assertSame('ran', $s->run($t)['cms.publish_scheduled'] ?? null);
        $this->assertSame('publie', $this->row($id)['status']);
        $this->assertSame('skipped_not_due', $s->run($t + 30)['cms.publish_scheduled'] ?? null);
        $this->assertSame('ran', $s->run($t + 61)['cms.publish_scheduled'] ?? null, 'prête 61 s plus tard : aucun cycle de cron de 5 min n\'est sauté');
        $this->assertStringContainsString('CmsScheduler::register', (string) file_get_contents(dirname(__DIR__, 2) . '/bin/cron.php'));
    }

    public function test_media_kept_in_the_live_version_is_still_in_use(): void
    {
        $this->app();
        $path = '2026/11/aaaabbbbccccddddeeeeffff00001111.png';
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => $path, 'original_name' => 'x', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('1', 64)]);
        $media = $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Photo', 'alt' => 'Photo']);
        $id = $this->article('Avec image', ['body' => '<p><img src="/media/' . $path . '" alt="Photo"></p>', 'excerpt' => 'Texte']);
        $this->publish($id);
        $svc = new \App\Modules\Cms\MediaService($this->db, $this->audit(), new \App\Core\Uploads($this->db, sys_get_temp_dir(), sys_get_temp_dir(), 1000));
        $this->service()->update($id, ['title' => 'Avec image', 'body' => '<p>sans image</p>'], $this->author);
        $this->assertNotNull($this->row($id)['published_body']);
        $this->assertStringNotContainsString($path, (string) $this->row($id)['body']);
        $this->assertContains('article', array_column($svc->usages($media), 'type'), 'la version en ligne conservée utilise encore le média');
        $this->expectException(\DomainException::class);
        $svc->delete($media, $this->author);
    }

    public function test_publisher_structural_edit_keeps_scheduled_state_and_date(): void
    {
        $id = $this->article('Programmé structurel');
        $this->service()->transition($id, 'programme', $this->publisher, $this->future('+3 days'));
        $before = $this->row($id);
        $this->service()->update($id, ['title' => 'Programmé structurel', 'body' => '<p>Corps Programmé structurel</p>', 'author_label' => 'Le bureau'], $this->publisher);
        $after = $this->row($id);
        $this->assertSame('programme', $after['status']);
        $this->assertSame($before['published_at'], $after['published_at']);
        $this->assertSame('Le bureau', $after['author_label']);
        $this->service()->update($id, ['title' => 'Titre changé', 'body' => '<p>Corps Programmé structurel</p>', 'author_label' => 'Le bureau'], $this->publisher);
        $this->assertSame('brouillon', $this->row($id)['status'], 'contenu versionné : programmation annulée');
    }

    public function test_live_list_is_ordered_by_displayed_date_and_limited(): void
    {
        $old = $this->article('Ancien');
        $this->publish($old);
        $this->db->query("UPDATE cms_articles SET published_at = '2026-01-01 10:00:00' WHERE id = ?", [$old]);
        $mid = $this->article('Milieu');
        $this->publish($mid);
        $this->db->query("UPDATE cms_articles SET published_at = '2026-03-01 10:00:00' WHERE id = ?", [$mid]);
        $this->service()->update($old, ['title' => 'Ancien v2', 'body' => '<p>v2</p>'], $this->author);
        $this->db->query("UPDATE cms_articles SET published_version_at = '2026-01-01 10:00:00' WHERE id = ?", [$old]);
        $this->service()->transition($old, 'programme', $this->publisher, $this->future('+30 days'));
        $new = $this->article('Récent');
        $this->publish($new);
        $this->db->query("UPDATE cms_articles SET published_at = '2026-06-01 10:00:00' WHERE id = ?", [$new]);
        $draft = $this->article('Jamais public');
        $this->assertSame([$new, $mid, $old], array_column($this->service()->liveList(), 'id'));
        $this->assertSame([$new, $mid], array_column($this->service()->liveList(2), 'id'));
        $this->assertNotContains($draft, array_column($this->service()->liveList(), 'id'));
    }

    public function test_author_cannot_publish(): void
    {
        $id = $this->article('Statuts');
        $this->actingAs($this->author);
        foreach (['publie', 'programme', 'archive'] as $to) {
            $res = $this->request('POST', "/admin/site/articles/$id/etat", ['to' => $to, 'at' => $this->future()->format('Y-m-d\TH:i')]);
            $this->assertSame(422, $res->status(), $to);
            $this->assertStringContainsString('droit de publication requis', html_entity_decode($res->body()));
        }
        $this->assertSame('brouillon', $this->row($id)['status']);
        $this->assertSame(302, $this->request('POST', "/admin/site/articles/$id/etat", ['to' => 'en_relecture'])->status());
        $this->assertSame('en_relecture', $this->row($id)['status']);
        $this->expectException(ContentTransitionInvalid::class);
        $this->service()->transition($id, 'publie', $this->author);
    }

    public function test_archived_article_leaves_public_lists(): void
    {
        $id = $this->article('À archiver');
        $other = $this->article('Reste');
        $this->publish($id);
        $this->publish($other);
        $this->assertCount(2, $this->service()->liveList());
        $this->service()->transition($id, 'archive', $this->publisher);
        $this->assertSame([$other], array_column($this->service()->liveList(), 'id'));
        $this->assertNull($this->service()->liveVersion($id));
        $this->assertNotNull($this->row($id)['archived_at']);
        $this->service()->transition($id, 'brouillon', $this->publisher);
        $this->assertNull($this->row($id)['archived_at']);
    }

    public function test_live_version_rules_match_pages(): void
    {
        $s = $this->service();
        $id = $this->article('V1');
        $this->assertNull($s->liveVersion($id), 'brouillon jamais public');
        $s->transition($id, 'en_relecture', $this->author);
        $this->assertNull($s->liveVersion($id));
        $this->publish($id);
        $this->assertSame('V1', $s->liveVersion($id)['title']);
        $s->update($id, ['title' => 'V2', 'body' => '<p>V2</p>'], $this->author);
        $this->assertSame('brouillon', $this->row($id)['status']);
        $this->assertSame('V1', $s->liveVersion($id)['title'], 'la version en ligne reste servie');
        $this->assertStringContainsString('V1', $s->liveVersion($id)['body']);
        $s->transition($id, 'programme', $this->publisher, $this->future('+1 day'));
        $this->assertSame('V1', $s->liveVersion($id)['title'], 'programmé non échu : version conservée');
        $this->db->query("UPDATE cms_articles SET published_at = '2000-01-01 00:00:00' WHERE id = ?", [$id]);
        $this->assertSame('V2', $s->liveVersion($id)['title'], 'programmé échu : copie de travail');
        $s->unpublish($id, $this->publisher);
        $this->assertNull($s->liveVersion($id));
        $this->assertNull($this->row($id)['published_body']);
        $this->publish($id);
        $s->transition($id, 'archive', $this->publisher);
        $this->assertNull($s->liveVersion($id));
        $this->assertNull($this->liveOfNone());
    }

    private function liveOfNone(): mixed
    {
        return $this->service()->liveVersion(999999);
    }

    public function test_review_edit_returns_to_draft_and_seen_version_is_enforced(): void
    {
        $id = $this->article('Relu');
        $this->service()->transition($id, 'en_relecture', $this->author);
        $this->actingAs($this->publisher);
        $seen = $this->seen($id);
        $this->assertNotSame('', $seen);
        $this->service()->update($id, ['title' => 'Relu modifié', 'body' => '<p>x</p>'], $this->author);
        $this->assertSame('brouillon', $this->row($id)['status']);
        $this->service()->transition($id, 'en_relecture', $this->author);
        $res = $this->request('POST', "/admin/site/articles/$id/etat", ['to' => 'publie', 'version' => $seen]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('relisez-le', html_entity_decode($res->body()));
        $this->assertSame('en_relecture', $this->row($id)['status']);
        $this->assertSame(422, $this->request('POST', "/admin/site/articles/$id/etat", ['to' => 'publie'])->status(), 'version absente refusée');
        $this->assertSame(302, $this->request('POST', "/admin/site/articles/$id/etat", ['to' => 'publie', 'version' => $this->seen($id)])->status());
        $this->assertSame('publie', $this->row($id)['status']);
    }

    public function test_structural_fields_of_a_live_article_need_publish_right(): void
    {
        $id = $this->article('En ligne');
        $this->publish($id);
        try {
            $this->service()->update($id, ['title' => 'En ligne', 'body' => '<p>Corps En ligne</p>', 'slug' => 'autre-adresse'], $this->author);
            $this->fail('refus attendu');
        } catch (PageInvalid $e) {
            $this->assertArrayHasKey('slug', $e->errors);
        }
        $this->service()->update($id, ['title' => 'En ligne', 'body' => '<p>Corps En ligne</p>', 'slug' => 'autre-adresse'], $this->publisher);
        $this->assertSame('autre-adresse', $this->row($id)['slug']);
    }

    public function test_only_never_published_drafts_can_be_deleted(): void
    {
        $a = $this->article('Jetable');
        $b = $this->article('Publié');
        $this->publish($b);
        $this->actingAs($this->author);
        $this->assertSame(302, $this->request('POST', "/admin/site/articles/$a/suppression")->status());
        $this->assertNull($this->db->fetch('SELECT id FROM cms_articles WHERE id = ?', [$a]));
        $this->assertSame(422, $this->request('POST', "/admin/site/articles/$b/suppression")->status());
        $this->assertNotNull($this->db->fetch('SELECT id FROM cms_articles WHERE id = ?', [$b]));
    }

    public function test_category_and_tag_slugs_are_unique(): void
    {
        $t = new TaxonomyService($this->db, $this->audit());
        foreach (['categories', 'etiquettes'] as $kind) {
            $t->create($kind, 'Actualités', '', $this->author);
            foreach ([['Autre nom', 'actualites'], ['Actualités', 'autre'], ['X', 'admin'], ['Y', 'Mauvais Slug']] as [$name, $slug]) {
                try {
                    $t->create($kind, $name, $slug, $this->author);
                    $this->fail("$kind $name/$slug accepté");
                } catch (PageInvalid) {
                    $this->assertTrue(true);
                }
            }
        }
        $a = $this->article('Étiquetés', ['tags' => 'Actualités, Nouveau, nouveau']);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_article_tag WHERE article_id = ?', [$a]));
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_tags'));
        $this->assertSame('actualites', $this->db->fetchValue("SELECT slug FROM cms_tags WHERE name = 'Actualités'"));
    }

    private function seulPublicateur(): int
    {
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('seul_pub', 'Seul publicateur')");
        $roleId = (int) $this->db->fetchValue("SELECT id FROM roles WHERE name = 'seul_pub'");
        $perm = (int) $this->db->fetchValue("SELECT id FROM permissions WHERE name = 'cms.publish'");
        $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $perm]);
        return $this->createUser('seul' . random_int(1, 99999) . '@x.test', ['seul_pub']);
    }

    public function test_publisher_without_manage_right_cannot_create_tags(): void
    {
        $u = $this->seulPublicateur();
        $this->expectException(PageInvalid::class);
        $this->article('Tags', ['tags' => 'Inconnue'], $u);
    }

    public function test_deleting_a_used_category_is_refused(): void
    {
        $t = new TaxonomyService($this->db, $this->audit());
        $c = $t->create('categories', 'Vie associative', '', $this->author);
        $unused = $t->create('categories', 'Vide', '', $this->author);
        $tag = $t->create('etiquettes', 'Fête', '', $this->author);
        $a = $this->article('Classé', ['category_id' => (string) $c, 'tags' => 'Fête']);
        $this->actingAs($this->author);
        $res = $this->request('POST', "/admin/site/articles/categories/$c/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('utilisée par 1 article', html_entity_decode($res->body()));
        $this->assertSame(422, $this->request('POST', "/admin/site/articles/etiquettes/$tag/suppression")->status());
        $this->assertSame(302, $this->request('POST', "/admin/site/articles/categories/$unused/suppression")->status());
        $this->assertNull($this->db->fetch('SELECT id FROM cms_categories WHERE id = ?', [$unused]));
        $this->assertNotNull($this->db->fetch('SELECT id FROM cms_categories WHERE id = ?', [$c]));
        $this->assertSame(200, $this->request('GET', '/admin/site/articles/categories')->status());
        $this->assertSame(200, $this->request('GET', '/admin/site/articles/etiquettes')->status());
        $this->assertSame($c, (int) $this->row($a)['category_id']);
    }

    public function test_body_is_sanitised(): void
    {
        $id = $this->article('Corps', ['body' => '<p onclick="x()">Salut</p><script>alert(1)</script><a href="javascript:alert(1)">l</a><img src="https://evil.test/a.png" alt="x"><h1>T</h1>']);
        $body = (string) $this->row($id)['body'];
        foreach (['<script', 'onclick', 'javascript:', 'evil.test', '<h1'] as $bad) {
            $this->assertStringNotContainsString($bad, $body, $bad);
        }
        $this->assertStringContainsString('Salut', $body);
    }

    public function test_hostile_text_is_escaped_everywhere(): void
    {
        $this->actingAs($this->author);
        $t = new TaxonomyService($this->db, $this->audit());
        $c = $t->create('categories', self::EVIL, 'hostile', $this->author);
        $id = $this->article(self::EVIL, ['excerpt' => self::EVIL, 'author_label' => self::EVIL, 'seo_title' => self::EVIL, 'category_id' => (string) $c, 'body' => '<p>' . self::EVIL . '</p>']);
        foreach (['/admin/site/articles', "/admin/site/articles/$id", "/admin/site/articles/$id/apercu", '/admin/site/articles/categories', '/admin/site/articles?q=' . urlencode(self::EVIL)] as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(200, $res->status(), $url);
            $this->assertStringNotContainsString('<script>alert(1)', $res->body(), $url);
            $this->assertStringNotContainsString('<img src=x', $res->body(), $url);
        }
        $this->assertSame('noindex, nofollow', $this->request('GET', "/admin/site/articles/$id/apercu")->headers()['X-Robots-Tag'] ?? null);
    }

    public function test_permissions_and_csrf(): void
    {
        $id = $this->article('Perm');
        $this->actingAs($this->createUser('medias@x.test', ['gest_medias']));
        foreach (['/admin/site/articles', '/admin/site/articles/nouveau', "/admin/site/articles/$id", "/admin/site/articles/$id/apercu", '/admin/site/articles/categories', '/admin/site/articles/etiquettes'] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status(), $url);
        }
        $this->assertSame(403, $this->request('POST', "/admin/site/articles/$id/etat", ['to' => 'en_relecture'])->status());
        $this->actingAs($this->seulPublicateur());
        $this->assertSame(403, $this->request('GET', '/admin/site/articles/categories')->status(), 'catégories : cms.pages.manage');
        $this->assertSame(200, $this->request('GET', '/admin/site/articles')->status());
        $this->actingAs($this->publisher);
        $this->assertSame(200, $this->request('GET', '/admin/site/articles')->status());
        $this->actingAs($this->author);
        $this->assertStringContainsString('href="/admin/site/articles"', $this->request('GET', '/admin/site/articles')->body());
        $this->assertSame(200, $this->request('GET', '/admin/site/articles/categories')->status());
        foreach (["/admin/site/articles/$id/etat", '/admin/site/articles/nouveau', '/admin/site/articles/categories'] as $url) {
            $res = $this->app()->handle(new \App\Core\Http\Request('POST', $url, [], ['to' => 'en_relecture', 'title' => 'x', 'name' => 'x', '_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
            $this->assertContains($res->status(), [403, 419], $url);
        }
        $this->assertSame('brouillon', $this->row($id)['status']);
    }

    public function test_form_sentinel_and_validation(): void
    {
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/articles/nouveau', ['title' => 'Tronqué', '_form_complete' => null]);
        $this->assertSame(422, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_articles'));
        $res = $this->request('POST', '/admin/site/articles/nouveau', ['title' => '', 'excerpt' => str_repeat('é', 301)]);
        $this->assertSame(422, $res->status());
        $img = $this->image();
        $res = $this->request('POST', '/admin/site/articles/nouveau', ['title' => 'Complet', 'body' => '<p>ok</p>', 'featured_media_id' => (string) $img, 'tags' => 'Un, Deux', 'author_label' => 'Le bureau']);
        $this->assertSame(302, $res->status());
        $row = $this->db->fetch("SELECT * FROM cms_articles WHERE slug = 'complet'");
        $this->assertSame('brouillon', $row['status']);
        $this->assertNotNull($row['featured_file_id']);
        $this->assertSame('Le bureau', $row['author_label']);
        $this->assertSame('', (string) $row['excerpt']);
        $this->assertStringContainsString('Deux, Un', $this->request('GET', '/admin/site/articles/' . $row['id'])->body());
    }

    public function test_audit_has_no_content(): void
    {
        $id = $this->article('Secret');
        $this->service()->update($id, ['title' => 'Secret 2', 'body' => '<p>corps confidentiel</p>'], $this->author);
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE auditable_type = 'cms_articles' AND (new_values LIKE '%confidentiel%' OR old_values LIKE '%confidentiel%')"));
    }
}
