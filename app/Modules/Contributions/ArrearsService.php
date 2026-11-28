<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Export\Csv;
use App\Core\Export\Xlsx;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;

/**
 * Impayés : appels appelee|partielle dont l'échéance est passée (Africa/Abidjan), reste dû = montant - paiements non annulés.
 * La règle « membre à jour » n'est pas définie par le cahier des charges : tant que contributions.up_to_date_rule n'est pas validé,
 * on ne fournit que des nombres et des montants, jamais de notion « à jour ».
 */
final class ArrearsService
{
    public const PER_PAGE = 50;
    public const HEADERS = ['N° membre', 'Nom', 'Prénoms', 'Type', 'Période', 'Échéance', 'Jours de retard', 'Reste dû (XOF)', 'Zone'];
    private const CHUNK = 2000;
    private const REST = 'c.amount_due - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.contribution_call_id = c.id AND p.cancelled_at IS NULL), 0)';

    public function __construct(private Database $db, private MemberScope $scope, private Settings $settings, private Audit $audit)
    {
    }

    /** @return array{state: string, max: ?int} state : pending (non validée), invalid (valeur inexploitable), ok */
    public function rule(): array
    {
        try {
            if (!$this->settings->isValidated('contributions', 'up_to_date_rule')) {
                return ['state' => 'pending', 'max' => null];
            }
            $v = $this->settings->get('contributions', 'up_to_date_rule');
        } catch (\InvalidArgumentException | \JsonException) {
            return ['state' => 'pending', 'max' => null];
        }
        return is_int($v) && $v >= 0 ? ['state' => 'ok', 'max' => $v] : ['state' => 'invalid', 'max' => null];
    }

