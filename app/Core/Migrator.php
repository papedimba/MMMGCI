<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> versions (noms de fichier) non encore appliquées */
    public function pending(): array
    {
        return array_keys($this->scan());
    }

    /** @return list<string> versions appliquées par cet appel */
    public function migrate(): array
    {
        $applied = $this->scan();
        $done = [];
        foreach ($applied as $version => [$sql, $checksum]) {
            $this->run($version, $sql, $checksum);
            $done[] = $version;
        }
        return $done;
    }

    /** Valide les migrations déjà appliquées et retourne les autres : version => [sql, checksum]. */
    private function scan(): array
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $known = [];
        foreach ($this->db->fetchAll('SELECT version, checksum FROM schema_migrations') as $r) {
            $known[$r['version']] = $r['checksum'];
        }
        $files = glob(rtrim($this->dir, '/') . '/[0-9][0-9][0-9][0-9]_*.sql') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $version = basename($file);
            $sql = (string) file_get_contents($file);
            $checksum = hash('sha256', $sql);
            if (!isset($known[$version])) {
                $pending[$version] = [$sql, $checksum];
            } elseif ($known[$version] !== $checksum) {
                throw new RuntimeException("La migration $version a été modifiée après son application ; créez une nouvelle migration au lieu de la modifier.");
            }
        }
        return $pending;
    }

    private function run(string $version, string $sql, string $checksum): void
    {
        try {
            // shortcut: split naïf sur « ; » en fin de ligne, pas de ; dans des corps de procédure ; DDL non transactionnel.
            foreach (preg_split('/;[ \t]*\R/', $sql . "\n") as $stmt) {
                $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
                if ($stmt !== '' && $stmt !== ';') {
                    $this->db->query($stmt)->closeCursor();
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Échec de la migration $version : " . $e->getMessage(), 0, $e);
        }
        $this->db->insert('schema_migrations', ['version' => $version, 'checksum' => $checksum]);
    }
}
