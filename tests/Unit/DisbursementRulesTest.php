<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Disbursements\ApprovalRules;
use App\Modules\Disbursements\DisbursementGate;
use App\Modules\Disbursements\DisbursementTransitionInvalid;
use Tests\DatabaseTestCase;
use Tests\Support\DisbursementFixtures;

final class DisbursementRulesTest extends DatabaseTestCase
{
    use DisbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCircuit();
    }

    /** Ligne forcée à 1 malgré le CHECK (désactivé le temps de l'insertion) : le service exige quand même deux approbateurs distincts. */
    public function test_two_distinct_approvers_are_required_even_if_rule_row_says_one(): void
    {
        $this->db->query('DELETE FROM approval_rules');
        $this->db->query('SET SESSION check_constraint_checks = 0');
        $this->db->query('INSERT INTO approval_rules (min_amount, max_amount, approvals_required) VALUES (0, NULL, 1)');
        $this->db->query('SET SESSION check_constraint_checks = 1');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT approvals_required FROM approval_rules'));
        $this->assertSame(2, (new ApprovalRules($this->db))->requiredFor(5_000_000));
        $id = $this->submitted();
        $this->svc()->approve($id, $this->approverA, null);
        $this->assertSame('approuve_1', $this->state($id));
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class);
        $this->svc()->approve($id, $this->approverB, null);
        $this->assertSame('approuve_2', $this->state($id));
        // Et le CHECK de la base refuse la ligne en temps normal.
        $this->expectException(\PDOException::class);
        $this->db->query('INSERT INTO approval_rules (min_amount, max_amount, approvals_required) VALUES (0, NULL, 1)');
    }

    public function test_tranche_overlap_uses_highest_requirement(): void
    {
        $this->db->query('DELETE FROM approval_rules');
        // Ordre d'insertion volontairement mélangé : le résultat ne dépend pas de l'ordre des lignes.
        foreach ([['2000', '10000', 4], ['0', null, 2], ['1000', '5000', 3]] as [$min, $max, $n]) {
            $this->db->insert('approval_rules', ['min_amount' => $min, 'max_amount' => $max, 'approvals_required' => $n]);
        }
        $rules = new ApprovalRules($this->db);
        $this->assertSame(2, $rules->requiredFor(50_000));      // 500
        $this->assertSame(3, $rules->requiredFor(100_000));     // 1 000 : borne incluse
        $this->assertSame(3, $rules->requiredFor(150_000));     // 1 500
        $this->assertSame(4, $rules->requiredFor(300_000));     // 3 000 : chevauchement 3 / 4 -> 4
        $this->assertSame(4, $rules->requiredFor(1_000_000));   // 10 000 : borne incluse
        $this->assertSame(2, $rules->requiredFor(2_000_000));   // 20 000 : seule la tranche ouverte
        $this->db->query('DELETE FROM approval_rules');
        $this->assertSame(2, $rules->requiredFor(100), 'aucune tranche : défaut technique 2');
    }

    public function test_higher_tranche_keeps_intermediate_state_until_reached(): void
    {
        $this->db->insert('approval_rules', ['min_amount' => '10000', 'max_amount' => null, 'approvals_required' => 3]);
        $third = $this->user('appC', ['dec_appr']);
        $id = $this->submitted(2_000_000); // 20 000 >= 10 000 : 3 approbations
        $this->svc()->approve($id, $this->approverA, null);
        $this->svc()->approve($id, $this->approverB, null);
        $this->assertSame('approuve_1', $this->state($id));
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class);
        $this->svc()->approve($id, $third, null);
        $this->assertSame('approuve_2', $this->state($id));
    }

    public function test_operational_counts_distinct_active_approvers_through_roles(): void
    {
        $gate = new DisbursementGate($this->db);
        $this->assertTrue($gate->isOperational());
        $this->db->query('DELETE FROM user_roles WHERE user_id = ?', [$this->approverB]);
        $this->assertFalse($gate->isOperational());
        $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = \'dec_init_appr\'', [$this->approverA]);
        $this->assertFalse($gate->isOperational(), 'un approbateur détenant la permission par deux rôles compte une fois');
        $this->user('inactif', ['dec_appr'], active: false);
        $this->assertFalse($gate->isOperational(), 'un utilisateur inactif ne compte pas');
        $this->user('appC', ['dec_init_appr']);
        $this->assertTrue($gate->isOperational());
    }
}
