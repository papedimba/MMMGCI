<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Sequence;
use App\Modules\Contributions\CallService;
use App\Modules\Contributions\ContributionTypeService;
use App\Modules\Contributions\PaymentService;
use App\Modules\Contributions\ReceiptService;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;
use Tests\Support\AdminTestCase;

final class PaymentReceiptTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;
    private int $account;
    private int $manager;
    private int $viewer;
    private int $delegate;
    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receipts', 'payments', 'contribution_calls', 'contribution_types', 'members'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'ZA ' . bin2hex(random_bytes(3)), 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'ZB ' . bin2hex(random_bytes(3)), 'type' => 'port']);
        $this->account = $this->db->insert('financial_accounts', ['name' => 'Rec ' . bin2hex(random_bytes(3))]);
        foreach (['pr_view' => ['members.view', 'contributions.view', 'members.scope.all'], 'pr_manage' => ['members.view', 'contributions.view', 'contributions.manage', 'members.scope.all'],
            'pr_deleg' => ['members.view', 'contributions.view']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $n = bin2hex(random_bytes(3));
        $this->manager = $this->createUser("m$n@pr.test", ['pr_manage']);
        $this->viewer = $this->createUser("v$n@pr.test", ['pr_view']);
        $this->delegate = $this->createUser("d$n@pr.test", ['pr_deleg']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $this->delegate]);
        $this->typeId = (new ContributionTypeService($this->db, $this->audit()))->save(null, ['name' => 'T ' . $n, 'kind' => 'periodique', 'amount' => '10 000', 'periodicity' => 'mensuelle',
            'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'], $this->manager);
    }

    private function audit(?Database $db = null): Audit
    {
        return new Audit($db ?? $this->db, 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    private function svc(?Database $db = null): PaymentService
    {
        $db ??= $this->db;
        return new PaymentService($db, $this->audit($db), new Ledger($db, $this->audit($db), new Sequence($db)), new Sequence($db), new ReceiptService($db, new Sequence($db)), new MemberScope($db, new Gate($db)));
    }

    private function member(string $n, ?int $zone = null, string $last = null): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $last ?? "Nom$n", 'first_names' => 'Yao', 'zone_id' => $zone ?? $this->zoneA, 'status' => 'actif']);
    }

    /** @return array{int,int} [membre, appel] */
    private function call(string $n = 'M1', ?int $zone = null, string $last = null): array
    {
        $m = $this->member($n, $zone, $last);
        $c = (new CallService($this->db, $this->audit(), new MemberScope($this->db, new Gate($this->db))))->createForMember($m, $this->typeId, ['start' => $this->now()->format('Y-m-d')], $this->manager);
        return [$m, $c];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
    }

    private function pay(int $m, int $c, int $cents, ?string $key = null, ?int $actor = null): int
    {
        return $this->svc()->record($m, $c, $cents, 'especes', $this->now(), null, $this->account, $key ?? bin2hex(random_bytes(16)), $actor ?? $this->manager);
    }

    private function callStatus(int $c): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM contribution_calls WHERE id = ?', [$c]);
    }

    private function refuses(callable $f, string $needle = ''): void
    {
        try {
            $f();
            $this->fail('refus attendu');
        } catch (DomainException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function test_payment_is_idempotent_on_same_key(): void
    {
        [$m, $c] = $this->call();
        $a = $this->pay($m, $c, 400_000, 'abcdefabcdefabcdef12');
        $b = $this->pay($m, $c, 400_000, 'abcdefabcdefabcdef12');
        $this->assertSame($a, $b);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM receipts'));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE source_type = ? AND source_id = ?', ['contribution_call', $c]));
        $this->assertNotNull($this->db->fetchValue('SELECT financial_transaction_id FROM payments WHERE id = ?', [$a]));
    }

    public function test_replay_with_different_payload_is_refused(): void
    {
        [$m, $c] = $this->call('M1');
        [$m2, $c2] = $this->call('M2');
        $this->pay($m, $c, 400_000, 'keykeykeykeykeykey1');
        $this->refuses(fn () => $this->pay($m, $c, 300_000, 'keykeykeykeykeykey1'), 'clé');
        $this->refuses(fn () => $this->pay($m2, $c2, 400_000, 'keykeykeykeykeykey1'), 'clé');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
    }

    public function test_partial_then_full_payment_sets_call_status(): void
    {
        [$m, $c] = $this->call();
        $this->assertSame('appelee', $this->callStatus($c));
        $this->pay($m, $c, 400_000);
        $this->assertSame('partielle', $this->callStatus($c));
        $this->pay($m, $c, 600_000);
        $this->assertSame('payee', $this->callStatus($c));
        $this->refuses(fn () => $this->pay($m, $c, 100), 'soldé');
    }

    public function test_overpayment_on_call_is_refused(): void
    {
        [$m, $c] = $this->call();
        $this->refuses(fn () => $this->pay($m, $c, 1_000_100), 'dépasse');
        $this->pay($m, $c, 900_000);
        $this->refuses(fn () => $this->pay($m, $c, 100_100), 'dépasse');
        $this->refuses(fn () => $this->svc()->record($m, null, 100, 'especes', $this->now(), null, $this->account, bin2hex(random_bytes(16)), $this->manager), 'sans appel');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
    }

    public function test_receipt_numbers_are_unique_and_sequential(): void
    {
        $ids = [];
        foreach (['M1', 'M2', 'M3'] as $n) {
            [$m, $c] = $this->call($n);
            $ids[] = $this->pay($m, $c, 100_000);
        }
        $nums = array_column($this->db->fetchAll('SELECT receipt_number FROM receipts ORDER BY id'), 'receipt_number');
        $year = $this->now()->format('Y');
        $this->assertCount(3, array_unique($nums));
        foreach ($nums as $x) {
            $this->assertMatchesRegularExpression("/^REC-$year-\\d{5}$/", $x);
        }
        $n = array_map(static fn (string $s): int => (int) substr($s, -5), $nums);
        $this->assertSame([$n[0], $n[0] + 1, $n[0] + 2], $n);
    }

    public function test_cancel_creates_reversal_and_marks_receipt_cancelled(): void
    {
        [$m, $c] = $this->call();
        $p = $this->pay($m, $c, 1_000_000);
        $tx = (int) $this->db->fetchValue('SELECT financial_transaction_id FROM payments WHERE id = ?', [$p]);
        $ledger = new Ledger($this->db, $this->audit(), new Sequence($this->db));
        $this->assertSame(1_000_000, $ledger->balance($this->account));
        $this->refuses(fn () => $this->svc()->cancel($p, '  ', $this->manager), 'motif');
        $this->svc()->cancel($p, 'Erreur de saisie', $this->manager);
        $rev = $this->db->fetch('SELECT direction, amount FROM financial_transactions WHERE reversal_of_id = ?', [$tx]);
        $this->assertSame(['depense', '10000.00'], [$rev['direction'], $rev['amount']]);
        $this->assertSame(0, $ledger->balance($this->account));
        $r = $this->db->fetch('SELECT cancelled_at, cancel_reason FROM receipts WHERE payment_id = ?', [$p]);
        $this->assertSame('Erreur de saisie', $r['cancel_reason']);
        $this->assertNotNull($r['cancelled_at']);
        $this->assertSame('Erreur de saisie', $this->db->fetchValue('SELECT cancel_reason FROM payments WHERE id = ?', [$p]));
        $this->assertSame('appelee', $this->callStatus($c));
        $this->actingAs($this->viewer);
        $body = $this->request('GET', "/admin/cotisations/paiements/$p/recu")->body();
        $this->assertStringContainsString('ANNULÉ', $body);
        $this->assertStringContainsString('Erreur de saisie', $body);
    }

    public function test_cancel_twice_is_refused(): void
    {
        [$m, $c] = $this->call();
        $p = $this->pay($m, $c, 500_000);
        $this->svc()->cancel($p, 'Erreur', $this->manager);
        $this->refuses(fn () => $this->svc()->cancel($p, 'Encore', $this->manager), 'déjà annulé');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions WHERE reversal_of_id IS NOT NULL AND account_id = ?', [$this->account]));
    }

    public function test_cancel_partial_payment_restores_previous_status(): void
    {
        [$m, $c] = $this->call();
        $p1 = $this->pay($m, $c, 400_000);
        $p2 = $this->pay($m, $c, 600_000);
        $this->svc()->cancel($p2, 'x', $this->manager);
        $this->assertSame('partielle', $this->callStatus($c));
        $this->svc()->cancel($p1, 'x', $this->manager);
        $this->assertSame('appelee', $this->callStatus($c));
    }

    public function test_french_decimal_input_is_parsed(): void
    {
        [$m, $c] = $this->call();
        $this->db->update('contribution_calls', ['amount_due' => '20000.00'], ['id' => $c]);
        $this->actingAs($this->manager);
        $res = $this->request('POST', "/admin/cotisations/appels/$c/encaisser", ['amount' => '12 500,50', 'method' => 'mobile_money', 'paid_on' => $this->now()->format('Y-m-d'), 'external_ref' => 'MM1', 'key' => bin2hex(random_bytes(16))]);
        $this->assertSame(302, $res->status(), $res->body());
        $this->assertSame('12500.50', $this->db->fetchValue('SELECT amount FROM payments'));
        $this->assertSame(1_250_050, Money::parse('12 500,50'));
    }

    public function test_zero_negative_and_huge_amounts_are_refused(): void
    {
        [$m, $c] = $this->call();
        $this->actingAs($this->manager);
        foreach (['0', '-5', '1 000 000 000 000 000', 'abc', ''] as $bad) {
            $res = $this->request('POST', "/admin/cotisations/appels/$c/encaisser", ['amount' => $bad, 'method' => 'especes', 'paid_on' => $this->now()->format('Y-m-d'), 'key' => bin2hex(random_bytes(16))]);
            $this->assertSame(422, $res->status(), $bad);
        }
        $this->refuses(fn () => $this->pay($m, $c, 0));
        $this->refuses(fn () => $this->pay($m, $c, -100));
        $future = $this->now()->modify('+2 days');
        $this->refuses(fn () => $this->svc()->record($m, $c, 100, 'especes', $future, null, $this->account, bin2hex(random_bytes(16)), $this->manager), 'futur');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
    }

    public function test_receipt_escapes_hostile_member_name(): void
    {
        [$m, $c] = $this->call('M1', null, '<script>alert(1)</script>');
        $p = $this->pay($m, $c, 100_000);
        $this->actingAs($this->viewer);
        foreach (["/admin/cotisations/paiements/$p/recu", "/admin/cotisations/paiements/$p", "/admin/adherents/$m?onglet=cotisations"] as $url) {
            $body = $this->request('GET', $url)->body();
            $this->assertStringNotContainsString('<script>alert(1)', $body, $url);
        }
        $receipt = $this->request('GET', "/admin/cotisations/paiements/$p/recu")->body();
        $this->assertStringContainsString('&lt;script&gt;', $receipt);
        $this->assertStringContainsString('Reçu de cotisation', $receipt);
        $this->assertSame(1, substr_count($receipt, '<h1'));
    }

    public function test_payment_requires_contributions_manage_and_csrf(): void
    {
        [$m, $c] = $this->call();
        $post = ['amount' => '1000', 'method' => 'especes', 'paid_on' => $this->now()->format('Y-m-d'), 'key' => bin2hex(random_bytes(16))];
        $this->actingAs($this->viewer);
        $this->assertSame(403, $this->request('GET', "/admin/cotisations/appels/$c/encaisser")->status());
        $this->assertSame(403, $this->request('POST', "/admin/cotisations/appels/$c/encaisser", $post)->status());
        $this->actingAs($this->manager);
        $this->assertSame(200, $this->request('GET', "/admin/cotisations/appels/$c/encaisser")->status());
        $form = $this->request('GET', "/admin/cotisations/appels/$c/encaisser")->body();
        $this->assertMatchesRegularExpression('/name="key" value="[0-9a-f]{32}"/', $form);
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', "/admin/cotisations/appels/$c/encaisser", [], $post, [], ['REMOTE_ADDR' => '127.0.0.1', 'QUERY_STRING' => '']));
        $this->assertSame(419, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
    }

    public function test_scope_matrix(): void
    {
        [$in, $cIn] = $this->call('M1', $this->zoneA);
        [$out, $cOut] = $this->call('M2', $this->zoneB);
        $pIn = $this->pay($in, $cIn, 100_000);
        $pOut = $this->pay($out, $cOut, 100_000);
        // Délégué (zone A, sans contributions.manage) : aucun encaissement ; lecture limitée à son périmètre.
        $this->actingAs($this->delegate);
        $this->assertSame(403, $this->request('POST', "/admin/cotisations/appels/$cIn/encaisser", ['amount' => '1', 'method' => 'especes', 'paid_on' => '2020-01-01', 'key' => bin2hex(random_bytes(16))])->status());
        $this->assertSame(403, $this->request('GET', "/admin/cotisations/paiements/$pIn/annuler")->status());
        $this->assertSame(200, $this->request('GET', "/admin/cotisations/paiements/$pIn")->status());
        $this->assertSame(200, $this->request('GET', "/admin/cotisations/paiements/$pIn/recu")->status());
        $this->assertSame(404, $this->request('GET', "/admin/cotisations/paiements/$pOut")->status());
        $this->assertSame(404, $this->request('GET', "/admin/cotisations/paiements/$pOut/recu")->status());
        // Un gestionnaire cantonné à la zone A : 404 hors zone, y compris pour encaisser et annuler.
        $db = $this->db;
        $zoneManager = $this->createUser('zm' . bin2hex(random_bytes(3)) . '@pr.test', []);
        $db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['pr_zone', 'pr_zone']);
        foreach (['members.view', 'contributions.view', 'contributions.manage'] as $p) {
            $db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', ['pr_zone', $p]);
        }
        $db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$zoneManager, 'pr_zone']);
        $db->update('users', ['zone_id' => $this->zoneA], ['id' => $zoneManager]);
        $this->actingAs($zoneManager);
        $this->assertSame(404, $this->request('GET', "/admin/cotisations/appels/$cOut/encaisser")->status());
        $this->assertSame(404, $this->request('POST', "/admin/cotisations/paiements/$pOut/annuler", ['reason' => 'x'])->status());
        $this->assertSame(200, $this->request('GET', "/admin/cotisations/appels/$cIn/encaisser")->status());
        $this->expectException(\App\Core\NotFound::class);
        $this->svc()->record($out, $cOut, 100, 'especes', $this->now(), null, $this->account, bin2hex(random_bytes(16)), $zoneManager);
    }

    /** Course paiement / annulation d'appel : les deux passent par le verrou FOR UPDATE de l'appel (preuve avec une 2e connexion), puis ordre séquentiel dans les deux sens. */
    public function test_call_cancellation_and_payment_serialise_on_the_call_lock(): void
    {
        [$m, $c] = $this->call();
        $other = Database::connect(self::config(), test: true);
        $callSvc = new CallService($this->db, $this->audit(), new MemberScope($this->db, new Gate($this->db)));
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->pdo()->beginTransaction();
        $other->fetch('SELECT id FROM contribution_calls WHERE id = ? FOR UPDATE', [$c]); // le « concurrent » tient l'appel
        foreach ([fn () => $this->pay($m, $c, 100_000), fn () => $callSvc->cancel($c, 'x', $this->manager)] as $op) {
            try {
                $op();
                $this->fail('l\'opération aurait dû attendre le verrou de l\'appel');
            } catch (PDOException $e) {
                $this->assertSame(1205, $e->errorInfo[1]); // lock wait timeout : elle bloque avant toute lecture de l'état
            }
        }
        $other->pdo()->rollBack();
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
        // Paiement d'abord : l'annulation de l'appel est refusée ; après annulation du paiement, elle passe, puis plus aucun paiement.
        $p = $this->pay($m, $c, 100_000);
        $this->refuses(fn () => $callSvc->cancel($c, 'x', $this->manager), 'paiement');
        $this->svc()->cancel($p, 'erreur', $this->manager);
        $callSvc->cancel($c, 'x', $this->manager);
        $this->refuses(fn () => $this->pay($m, $c, 100_000), 'annulé');
    }
}
