<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

/** Tentatives simultanées (processus fils, connexions distinctes) : aucune limite ne doit être dépassée. */
final class LoginThrottleConcurrencyTest extends AdminTestCase
{
    use EspaceFixtures;

    private const N = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
    }

    /** @return array<string,int> nombre de S, B et F */
    private function burst(string $world, callable $identifier, string $password): array
    {
        $start = (int) ((microtime(true) + 3) * 1e6); // laisse le temps à chaque fils de démarrer
        $procs = [];
        for ($i = 0; $i < self::N; $i++) {
            $procs[] = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/login_worker.php', $world, $identifier($i), $password, (string) $start],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = '';
        foreach ($procs as $i => $p) {
            $out .= stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            proc_close($p);
        }
        $this->assertMatchesRegularExpression('/^[SBF]{' . self::N . '}$/', $out, $out);
        return ['S' => substr_count($out, 'S'), 'B' => substr_count($out, 'B'), 'F' => substr_count($out, 'F')];
    }

    public function test_parallel_wrong_passwords_for_one_member_are_capped_at_five(): void
    {
        $this->memberWithAccount(['phone' => '0766666666']);
        $r = $this->burst('espace', static fn () => '0766666666', 'mauvais mot de passe long');
        $this->assertSame(5, $r['F'], json_encode($r));
        $this->assertSame(self::N - 5, $r['B']);
        $this->assertSame(5, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'espace:0766666666' AND succeeded = 0"));
    }

    public function test_parallel_wrong_passwords_from_one_ip_are_capped_at_twenty(): void
    {
        $r = $this->burst('espace', static fn (int $i) => "INCONNU-$i", 'mauvais mot de passe long');
        $this->assertSame(20, $r['F'], json_encode($r));
        $this->assertSame(20, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0"));
    }

    public function test_parallel_staff_wrong_passwords_are_capped_at_five(): void
    {
        $this->createUser('rafale@x.test', []);
        $r = $this->burst('admin', static fn () => 'rafale@x.test', 'mauvais mot de passe long');
        $this->assertSame(5, $r['F'], json_encode($r));
        $this->assertSame(5, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'rafale@x.test' AND succeeded = 0"));
    }
}
