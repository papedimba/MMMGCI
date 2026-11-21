<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Settings;
use App\Core\Uploads;
use App\Modules\Cms\BannerService;
use App\Modules\Cms\CmsScheduler;
use App\Modules\Cms\ContentTransitionInvalid;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\GalleryService;
use App\Modules\Cms\PageInvalid;
use App\Modules\Cms\TestimonialService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

final class CmsContentTypesTest extends AdminTestCase
{
    private const EVIL = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
    private int $author;
    private int $publisher;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-cms-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        foreach (['sensitive_access_logs', 'cms_page_redirects', 'cms_menu_items', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_testimonials',
            'cms_article_tag', 'cms_articles', 'cms_tags', 'cms_categories'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query('DELETE FROM users');
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL WHERE `group` = 'site' AND `key` = 'testimonials_enabled'");
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    protected function request(string $method, string $path, array $post = [], array $files = []): \App\Core\Http\Response
    {
        if (strtoupper($method) === 'POST' && preg_match('~^/admin/site/(evenements|documents|galeries|bannieres|temoignages)/(nouveau|\d+)$~', $path) === 1) {
            $post += ['_form_complete' => '1'];
            if ($post['_form_complete'] === null) {
                unset($post['_form_complete']);
            }
        }
        return parent::request($method, $path, $post, $files);
    }

    private function gate(): \App\Core\Gate
    {
        $this->app();
        return $this->services['gate']();
    }

    private function audit(): \App\Core\Audit
    {
        $this->app();
        return $this->services['audit']();
    }

    private function events(): EventService
    {
        return new EventService($this->db, $this->audit(), $this->gate());
    }

    private function docs(): DocumentService
    {
        return new DocumentService($this->db, $this->audit(), $this->gate());
    }

    private function galleries(): GalleryService
    {
        return new GalleryService($this->db, $this->audit(), $this->gate());
    }

    private function banners(): BannerService
    {
        return new BannerService($this->db, $this->audit(), $this->gate());
    }

    private function testimonials(): TestimonialService
    {
        return new TestimonialService($this->db, $this->audit(), $this->gate(), new Settings($this->db), $this->overrides['uploads']);
    }

    private function media(string $mime = 'image/png', ?string $alt = 'Quai du port', string $title = 'Quai'): int
    {
        $ext = ['image/png' => 'png', 'application/pdf' => 'pdf', 'image/webp' => 'webp', 'text/html' => 'html'][$mime];
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.' . $ext, 'original_name' => 'x', 'mime' => $mime, 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        return $this->db->insert('cms_media', ['file_id' => $file, 'title' => $title, 'alt' => $alt]);
    }

    private function v(object $svc, int $id): int
    {
        return (int) $svc->get($id)['content_version'];
    }

    private function future(string $m = '+2 days'): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    private function input(string $m): string
    {
        return $this->future($m)->format('Y-m-d\TH:i');
    }

    /** @return array<string,mixed> */
    private function pdfUpload(): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => 'consentement-signe.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function enableTestimonials(bool $value = true): void
    {
        (new Settings($this->db))->validate('site', 'testimonials_enabled', $value, $this->publisher, 'Décision test');
    }

    // ---- Évènements -------------------------------------------------------------------------------------------------------------

    public function test_event_end_cannot_precede_start(): void
    {
        $base = ['title' => 'Assemblée', 'starts_at' => $this->input('+3 days')];
        try {
            $this->events()->create($base + ['ends_at' => $this->input('+2 days')], $this->author);
            $this->fail('fin avant début');
        } catch (PageInvalid $e) {
            $this->assertArrayHasKey('ends_at', $e->errors);
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_events'));
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/evenements/nouveau', $base + ['ends_at' => $this->input('+1 day')]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('La fin ne peut pas précéder le début', $res->body());
        $this->assertSame(302, $this->request('POST', '/admin/site/evenements/nouveau', $base + ['ends_at' => $base['starts_at'], 'location' => 'Abidjan'])->status());
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_events WHERE starts_at > NOW() AND ends_at < starts_at"));
        $this->expectException(PageInvalid::class);
        $this->events()->create(['title' => 'Sans début'], $this->author);
    }

    public function test_event_workflow_and_public_helper(): void
    {
        $svc = $this->events();
        $id = $svc->create(['title' => 'Fête', 'starts_at' => $this->input('+5 days'), 'description' => '<p>Texte</p><script>x</script>'], $this->author);
        $this->assertStringNotContainsString('script', (string) $this->db->fetchValue('SELECT description FROM cms_events WHERE id = ?', [$id]));
        $this->assertSame([], $svc->liveList(), 'brouillon jamais public');
        try {
            $svc->transition($id, 'publie', $this->author);
            $this->fail('auteur ne publie pas');
        } catch (ContentTransitionInvalid) {
        }
        $svc->transition($id, 'en_relecture', $this->author);
        $v = (int) $svc->get($id)['content_version'];
        $svc->update($id, ['title' => 'Fête 2', 'starts_at' => $this->input('+5 days')], $this->author); // relecture modifiée -> brouillon
        $this->assertSame('brouillon', $svc->get($id)['status']);
        $this->expectExceptionObject(new ContentTransitionInvalid('L\'évènement a été modifié depuis votre lecture, relisez-le.'));
        try {
            $svc->transition($id, 'publie', $this->publisher, null, $v);
        } finally {
            $svc->transition($id, 'publie', $this->publisher, null, (int) $svc->get($id)['content_version']);
            $this->assertCount(1, $svc->liveList());
            // sans version conservée : l'auteur ne modifie pas un évènement déjà en ligne, le publicateur oui
            try {
                $svc->update($id, ['title' => 'Piraté', 'starts_at' => $this->input('+5 days')], $this->author);
                $this->fail('auteur sur évènement en ligne');
            } catch (ContentTransitionInvalid) {
            }
            $svc->update($id, ['title' => 'Fête 3', 'starts_at' => $this->input('+5 days')], $this->publisher);
            $this->assertSame('publie', $svc->get($id)['status']);
            $this->assertSame('Fête 3', $svc->liveList()[0]['title']);
            try {
                $svc->delete($id, $this->publisher);
                $this->fail('suppression d\'un publié');
            } catch (ContentTransitionInvalid) {
            }
        }
    }

    public function test_event_live_list_filters_and_scheduling(): void
    {
        $svc = $this->events();
        $mk = fn (string $t, string $start, ?string $end = null): int => $svc->create(['title' => $t, 'starts_at' => $this->input($start), 'ends_at' => $end === null ? '' : $this->input($end)], $this->author);
        $up = $mk('À venir', '+3 days');
        $past = $mk('Passé', '-10 days', '-9 days');
        $draft = $mk('Brouillon', '+4 days');
        $arch = $mk('Archivé', '+6 days');
        $sched = $mk('Programmé', '+8 days');
        $due = $mk('Échu', '+9 days');
        foreach ([$up, $past, $arch] as $i) {
            $svc->transition($i, 'publie', $this->publisher);
        }
        $svc->transition($arch, 'archive', $this->publisher);
        $svc->transition($sched, 'programme', $this->publisher, $this->future('+1 day'));
        $svc->transition($due, 'programme', $this->publisher, $this->future('+1 hour'));
        $this->assertSame(['À venir'], array_column($svc->liveList(['when' => 'upcoming']), 'title'));
        $this->assertSame(['Passé'], array_column($svc->liveList(['when' => 'past']), 'title'));
        $this->assertSame(['À venir', 'Passé'], array_column($svc->liveList(), 'title'));
        $this->assertSame([], $svc->liveList(['slug' => 'brouillon']));
        $this->assertSame([], $svc->liveList(['slug' => 'programme']), 'programmé futur : pas public');
        $this->db->query("UPDATE cms_events SET published_at = '2000-01-01 00:00:00' WHERE id = ?", [$due]);
        $this->assertContains('Échu', array_column($svc->liveList(), 'title'), 'programmé échu : public');
        $cron = new CmsScheduler($this->audit(), [$svc]);
        $this->assertSame(1, $cron->publishDue($this->future('+1 minute')));
        $this->assertSame('publie', $svc->get($due)['status']);
        $this->assertSame('brouillon', $svc->get($draft)['status']);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Cms/CmsScheduler.php');
        $this->assertStringContainsString('new EventService', $src, 'évènements enregistrés dans la tâche cron');
    }

    // ---- Documents officiels ----------------------------------------------------------------------------------------------------

    public function test_document_not_public_until_published_by_a_publisher(): void
    {
        $svc = $this->docs();
        $pdf = $this->media('application/pdf', null, 'Statuts');
        $id = $svc->create(['title' => 'Statuts 2026', 'category' => 'Statuts', 'document_date' => '2026-01-15', 'media_id' => (string) $pdf], $this->author);
        $this->assertSame('brouillon', $svc->get($id)['status']);
        $this->assertSame([], $svc->publishedList(null));
        $this->assertSame([], $svc->publishedList('Statuts'));
        try {
            $svc->transition($id, 'publie', $this->author);
            $this->fail('un rédacteur ne publie pas');
        } catch (ContentTransitionInvalid) {
        }
        $this->actingAs($this->author);
        $this->request('POST', "/admin/site/documents/$id/etat", ['to' => 'publie']);
        $this->assertSame('brouillon', $svc->get($id)['status']);
        $this->assertSame([], $svc->publishedList(null));
        $svc->transition($id, 'publie', $this->publisher, $this->v($svc, $id));
        $list = $svc->publishedList(null);
        $this->assertCount(1, $list);
        $this->assertStringStartsWith('/media/', $list[0]['url']);
        $this->assertSame([], $svc->publishedList('Autre'));
        $this->assertCount(1, $svc->publishedList('Statuts'));
        try {
            $svc->update($id, ['title' => 'Modifié', 'document_date' => '2026-01-15', 'media_id' => (string) $pdf], $this->author);
            $this->fail('publié : réservé aux publicateurs');
        } catch (ContentTransitionInvalid) {
        }
        $svc->update($id, ['title' => 'Modifié', 'category' => 'Statuts', 'document_date' => '2026-01-15', 'media_id' => (string) $pdf], $this->publisher);
        $svc->transition($id, 'archive', $this->publisher);
        $this->assertSame([], $svc->publishedList(null), 'archivé : non public');
        $this->assertSame(['Statuts'], $svc->categories());
        try {
            $svc->delete($id, $this->publisher);
            $this->fail('suppression d\'un document déjà publié');
        } catch (ContentTransitionInvalid) {
        }
    }

    public function test_document_file_must_be_whitelisted(): void
    {
        $svc = $this->docs();
        $base = ['title' => 'Pièce', 'document_date' => '2026-02-01'];
        foreach (['image/webp' => 'webp', 'text/html' => 'html'] as $mime => $_) {
            try {
                $svc->create($base + ['media_id' => (string) $this->media($mime, 'a')], $this->author);
                $this->fail($mime);
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('media_id', $e->errors, $mime);
            }
        }
        foreach (['', '999999', 'abc'] as $bad) {
            try {
                $svc->create($base + ['media_id' => $bad], $this->author);
                $this->fail("média « $bad »");
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('media_id', $e->errors);
            }
        }
        try {
            $svc->create(['title' => 'X', 'document_date' => '2026-13-45', 'media_id' => (string) $this->media('application/pdf', null)], $this->author);
            $this->fail('date');
        } catch (PageInvalid $e) {
            $this->assertArrayHasKey('document_date', $e->errors);
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_documents'));
        $svc->create($base + ['media_id' => (string) $this->media('application/pdf', null)], $this->author);
        $svc->create($base + ['media_id' => (string) $this->media('image/png')], $this->author);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_documents'));
    }

    // ---- Galeries -----------------------------------------------------------------------------------------------------------------

    public function test_gallery_item_requires_alt(): void
    {
        $svc = $this->galleries();
        $g = $svc->create(['title' => 'Journée du port'], $this->author);
        foreach ([$this->media('application/pdf', null), $this->media('image/png', ''), $this->media('image/png', '   ')] as $bad) {
            try {
                $svc->addItem($g, ['media_id' => (string) $bad], $this->author);
                $this->fail('média refusé attendu');
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('media_id', $e->errors);
            }
        }
        $this->assertSame([], $svc->items($g));
        $a = $svc->addItem($g, ['media_id' => (string) $this->media(), 'caption' => 'Un', 'credit' => 'Studio'], $this->author);
        $b = $svc->addItem($g, ['media_id' => (string) $this->media('image/png', 'Deux')], $this->author);
        $this->assertSame([$a, $b], array_map('intval', array_column($svc->items($g), 'id')));
        $svc->moveItem($g, $b, -1, $this->author);
        $this->assertSame([$b, $a], array_map('intval', array_column($svc->items($g), 'id')));
        $svc->moveItem($g, $b, -1, $this->author); // déjà premier : sans effet
        $svc->moveItem($g, $b, 1, $this->author);
        $this->assertSame([$a, $b], array_map('intval', array_column($svc->items($g), 'id')));
        try {
            $svc->updateItem($g, $a, ['caption' => str_repeat('é', 201)], $this->author);
            $this->fail('légende trop longue');
        } catch (PageInvalid $e) {
            $this->assertArrayHasKey('caption', $e->errors);
        }
        // Lecture publique : seulement les albums publiés ; une image dont l'alt a disparu n'est pas servie.
        $this->assertSame([], $svc->publishedAlbums());
        $this->assertSame([], $svc->publishedItems($g));
        $svc->transition($g, 'publie', $this->publisher, $this->v($svc, $g));
        $this->assertSame(2, (int) $svc->publishedAlbums()[0]['item_count']);
        $this->assertCount(2, $svc->publishedItems($g));
        $this->db->query("UPDATE cms_media SET alt = '' WHERE id = (SELECT media_id FROM cms_gallery_items WHERE id = ?)", [$b]);
        $this->assertCount(1, $svc->publishedItems($g));
        try {
            $svc->addItem($g, ['media_id' => (string) $this->media()], $this->author);
            $this->fail('album publié : réservé aux publicateurs');
        } catch (ContentTransitionInvalid) {
        }
        $svc->transition($g, 'archive', $this->publisher);
        $this->assertSame([], $svc->publishedAlbums());
        $this->assertSame([], $svc->publishedItems($g));
    }

    public function test_gallery_screens_work_without_javascript(): void
    {
        $this->actingAs($this->author);
        $this->assertSame(302, $this->request('POST', '/admin/site/galeries/nouveau', ['title' => 'Album', 'description' => '<p>x</p>'])->status());
        $g = (int) $this->db->fetchValue("SELECT id FROM cms_galleries WHERE slug = 'album'");
        $m1 = $this->media();
        $m2 = $this->media('image/png', 'Autre');
        $this->assertSame(302, $this->request('POST', "/admin/site/galeries/$g/elements", ['media_id' => (string) $m1])->status());
        $this->request('POST', "/admin/site/galeries/$g/elements", ['media_id' => (string) $m2]);
        $second = (int) $this->db->fetchValue('SELECT id FROM cms_gallery_items WHERE media_id = ?', [$m2]);
        $this->assertSame(302, $this->request('POST', "/admin/site/galeries/$g/elements/$second/monter")->status());
        $this->assertSame($m2, (int) $this->galleries()->items($g)[0]['media_id']);
        $this->assertSame(302, $this->request('POST', "/admin/site/galeries/$g/elements/$second/legende", ['caption' => 'Légende', 'credit' => 'Photo'])->status());
        $this->assertSame('Légende', $this->galleries()->items($g)[0]['caption']);
        $body = $this->request('GET', "/admin/site/galeries/$g")->body();
        $this->assertStringContainsString('formnovalidate', $body);
        $this->assertSame(302, $this->request('POST', "/admin/site/galeries/$g/elements/$second/retrait")->status());
        $this->assertCount(1, $this->galleries()->items($g));
        $res = $this->request('POST', "/admin/site/galeries/$g/elements", ['media_id' => (string) $this->media('image/png', '')]);
        $this->assertSame(422, $res->status());
    }

    // ---- Bannières ----------------------------------------------------------------------------------------------------------------

    public function test_banner_limit_of_three_active(): void
    {
        $svc = $this->banners();
        $mk = fn (string $t, string $start = '', string $end = ''): int => $svc->create(['title' => $t, 'starts_at' => $start, 'ends_at' => $end], $this->author);
        $ids = [$mk('Un'), $mk('Deux'), $mk('Trois', $this->input('+10 days'), $this->input('+20 days'))];
        foreach ($ids as $i) {
            $svc->transition($i, 'publie', $this->publisher, $this->v($svc, $i));
        }
        $four = $mk('Quatre');
        try {
            $svc->transition($four, 'publie', $this->publisher, $this->v($svc, $four));
            $this->fail('4e bannière');
        } catch (ContentTransitionInvalid $e) {
            $this->assertStringContainsString('Trois bannières', $e->getMessage());
        }
        $this->assertSame('brouillon', $svc->get($four)['status']);
        // La période terminée ne compte pas ; une bannière archivée libère la place.
        $past = $mk('Passée', $this->input('-5 days'), $this->input('-1 day'));
        $svc->transition($past, 'publie', $this->publisher, $this->v($svc, $past));
        $svc->transition($ids[0], 'archive', $this->publisher);
        $svc->transition($four, 'publie', $this->publisher, $this->v($svc, $four));
        $this->assertSame(['Deux', 'Quatre'], array_column($svc->activeNow($this->future('now')), 'text'), 'Trois commence dans le futur, Passée est terminée');
        $this->assertSame(['Deux', 'Trois', 'Quatre'], array_column($svc->activeNow($this->future('+11 days')), 'text'));
        $this->assertSame([], array_filter(array_column($svc->activeNow($this->future('+30 days')), 'text'), static fn (string $t): bool => $t === 'Trois'));
        // Un brouillon ne s'affiche jamais ; la modification d'une bannière publiée est aussi soumise à la limite.
        $this->assertNotContains('Un', array_column($svc->activeNow($this->future('now')), 'text'));
    }

    public function test_banner_publication_locks_the_set_and_links_are_safe(): void
    {
        $svc = $this->banners();
        $id = $svc->create(['title' => 'Une'], $this->author);
        $other = Database::connect(self::config(), test: true);
        $other->pdo()->beginTransaction();
        $other->query('SELECT id FROM cms_banners ORDER BY id FOR UPDATE'); // une publication concurrente détient le verrou de l'ensemble
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $svc->transition($id, 'publie', $this->publisher, $this->v($svc, $id));
            $this->fail('verrou attendu');
        } catch (ContentTransitionInvalid $e) {
            $this->assertStringContainsString('occupé', $e->getMessage());
        } finally {
            $other->pdo()->rollBack();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $svc->transition($id, 'publie', $this->publisher, $this->v($svc, $id));
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test', str_repeat('a', 300)] as $bad) {
            try {
                $svc->create(['title' => 'Lien', 'link_url' => $bad], $this->author);
                $this->fail($bad);
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('link_url', $e->errors);
            }
        }
        $svc->create(['title' => 'Lien', 'link_url' => 'https://exemple.ci/x'], $this->author);
        $svc->create(['title' => 'Lien interne', 'link_url' => '/page'], $this->author);
        foreach ([['title' => str_repeat('a', 161)], ['title' => ''], ['title' => 'x', 'starts_at' => $this->input('+2 days'), 'ends_at' => $this->input('+1 day')]] as $bad) {
            try {
                $svc->create($bad, $this->author);
                $this->fail('refus attendu');
            } catch (PageInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- Témoignages ----------------------------------------------------------------------------------------------------------------

    public function test_testimonial_cannot_be_published_without_consent_file_and_setting(): void
    {
        $svc = $this->testimonials();
        $withConsent = $svc->create(['body' => 'Très bien.', 'author_name' => 'Awa Koné', 'author_role' => 'Adhérente'], $this->pdfUpload(), $this->author);
        $without = $svc->create(['body' => 'Sans pièce.', 'author_name' => 'Yao'], null, $this->author);
        $this->assertNotNull($svc->get($withConsent)['consent_file_id']);
        // réglage non validé
        try {
            $svc->transition($withConsent, 'publie', $this->publisher, $this->v($svc, $withConsent));
            $this->fail('réglage non validé');
        } catch (ContentTransitionInvalid $e) {
            $this->assertStringContainsString('testimonials_enabled', $e->getMessage());
        }
        // réglage validé mais valeur false (ou non strictement true)
        $this->enableTestimonials(false);
        $this->assertFalse($svc->enabled());
        $this->expectOutputString('');
        try {
            $svc->transition($withConsent, 'publie', $this->publisher, $this->v($svc, $withConsent));
            $this->fail('valeur false');
        } catch (ContentTransitionInvalid) {
        }
        $this->enableTestimonials(true);
        $this->assertTrue($svc->enabled());
        try {
            $svc->transition($without, 'publie', $this->publisher, $this->v($svc, $without));
            $this->fail('sans consentement');
        } catch (ContentTransitionInvalid $e) {
            $this->assertStringContainsString('consentement', $e->getMessage());
        }
        try {
            $svc->transition($withConsent, 'publie', $this->author);
            $this->fail('rédacteur sans cms.publish');
        } catch (ContentTransitionInvalid) {
        }
        $this->assertSame([], $svc->publishable());
        $svc->transition($withConsent, 'publie', $this->publisher, $this->v($svc, $withConsent));
        $this->assertSame(['Awa Koné'], array_column($svc->publishable(), 'author_name'));
        $this->assertArrayNotHasKey('consent_file_id', $svc->publishable()[0]);
        $this->enableTestimonials(false);
        $this->assertSame([], $svc->publishable(), 'réglage retiré : plus public');
        $this->enableTestimonials(true);
        $svc->transition($withConsent, 'archive', $this->publisher);
        $this->assertSame([], $svc->publishable());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_testimonials WHERE status = ?', ['publie']));
        // suppression d'un brouillon : la ligne seule ; la pièce privée est conservée, aucun média public touché
        $mediaCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media');
        $svc->delete($without, $this->author);
        $this->assertSame($mediaCount, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media'));
        $this->assertCount(1, $this->db->fetchAll('SELECT id FROM cms_testimonials'));
        // limites
        foreach ([['body' => str_repeat('a', 601), 'author_name' => 'x'], ['body' => 'x', 'author_name' => str_repeat('a', 81)], ['body' => 'x', 'author_name' => 'a', 'author_role' => str_repeat('a', 81)]] as $bad) {
            try {
                $svc->create($bad, null, $this->author);
                $this->fail('limite');
            } catch (PageInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_consent_file_is_private_and_never_served_publicly(): void
    {
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/temoignages/nouveau', ['body' => 'Merci.', 'author_name' => 'Awa'], ['consent' => $this->pdfUpload()]);
        $this->assertSame(302, $res->status());
        $id = (int) $this->db->fetchValue('SELECT id FROM cms_testimonials');
        $file = $this->db->fetch('SELECT f.* FROM cms_testimonials t JOIN files f ON f.id = t.consent_file_id WHERE t.id = ?', [$id]);
        $this->assertSame('private', $file['disk']);
        $this->assertFileExists($this->root . '/private/' . $file['path']);
        $this->assertFileDoesNotExist($this->root . '/public/' . $file['path']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media WHERE file_id = ?', [$file['id']]), 'jamais une entrée de la médiathèque');
        // Aucune page ni aide de lecture ne cite le chemin du fichier.
        foreach (['/admin/site/temoignages', "/admin/site/temoignages/$id"] as $url) {
            $this->assertStringNotContainsString($file['path'], $this->request('GET', $url)->body(), $url);
        }
        $this->enableTestimonials();
        $this->testimonials()->transition($id, 'publie', $this->publisher, (int) $this->testimonials()->get($id)['content_version']);
        $this->assertStringNotContainsString($file['path'], json_encode($this->testimonials()->publishable()));
        // Téléchargement : refusé au rédacteur, invisible pour lui, journalisé pour le publicateur.
        $this->assertStringNotContainsString('/consentement', $this->request('GET', "/admin/site/temoignages/$id")->body());
        $this->assertSame(403, $this->request('GET', "/admin/site/temoignages/$id/consentement")->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        $this->actingAs($this->publisher);
        $this->assertStringContainsString('/consentement', $this->request('GET', "/admin/site/temoignages/$id")->body());
        $dl = $this->request('GET', "/admin/site/temoignages/$id/consentement");
        $this->assertSame(200, $dl->status());
        $this->assertSame('application/pdf', $dl->headers()['Content-Type']);
        $this->assertStringStartsWith('%PDF-', $dl->body());
        $this->assertStringNotContainsString($file['path'], $dl->headers()['Content-Disposition']);
        $log = $this->db->fetch('SELECT * FROM sensitive_access_logs');
        $this->assertSame([$this->publisher, 'cms_testimonial', $id, 'consent'], [(int) $log['user_id'], $log['subject_type'], (int) $log['subject_id'], $log['field_group']]);
        $this->assertSame(404, $this->request('GET', '/admin/site/temoignages/999999/consentement')->status());
        $this->assertSame(404, $this->request('GET', "/admin/site/temoignages/$id/consentement/../x")->status() === 404 ? 404 : 0);
        // Remplacer le consentement retire l'ancien fichier privé.
        $this->request('POST', "/admin/site/temoignages/$id", ['body' => 'Merci.', 'author_name' => 'Awa'], ['consent' => $this->pdfUpload()]);
        $this->assertFileDoesNotExist($this->root . '/private/' . $file['path']);
        // Un fichier refusé (format) ne crée rien.
        $bad = $this->root . '/x.tmp';
        file_put_contents($bad, '<?php echo 1;');
        $res = $this->request('POST', '/admin/site/temoignages/nouveau', ['body' => 'x', 'author_name' => 'y'], ['consent' => ['name' => 'a.php', 'tmp_name' => $bad, 'size' => 13, 'error' => UPLOAD_ERR_OK, 'type' => 'x']]);
        $this->assertSame(422, $res->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_testimonials'));
    }

    public function test_testimonial_list_warns_when_setting_is_not_validated(): void
    {
        $this->actingAs($this->author);
        $this->assertStringContainsString('Aucun témoignage ne peut être publié', $this->request('GET', '/admin/site/temoignages')->body());
        $this->enableTestimonials();
        $this->assertStringNotContainsString('ne peut être publié', $this->request('GET', '/admin/site/temoignages')->body());
    }

    public function test_publication_needs_the_version_the_publisher_saw(): void
    {
        $pdf = $this->media('application/pdf', null);
        $doc = $this->docs();
        $d = $doc->create(['title' => 'Doc', 'document_date' => '2026-01-01', 'media_id' => (string) $pdf], $this->author);
        $seen = $this->v($doc, $d);
        $doc->update($d, ['title' => 'Doc', 'document_date' => '2026-01-01', 'media_id' => (string) $this->media('application/pdf', null)], $this->author); // autre fichier
        $this->assertSame('brouillon', $this->tryPublish($doc, $d, $seen));
        $this->assertSame('publie', $this->tryPublish($doc, $d, $this->v($doc, $d)));

        $t = $this->testimonials();
        $this->enableTestimonials();
        $id = $t->create(['body' => 'Avant', 'author_name' => 'Awa'], $this->pdfUpload(), $this->author);
        $seen = $this->v($t, $id);
        $t->update($id, ['body' => 'Après', 'author_name' => 'Awa'], null, $this->author);
        $this->assertSame('brouillon', $this->tryPublish($t, $id, $seen));
        $seen = $this->v($t, $id);
        $t->update($id, ['body' => 'Après', 'author_name' => 'Awa'], $this->pdfUpload(), $this->author); // remplacement du consentement
        $this->assertSame('brouillon', $this->tryPublish($t, $id, $seen));

        $g = $this->galleries();
        $gid = $g->create(['title' => 'Alb'], $this->author);
        $item = $g->addItem($gid, ['media_id' => (string) $this->media()], $this->author);
        $other = $g->addItem($gid, ['media_id' => (string) $this->media('image/png', 'B')], $this->author);
        foreach ([fn () => $g->updateItem($gid, $item, ['caption' => 'x'], $this->author), fn () => $g->moveItem($gid, $item, 1, $this->author),
            fn () => $g->removeItem($gid, $other, $this->author), fn () => $g->addItem($gid, ['media_id' => (string) $this->media('image/png', 'C')], $this->author)] as $change) {
            $seen = $this->v($g, $gid);
            $change();
            $this->assertSame('brouillon', $this->tryPublish($g, $gid, $seen));
        }

        $b = $this->banners();
        $bid = $b->create(['title' => 'B'], $this->author);
        $seen = $this->v($b, $bid);
        $b->update($bid, ['title' => 'B2'], $this->author);
        $this->assertSame('brouillon', $this->tryPublish($b, $bid, $seen));
        $this->assertSame('brouillon', $this->tryPublish($b, $bid, null), 'version absente : refus');

        // Écran : le formulaire porte la version affichée ; une version périmée est refusée avec le message.
        $this->actingAs($this->publisher);
        $v = $this->v($b, $bid);
        $this->assertStringContainsString('name="version" value="' . $v . '"', $this->request('GET', "/admin/site/bannieres/$bid")->body());
        $b->update($bid, ['title' => 'B3'], $this->author);
        $res = $this->request('POST', "/admin/site/bannieres/$bid/etat", ['to' => 'publie', 'version' => (string) $v]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('modifié depuis votre lecture', $res->body());
        $this->assertSame('brouillon', $b->get($bid)['status']);
    }

    private function tryPublish(object $svc, int $id, ?int $seen): string
    {
        try {
            $svc->transition($id, 'publie', $this->publisher, $seen);
        } catch (ContentTransitionInvalid $e) {
            $this->assertStringContainsString('relisez-le', $e->getMessage());
        }
        return (string) $svc->get($id)['status'];
    }

    // ---- Transversal ------------------------------------------------------------------------------------------------------------------

    public function test_all_types_escape_hostile_text(): void
    {
        $e = self::EVIL;
        $event = $this->events()->create(['title' => $e, 'location' => $e, 'description' => '<p>' . $e . '</p>', 'starts_at' => $this->input('+1 day')], $this->author);
        $pdf = $this->media('application/pdf', null, $e);
        $doc = $this->docs()->create(['title' => $e, 'category' => $e, 'document_date' => '2026-03-03', 'media_id' => (string) $pdf], $this->author);
        $g = $this->galleries()->create(['title' => $e, 'description' => '<p>' . $e . '</p>'], $this->author);
        $this->galleries()->addItem($g, ['media_id' => (string) $this->media('image/png', $e, $e), 'caption' => $e, 'credit' => $e], $this->author);
        $banner = $this->banners()->create(['title' => $e, 'link_url' => '/x'], $this->author);
        $t = $this->testimonials()->create(['body' => $e, 'author_name' => $e, 'author_role' => $e], null, $this->author);
        $this->actingAs($this->publisher);
        $urls = [];
        foreach (['evenements' => $event, 'documents' => $doc, 'galeries' => $g, 'bannieres' => $banner, 'temoignages' => $t] as $seg => $id) {
            array_push($urls, "/admin/site/$seg", "/admin/site/$seg/$id", "/admin/site/$seg/nouveau");
        }
        foreach ($urls as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(200, $res->status(), $url);
            $this->assertStringNotContainsString('<script>alert(1)', $res->body(), $url);
            $this->assertStringNotContainsString('<img src=x', $res->body(), $url);
        }
        // Un envoi refusé réaffiche la saisie hostile échappée.
        $res = $this->request('POST', '/admin/site/bannieres/nouveau', ['title' => $e . str_repeat('a', 200)]);
        $this->assertSame(422, $res->status());
        $this->assertStringNotContainsString('<script>alert(1)', $res->body());
    }

    public function test_permissions_and_csrf(): void
    {
        $segments = ['evenements', 'documents', 'galeries', 'bannieres', 'temoignages'];
        $this->actingAs($this->createUser('medias@x.test', ['gest_medias']));
        foreach ($segments as $s) {
            foreach (["/admin/site/$s", "/admin/site/$s/nouveau"] as $url) {
                $this->assertSame(403, $this->request('GET', $url)->status(), $url);
            }
            $this->assertSame(403, $this->request('POST', "/admin/site/$s/nouveau", ['title' => 'x'])->status(), $s);
        }
        $this->actingAs($this->author);
        foreach ($segments as $s) {
            $this->assertSame(200, $this->request('GET', "/admin/site/$s")->status(), $s);
            $this->assertStringContainsString('href="/admin/site/' . $s . '"', $this->request('GET', "/admin/site/$s")->body(), 'entrée de menu');
            $res = $this->app()->handle(new \App\Core\Http\Request('POST', "/admin/site/$s/nouveau", [], ['title' => 'x', 'body' => 'x', 'author_name' => 'x', '_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
            $this->assertContains($res->status(), [403, 419], $s);
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT (SELECT COUNT(*) FROM cms_events) + (SELECT COUNT(*) FROM cms_banners) + (SELECT COUNT(*) FROM cms_testimonials) + (SELECT COUNT(*) FROM cms_galleries)'));
        // Seul publicateur (sans cms.pages.manage) : voit les listes ; le téléchargement du consentement est réservé à cms.publish.
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('seul_pub', 'Seul publicateur')");
        $role = (int) $this->db->fetchValue("SELECT id FROM roles WHERE name = 'seul_pub'");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = 'cms.publish'", [$role]);
        $this->actingAs($this->createUser('seul@x.test', ['seul_pub']));
        foreach ($segments as $s) {
            $this->assertSame(200, $this->request('GET', "/admin/site/$s")->status(), $s);
        }
    }

    public function test_form_sentinel_and_publication_buttons(): void
    {
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/documents/nouveau', ['title' => 'Tronqué', '_form_complete' => null]);
        $this->assertSame(422, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_documents'));
        $b = $this->banners()->create(['title' => 'Bandeau'], $this->author);
        $body = $this->request('GET', "/admin/site/bannieres/$b")->body();
        $this->assertStringNotContainsString('name="to" value="publie"', $body, 'le rédacteur ne voit pas Publier');
        $this->assertStringContainsString('name="_form_complete"', $body);
        $this->actingAs($this->publisher);
        $this->assertStringContainsString('name="to" value="publie"', $this->request('GET', "/admin/site/bannieres/$b")->body());
        $this->assertSame(302, $this->request('POST', "/admin/site/bannieres/$b/etat", ['to' => 'publie', 'version' => (string) $this->v($this->banners(), $b)])->status());
        $this->assertSame('publie', $this->banners()->get($b)['status']);
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE auditable_type = 'cms_banners' AND (new_values LIKE '%Bandeau%' OR old_values LIKE '%Bandeau%')"), 'l\'audit ne contient pas le texte');
    }
}
