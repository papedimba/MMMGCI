<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use App\Core\NotFound;
use App\Core\Sequence;
use App\Core\Settings;
use App\Core\Validator;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Fiche adhérent : création (numéro attribué), modification, détection de doublons ; chaque écriture auditée dans sa transaction. */
final class MemberService
{
    public const DEFAULT_FORMAT = 'MMMGCI-{YYYY}-{NNNNN}';
    private const TEXT = ['sex' => 10, 'birth_place' => 120, 'nationality' => 80, 'address' => 255, 'id_document_number' => 60,
        'employment_status' => 60, 'employer' => 160, 'seaman_book_number' => 60];
    private const REFS = ['zone_id' => 'zones', 'category_id' => 'member_categories', 'profession_id' => 'maritime_professions', 'specialty_id' => 'maritime_specialties'];

    public function __construct(private Database $db, private Audit $audit, private Settings $settings, private Sequence $sequence)
    {
    }

    /**
     * Ne bloque pas un doublon : le contrôleur exige confirm_duplicate=1 après findDuplicates().
     * @param array<string,mixed> $data
     */
    public function create(array $data, int $actor): int
    {
        $fields = $this->validate($data, true);
        return $this->db->atomic(function () use ($fields, $actor): int {
            // Séquence et insertion dans la même transaction : pas de numéro perdu ni dupliqué en concurrence.
            $fields['member_number'] = $this->nextNumber();
            $id = $this->db->insert('members', $fields);
            $this->audit->log($actor, 'member.created', 'member', $id, null, $fields);
            return $id;
        });
    }

    /** Formulaire complet : une clé absente vaut vide. Le numéro et le statut ne changent jamais ici (le statut relève de members.status). @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actor): void
    {
        $fields = $this->validate($data, false);
        $this->db->atomic(function () use ($id, $fields, $actor): void {
            $before = $this->db->fetch('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]) ?? throw new NotFound("Adhérent $id introuvable.");
            $changed = array_filter($fields, static fn ($v, $k): bool => ($before[$k] === null ? null : (string) $before[$k]) !== ($v === null ? null : (string) $v), ARRAY_FILTER_USE_BOTH);
            if ($changed === []) {
                return;
            }
            $this->db->update('members', $changed, ['id' => $id]);
            $this->audit->log($actor, 'member.updated', 'member', $id, array_intersect_key($before, $changed), $changed);
        });
    }

    /**
     * Même nom et prénoms (casse, accents et espaces ignorés) ET même date de naissance, ou même téléphone normalisé.
     * La collation utf8mb4_unicode_ci ignore déjà casse et accents ; les espaces sont réduits à l'écriture et ici.
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public function findDuplicates(array $data, ?int $exceptId = null): array
    {
        $where = [];
        $params = [];
        $last = self::squash($data['last_name'] ?? null);
        $first = self::squash($data['first_names'] ?? null);
        $birth = self::isoDate($data['birth_date'] ?? null);
        if ($last !== null && $first !== null && $birth !== null) {
            $where[] = '(last_name = ? AND first_names = ? AND birth_date = ?)';
            array_push($params, $last, $first, $birth);
        }
        $phone = self::normalizePhone($data['phone'] ?? null);
        if ($phone !== null) {
            $where[] = 'phone = ?';
            $params[] = $phone;
        }
        if ($where === []) {
            return [];
        }
        $sql = 'SELECT id, member_number, last_name, first_names, birth_date, phone, zone_id FROM members WHERE deleted_at IS NULL AND (' . implode(' OR ', $where) . ')';
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->fetchAll($sql . ' ORDER BY id', $params);
    }

    /** Chiffres avec « + » initial facultatif ; +225 / 00225 suivi de 10 chiffres ramené aux 10 chiffres (comme les comptes). */
    public static function normalizePhone(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        $plus = str_starts_with($s, '+') || str_starts_with($s, '00');
        $digits = preg_replace('/\D/', '', $s) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($s, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^225(\d{10})$/', $digits, $m) && $plus) {
            return $m[1];
        }
        return ($plus ? '+' : '') . $digits;
    }

    /** Espaces réduits ; null si vide. */
    private static function squash(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return $t === '' ? null : $t;
    }

    private static function isoDate(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($v), $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return preg_match('#^\d{4}-\d{2}-\d{2}$#', trim($v)) ? trim($v) : null;
    }

