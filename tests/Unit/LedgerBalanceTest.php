<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit;
use App\Core\Money;
use App\Core\Sequence;
use App\Modules\Finance\Ledger;
use DateTimeImmutable;
use DomainException;
use Tests\DatabaseTestCase;

final class LedgerBalanceTest extends DatabaseTestCase
{
    private Ledger $ledger;
    private int $account;
    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new Ledger($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))), new Sequence($this->db));
        $this->account = $this->db->insert('financial_accounts', ['name' => 'Cpt ' . bin2hex(random_bytes(4)), 'opening_balance' => '1000.50']);
        $this->category = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE name = 'Dons, legs et subventions'");
    }

    private function receipt(int $cents, string $date = '2026-03-10 10:00:00'): int
    {
        return $this->ledger->record($this->account, $this->category, 'recette', $cents, new DateTimeImmutable($date), [], 1);
    }

    private function expense(int $cents, string $date = '2026-03-11 10:00:00'): int
    {
        $cat = $this->db->insert('financial_categories', ['name' => 'Dep ' . bin2hex(random_bytes(4)), 'direction' => 'depense']);
        $this->db->insert('financial_transactions', ['reference' => 'TX-T-' . bin2hex(random_bytes(4)), 'account_id' => $this->account, 'category_id' => $cat,
            'direction' => 'depense', 'amount' => Money::toDecimal($cents), 'occurred_at' => $date]); // les dépenses n'ont pas d'entrée publique : insertion directe de test
        return $cat;
    }

    public function test_balance_is_opening_plus_receipts_minus_expenses(): void
    {
        $this->assertSame(100050, $this->ledger->balance($this->account));
        $this->receipt(1250050); // 12 500,50
        $this->expense(30000);
        $this->assertSame(100050 + 1250050 - 30000, $this->ledger->balance($this->account));
        $this->assertSame(100050 + 1250050, $this->ledger->balance($this->account, new DateTimeImmutable('2026-03-10 00:00:00')), 'jour inclus, dépense du lendemain exclue');
        $this->assertSame(100050, $this->ledger->balance($this->account, new DateTimeImmutable('2026-03-09')));
    }

    public function test_balance_can_be_negative(): void
    {
        $this->expense(500000);
        $this->assertSame(100050 - 500000, $this->ledger->balance($this->account));
    }

    public function test_amount_zero_or_negative_is_refused(): void
    {
        foreach ([0, -100] as $bad) {
            try {
                $this->receipt($bad);
                $this->fail('Montant refusé attendu');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$this->account]));
    }

    public function test_money_never_uses_float(): void
    {
        $this->receipt(1); // 0,01 : un flottant donnerait 1000.5 + 0.01 imprécis sur de gros cumuls
        for ($i = 0; $i < 10; $i++) {
            $this->receipt(10);
        }
        $this->assertSame(100050 + 1 + 100, $this->ledger->balance($this->account));
        $this->assertIsInt($this->ledger->balance($this->account));
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Finance/Ledger.php');
        $this->assertDoesNotMatchRegularExpression('/\(float\)|floatval|\bfloat\b|round\(/', $src);
    }

    public function test_reversal_restores_balance_and_cannot_be_reversed_twice(): void
    {
        $id = $this->receipt(250000);
        $before = 100050 + 250000;
        $this->assertSame($before, $this->ledger->balance($this->account));
        $rev = $this->ledger->reverse($id, 'Saisie en double', 1);
        $this->assertSame(100050, $this->ledger->balance($this->account));
        $row = $this->db->fetch('SELECT * FROM financial_transactions WHERE id = ?', [$rev]);
        $this->assertSame('depense', $row['direction']);
        $this->assertSame('2500.00', $row['amount']);
        $this->assertSame($id, (int) $row['reversal_of_id']);
        foreach ([$id, $rev] as $target) { // déjà contre-passée / elle-même une contre-passation
            try {
                $this->ledger->reverse($target, 'Encore', 1);
                $this->fail('Refus attendu');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(100050, $this->ledger->balance($this->account));
    }

    public function test_reversal_requires_a_reason_and_database_backstops_double_reversal(): void
    {
        $id = $this->receipt(1000);
        try {
            $this->ledger->reverse($id, '   ', 1);
            $this->fail('Motif obligatoire');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->ledger->reverse($id, 'ok', 1);
        $this->expectException(\PDOException::class); // filet UNIQUE(reversal_of_id)
        $this->db->insert('financial_transactions', ['reference' => 'TX-DUP-1', 'account_id' => $this->account, 'category_id' => $this->category, 'direction' => 'depense',
            'amount' => '10.00', 'occurred_at' => '2026-03-10 10:00:00', 'reversal_of_id' => $id]);
    }

    public function test_record_joins_an_open_transaction(): void
    {
        $this->db->transaction(function (): void {
            $this->receipt(5000);
            $this->receipt(5000);
        });
        $this->assertSame(100050 + 10000, $this->ledger->balance($this->account));
        $this->assertMatchesRegularExpression('/^TX-\d{4}-\d{6}$/', (string) $this->db->fetchValue('SELECT reference FROM financial_transactions WHERE account_id = ? LIMIT 1', [$this->account]));
    }
}
