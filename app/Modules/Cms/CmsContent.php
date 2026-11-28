<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;

/** Socle des types de contenu de la tâche 7 (évènements, documents, galeries, bannières, témoignages) : verrou, texte, adresses, médias, dates. */
abstract class CmsContent
{
    protected const TABLE = '';
    protected const NOUN = ''; // préfixe des actions d'audit : cms.<noun>_created…
    protected const THE = 'Ce contenu';
    protected const TZ = 'Africa/Abidjan';

    public function __construct(protected Database $db, protected Audit $audit, protected Gate $gate)
    {
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->db->fetch('SELECT * FROM ' . static::TABLE . ' WHERE id = ?', [$id]) ?? throw new NotFound('Contenu introuvable.');
    }

    /** @return array<string,mixed> */
    protected function lock(int $id): array
    {
        return $this->db->fetch('SELECT * FROM ' . static::TABLE . ' WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Contenu introuvable.');
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    /** @param array<string,mixed> $data */
    protected static function str(array $data, string $k, bool $multi = false): string
    {
        return Blocks::text(is_scalar($data[$k] ?? null) ? (string) $data[$k] : '', $multi);
    }

    /** Texte simple limité en longueur ; l'erreur est notée dans $errors. @param array<string,mixed> $data @param array<string,string> $errors */
    protected static function limited(array $data, string $k, int $max, string $label, array &$errors, bool $required = false, bool $multi = false): string
    {
        $v = self::str($data, $k, $multi);
        if ($required && $v === '') {
            $errors[$k] = "$label est obligatoire.";
        } elseif (mb_strlen($v) > $max) {
            $errors[$k] = "$label compte $max caractères au maximum (" . mb_strlen($v) . ' saisis).';
        }
        return $v;
    }

    /** Date et heure saisies (AAAA-MM-JJTHH:MM, heure d'Abidjan) -> « Y-m-d H:i:s » ; null si absente ; false si invalide. */
    protected static function dateTime(mixed $raw): string|null|false
    {
        $s = is_scalar($raw) ? trim((string) $raw) : '';
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $s, new DateTimeZone(self::TZ));
        return $d !== false && $d->format('Y-m-d\TH:i') === $s ? $d->format('Y-m-d H:i:s') : false;
    }

    protected static function optionalId(mixed $raw): int|null|false
    {
        $s = is_scalar($raw) ? trim((string) $raw) : '';
        if ($s === '') {
            return null;
        }
        return ctype_digit($s) && strlen($s) < 19 && (int) $s > 0 ? (int) $s : false;
    }

    /**
     * Média de la médiathèque (verrou partagé) -> file_id, ou erreur. $mimes : types acceptés ; $altRequired : texte alternatif non vide exigé.
     * @param list<string> $mimes @param array<string,string> $errors
     */
    protected function mediaFile(mixed $raw, string $key, array $mimes, bool $altRequired, array &$errors): ?int
    {
        $mid = self::optionalId($raw);
        if ($mid === false) {
            $errors[$key] = 'Média invalide.';
            return null;
        }
        if ($mid === null) {
            return null;
        }
        $m = $this->db->fetch('SELECT m.file_id, m.alt, f.mime FROM cms_media m JOIN files f ON f.id = m.file_id WHERE m.id = ? LOCK IN SHARE MODE', [$mid]);
        if ($m === null) {
            $errors[$key] = "Le média n° $mid n'existe pas (supprimé ?) : choisissez-en un autre.";
        } elseif (!in_array($m['mime'], $mimes, true)) {
            $errors[$key] = 'Ce type de fichier n\'est pas accepté ici.';
        } elseif ($altRequired && trim((string) $m['alt']) === '') {
            $errors[$key] = 'Ce média n\'a pas de texte alternatif : renseignez-le dans la médiathèque avant de l\'utiliser.';
        } else {
            return (int) $m['file_id'];
        }
        return null;
    }

    /** id du média de la médiathèque portant ce fichier (pour réafficher le formulaire). */
    public function mediaIdOfFile(?int $fileId): ?int
    {
        $id = $fileId === null ? null : $this->db->fetchValue('SELECT id FROM cms_media WHERE file_id = ? ORDER BY id LIMIT 1', [$fileId]);
        return $id === null ? null : (int) $id;
    }

    /** Médias de la médiathèque (id => titre) dont le type MIME est dans $mimes. @param list<string> $mimes @return array<int,string> */
    public function mediaChoices(array $mimes): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT m.id, m.title, f.mime FROM cms_media m JOIN files f ON f.id = m.file_id ORDER BY m.title, m.id') as $r) {
            if (in_array($r['mime'], $mimes, true)) {
                $out[(int) $r['id']] = $r['title'] . ' (n° ' . $r['id'] . ')';
            }
        }
        return $out;
    }

    /** Adresse : saisie validée, ou proposée à partir du titre ; conservée si vide en modification. @param array<string,string> $errors */
    protected function resolveSlug(string $slug, string $title, ?int $id, array &$errors): string
    {
        $slugs = new Slugs($this->db);
        try {
            $keep = $slug === '' && $id !== null ? $this->db->fetchValue('SELECT slug FROM ' . static::TABLE . ' WHERE id = ?', [$id]) : null;
            if ($keep !== null) {
                return (string) $keep;
            }
            if ($slug !== '') {
                return $slugs->validate($slug, $id, static::TABLE);
            }
            $base = Slugs::fromTitle($title);
            for ($n = 1; $n <= 50; $n++) {
                $candidate = $n === 1 ? $base : rtrim(substr($base, 0, 80 - strlen("-$n")), '-') . "-$n";
                try {
                    return $slugs->validate($candidate, $id, static::TABLE);
                } catch (DomainException) {
                }
            }
            throw new DomainException('Aucune adresse libre : saisissez-en une.');
        } catch (DomainException $e) {
            $errors['slug'] = $e->getMessage();
            return '';
        }
    }

    protected function guardSlugRace(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new PageInvalid(['slug' => 'Cette adresse vient d\'être prise : choisissez-en une autre.']);
            }
            throw $e;
        }
    }

    /** Valeur d'un champ datetime-local à partir de la base. */
    public static function inputDate(?string $v): string
    {
        return $v === null ? '' : str_replace(' ', 'T', substr($v, 0, 16));
    }
}
