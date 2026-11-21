<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\UploadError;
use App\Core\Uploads;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

final class UploadsTest extends DatabaseTestCase
{
    private string $root;
    private Uploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-up-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->uploads = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1024 * 1024, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string,mixed> */
    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => $error, 'type' => 'x/y'];
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(20, 10);
        ob_start();
        imagejpeg($im, null, 90);
        $data = (string) ob_get_clean();
        // Faux segment APP1 (EXIF) inséré après SOI.
        $exif = "Exif\0\0SECRET-GPS";
        return substr($data, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($data, 2);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function refused(string $name, string $content, string $msg): void
    {
        try {
            $this->uploads->store($this->upload($name, $content), 'document', false, null);
            $this->fail('UploadError attendue');
        } catch (UploadError $e) {
            $this->assertSame($msg, $e->getMessage());
        }
    }

    public function test_uppercase_extension_and_multiple_dots_are_accepted(): void
    {
        $a = $this->uploads->store($this->upload('PHOTO.JPG', $this->jpeg()), 'image', false, 1);
        $b = $this->uploads->store($this->upload('piece.identite.pdf', $this->pdf()), 'document', false, 1);
        $this->assertStringEndsWith('.jpg', (string) $this->db->fetchValue('SELECT path FROM files WHERE id = ?', [$a]));
        $row = $this->db->fetch('SELECT * FROM files WHERE id = ?', [$b]);
        $this->assertSame('application/pdf', $row['mime']);
        $this->assertSame('piece.identite.pdf', $row['original_name']);
        $this->assertSame(hash('sha256', $this->pdf()), $row['sha256']);
        $this->assertSame(strlen($this->pdf()), (int) $row['size']);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $row['path']);
        $this->assertSame(0640, fileperms($this->uploads->path($b)) & 0777);
    }

    public function test_modes_depend_on_the_disk(): void
    {
        $old = umask(077); // le mode demandé ne doit pas dépendre du umask du serveur
        try {
            $priv = $this->uploads->store($this->upload('a.pdf', $this->pdf()), 'document', false, 1);
            $pub = $this->uploads->store($this->upload('b.jpg', $this->jpeg()), 'image', true, 1);
        } finally {
            umask($old);
        }
        $p = $this->uploads->path($priv);
        $q = $this->uploads->path($pub);
        $this->assertSame([0640, 0750, 0750], [fileperms($p) & 0777, fileperms(dirname($p)) & 0777, fileperms(dirname($p, 2)) & 0777]);
        $this->assertSame([0644, 0755, 0755], [fileperms($q) & 0777, fileperms(dirname($q)) & 0777, fileperms(dirname($q, 2)) & 0777]);
    }

    public function test_php_disguised_as_jpg_is_refused(): void
    {
        $this->refused('x.jpg', '<?php system($_GET["c"]);', 'Format non accepté : PDF, JPG ou PNG uniquement.');
    }

    public function test_double_extension_php_is_refused(): void
    {
        $this->refused('x.jpg.php', $this->jpeg(), 'Format non accepté : PDF, JPG ou PNG uniquement.');
    }

    public function test_svg_is_refused(): void
    {
        $this->refused('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'Format non accepté : PDF, JPG ou PNG uniquement.');
    }

    public function test_pdf_mime_with_wrong_extension_is_refused(): void
    {
        $this->refused('x.png', $this->pdf(), 'Format non accepté : PDF, JPG ou PNG uniquement.');
    }

    public function test_pdf_in_image_profile_is_refused_with_image_formats(): void
    {
        $this->expectExceptionMessage('Format non accepté : JPG, PNG ou WebP uniquement.');
        $this->uploads->store($this->upload('a.pdf', $this->pdf()), 'image', false, null);
    }

    public function test_appended_php_payload_is_not_stored(): void
    {
        $id = $this->uploads->store($this->upload('a.jpg', $this->jpeg() . '<?php system($_GET["c"]); ?>'), 'image', false, null);
        $this->assertStringNotContainsString('<?php', (string) file_get_contents($this->uploads->path($id)));
    }

    public function test_jpeg_header_claiming_65000_square_is_refused(): void
    {
        $j = $this->jpeg();
        $i = strpos($j, "\xFF\xC0");
        $this->assertNotFalse($i);
        $j = substr_replace($j, pack('nn', 65000, 65000), $i + 5, 4);
        $this->expectExceptionMessage('Image trop grande');
        $this->uploads->store($this->upload('a.jpg', $j), 'image', false, null);
    }

    public function test_path_refuses_stored_path_escaping_dir(): void
    {
        file_put_contents($this->root . '/outside.pdf', 'x');
        $id = $this->db->insert('files', ['disk' => 'private', 'path' => '../outside.pdf', 'original_name' => 'o', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        $this->expectException(InvalidArgumentException::class);
        $this->uploads->path($id);
    }

    public function test_write_failure_becomes_upload_error_even_with_warnings_as_exceptions(): void
    {
        file_put_contents($this->root . '/blocker', 'x');
        $broken = new Uploads($this->db, $this->root . '/blocker', $this->root . '/public', 5 * 1024 * 1024, true);
        set_error_handler(static function (int $n, string $m): bool { throw new \ErrorException($m, 0, $n); });
        try {
            $broken->store($this->upload('a.pdf', $this->pdf()), 'document', false, null);
            $this->fail('UploadError attendue');
        } catch (UploadError $e) {
            $this->assertStringStartsWith('Le fichier n\'a pas pu être enregistré : réessayez', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function test_bidi_controls_and_invalid_utf8_are_stripped_from_display_name(): void
    {
        $id = $this->uploads->store($this->upload("a\u{202E}fdp\xFF.pdf", $this->pdf()), 'document', false, null);
        $this->assertSame('afdp?.pdf', $this->db->fetchValue('SELECT original_name FROM files WHERE id = ?', [$id]));
    }

    public function test_empty_file_message(): void
    {
        $this->refused('x.pdf', '', 'Le fichier est vide : choisissez un autre fichier.');
    }

    public function test_ini_size_error_message_is_french_and_actionable(): void
    {
        $this->expectException(UploadError::class);
        $this->expectExceptionMessage('Fichier trop volumineux : 5 Mo au maximum. Réduisez la photo ou numérisez en qualité moyenne.');
        $this->uploads->store($this->upload('x.pdf', '', UPLOAD_ERR_INI_SIZE), 'document', false, null);
    }

    public function test_file_over_max_bytes_is_refused(): void
    {
        $small = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 10, true);
        $this->expectExceptionMessage('Fichier trop volumineux');
        $small->store($this->upload('x.pdf', $this->pdf()), 'document', false, null);
    }

    public function test_private_file_is_stored_outside_public_dir(): void
    {
        $id = $this->uploads->store($this->upload('a.pdf', $this->pdf()), 'document', false, 3);
        $path = $this->uploads->path($id);
        $this->assertStringStartsWith(realpath($this->root . '/private') . '/', $path);
        $this->assertStringNotContainsString('/public/', $path);
        $this->assertSame('private', $this->db->fetchValue('SELECT disk FROM files WHERE id = ?', [$id]));
        $pub = $this->uploads->store($this->upload('a.pdf', $this->pdf()), 'document', true, null);
        $this->assertStringStartsWith(realpath($this->root . '/public') . '/', $this->uploads->path($pub));
    }

    public function test_image_is_reencoded_without_exif(): void
    {
        $src = $this->jpeg();
        $this->assertStringContainsString('SECRET-GPS', $src);
        $id = $this->uploads->store($this->upload('a.jpg', $src), 'image', false, null);
        $out = (string) file_get_contents($this->uploads->path($id));
        $this->assertStringNotContainsString('SECRET-GPS', $out);
        $this->assertSame([20, 10], array_slice(getimagesizefromstring($out), 0, 2));
    }

    public function test_oversized_image_dimensions_are_refused_before_decoding(): void
    {
        // PNG 1x1 dont l'en-tête IHDR annonce 10000x10000 (100 Mpx).
        $ihdr = pack('NN', 10000, 10000) . "\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->expectExceptionMessage('Image trop grande : réduisez sa taille (40 mégapixels au maximum).');
        $this->uploads->store($this->upload('a.png', $png), 'image', false, null);
    }

    public function test_corrupt_image_is_refused(): void
    {
        $this->expectExceptionMessage('Format non accepté');
        $this->uploads->store($this->upload('a.png', "\x89PNG\r\n\x1a\nbroken"), 'image', false, null);
    }

    public function test_original_name_is_sanitised(): void
    {
        $id = $this->uploads->store($this->upload("../../etc/pa\x00ss\nwd.pdf", $this->pdf()), 'document', false, null);
        $this->assertSame('passwd.pdf', $this->db->fetchValue('SELECT original_name FROM files WHERE id = ?', [$id]));
    }

    public function test_path_refuses_unknown_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->uploads->path(999999);
    }

    public function test_db_failure_deletes_written_file(): void
    {
        $this->db->query('DELETE FROM files');
        $this->db->query('ALTER TABLE files ADD CONSTRAINT chk_tmp CHECK (size < 5)');
        try {
            $this->uploads->store($this->upload('a.pdf', $this->pdf()), 'document', false, null);
            $this->fail('exception attendue');
        } catch (UploadError) {
            $this->fail('pas une UploadError');
        } catch (\Throwable) {
            $this->assertSame([], glob($this->root . '/private/*/*/*') ?: []);
        } finally {
            $this->db->query('ALTER TABLE files DROP CONSTRAINT chk_tmp');
        }
    }
}
