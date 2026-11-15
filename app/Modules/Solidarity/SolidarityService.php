<?php
declare(strict_types=1);

namespace App\Modules\Solidarity;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Export\Csv;
use App\Core\Export\Xlsx;
use App\Core\Money;
use App\Modules\Finance\Ledger;
use DomainException;

/**
 * Fonds de solidarité : lecture seule du grand livre du compte de type « solidarity_fund » actif (au plus un, imposé par AccountService).
 *
 * Traitement des contre-passations (lignes avec reversal_of_id) : elles ne sont jamais des décaissements.
 *  - recettes nettes   = recettes d'origine − contre-passations de recettes (écritures « depense » avec reversal_of_id) ;
 *  - décaissé net      = dépenses d'origine (uniquement source « disbursement ») − contre-passations de décaissements (« recette » avec reversal_of_id) ;
 *  - invariant : solde = ouverture + recettes nettes − décaissé net. Rien ici n'écrit au grand livre.
 */
final class SolidarityService
{
    public const HEADERS = ['Référence', 'Date', 'Sens', 'Catégorie', 'Montant (XOF)'];
    private const RECEIPT = "CASE WHEN t.direction = 'recette' AND t.reversal_of_id IS NULL THEN t.amount WHEN t.direction = 'depense' AND t.reversal_of_id IS NOT NULL THEN -t.amount ELSE 0 END";
    private const DISBURSED = "CASE WHEN t.direction = 'depense' AND t.reversal_of_id IS NULL THEN t.amount WHEN t.direction = 'recette' AND t.reversal_of_id IS NOT NULL THEN -t.amount ELSE 0 END";

    public function __construct(private Database $db, private Audit $audit, private Ledger $ledger)
    {
    }

    /** @return ?array{id: int, name: string, opening_balance: string} le compte actif, ou null */
    public function account(): ?array
    {
        $a = $this->db->fetch("SELECT id, name, opening_balance FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1 ORDER BY id LIMIT 1");
        return $a === null ? null : ['id' => (int) $a['id'], 'name' => (string) $a['name'], 'opening_balance' => (string) $a['opening_balance']];
    }

    /**
     * @return array{account: array, opening: int, balance: int, receipts_total: int, disbursed_total: int, by_month: list<array{month: string, receipts: int, disbursed: int}>}
     */
    public function summary(): array
    {
        $acc = $this->account() ?? throw new DomainException('Aucun compte de fonds de solidarité actif.');
        $tot = $this->db->fetch('SELECT COALESCE(SUM(' . self::RECEIPT . '), 0) AS r, COALESCE(SUM(' . self::DISBURSED . '), 0) AS d FROM financial_transactions t WHERE t.account_id = ?', [$acc['id']]);
        $start = Ledger::now()->modify('first day of this month')->setTime(0, 0)->modify('-11 months'); // Africa/Abidjan = UTC+0 sans heure d'été
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$start->modify("+$i months")->format('Y-m')] = ['receipts' => 0, 'disbursed' => 0];
        }
        $rows = $this->db->fetchAll('SELECT DATE_FORMAT(t.occurred_at, \'%Y-%m\') AS ym, SUM(' . self::RECEIPT . ') AS r, SUM(' . self::DISBURSED . ') AS d
            FROM financial_transactions t WHERE t.account_id = ? AND t.occurred_at >= ? GROUP BY ym', [$acc['id'], $start->format('Y-m-d H:i:s')]);
        foreach ($rows as $row) {
            if (isset($months[$row['ym']])) {
                $months[$row['ym']] = ['receipts' => self::cents((string) $row['r']), 'disbursed' => self::cents((string) $row['d'])];
            }
        }
        $by = [];
        foreach ($months as $m => $v) {
            $by[] = ['month' => $m] + $v;
        }
        return ['account' => $acc, 'opening' => Money::fromDecimal($acc['opening_balance']), 'balance' => $this->ledger->balance($acc['id']),
            'receipts_total' => self::cents((string) $tot['r']), 'disbursed_total' => self::cents((string) $tot['d']), 'by_month' => $by];
    }

    /** Dernières opérations, sans libellé libre (il peut citer un bénéficiaire). @return list<array<string,mixed>> */
    public function latest(int $limit = 20): array
    {
        $acc = $this->account();
        return $acc === null ? [] : $this->db->fetchAll('SELECT t.id, t.reference, t.occurred_at, t.direction, t.amount, t.source_type, t.source_id, t.reversal_of_id, c.name AS category_name
            FROM financial_transactions t JOIN financial_categories c ON c.id = t.category_id WHERE t.account_id = ? ORDER BY t.occurred_at DESC, t.id DESC LIMIT ' . max(1, min(200, $limit)), [$acc['id']]);
    }

    /** Export audité : référence, date, sens, catégorie, montant ; ni libellé ni bénéficiaire. */
    public function file(string $format, int $userId, ?string $ip, ?string $ua): string
    {
        $acc = $this->account() ?? throw new DomainException('Aucun compte de fonds de solidarité actif.');
        $rows = [];
        foreach ($this->db->fetchAll('SELECT t.reference, t.occurred_at, t.direction, t.amount, t.reversal_of_id, c.name AS category_name
            FROM financial_transactions t JOIN financial_categories c ON c.id = t.category_id WHERE t.account_id = ? ORDER BY t.occurred_at DESC, t.id DESC', [$acc['id']]) as $t) {
            $rows[] = [(string) $t['reference'], date('d/m/Y', strtotime((string) $t['occurred_at'])), $t['direction'] === 'recette' ? 'Entrée' : 'Sortie',
                (string) $t['category_name'] . ($t['reversal_of_id'] !== null ? ' (contre-passation)' : ''), str_replace('.', ',', (string) $t['amount'])];
        }
        $this->audit->log($userId, 'solidarity.exported', 'financial_account', $acc['id'], null, ['rows' => count($rows), 'format' => $format], null, $ip, $ua);
        return $format === 'xlsx' ? Xlsx::build('Fonds de solidarité', self::HEADERS, $rows) : Csv::build(self::HEADERS, $rows);
    }

    /** Décimal SQL (éventuellement négatif) -> centimes entiers. */
    private static function cents(string $dec): int
    {
        return $dec !== '' && $dec[0] === '-' ? -Money::fromDecimal(substr($dec, 1)) : Money::fromDecimal($dec);
    }
}
