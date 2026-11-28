<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Uploads;
use App\Modules\Cms\MediaService;
use App\Modules\Cms\MediaVariants;
use App\Modules\Cms\PageService;
use Tests\Support\AdminTestCase;

/** Variantes responsives (GD, 480 et 960 px) créées à l'ajout et à la demande ; rendu srcset/sizes/lazy/width/height. */
final class MediaVariantsTest extends AdminTestCase
{
    private string $root;
    private Uploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-variants-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0755, true);
        $this->uploads = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->overrides['uploads'] = $this->uploads;
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_pages', 'cms_gallery_items', 'cms_media', 'users'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function upload(string $type, int $w, int $h): int
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 80, 120));
        $tmp = $this->root . '/src.' . $type;
        $type === 'png' ? imagepng($img, $tmp) : imagejpeg($img, $tmp, 90);
        $this->app();
        $media = new MediaService($this->db, $this->services['audit'](), $this->uploads);
        return $media->add(['name' => "photo.$type", 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)], ['title' => 'Quai', 'alt' => 'Quai du port'], $this->createUser('m' . bin2hex(random_bytes(3)) . '@x.test', ['editeur']));
    }

    public function test_variants_are_generated_and_srcset_rendered(): void
    {
        // À l'ajout : JPEG → variantes JPEG 480 et 960, droits 0644, dimensions proportionnelles.
        $id = $this->upload('jpg', 1600, 900);
        $file = $this->uploads->path((int) $this->db->fetchValue('SELECT file_id FROM cms_media WHERE id = ?', [$id]));
        $base = substr($file, 0, -4);
        foreach ([480 => 270, 960 => 540] as $w => $h) {
            $this->assertFileExists("$base-$w.jpg");
            $this->assertSame([$w, $h], array_slice((array) getimagesize("$base-$w.jpg"), 0, 2));
            $this->assertSame('0644', substr(sprintf('%o', fileperms("$base-$w.jpg")), -4));
        }
        // À la demande : variantes effacées puis recréées ; idempotent.
        unlink("$base-480.jpg");
        $v = (new MediaVariants($this->db, $this->uploads))->generate($id);
        $this->assertSame([480, 960], array_column($v, 'width'));
        $this->assertFileExists("$base-480.jpg");
        $this->assertStringStartsWith('/media/', $v[0]['url']);
        $this->assertSame($v, (new MediaVariants($this->db, $this->uploads))->generate($id));

        // PNG → WebP ; une image plus petite que 960 n'a que la variante 480 ; plus petite que 480 : aucune.
        $png = $this->upload('png', 700, 350);
        $this->assertSame([[480, 'webp']], array_map(static fn (array $x): array => [$x['width'], pathinfo($x['url'], PATHINFO_EXTENSION)], (new MediaVariants($this->db, $this->uploads))->generate($png)));
        $this->assertSame([], (new MediaVariants($this->db, $this->uploads))->generate($this->upload('png', 300, 200)));
        $this->assertSame([], (new MediaVariants($this->db, $this->uploads))->generate(999999), 'média inconnu : rien');

        // Rendu : bloc image d'une page publiée.
        $this->app();
        $pages = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        $author = $this->createUser('r@x.test', ['redacteur']);
        $pub = $this->createUser('e@x.test', ['editeur']);
        $pid = $pages->create(['title' => 'Le port', 'blocks' => [['type' => 'image', 'media_id' => (string) $id, 'caption' => '']]], $author);
        $pages->publish($pid, $pub, null, (int) $pages->get($pid)['content_version']);
        $html = $this->app()->handle(new Request('GET', '/le-port', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']))->body();
        $url = '/media/' . $this->db->fetchValue('SELECT f.path FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ?', [$id]);
        $stem = substr($url, 0, -4);
        $this->assertStringContainsString('<img src="' . $url . '" srcset="' . $stem . '-480.jpg 480w, ' . $stem . '-960.jpg 960w, ' . $url . ' 1600w" sizes="', $html);
        $this->assertMatchesRegularExpression('~<img src="' . preg_quote($url, '~') . '"[^>]* alt="Quai du port" width="1600" height="900" loading="lazy" decoding="async">~', $html);

        // Suppression du média : variantes retirées aussi.
        $this->app();
        (new MediaService($this->db, $this->services['audit'](), $this->uploads))->delete($png, $pub);
        $this->assertSame([], glob($this->root . '/public/*/*/*-480.webp') ?: []);
    }
}