    /** @return array{unpaid_calls: int, amount_due: int, up_to_date?: bool} montant en centimes (entier) ; up_to_date seulement si la règle est validée */
    public function memberSituation(int $memberId): array
    {
        $today = Ledger::now()->format('Y-m-d');
        $row = $this->db->fetch('SELECT COUNT(*) AS n, COALESCE(SUM(' . self::REST . '), 0) AS due FROM contribution_calls c
            WHERE c.member_id = ? AND c.status IN (\'appelee\', \'partielle\') AND c.due_date < ?', [$memberId, $today]);
        $out = ['unpaid_calls' => (int) $row['n'], 'amount_due' => Money::fromDecimal((string) $row['due'])];
        $rule = $this->rule();
        if ($rule['state'] === 'ok') {
            $out['up_to_date'] = $out['unpaid_calls'] <= $rule['max'];
        }
        return $out;
    }

    /** @param array<string,mixed> $criteria @return array{rows: list<array<string,mixed>>, total: int, sum: int, pages: int, page: int} */
    public function page(int $userId, array $criteria, int $page): array
    {
        [$cond, $params] = $this->build($userId, $criteria);
        $from = 'FROM contribution_calls c JOIN members m ON m.id = c.member_id JOIN contribution_types t ON t.id = c.contribution_type_id LEFT JOIN zones z ON z.id = m.zone_id WHERE ' . $cond;
        $tot = $this->db->fetch('SELECT COUNT(*) AS n, COALESCE(SUM(' . self::REST . '), 0) AS due ' . $from, $params);
        $total = (int) $tot['n'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->fetchAll('SELECT m.id AS member_id, m.member_number, m.last_name, m.first_names, t.name AS type_name, c.period_label, c.due_date,
            DATEDIFF(?, c.due_date) AS days_late, ' . self::REST . ' AS rest ' . $from . ' ORDER BY c.due_date, m.last_name, m.first_names, c.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            [Ledger::now()->format('Y-m-d'), ...$params]);
        return ['rows' => $rows, 'total' => $total, 'sum' => Money::fromDecimal((string) $tot['due']), 'pages' => $pages, 'page' => $page];
    }

    /** Tous les impayés du périmètre, lus par lots (mémoire bornée).
     * @param array<string,mixed> $criteria @return \Generator<array<string,mixed>> */
    public function unpaid(int $userId, array $criteria): \Generator
    {
        [$cond, $params] = $this->build($userId, $criteria);
        $today = Ledger::now()->format('Y-m-d');
        for ($offset = 0;; $offset += self::CHUNK) {
            $rows = $this->db->fetchAll('SELECT m.member_number, m.last_name, m.first_names, t.name AS type_name, c.period_label, c.due_date, DATEDIFF(?, c.due_date) AS days_late,
                ' . self::REST . ' AS rest, z.name AS zone_name
                FROM contribution_calls c JOIN members m ON m.id = c.member_id JOIN contribution_types t ON t.id = c.contribution_type_id LEFT JOIN zones z ON z.id = m.zone_id
                WHERE ' . $cond . ' ORDER BY c.due_date, m.last_name, m.first_names, c.id LIMIT ' . self::CHUNK . " OFFSET $offset", [$today, ...$params]);
            yield from $rows;
            if (count($rows) < self::CHUNK) {
                return;
            }
        }
    }

    /** Fichier csv|xlsx ; journal : nombre de lignes, format et critères appliqués (le texte libre n'est pas conservé), aucune donnée personnelle. */
    public function file(string $format, int $userId, array $criteria, ?string $ip, ?string $ua): string
    {
        $rows = [];
        foreach ($this->unpaid($userId, $criteria) as $r) {
            $rows[] = [(string) $r['member_number'], (string) $r['last_name'], (string) $r['first_names'], (string) $r['type_name'], (string) $r['period_label'],
                date('d/m/Y', strtotime((string) $r['due_date'])), (string) $r['days_late'], str_replace('.', ',', Money::toDecimal(Money::fromDecimal((string) $r['rest']))), (string) $r['zone_name']];
        }
        $this->audit->log($userId, 'contributions.arrears_exported', 'contribution_call', null, null, ['rows' => count($rows), 'format' => $format, 'criteria' => self::applied($criteria)], null, $ip, $ua);
        return $format === 'xlsx' ? Xlsx::build('Impayés', self::HEADERS, $rows) : Csv::build(self::HEADERS, $rows);
    }

    /** Critères réellement appliqués (valeurs valides seulement) ; q => true.
     * @param array<string,mixed> $criteria @return array<string,mixed> */
    public static function applied(array $criteria): array
    {
        $out = [];
        if (is_string($criteria['q'] ?? null) && trim($criteria['q']) !== '') {
            $out['q'] = true;
        }
        foreach (['zone_id', 'type_id', 'min_age_days'] as $k) {
            $v = $criteria[$k] ?? null;
            if (is_int($v) ? $v >= 0 : (is_string($v) && preg_match('/^\d{1,9}$/', $v))) {
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function build(int $userId, array $criteria): array
    {
        [$scopeSql, $params] = $this->scope->where($userId);
        $today = Ledger::now();
        $applied = self::applied($criteria);
        $min = max(1, $applied['min_age_days'] ?? 1); // « échu » = échéance strictement avant aujourd'hui
        $where = [$scopeSql, "c.status IN ('appelee', 'partielle')", 'c.due_date <= ?'];
        $params[] = $today->modify('-' . min($min, 36500) . ' days')->format('Y-m-d');
        foreach (['zone_id' => 'm.zone_id', 'type_id' => 'c.contribution_type_id'] as $k => $col) {
            if (isset($applied[$k])) {
                $where[] = "$col = ?";
                $params[] = $applied[$k];
            }
        }
        if (isset($applied['q'])) {
            $q = trim($criteria['q']);
            $like = static fn (string $s): string => addcslashes($s, '\\%_') . '%';
            $names = [];
            $or = ['m.member_number LIKE ?'];
            $params[] = $like($q);
            foreach (preg_split('/\s+/u', $q) ?: [] as $word) {
                $names[] = '(m.last_name LIKE ? OR m.first_names LIKE ? OR m.first_names LIKE ?)';
                array_push($params, $like($word), $like($word), '% ' . $like($word));
            }
            $or[] = '(' . implode(' AND ', $names) . ')';
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode(' AND ', $where), $params];
    }
}
