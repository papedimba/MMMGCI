<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Export\Csv;
use App\Core\Export\Xlsx;

/** Export et impression de la liste des adhérents : colonnes fixes (jamais de pièce, de photo ni de numéro d'identité), périmètre de l'utilisateur. */
final class MemberExport
{
    public const HEADERS = ['N°', 'Nom', 'Prénoms', 'Catégorie', 'Profession', 'Zone', 'Statut', 'Date d\'adhésion', 'Téléphone', 'E-mail'];

    public function __construct(private MemberSearch $search, private Audit $audit)
    {
    }

    /** @param array<string,mixed> $criteria @return \Generator<list<string>> */
    public function rows(int $userId, array $criteria, string $sort): \Generator
    {
        foreach ($this->search->stream($userId, $criteria, $sort) as $m) {
            yield [(string) $m['member_number'], (string) $m['last_name'], (string) $m['first_names'], (string) $m['category_name'], (string) $m['profession_name'],
                (string) $m['zone_name'], (string) $m['status_label'], $m['joined_at'] ? date('d/m/Y', strtotime((string) $m['joined_at'])) : '', (string) $m['phone'], (string) $m['email']];
        }
    }

    /** Génère le fichier (csv|xlsx) et journalise l'export ; l'audit ne contient que le nombre de lignes, le format et les critères (texte libre non conservé). */
    public function file(string $format, int $userId, array $criteria, string $sort, ?string $ip, ?string $ua): string
    {
        $rows = iterator_to_array($this->rows($userId, $criteria, $sort), false);
        $this->audited($format, count($rows), $userId, $criteria, $ip, $ua);
        return $format === 'xlsx' ? Xlsx::build('Adhérents', self::HEADERS, $rows) : Csv::build(self::HEADERS, $rows);
    }

    /** Critères réellement appliqués par MemberSearch (clés connues, valeurs valides) ; le texte libre n'est jamais conservé (q => true).
     * @param array<string,mixed> $criteria @return array<string,mixed> */
    public static function applied(array $criteria): array
    {
        $out = [];
        $q = $criteria['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $out['q'] = true;
        }
        $v = $criteria['status'] ?? null;
        if (is_string($v) && preg_match('/^[a-z_]{1,30}$/', $v)) {
            $out['status'] = $v;
        }
        foreach (['category_id', 'zone_id', 'profession_id'] as $k) {
            $v = $criteria[$k] ?? null;
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $out[$k] = (string) $v;
            }
        }
        foreach (['joined_from', 'joined_to'] as $k) {
            $v = $criteria[$k] ?? null;
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $criteria */
    public function audited(string $format, int $count, int $userId, array $criteria, ?string $ip, ?string $ua): void
    {
        $criteria = self::applied($criteria);
        $this->audit->log($userId, 'member.exported', 'member', null, null, ['rows' => $count, 'format' => $format, 'criteria' => $criteria], null, $ip, $ua);
    }
}
