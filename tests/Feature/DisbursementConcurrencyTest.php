<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Modules\Disbursements\DisbursementBusy;
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
        $out = $this->race(fn () => $this->approveNow($id, $this->approverA, 'premier clic'), ['approve', (string) $id, (string) $this->approverA, '1']);
        $this->assertStringStartsWith('DisbursementRuleViolation: Vous vous êtes déjà prononcé', $out);
        $this->assertSame(1, $this->approvals($id));
        $this->assertSame('approuve_1', $this->state($id));
    }

    public function test_payment_race_creates_one_payment(): void
    {
        $id = $this->approved();
        $proof = $this->file($this->payer);
        $out = $this->race(fn () => $this->payNow($id, proof: $proof), ['pay', (string) $id, (string) $this->payer, (string) $proof, bin2hex(random_bytes(16)), '1', '5000000']);
        $this->assertStringStartsWith('DisbursementTransitionInvalid: Impossible de payer cette demande : elle est déjà payée.', $out);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]));
        $this->assertSame(100_000_000 - 5_000_000, $this->ledger()->balance($this->account));
    }

    public function test_edit_during_approval_serialises(): void
    {
        // Approbation d'abord (non validée), modification substantielle en parallèle : la révision gagnante rend l'approbation caduque.
        $id = $this->submitted();
        $out = $this->race(fn () => $this->approveNow($id, $this->approverA, null), ['revise', (string) $id, (string) $this->initiator, '6000000']);
        $this->assertSame('ok', $out);
        $r = $this->db->fetch('SELECT status, revision FROM disbursement_requests WHERE id = ?', [$id]);
        $this->assertSame(['soumis', 2], [$r['status'], (int) $r['revision']]);
        $this->assertSame(1, $this->approvals($id));
        $this->assertSame(0, $this->approvals($id, validOnly: true));
        // Ordre inverse : la modification d'abord ; l'approbation concurrente, faite sur la révision vue (2), est refusée.
        $out = $this->race(fn () => $this->svc()->revise($id, ['amount' => 6_500_000], 'Ajustement', $this->initiator), ['approve', (string) $id, (string) $this->approverB, '2']);
        $this->assertSame('DisbursementRuleViolation: ' . \App\Modules\Disbursements\DisbursementService::STALE, $out);
        $this->assertSame(0, $this->approvals($id, validOnly: true));
        $this->assertSame('soumis', $this->state($id));
    }

    private function mysqli(): \mysqli
    {
        $c = self::config();
        $m = new \mysqli((string) $c->get('DB_HOST', '127.0.0.1'), (string) $c->get('DB_USER'), (string) $c->get('DB_PASS'), (string) $c->get('DB_TEST_NAME'), (int) $c->get('DB_PORT', '3306'));
        $m->query('SET SESSION innodb_lock_wait_timeout = 5');
        return $m;
    }

    /**
     * Rejoue sur deux connexions la séquence de requêtes d'une action sur deux demandes DIFFÉRENTES : verrou de la demande, test
     * d'existence, puis INSERT ; l'INSERT de A part en asynchrone avant celui de B. @return array{int,int} errno de A et de B
     * @param array{0: list<int|string>, 1: list<int|string>} $ids
     */
    private function interleave(array $requests, callable $check, callable $insert): array
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        [$a, $b] = [$this->mysqli(), $this->mysqli()];
        foreach ([[$a, 0], [$b, 1]] as [$m, $k]) {
            $m->begin_transaction();
            $m->query('SELECT * FROM disbursement_requests WHERE id = ' . (int) $requests[$k] . ' FOR UPDATE')->fetch_all();
            $m->query($check($k))->fetch_all();
        }
        $a->query($insert(0), MYSQLI_ASYNC);
        usleep(300_000);
        $b->query($insert(1));
        $errB = $b->errno;
        $links = $errors = $reject = [$a];
        mysqli_poll($links, $errors, $reject, 7);
        $a->reap_async_query();
        $errA = $a->errno;
        $a->rollback();
        $b->rollback();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return [$errA, $errB];
    }

    /**
     * Sonde de la revue (I2), rendue permanente : l'ancien motif (lecture verrouillante d'une clé absente puis INSERT) interbloque deux
     * demandes différentes (1213, témoin) ; le motif du service (lecture simple, l'UNIQUE en filet) passe sans interblocage ni attente,
     * pour les trois cas : décision déjà prise (approve/reject), clé d'idempotence (pay), pièces (revise).
     */
    public function test_existence_checks_take_no_gap_lock_across_two_requests(): void
    {
        $r = [$this->submitted(), $this->submitted()];
        $users = [$this->approverA, $this->approverB];
        $file = $this->file();
        $keys = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
        $cases = [
            'décision' => [fn (int $k) => "SELECT 1 FROM disbursement_approvals WHERE disbursement_request_id = {$r[$k]} AND revision = 1 AND approver_id = {$users[$k]}",
                fn (int $k) => "INSERT INTO disbursement_approvals (disbursement_request_id, revision, approver_id, decision) VALUES ({$r[$k]}, 1, {$users[$k]}, 'approve')"],
            'idempotence' => [fn (int $k) => "SELECT id FROM disbursement_payments WHERE idempotency_key = '{$keys[$k]}'",
                fn (int $k) => "INSERT INTO disbursement_payments (disbursement_request_id, paid_at, method, paid_by, proof_file_id, idempotency_key) VALUES ({$r[$k]}, NOW(), 'especes', 1, $file, '{$keys[$k]}')"],
            'pièces' => [fn (int $k) => "SELECT id FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = {$r[$k]} + 1000000",
                fn (int $k) => "INSERT INTO financial_attachments (attachable_type, attachable_id, file_id) VALUES ('disbursement_request', {$r[$k]} + 1000000, $file)"],
        ];
        foreach ($cases as $name => [$check, $insert]) {
            $old = $this->interleave($r, fn (int $k) => $check($k) . ' LOCK IN SHARE MODE', $insert);
            $this->assertContains(1213, $old, "$name : témoin, l'ancien motif interbloque");
            $this->assertSame([0, 0], $this->interleave($r, $check, $insert), "$name : le motif du service passe");
        }
        // Le service n'utilise plus de lecture verrouillante que pour le décompte des approbations et le compte (clé primaire existante).
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Disbursements/DisbursementService.php');
        $this->assertSame(2, substr_count($src, 'LOCK IN SHARE MODE'));
        $this->assertMatchesRegularExpression('/function validApprovals[^}]+LOCK IN SHARE MODE/', $src);
        $this->assertMatchesRegularExpression("/FROM financial_accounts WHERE id = \? LOCK IN SHARE MODE/", $src);
        // Et les deux actions réelles, en série, réussissent.
        $this->approveNow($r[0], $this->approverA);
        $this->approveNow($r[1], $this->approverB);
        $this->assertSame(['approuve_1', 'approuve_1'], [$this->state($r[0]), $this->state($r[1])]);
    }

    /** Vrai interblocage dont l'action est la victime : run() rejoue l'action une fois, elle aboutit (aucune erreur 1213 ne remonte). */
    public function test_deadlock_victim_is_retried_once(): void
    {
        $id = $this->submitted();
        $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/disbursement_deadlock_worker.php', (string) $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertSame("ready\n", fgets($pipes[1]));
        $this->approveNow($id, $this->approverA, 'malgré l\'interblocage');
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->assertSame("locked\ndone", $out, 'le fils a obtenu le verrou : c\'est bien l\'action qui a été la victime, puis rejouée');
        $this->assertSame('approuve_1', $this->state($id));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'disbursement.approved' AND auditable_id = ?", [$id]));
    }

    /** Verrou tenu par une autre connexion : chaque action attend le verrou de la demande (1205, traduit en DisbursementBusy) au lieu de lire un état périmé. */
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
                fn () => $this->approveNow($submitted, $this->approverA, null), fn () => $this->rejectNow($submitted, $this->approverA, 'x'),
                fn () => $this->svc()->cancel($submitted, $this->initiator), fn () => $this->payNow($approved)] as $op) {
                try {
                    $op();
                    $this->fail('l\'action aurait dû attendre le verrou de la demande');
                } catch (DisbursementBusy $e) { // 1205 traduit : jamais une erreur SQL brute
                    $this->assertSame(DisbursementBusy::MESSAGE, $e->getMessage());
                    $this->assertSame(1205, $e->getPrevious()->errorInfo[1]);
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
            $this->svc($b)->approve($id, $this->approverA, 1, null); // B : approbation complète, validée
            $this->assertSame('soumis', $this->db->fetchValue('SELECT status FROM disbursement_requests WHERE id = ?', [$id]), 'la vue de A est bien périmée');
            // Même approbateur côté A : refusé, car A relit sous verrou l'approbation validée par B.
            $this->refuses(fn () => $this->approveNow($id, $this->approverA, null), DisbursementRuleViolation::class, 'déjà prononcé');
            // Second approbateur côté A : compte l'approbation de B, donc approuve_2 (et non approuve_1 d'après la vue périmée).
            $this->approveNow($id, $this->approverB, null);
            $this->db->pdo()->commit();
        } catch (\Throwable $e) {
            $this->db->pdo()->rollBack();
            throw $e;
        }
        $this->assertSame('approuve_2', $this->state($id));
        $this->assertSame(2, $this->approvals($id, validOnly: true));
    }
}
