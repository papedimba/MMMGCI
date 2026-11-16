<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Uploads;
use App\Modules\Cms\MediaService;
use DomainException;
use Tests\Support\AdminTestCase;

final class MediaLibraryTest extends AdminTestCase
{
    private string $root;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-media-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->useUploads(5 * 1048576);
        foreach (['cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_menu_items', 'cms_events', 'cms_articles', 'cms_pages', 'cms_media', 'users'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->admin = $this->createUser('media@x.test', ['gest_medias']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function useUploads(int $max): void
    {
        $this->forgetApp();
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', $max, true);
    }

    /** @return array<string,mixed> */
    private function file(string $name, string $bytes, string $mime = 'application/octet-stream'): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $bytes);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK, 'type' => $mime];
    }

    private function pngBytes(int $w = 8, int $h = 6, bool $noise = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        if ($noise) {
            for ($x = 0; $x < $w; $x++) {
                for ($y = 0; $y < $h; $y++) {
                    imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF));
                }
            }
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> */
    private function png(string $name = 'Photo Famille.png'): array
    {
        return $this->file($name, $this->pngBytes(), 'image/png');
    }

    private function pdf(string $name = 'statuts.pdf'): array
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'application/pdf');
    }

    private function service(): MediaService
    {
        $this->app();
        return new MediaService($this->db, $this->services['audit'](), $this->overrides['uploads']);
    }

    /** @param array<string,string> $meta */
    private function add(array $file, array $meta = []): int
    {
        return $this->service()->add($file, $meta + ['title' => 'Titre', 'alt' => 'Texte alternatif'], $this->admin);
    }

    public function test_upload_outside_whitelist_is_refused(): void
    {
        $png = $this->pngBytes();
        $cases = [
            'php' => $this->file('shell.php', '<?php echo 1;'),
            'svg' => $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
            'html' => $this->file('page.html', '<html><script>alert(1)</script></html>', 'text/html'),
            'double extension php last' => $this->file('photo.jpg.php', $png, 'image/png'),
            'double extension php hidden in jpg' => $this->file('shell.php.jpg', '<?php system($_GET["c"]);', 'image/jpeg'),
            'lying mime (png named jpg)' => $this->file('a.jpg', $png, 'image/jpeg'),
            'svg named png' => $this->file('a.png', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/png'),
            'phtml' => $this->file('a.phtml', $png, 'image/png'),
            'htaccess' => $this->file('.htaccess', 'php_flag engine on'),
        ];
        foreach ($cases as $label => $file) {
            try {
                $this->add($file);
                $this->fail("Accepté : $label");
            } catch (\App\Core\UploadError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media'));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM files'));
        $res = $this->request('POST', '/admin/site/medias/nouveau', ['title' => 'x', 'alt' => 'x'], ['fichier' => $cases['svg']]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Format non accepté', $res->body());
    }

    public function test_image_requires_alt(): void
    {
        try {
            $this->add($this->png(), ['alt' => '  ']);
            $this->fail('alt manquant accepté');
        } catch (DomainException $e) {
            $this->assertStringContainsString('texte alternatif', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM files'));
        $res = $this->request('POST', '/admin/site/medias/nouveau', ['title' => 'Quai', 'alt' => ''], ['fichier' => $this->png()]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('texte alternatif', $res->body());
        // Un document n'en exige pas.
        $id = $this->add($this->pdf(), ['alt' => '']);
        $this->assertGreaterThan(0, $id);
    }

    public function test_filename_is_never_kept_on_disk(): void
    {
        $id = $this->add($this->png('Photo Famille ../../x.png'), ['title' => 'Quai']);
        $row = $this->db->fetch('SELECT f.path, f.disk FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$id]);
        $this->assertSame('public', $row['disk']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $row['path']);
        $this->assertFileExists($this->root . '/public/' . $row['path']);
        $names = array_map('basename', glob($this->root . '/public/*/*/*') ?: []);
        $this->assertSame([basename($row['path'])], $names);
        $this->assertStringNotContainsStringIgnoringCase('famille', implode('', $names));
    }

    public function test_oversized_image_is_refused(): void
    {
        $this->useUploads(2048);
        $this->actingAs($this->admin);
        $this->expectException(\App\Core\UploadError::class);
        try {
            $this->add($this->file('big.png', $this->pngBytes(64, 64, true), 'image/png'));
        } finally {
            $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media'));
        }
    }

    public function test_image_with_too_many_pixels_is_refused(): void
    {
        $ihdr = pack('N', 20000) . pack('N', 20000) . "\x08\x02\x00\x00\x00";
        $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->expectException(\App\Core\UploadError::class);
        $this->add($this->file('bomb.png', $bomb, 'image/png'));
    }

    public function test_delete_is_refused_when_used_and_lists_usages(): void
    {
        $id = $this->add($this->png(), ['title' => 'Quai']);
        $fileId = (int) $this->db->fetchValue('SELECT file_id FROM cms_media WHERE id = ?', [$id]);
        $path = (string) $this->db->fetchValue('SELECT path FROM files WHERE id = ?', [$fileId]);
        $svc = $this->service();
        $this->assertSame([], $svc->usages($id));

        $this->db->insert('cms_pages', ['title' => 'Accueil <b>', 'slug' => 'accueil', 'featured_file_id' => $fileId]);
        $this->db->insert('cms_pages', ['title' => 'Bloc', 'slug' => 'bloc', 'blocks' => json_encode([['type' => 'image', 'media_id' => $id]])]);
        $this->db->insert('cms_pages', ['title' => 'Publiée', 'slug' => 'publiee', 'published_blocks' => json_encode([['type' => 'rich', 'html' => '<img src="/media/' . $path . '" alt="x">']])]);
        $this->db->insert('cms_pages', ['title' => 'Autre', 'slug' => 'autre', 'blocks' => json_encode([['type' => 'image', 'media_id' => $id * 10 + 1]])]); // id voisin : pas d'usage
        $this->db->insert('cms_articles', ['title' => 'Article', 'slug' => 'article', 'og_file_id' => $fileId]);
        $this->db->insert('cms_events', ['title' => 'Évènement', 'slug' => 'evt', 'starts_at' => '2027-01-01 10:00:00', 'featured_file_id' => $fileId]);
        $gal = $this->db->insert('cms_galleries', ['title' => 'Galerie', 'slug' => 'gal']);
        $this->db->insert('cms_gallery_items', ['gallery_id' => $gal, 'media_id' => $id]);
        $this->db->insert('cms_banners', ['title' => 'Bandeau', 'file_id' => $fileId]);
        $menu = (int) $this->db->fetchValue('SELECT id FROM cms_menus LIMIT 1');
        $this->db->insert('cms_menu_items', ['menu_id' => $menu, 'label' => 'Lien', 'url' => '/media/' . $path]);

        $types = array_column($svc->usages($id), 'type');
        sort($types);
        $this->assertSame(['article', 'banniere', 'evenement', 'galerie', 'menu', 'page', 'page', 'page'], $types);
        try {
            $svc->delete($id, $this->admin);
            $this->fail('suppression acceptée');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Accueil <b>', $e->getMessage()); // texte brut, échappé au rendu
            $this->assertStringContainsString('galerie', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media WHERE id = ?', [$id]));
        $res = $this->request('POST', "/admin/site/medias/$id/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Accueil &lt;b&gt;', $res->body());
        $this->assertStringNotContainsString('Accueil <b>', $res->body());
        $this->assertFileExists($this->root . '/public/' . $path);
    }

    public function test_unused_media_is_deleted_with_its_file_and_audited(): void
    {
        $id = $this->add($this->png());
        $path = (string) $this->db->fetchValue('SELECT f.path FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$id]);
        $res = $this->request('POST', "/admin/site/medias/$id/suppression");
        $this->assertSame(302, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media'));
        $this->assertFileDoesNotExist($this->root . '/public/' . $path);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.media_deleted' AND auditable_id = ?", [$id]));
    }

    public function test_custom_usage_provider_is_merged(): void
    {
        $id = $this->add($this->png());
        $svc = $this->service();
        $svc->registerUsageProvider('temoignage', static fn (int $m): array => [['type' => 'temoignage', 'id' => 7, 'label' => 'Avis de Yao']]);
        $this->assertSame([['type' => 'temoignage', 'id' => 7, 'label' => 'Avis de Yao']], $svc->usages($id));
        $this->expectException(DomainException::class);
        $svc->delete($id, $this->admin);
    }

    public function test_public_media_directory_serves_no_php(): void
    {
        $ht = (string) file_get_contents(dirname(__DIR__, 2) . '/public/media/.htaccess');
        $this->assertStringContainsString('php_flag engine off', $ht);
        $this->assertStringContainsString('RemoveHandler .php .phtml .phar', $ht);
        $this->assertMatchesRegularExpression('/FilesMatch.*php.*\n\s*Require all denied/', $ht);
        $this->assertStringContainsString('nosniff', $ht);
    }

    public function test_requires_cms_media_manage(): void
    {
        $id = $this->add($this->png());
        $writes = [['GET', '/admin/site/medias/nouveau'], ['POST', '/admin/site/medias/nouveau'], ['GET', "/admin/site/medias/$id"], ['POST', "/admin/site/medias/$id"], ['POST', "/admin/site/medias/$id/suppression"]];
        $reads = [['GET', '/admin/site/medias'], ['GET', '/admin/site/medias/selecteur']];

        $this->actingAs($this->createUser('red@x.test', ['redacteur'])); // cms.pages.manage seul : lecture et sélecteur
        foreach ($reads as [$m, $p]) {
            $this->assertSame(200, $this->request($m, $p)->status(), "$m $p redacteur");
        }
        foreach ($writes as [$m, $p]) {
            $this->assertSame(403, $this->request($m, $p)->status(), "$m $p redacteur");
        }
        $this->assertStringContainsString('Médias', $this->request('GET', '/admin')->body());

        $this->actingAs($this->createUser('nul@x.test', ['lecture_seule']));
        foreach (array_merge($reads, $writes) as [$m, $p]) {
            $this->assertSame(403, $this->request($m, $p)->status(), "$m $p lecture_seule");
        }
        $this->assertStringNotContainsString('/admin/site/medias', $this->request('GET', '/admin')->body());

        $this->actingAs($this->admin); // gest_medias : tout
        foreach ($reads as [$m, $p]) {
            $this->assertSame(200, $this->request($m, $p)->status());
        }
        $this->assertSame(200, $this->request('GET', "/admin/site/medias/$id")->status());
        $this->assertSame(200, $this->request('GET', '/admin/site/medias/nouveau')->status());
        $this->assertStringContainsString('Site web', $this->request('GET', '/admin')->body());
    }

    public function test_post_without_csrf_is_refused(): void
    {
        $app = $this->app();
        $res = $app->handle(new \App\Core\Http\Request('POST', '/admin/site/medias/nouveau', [], ['title' => 'x', 'alt' => 'x'], ['fichier' => $this->png()], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertContains($res->status(), [403, 419]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_media'));
    }

    public function test_hostile_title_is_escaped(): void
    {
        $evil = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
        $id = $this->add($this->png(), ['title' => $evil, 'alt' => $evil, 'caption' => $evil, 'credit' => $evil, 'folder' => 'Évènements 2026']);
        $pages = [
            '/admin/site/medias', "/admin/site/medias/$id", '/admin/site/medias/selecteur', '/admin/site/medias?q=' . urlencode($evil),
        ];
        foreach ($pages as $p) {
            $body = $this->request('GET', $p)->body();
            $this->assertStringNotContainsString('<script>alert', $body, $p);
            $this->assertStringNotContainsString('<img src=x', $body, $p);
        }
        $this->assertStringContainsString('&lt;script&gt;', $this->request('GET', "/admin/site/medias/$id")->body());
        // Même après un renvoi du formulaire invalide.
        $res = $this->request('POST', "/admin/site/medias/$id", ['title' => '', 'alt' => $evil, 'caption' => $evil]);
        $this->assertSame(422, $res->status());
        $this->assertStringNotContainsString('<script>alert', $res->body());
    }

    public function test_folder_name_is_sanitised(): void
    {
        $cases = ['Évènements 2026' => 'evenements-2026', '../../etc/passwd' => 'etc-passwd', '  ' => null, '' => null, '<b>X</b>' => 'b-x-b', str_repeat('a', 60) => str_repeat('a', 40)];
        foreach ($cases as $in => $out) {
            $id = $this->add($this->png(), ['folder' => (string) $in]);
            $this->assertSame($out, $this->db->fetchValue('SELECT folder FROM cms_media WHERE id = ?', [$id]), "dossier « $in »");
        }
    }

    public function test_search_filters_and_pagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->add($this->png(), ['title' => "Photo $i", 'folder' => $i <= 3 ? 'quais' : '']);
        }
        $this->add($this->pdf(), ['title' => 'Statuts', 'caption' => 'Version signée']);
        $svc = $this->service();
        $p1 = $svc->search([], 1);
        $this->assertSame(26, $p1['total']);
        $this->assertCount(24, $p1['rows']);
        $this->assertSame(2, $p1['pages']);
        $this->assertCount(2, $svc->search([], 2)['rows']);
        $this->assertSame(1, $svc->search(['type' => 'document'], 1)['total']);
        $this->assertSame(25, $svc->search(['type' => 'image'], 1)['total']);
        $this->assertSame(3, $svc->search(['folder' => 'quais'], 1)['total']);
        $this->assertSame(1, $svc->search(['q' => 'signée'], 1)['total']);
        $this->assertSame(0, $svc->search(['q' => '100%_'], 1)['total']); // jokers LIKE neutralisés
        $this->assertSame(0, $svc->search(['q' => '%'], 1)['total']);
        $this->assertSame(['quais'], $svc->folders());
        $body = $this->request('GET', '/admin/site/medias?type=image')->body();
        $this->assertStringContainsString('loading="lazy"', $body);
        $this->assertStringContainsString('width="8" height="6"', $body);
    }

    public function test_add_and_update_through_screens_and_picker(): void
    {
        $res = $this->request('POST', '/admin/site/medias/nouveau', ['title' => 'Quai de Vridi', 'alt' => 'Pirogues au quai', 'caption' => 'Matin', 'credit' => 'A. Koné', 'folder' => 'Quais'], ['fichier' => $this->png()]);
        $this->assertSame(302, $res->status());
        $row = $this->db->fetch('SELECT * FROM cms_media');
        $this->assertSame('quais', $row['folder']);
        $this->assertSame($this->admin, (int) $row['uploaded_by']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.media_added' AND auditable_id = ?", [$row['id']]));

        $res = $this->request('POST', "/admin/site/medias/{$row['id']}", ['title' => 'Quai', 'alt' => 'Pirogues', 'caption' => '', 'credit' => '', 'folder' => '']);
        $this->assertSame(302, $res->status());
        $this->assertSame(['Quai', null, null], array_values($this->db->fetch('SELECT title, caption, folder FROM cms_media WHERE id = ?', [$row['id']])));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.media_updated' AND auditable_id = ?", [$row['id']]));

        $path = (string) $this->db->fetchValue('SELECT path FROM files WHERE id = ?', [$row['file_id']]);
        $this->add($this->pdf());
        $html = $this->request('GET', '/admin/site/medias/selecteur?q=Quai')->body();
        $this->assertStringContainsString('data-media-id="' . $row['id'] . '"', $html);
        $this->assertStringContainsString('data-media-url="/media/' . $path . '"', $html);
        $this->assertStringContainsString('data-media-alt="Pirogues"', $html);
        $this->assertStringContainsString('Choisir', $html);
        $this->assertSame(1, substr_count($html, 'data-media-id='), 'les documents ne sont pas proposés');
    }

    public function test_text_references_block_deletion(): void
    {
        $id = $this->add($this->png());
        $fileId = (int) $this->db->fetchValue('SELECT file_id FROM cms_media WHERE id = ?', [$id]);
        $path = (string) $this->db->fetchValue('SELECT path FROM files WHERE id = ?', [$fileId]);
        $img = '<img src="/media/' . $path . '" alt="x">';
        $esc = json_encode($img);
        $cases = [
            'article' => fn () => $this->db->insert('cms_articles', ['title' => 'A', 'slug' => 'a-b', 'body' => $img]),
            'article excerpt' => fn () => $this->db->insert('cms_articles', ['title' => 'A2', 'slug' => 'a-c', 'excerpt' => $img]),
            'evenement' => fn () => $this->db->insert('cms_events', ['title' => 'E', 'slug' => 'e-b', 'starts_at' => '2027-01-01 10:00:00', 'description' => $img]),
            'galerie' => fn () => $this->db->insert('cms_galleries', ['title' => 'G', 'slug' => 'g-b', 'description' => $img]),
            'page excerpt' => fn () => $this->db->insert('cms_pages', ['title' => 'P', 'slug' => 'p-b', 'excerpt' => $img]),
            'banniere link' => fn () => $this->db->insert('cms_banners', ['title' => 'B', 'link_url' => '/media/' . $path]),
            'banniere body' => fn () => $this->db->insert('cms_banners', ['title' => 'B2', 'body' => $img]),
            'page media_ids' => fn () => $this->db->insert('cms_pages', ['title' => 'P2', 'slug' => 'p-c', 'blocks' => json_encode([['type' => 'galerie', 'media_ids' => [3, $id, 99]]])]),
            'page media_ids first' => fn () => $this->db->insert('cms_pages', ['title' => 'P3', 'slug' => 'p-d', 'published_blocks' => '{"media_ids": [' . $id . ']}']),
            'page file_id' => fn () => $this->db->insert('cms_pages', ['title' => 'P4', 'slug' => 'p-e', 'blocks' => '{"file_id": "' . $fileId . '"}']),
            'page json path' => fn () => $this->db->insert('cms_pages', ['title' => 'P5', 'slug' => 'p-f', 'blocks' => '[{"html":' . $esc . '}]']),
        ];
        $svc = $this->service();
        foreach ($cases as $label => $make) {
            $make();
            $this->assertNotSame([], $svc->usages($id), $label);
            try {
                $svc->delete($id, $this->admin);
                $this->fail("Suppression acceptée : $label");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
            foreach (['cms_articles', 'cms_events', 'cms_galleries', 'cms_pages', 'cms_banners'] as $t) {
                $this->db->query("DELETE FROM $t");
            }
            $this->assertSame([], $svc->usages($id), "nettoyé : $label");
        }
        // Ids voisins : pas de faux positif.
        $this->db->insert('cms_pages', ['title' => 'N', 'slug' => 'n-b', 'blocks' => json_encode([['media_ids' => [$id * 10 + 1, 100 + $id], 'media_id' => $id * 10]])]);
        $this->assertSame([], $svc->usages($id));
        $svc->delete($id, $this->admin);
        $this->assertFileDoesNotExist($this->root . '/public/' . $path);
    }

    public function test_control_and_direction_characters_are_stripped(): void
    {
        $id = $this->add($this->png(), ['title' => "Qu\u{202E}ai\0 de\u{200B} Vridi", 'alt' => "Pir\u{202E}ogue\x01", 'caption' => "a\u{2066}b", 'credit' => "c\0d"]);
        $row = $this->db->fetch('SELECT title, alt, caption, credit FROM cms_media WHERE id = ?', [$id]);
        $this->assertSame(['Quai de Vridi', 'Pirogue', 'ab', 'cd'], array_values($row));
    }

    public function test_htaccess_forbids_listing_and_cgi(): void
    {
        $this->assertStringContainsString('Options -Indexes -ExecCGI', (string) file_get_contents(dirname(__DIR__, 2) . '/public/media/.htaccess'));
    }
}
