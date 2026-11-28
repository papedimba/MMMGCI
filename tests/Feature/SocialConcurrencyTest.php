<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use Tests\DatabaseTestCase;
use Tests\Support\SocialFixtures;

/**
 * Courses réelles (modèle de DisbursementConcurrencyTest) : un processus fils (seconde connexion PDO) lance la même action pendant
 * que le test tient ses verrous dans une transaction non validée ; on attend de voir le fils bloqué (PROCESSLIST), on valide, puis on lit sa sortie.
 */
final class SocialConcurrencyTest extends DatabaseTestCase
{
    use SocialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
    }

    /** @param list<string> $args */
    private function race(callable $hold, array $args, string $waitingOn): string
    {
        [$proc, $pipes] = $this->db->transaction(function () use ($hold, $args, $waitingOn): array {
            $hold();
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/social_worker.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $observer = Database::connect(self::config(), test: true);
            for ($i = 0; $i < 400 && (int) $observer->fetchValue('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE ?', [$waitingOn . '%']) === 0; $i++) {
                usleep(25_000);
            }
            $this->assertLessThan(400, $i, 'Le processus fils aurait dû attendre le verrou.');
            return [$proc, $pipes];
        });
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    }

    public function test_two_simultaneous_decisions_create_one(): void
    {
        $id = $this->requestIn('soumise_decision');
        $out = $this->race(fn () => $this->socialSvc()->decide($id, $this->grant(), $this->decider), ['decide', (string) $id, (string) $this->decider],
            'SELECT * FROM social_requests WHERE id = ? FOR UPDATE');
        self::assertStringStartsWith('SocialTransitionInvalid: ', $out);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_decisions WHERE social_request_id = ?', [$id]));
        self::assertSame('50000.00', $this->db->fetchValue('SELECT amount FROM social_decisions WHERE social_request_id = ?', [$id]), 'la première décision reste');
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'social.decided' AND auditable_id = ?", [$id]));
        self::assertSame('accordee', $this->state($id));
    }

    public function test_reference_numbers_never_duplicate(): void
    {
        $first = 0;
        $out = $this->race(function () use (&$first): void {
            $first = $this->registerRequest();
        }, ['register', (string) $this->memberA, (string) $this->typeId, (string) $this->creator], 'INSERT INTO sequences');
        self::assertMatchesRegularExpression('/^ok \d+$/', $out);
        $second = (int) substr($out, 3);
        $refs = $this->db->fetchAll('SELECT reference FROM social_requests WHERE id IN (?, ?) ORDER BY id', [$first, $second]);
        self::assertCount(2, $refs);
        [$a, $b] = array_column($refs, 'reference');
        self::assertNotSame($a, $b);
        self::assertSame((int) substr($a, -4) + 1, (int) substr($b, -4), 'numéros consécutifs, sans doublon');
    }
}
