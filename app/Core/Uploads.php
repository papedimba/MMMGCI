<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use Throwable;

/** Réception contrôlée des fichiers : extension, MIME réel, ré-encodage des images, stockage atomique. */
final class Uploads
{
    public const PROFILES = ['image' => ['jpg', 'jpeg', 'png', 'webp'], 'document' => ['pdf', 'jpg', 'jpeg', 'png']];
    private const MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    private const MAX_PIXELS = 40_000_000;

    public function __construct(private Database $db, private string $privateDir, private string $publicDir,
        private int $maxBytes, private bool $allowNonUploaded = false)
    {
    }

    /** @param array<string,mixed> $phpFile entrée de $_FILES */
    public function store(array $phpFile, string $profile, bool $public, ?int $userId): int
    {
        if (!isset(self::PROFILES[$profile])) {
            throw new InvalidArgumentException("Profil de téléversement inconnu : $profile");
        }
        $tooBig = new UploadError(sprintf('Fichier trop volumineux : %d Mo au maximum. Réduisez la photo ou numérisez en qualité moyenne.', intdiv($this->maxBytes, 1048576)));
        $error = (int) ($phpFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw $tooBig;
        }
        $tmp = (string) ($phpFile['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || (!$this->allowNonUploaded && !is_uploaded_file($tmp))) {
            throw new UploadError('Le téléversement a échoué : réessayez.');
        }
        $size = (int) filesize($tmp);
        if ($size === 0) {
            throw new UploadError('Le fichier est vide : choisissez un autre fichier.');
        }
        if ($size > $this->maxBytes) {
            throw $tooBig;
        }

        $name = (string) ($phpFile['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $badFormat = new UploadError('Format non accepté : ' . ($profile === 'image' ? 'JPG, PNG ou WebP' : 'PDF, JPG ou PNG') . ' uniquement.');
        if (!in_array($ext, self::PROFILES[$profile], true)) {
            throw $badFormat;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime !== self::MIMES[$ext]) {
            throw $badFormat;
        }

        if ($ext === 'pdf') {
            $content = (string) file_get_contents($tmp);
            if (!str_starts_with($content, '%PDF-')) {
                throw $badFormat;
            }
        } else {
            $content = $this->reencode($tmp, $mime, $badFormat);
        }

        $disk = $public ? 'public' : 'private';
        $base = $public ? $this->publicDir : $this->privateDir;
        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $base . '/' . $relative;
        $dir = dirname($target);
        $part = $dir . '/.' . bin2hex(random_bytes(8)) . '.part';
        try {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('mkdir');
            }
            if (file_put_contents($part, $content) !== strlen($content)) {
                throw new \RuntimeException('write');
            }
            chmod($part, 0640);
            if (!rename($part, $target)) {
                throw new \RuntimeException('rename');
            }
        } catch (\ErrorException | \RuntimeException) {
            @unlink($part);
            throw new UploadError('Le fichier n\'a pas pu être enregistré : réessayez dans quelques instants ; si le problème persiste, contactez l\'administrateur.');
        }

        try {
            $row = [
                'disk' => $disk,
                'path' => $relative,
                'original_name' => $this->displayName($name),
                'mime' => $mime,
                'size' => strlen($content),
                'sha256' => hash('sha256', $content),
                'uploaded_by' => $userId,
            ];
            // Rejoint la transaction de l'appelant si elle est ouverte.
            return $this->db->pdo()->inTransaction()
                ? $this->db->insert('files', $row)
                : (int) $this->db->transaction(fn (Database $db) => $db->insert('files', $row));
        } catch (Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    public function path(int $fileId): string
    {
        $row = $this->db->fetch('SELECT disk, path FROM files WHERE id = ?', [$fileId]);
        if ($row === null) {
            throw new InvalidArgumentException("Fichier inconnu : $fileId");
        }
        $base = realpath($row['disk'] === 'public' ? $this->publicDir : $this->privateDir);
        $full = realpath(($row['disk'] === 'public' ? $this->publicDir : $this->privateDir) . '/' . $row['path']);
        if ($base === false || $full === false || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Fichier introuvable : $fileId");
        }
        return $full;
    }

    private function reencode(string $tmp, string $mime, UploadError $badFormat): string
    {
        $info = @getimagesize($tmp);
        if ($info === false) {
            throw $badFormat;
        }
        // Contrôle de l'en-tête avant tout décodage (bombe de décompression).
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new UploadError('Image trop grande : réduisez sa taille (40 mégapixels au maximum).');
        }
        $im = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($im === false) {
            throw $badFormat;
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($im, null, 85),
                'image/png' => imagepng($im),
                default => imagewebp($im, null, 85),
            };
        } finally {
            $out = (string) ob_get_clean();
        }
        if (!$ok || $out === '') {
            throw $badFormat;
        }
        return $out;
    }

    /** Affichage uniquement : jamais utilisé pour construire un chemin. */
    private function displayName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', mb_scrub($name)) ?? '';
        $name = trim(basename(str_replace('\\', '/', $name)));
        return mb_substr($name, 0, 255);
    }
}