    private function nextNumber(): string
    {
        $format = $this->settings->get('members', 'number_format');
        if (!is_string($format) || !self::validFormat($format)) {
            $format = self::DEFAULT_FORMAT;
        }
        $year = (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->format('Y');
        $perYear = str_contains($format, '{YYYY}'); // sans {YYYY}, un seul compteur continu (sinon doublon dès le 1er janvier)
        $formatted = $this->sequence->next('member', 'M', 5, $perYear ? $year : 0);
        $n = substr($formatted, (int) strrpos($formatted, '-') + 1);
        return str_replace(['{YYYY}', '{NNNNN}'], [(string) $year, $n], $format);
    }

    private static function validFormat(string $f): bool
    {
        return substr_count($f, '{NNNNN}') === 1 && substr_count($f, '{YYYY}') <= 1
            && !str_contains(str_replace(['{YYYY}', '{NNNNN}'], '', $f), '{')
            && mb_strlen(str_replace(['{YYYY}', '{NNNNN}'], ['2026', '00000'], $f)) <= 24;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data, bool $creating): array
    {
        foreach (['last_name', 'first_names'] as $k) {
            if (is_string($data[$k] ?? null)) {
                $data[$k] = self::squash($data[$k]);
            }
        }
        if (is_string($data['email'] ?? null)) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }
        $rules = ['last_name' => 'required|string|max:100', 'first_names' => 'required|string|max:100', 'birth_date' => 'nullable|date|before_today',
            'email' => 'nullable|email|max:190', 'joined_at' => 'nullable|date', 'seaman_book_expiry' => 'nullable|date',
            'id_document_type' => 'nullable|string|max:30', 'status' => 'nullable|string|max:30'];
        $labels = ['last_name' => 'nom', 'first_names' => 'prénoms', 'birth_date' => 'date de naissance', 'email' => 'adresse e-mail', 'joined_at' => "date d'adhésion",
            'seaman_book_expiry' => "expiration du livret maritime", 'phone' => 'téléphone', 'phone_alt' => 'téléphone secondaire'];
        foreach (self::TEXT as $k => $max) {
            $rules[$k] = "nullable|string|max:$max";
        }
        foreach (self::REFS as $k => $_) {
            $rules[$k] = 'nullable|integer';
        }
        $v = Validator::make($data, $rules, $labels);
        $errors = $v->errors();
        $out = $v->validated();
        foreach (['phone', 'phone_alt'] as $k) {
            $raw = $data[$k] ?? null;
            $out[$k] = self::normalizePhone($raw);
            if ($raw !== null && trim((string) $raw) !== '' && ($out[$k] === null || !preg_match('/^\+?\d{6,15}$/', $out[$k]))) {
                $errors[$k] = 'Téléphone invalide : saisissez des chiffres, avec « + » en tête pour un numéro international.';
            }
        }
        if (!isset($errors['birth_date']) && is_string($out['birth_date'] ?? null) && $out['birth_date'] < '1900-01-01') {
            $errors['birth_date'] = 'date de naissance : la date doit être postérieure au 31/12/1899.';
        }
        foreach (self::REFS as $k => $table) {
            if (!isset($errors[$k]) && ($out[$k] ?? null) !== null && $this->db->fetchValue("SELECT 1 FROM $table WHERE id = ?", [(int) $out[$k]]) === null) {
                $errors[$k] = 'Valeur invalide pour « ' . $k . ' » : choisissez-la dans la liste proposée.';
            }
        }
        $status = $creating ? ($out['status'] ?? 'actif') : null;
        if ($creating && !isset($errors['status']) && $this->db->fetchValue('SELECT 1 FROM member_statuses WHERE code = ?', [$status]) === null) {
            $errors['status'] = 'Statut invalide : choisissez-le dans la liste proposée.';
        }
        if (!isset($errors['id_document_type']) && ($out['id_document_type'] ?? null) !== null
            && $this->db->fetchValue('SELECT 1 FROM member_document_types WHERE code = ?', [$out['id_document_type']]) === null) {
            $errors['id_document_type'] = 'Type de pièce invalide : choisissez-le dans la liste proposée.';
        }
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }
        unset($out['status']);
        foreach (self::REFS as $k => $_) {
            $out[$k] = ($out[$k] ?? null) === null ? null : (int) $out[$k];
        }
        return $out + ($creating ? ['status' => $status] : []);
    }
}
