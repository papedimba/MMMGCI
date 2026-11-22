<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Database;
use App\Core\Uploads;
use GdImage;
use InvalidArgumentException;

/**
 * Variantes responsives des images publiques (GD) : largeurs 480 et 960 px, jamais agrandies ; JPEG pour une source JPEG, WebP sinon
 * (PNG/WebP : la transparence est conservée). Fichiers à côté de l'original ({nom}-480.jpg…), droits 0644 comme le disque public.
 * Créées à l'ajout d'un média (MediaService::add) et à la demande (rendu public) si absentes ; retirées avec le média.
 */
final class MediaVariants
{
    public const WIDTHS = [480, 960];
    private const EXTS = ['jpg', 'webp'];

    public function __construct(private Database $db, private Uploads $uploads)
    {
    }

    /** @return list<array{url:string,width:int,height:int}> variantes d'un média de la médiathèque ([] : inconnu, non image, trop petit) */
    public function generate(int $mediaId): array
    {
        $fileId = $this->db->fetchValue('SELECT file_id FROM cms_media WHERE id = ?', [$mediaId]);
        return $fileId === null || $fileId === false ? [] : $this->forFile((int) $fileId);
    }

    /** @return list<array{url:string,width:int,height:int}> */
    public function forFile(int $fileId): array
    {
        $row = $this->db->fetch("SELECT path, mime FROM files WHERE id = ? AND disk = 'public'", [$fileId]);
        if ($row === null || !in_array($row['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [];
        }
        try {
            $src = $this->uploads->path($fileId);
        } catch (InvalidArgumentException) {
            return [];
        }
        $info = @getimagesize($src);
        $ext = $row['mime'] === 'image/jpeg' ? 'jpg' : 'webp';
        if ($info === false || ($ext === 'webp' && !function_exists('imagewebp'))) {
            return [];
        }
        [$w, $h] = $info;
        $out = [];
        $img = null;
        foreach (self::WIDTHS as $vw) {
            if ($vw >= $w) {
                break;
            }
            $vh = max(1, (int) round($h * $vw / $w));
            $target = self::variant($src, $vw, $ext);
            if (!is_file($target)) {
                $img ??= self::load($src, (string) $row['mime']);
                if ($img === null || !self::write($img, $vw, $vh, $target, $ext)) {
                    break;
                }
            }
            $out[] = ['url' => '/media/' . self::variant((string) $row['path'], $vw, $ext), 'width' => $vw, 'height' => $vh];
        }
        return $out;
    }

    /** Retire les variantes d'un original (chemin absolu) ; sans erreur si elles n'existent pas. */
    public static function remove(string $original): void
    {
        foreach (self::WIDTHS as $w) {
            foreach (self::EXTS as $ext) {
                $f = self::variant($original, $w, $ext);
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }

    private static function variant(string $path, int $width, string $ext): string
    {
        return (string) preg_replace('~\.[A-Za-z0-9]+$~', '', $path) . '-' . $width . '.' . $ext;
    }

    private static function load(string $src, string $mime): ?GdImage
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            default => @imagecreatefromwebp($src),
        };
        return $img instanceof GdImage ? $img : null;
    }

    private static function write(GdImage $img, int $w, int $h, string $target, string $ext): bool
    {
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 255, 255, 255, $ext === 'jpg' ? 0 : 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        $part = dirname($target) . '/.' . bin2hex(random_bytes(8)) . '.part';
        $ok = $ext === 'jpg' ? @imagejpeg($dst, $part, 82) : @imagewebp($dst, $part, 80);
        if (!$ok || !@chmod($part, 0644) || !@rename($part, $target)) {
            @unlink($part);
            error_log('cms.media: variante ' . $w . ' px non créée');
            return false;
        }
        return true;
    }
}
