<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class FinanceSchemaTest extends DatabaseTestCase
{
    private function expectSqlError(string $sql, array $params = []): void
    {
        try {
            $this->db->query($sql, $params);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Contrainte attendue : ' . $sql);
    }

    private function fixtures(): array
    {
        $n = bin2hex(random_bytes(4));
        $this->db->query('INSERT INTO members (member_number, last_name, first_names) VALUES (?, ?, ?)', ["T-$n", 'Test', 'Fin']);
        $m = (int) $this->db->pdo()->lastInsertId();
        $this->db->query('INSERT INTO financial_accounts (name) VALUES (?)', ["Cpt $n"]);
        $a = (int) $this->db->pdo()->lastInsertId();
        $c = (int) $this->db->fetchValue("SELECT id FROM financial_categories WHERE direction = 'recette' LIMIT 1");
        $this->db->query('INSERT INTO financial_categories (name, direction) VALUES (?, ?)', ["Dep $n", 'depense']);
        $d = (int) $this->db->pdo()->lastInsertId();
        $this->db->query("INSERT INTO files (path, original_name, mime, size, sha256) VALUES ('p', 'p', 'text/plain', 1, ?)", [str_repeat('a', 64)]);
        $f = (int) $this->db->pdo()->lastInsertId();
        return [$m, $a, $c, $d, $f, $n];
    }

    public function test_seeds_are_present_and_nothing_is_invented(): void
    {
        $names = array_column($this->db->fetchAll("SELECT name FROM financial_categories WHERE direction = 'recette' ORDER BY name"), 'name');
        $this->assertSame(['Contributions des partenaires', 'Contributions exceptionnelles', 'Cotisations périodiques', 'Dons, legs et subventions', 'Droits d\'adhésion', 'Produits d\'activités autorisées'], $names);
    }

    public function test_default_rule_and_settings(): void
    {
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_categories WHERE direction = 'depense'"));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_accounts') + (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_types'));
        $r = $this->db->fetchAll('SELECT * FROM approval_rules');
        $this->assertCount(1, $r);
        $this->assertSame([0.0, null, 2, null, 'disbursement.approve'], [(float) $r[0]['min_amount'], $r[0]['max_amount'], (int) $r[0]['approvals_required'], $r[0]['decision_ref'], $r[0]['eligible_permission']]);
        $this->assertSame(['contributions.up_to_date_rule', 'finance.fiscal_year_start', 'finance.insufficient_balance_policy', 'finance.payer_may_be_initiator'],
            array_column($this->db->fetchAll("SELECT CONCAT(`group`, '.', `key`) AS k FROM settings WHERE status = 'non_configure' AND (`group` = 'finance' OR `key` = 'up_to_date_rule') ORDER BY k"), 'k'));
    }

    public function test_finance_manage_goes_to_holders_of_contributions_or_solidarity_manage(): void
    {
        $q = static fn (string $p) => "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE p.name = '$p' ORDER BY r.name";
        $this->assertSame(['super_admin', 'tresorier_general'], array_column($this->db->fetchAll($q('finance.manage')), 'name'));
    }

    public function test_check_constraints_reject_invalid_values(): void
    {
        [$m, $a, $c, $d, $f] = $this->fixtures();
        $tx = 'INSERT INTO financial_transactions (reference, account_id, category_id, direction, amount, occurred_at) VALUES (?, ?, ?, ?, ?, NOW())';
        $this->expectSqlError($tx, ['X1', $a, $c, 'recette', '0']);
        $this->expectSqlError($tx, ['X2', $a, $c, 'recette', '-5']);
        $this->expectSqlError($tx, ['X3', $a, $c, 'autre', '5']);
        $this->expectSqlError('INSERT INTO approval_rules (approvals_required) VALUES (1)');
        $this->expectSqlError('INSERT INTO disbursement_requests (reference, account_id, category_id, amount, beneficiary_name, purpose, initiated_by) VALUES (?, ?, ?, 0, ?, ?, 1)', ['D0', $a, $d, 'B', 'P']);
        $this->expectSqlError("INSERT INTO disbursement_requests (reference, account_id, category_id, amount, beneficiary_name, purpose, initiated_by, status) VALUES (?, ?, ?, 10, ?, ?, 1, 'inconnu')", ['D0', $a, $d, 'B', 'P']);
        $this->expectSqlError("INSERT INTO financial_accounts (name, type) VALUES ('Z', 'coffre')");
    }

    public function test_unique_constraints_block_double_payment_receipt_and_approval_and_reversal(): void
    {
        [$m, $a, $c, $d, $f, $n] = $this->fixtures();
        $tx = 'INSERT INTO financial_transactions (reference, account_id, category_id, direction, amount, occurred_at, reversal_of_id) VALUES (?, ?, ?, ?, 10, NOW(), ?)';
        $this->db->query($tx, ["T1$n", $a, $c, 'recette', null]);
        $t = (int) $this->db->pdo()->lastInsertId();
        $this->db->query($tx, ["T2$n", $a, $c, 'depense', $t]);
        $this->expectSqlError($tx, ["T3$n", $a, $c, 'depense', $t]);

        $pay = 'INSERT INTO payments (reference, member_id, amount, method, paid_at, idempotency_key) VALUES (?, ?, 10, ?, NOW(), ?)';
        $this->db->query($pay, ["P1$n", $m, 'especes', "k$n"]);
        $p = (int) $this->db->pdo()->lastInsertId();
        $this->expectSqlError($pay, ["P2$n", $m, 'especes', "k$n"]);
        $this->expectSqlError($pay, ["P3$n", $m, 'troc', "k2$n"]);
        $rc = "INSERT INTO receipts (payment_id, receipt_number, issued_at) VALUES (?, ?, NOW())";
        $this->db->query($rc, [$p, "R1$n"]);
        $this->expectSqlError($rc, [$p, "R2$n"]);

        $this->db->query('INSERT INTO disbursement_requests (reference, account_id, category_id, amount, beneficiary_name, purpose, initiated_by) VALUES (?, ?, ?, 10, ?, ?, 1)', ["D1$n", $a, $d, 'B', 'P']);
        $dr = (int) $this->db->pdo()->lastInsertId();
        $ap = "INSERT INTO disbursement_approvals (disbursement_request_id, revision, approver_id, decision) VALUES (?, ?, 5, 'approve')";
        $this->db->query($ap, [$dr, 1]);
        $this->expectSqlError($ap, [$dr, 1]);
        $this->db->query($ap, [$dr, 2]);
        $dp = 'INSERT INTO disbursement_payments (disbursement_request_id, paid_at, method, paid_by, proof_file_id) VALUES (?, NOW(), ?, 1, ?)';
        $this->db->query($dp, [$dr, 'virement', $f]);
        $this->expectSqlError($dp, [$dr, 'virement', $f]);
    }
}
