<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Modules\Disbursements\DisbursementRuleViolation;
use PDOException;
use Tests\DatabaseTestCase;
use Tests\Support\DisbursementFixtures;

/**
 * Courses réelles : un processus fils (seconde connexion PDO, vrai parallélisme) lance la même action pendant que le test tient
 * la demande dans une transaction non validée ; on attend de voir le fils bloqué sur le verrou FOR UPDATE (PROCESSLIST), on valide,
 * puis on lit ce que le fils a obtenu. Complété par la technique du verrou tenu (lock wait timeout) et par un entrelacement
 * déterministe sur deux connexions (vue cohérente périmée).
 */
final class DisbursementConcurrencyTest extends DatabaseTestCase
{
    use DisbursementFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCircuit();
    }

    /** Exécute $hold dans une transaction du test, lance le fils pendant qu'elle tient les verrous, valide, et renvoie la sortie du fils. @param list<string> $args */
    private function race(callable $hold, array $args): string
    {
        [$proc, $pipes] = $this->db->transaction(function () use ($hold, $args): array {
            $hold();
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/disbursement_worker.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $observer = Database::connect(self::config(), test: true);
            for ($i = 0; $i < 400 && (int) $observer->fetchValue("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE 'SELECT * FROM disbursement_requests WHERE id = ? FOR UPDATE%'") === 0; $i++) {
                usleep(25_000);
            }
            $this->assertLessThan(400, $i, 'Le processus fils aurait dû attendre le verrou de la demande.');
            return [$proc, $pipes];
        });
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function approvals(int $id, bool $validOnly = false): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?' . ($validOnly ? ' AND invalidated_at IS NULL' : ''), [$id]);
    }

    public function test_two_simultaneous_approvals_by_same_user_count_once(): void
    {
        $id = $this->submitted();
        $out = $this->race(fn () => $this->svc()->approve($id, $this->approverA, 'premier clic'), ['approve', (string) $id, (string) $this->approverA]);
        $this->assertStringStartsWith('DisbursementRuleViolation: Vous vous êtes déjà prononcé', $out);
        $this->assertSame(1, $this->approvals($id));
        $this->assertSame('approuve_1', $this->state($id));
    }

    public function test_payment_race_creates_one_payment(): void
    {
        $id = $this->approved();
        $proof = $this->file();
        $out = $this->race(fn () => $this->payNow($id, proof: $proof), ['pay', (string) $id, (string) $this->payer, (string) $proof, bin2hex(random_bytes(16))]);
        $this->assertStringStartsWith('DisbursementTransitionInvalid: Impossible de payer cette demande : elle est déjà payée.', $out);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]));
        $this->assertSame(100_000_000 - 5_000_000, $this->ledger()->balance($this->account));
    }

    public function test_edit_during_approval_serialises(): void
    {
        // Approbation d'abord (non validée), modification substantielle en parallèle : la révision gagnante rend l'approbation caduque.
        $id = $this->submitted();
        $out = $this->race(fn () => $this->svc()->approve($id, $this->approverA, null), ['revise', (string) $id, (string) $this->initiator, '6000000']);
        $this->assertSame('ok', $out);
        $r = $this->db->fetch('SELECT status, revision FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertSame(['soumis', 2], [$r['status'], (int) $r['revision']]);
        $this->assertSame(1, $this->approvals($id));
        $this->assertSame(0, $this->approvals($id, validOnly: true));
        // Ordre inverse : la modification d'abord ; l'approbation concurrente, faite sur la révision vue (2), est refusée.
        $out = $this->race(fn () => $this->svc()->revise($id, ['amount' => 6_500_000], 'Ajustement', $this->initiator), ['approve', (string) $id, (string) $this->approverB, '2']);
        $this->assertStringStartsWith('DisbursementRuleViolation: La demande a été modifiée depuis votre lecture (révision 3)', $out);
        $this->assertSame(0, $this->approvals($id, validOnly: true));
        $this->assertSame('soumis', $this->state($id));
    }

    /** Verrou tenu par une autre connexion : chaque action attend le verrou de la demande (1205) au lieu de lire un état périmé. */
    public function test_every_action_waits_for_the_request_lock(): void
    {
        $draft = $this->draft();
        $submitted = $this->submitted();
        $approved = $this->approved();
        $other = Database::connect(self::config(), test: true);
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->pdo()->beginTransaction();
        $other->fetchAll('SELECT id FROM disbursement_requests WHERE id IN (?, ?, ?) FOR UPDATE', [$draft, $submitted, $approved]);
        try {
            foreach ([fn () => $this->svc()->submit($draft, $this->initiator), fn () => $this->svc()->revise($draft, ['purpose' => 'x'], '', $this->initiator),
                fn () => $this->svc()->approve($submitted, $this->approverA, null), fn () => $this->svc()->reject($submitted, $this->approverA, 'x'),
                fn () => $this->svc()->cancel($submitted, $this->initiator), fn () => $this->payNow($approved)] as $op) {
                try {
                    $op();
                    $this->fail('l\'action aurait dû attendre le verrou de la demande');
                } catch (PDOException $e) {
                    $this->assertSame(1205, $e->errorInfo[1]);
                }
            }
        } finally {
            $other->pdo()->rollBack();
        }
        $this->assertSame(['brouillon', 'soumis', 'approuve_2'], [$this->state($draft), $this->state($submitted), $this->state($approved)]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
    }

    /**
     * Entrelacement déterministe : la transaction A a lu la demande (vue cohérente figée) ; B fait une approbation complète et valide ;
     * l'appel de service de A, qui rejoint sa transaction, doit voir l'état validé par B une fois le verrou pris.
     */
    public function test_service_sees_committed_state_after_the_lock_despite_an_older_snapshot(): void
    {
        $id = $this->submitted();
        $b = Database::connect(self::config(), test: true);
        $this->db->pdo()->beginTransaction();
        try {
            $this->assertSame('soumis', $this->db->fetchValue('SELECT status FROM disbursement_requests WHERE id = ?', [$id]));
            $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$id]));
            $this->svc($b)->approve($id, $this->approverA, null); // B : approbation complète, validée
            $this->assertSame('soumis', $this->db->fetchValue('SELECT status FROM disbursement_requests WHERE id = ?', [$id]), 'la vue de A est bien périmée');
            // Même approbateur côté A : refusé, car A relit sous verrou l'approbation validée par B.
            $this->refuses(fn () => $this->svc()->approve($id, $this->approverA, null), DisbursementRuleViolation::class, 'déjà prononcé');
            // Second approbateur côté A : compte l'approbation de B, donc approuve_2 (et non approuve_1 d'après la vue périmée).
            $this->svc()->approve($id, $this->approverB, null);
            $this->db->pdo()->commit();
        } catch (\Throwable $e) {
            $this->db->pdo()->rollBack();
            throw $e;
        }
        $this->assertSame('approuve_2', $this->state($id));
        $this->assertSame(2, $this->approvals($id, validOnly: true));
    }
}
