<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Money;
use App\Core\Settings;
use App\Modules\Contributions\ArrearsService;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use Tests\Support\AdminTestCase;

final class ArrearsTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;
    private int $typeId;
    private int $admin;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receipts', 'payments', 'contribution_calls', 'contribution_types', 'members'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'contributions' AND `key` = 'up_to_date_rule'");
        $n = bin2hex(random_bytes(3));
        $this->zoneA = $this->db->insert('zones', ['name' => "ZA $n", 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => "ZB $n", 'type' => 'port']);
        $this->typeId = $this->db->insert('contribution_types', ['name' => "T $n", 'kind' => 'periodique', 'amount' => '10000.00', 'status' => 'actif']);
        foreach (['ar_view' => ['members.view', 'contributions.view', 'members.scope.all'], 'ar_exp' => ['members.view', 'contributions.view', 'contributions.export', 'members.scope.all'],
            'ar_zone' => ['members.view', 'contributions.view', 'contributions.export']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $this->admin = $this->createUser("a$n@ar.test", ['ar_exp']);
    }

    private function svc(): ArrearsService
    {
        return new ArrearsService($this->db, new MemberScope($this->db, new Gate($this->db)), new Settings($this->db),
            new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))));
    }

    private function member(string $n, int $zone, string $last = 'Nom'): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $last . $n, 'first_names' => 'Yao', 'zone_id' => $zone, 'status' => 'actif']);
    }

    private function day(int $offset): string
    {
        return Ledger::now()->modify(($offset >= 0 ? '+' : '') . "$offset days")->format('Y-m-d');
    }

    private function call(int $m, int $dueOffset, string $status = 'appelee', string $amount = '10000.00', ?string $period = null): int
    {
        $start = $period ?? $this->day(-1000 + ++$this->seq);
        return $this->db->insert('contribution_calls', ['member_id' => $m, 'contribution_type_id' => $this->typeId, 'period_label' => "P $start", 'period_start' => $start, 'period_end' => $start,
            'amount_due' => $amount, 'due_date' => $this->day($dueOffset), 'status' => $status]);
    }

    private function pay(int $m, int $c, string $amount, bool $cancelled = false): void
    {
        $this->db->insert('payments', ['reference' => 'R' . bin2hex(random_bytes(6)), 'member_id' => $m, 'contribution_call_id' => $c, 'amount' => $amount, 'method' => 'especes',
            'paid_at' => Ledger::now()->format('Y-m-d H:i:s'), 'idempotency_key' => bin2hex(random_bytes(16)), 'cancelled_at' => $cancelled ? Ledger::now()->format('Y-m-d H:i:s') : null]);
    }

    /** @return list<array<string,mixed>> */
    private function all(array $criteria = [], ?int $user = null): array
    {
        return iterator_to_array($this->svc()->unpaid($user ?? $this->admin, $criteria), false);
    }

    public function test_unpaid_list_is_exact_on_a_known_dataset(): void
    {
        $m1 = $this->member('1', $this->zoneA);
        $m2 = $this->member('2', $this->zoneB);
        $a = $this->call($m1, -10);                         // échu, rien payé : 10 000
        $b = $this->call($m1, -40, 'partielle');            // échu, 3 500 payés (+ un paiement annulé ignoré) : 6 500
        $this->pay($m1, $b, '3500.00');
        $this->pay($m1, $b, '2000.00', true);
        $this->call($m1, -5, 'payee');                      // soldé : exclu
        $this->call($m1, -5, 'annulee');                    // annulé : exclu
        $this->call($m1, 0);                                // échéance aujourd'hui : pas encore échu
        $this->call($m1, 15);                               // futur
        $c = $this->call($m2, -3, 'appelee', '2500.50');    // échu : 2 500,50
        $rows = $this->all();
        $this->assertSame([$b, $a, $c], array_map(fn (array $r): int => (int) $this->db->fetchValue('SELECT c.id FROM contribution_calls c JOIN members m ON m.id = c.member_id WHERE m.member_number = ? AND c.due_date = ?', [$r['member_number'], $r['due_date']]), $rows));
        $this->assertSame(['40', '10', '3'], array_map(fn ($r) => (string) $r['days_late'], $rows));
        $this->assertSame([650_000, 1_000_000, 250_050], array_map(fn ($r) => Money::fromDecimal((string) $r['rest']), $rows));
        $page = $this->svc()->page($this->admin, [], 1);
        $this->assertSame(3, $page['total']);
        $this->assertSame(1_900_050, $page['sum']);
        $this->assertSame(['unpaid_calls' => 2, 'amount_due' => 1_650_000], $this->svc()->memberSituation($m1));
        // filtres
        $this->assertCount(1, $this->all(['zone_id' => (string) $this->zoneB]));
        $this->assertCount(2, $this->all(['min_age_days' => '10']));
        $this->assertCount(1, $this->all(['min_age_days' => '11']));
        $this->assertCount(1, $this->all(['q' => 'Nom2']));
        $this->assertSame(1_900_050, $this->svc()->page($this->admin, ['type_id' => (string) $this->typeId], 1)['sum']);
        $this->assertSame(0, $this->svc()->page($this->admin, ['type_id' => '999999'], 1)['total']);
    }

    public function test_malformed_criteria_are_ignored_without_error(): void
    {
        $this->call($this->member('1', $this->zoneA), -2);
        $bad = ['zone_id' => ['x'], 'type_id' => "1' OR 1=1 --", 'min_age_days' => '-5', 'q' => ['a'], 'x' => 1];
        $this->assertCount(1, $this->all($bad));
        $this->assertCount(1, $this->all(['min_age_days' => '99999999999999999999', 'q' => '']) ?: [1]); // dépassement : valeur ignorée
        $this->assertSame([], ArrearsService::applied($bad));
        $this->actingAs($this->admin);
        $this->assertSame(200, $this->request('GET', '/admin/cotisations/impayes?zone_id[]=1&q[]=x&min_age_days=abc&page=zz')->status());
        $this->assertSame(200, $this->request('GET', "/admin/cotisations/impayes?q=%25_'%22<script>&type_id=0")->status());
    }

    public function test_up_to_date_flag_absent_when_rule_not_validated(): void
    {
        $m = $this->member('1', $this->zoneA);
        $this->call($m, -2);
        $this->assertArrayNotHasKey('up_to_date', $this->svc()->memberSituation($m));
        $this->assertSame('pending', $this->svc()->rule()['state']);
        $this->actingAs($this->admin);
        $body = $this->request('GET', '/admin/cotisations/impayes')->body();
        $this->assertStringContainsString('en attente de validation par les instances', $body);
        $tab = $this->request('GET', "/admin/adherents/$m?onglet=cotisations")->body();
        $this->assertStringNotContainsString('à jour', $tab);
        $this->assertStringContainsString('1 appel échu impayé', $tab);
        // règle mal configurée : jamais appliquée
        foreach (['2', 1.5, true, -1] as $bad) {
            $this->db->query("UPDATE settings SET value = ?, status = 'valide' WHERE `group` = 'contributions' AND `key` = 'up_to_date_rule'", [json_encode($bad)]);
            $this->assertArrayNotHasKey('up_to_date', $this->svc()->memberSituation($m));
            $this->assertSame('invalid', $this->svc()->rule()['state']);
        }
        $this->assertStringContainsString('mal configurée', $this->request('GET', '/admin/cotisations/impayes')->body());
    }

    public function test_rule_applied_when_validated(): void
    {
        $m = $this->member('1', $this->zoneA);
        $this->call($m, -2);
        $this->call($m, -20);
        (new Settings($this->db))->validate('contributions', 'up_to_date_rule', 2, $this->admin, 'AG-2026-01');
        $this->assertTrue($this->svc()->memberSituation($m)['up_to_date']);
        $this->call($m, -50);
        $this->assertFalse($this->svc()->memberSituation($m)['up_to_date']);
        (new Settings($this->db))->validate('contributions', 'up_to_date_rule', 0, $this->admin, 'AG-2026-02');
        $this->assertFalse($this->svc()->memberSituation($m)['up_to_date']);
        $this->assertTrue($this->svc()->memberSituation($this->member('2', $this->zoneA))['up_to_date']);
    }

    public function test_scope_limits_unpaid_list(): void
    {
        $a = $this->call($this->member('1', $this->zoneA), -2);
        $this->call($this->member('2', $this->zoneB), -2);
        $deleg = $this->createUser('d' . bin2hex(random_bytes(3)) . '@ar.test', ['ar_zone']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $deleg]);
        $this->assertCount(1, $this->all([], $deleg));
        $this->assertSame(1, $this->svc()->page($deleg, [], 1)['total']);
        $this->actingAs($deleg);
        $body = $this->request('GET', '/admin/cotisations/impayes')->body();
        $this->assertStringContainsString('Nom1', $body);
        $this->assertStringNotContainsString('Nom2', $body);
        $csv = $this->request('GET', '/admin/cotisations/impayes/export.csv')->body();
        $this->assertStringContainsString('Nom1', $csv);
        $this->assertStringNotContainsString('Nom2', $csv);
        $this->assertNotNull($a);
    }

    public function test_export_requires_permission_and_is_audited(): void
    {
        $m = $this->member('1', $this->zoneA, '=cmd|');
        $this->call($m, -4, 'appelee', '1500.50');
        foreach (['/admin/cotisations/impayes/export.csv', '/admin/cotisations/impayes/export.xlsx'] as $url) {
            $this->assertSame(302, $this->request('GET', $url)->status());
        }
        $viewer = $this->createUser('v' . bin2hex(random_bytes(3)) . '@ar.test', ['ar_view']);
        $this->actingAs($viewer);
        foreach (['/admin/cotisations/impayes/export.csv', '/admin/cotisations/impayes/export.xlsx'] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status());
        }
        $this->assertSame(200, $this->request('GET', '/admin/cotisations/impayes')->status());
        $this->assertStringNotContainsString('export.csv', $this->request('GET', '/admin/cotisations/impayes')->body());
        $before = (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'contributions.arrears_exported'");
        $this->actingAs($this->admin);
        $res = $this->request('GET', '/admin/cotisations/impayes/export.csv?q=Secret&zone_id=' . $this->zoneA . '&bogus=1');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $lines = explode("\r\n", $res->body());
        $this->assertSame("\xEF\xBB\xBFN° membre;Nom;Prénoms;Type;Période;Échéance;Jours de retard;Reste dû (XOF);Zone", $lines[0]);
        $this->assertSame(200, $this->request('GET', '/admin/cotisations/impayes/export.xlsx')->status());
        $all = $this->request('GET', '/admin/cotisations/impayes/export.csv')->body();
        $this->assertStringContainsString("'=cmd|1", $all); // neutralisé
        $this->assertStringContainsString(';4;1500,50;', $all);
        $this->assertStringNotContainsString('@', $all);
        $row = $this->db->fetch("SELECT * FROM audit_logs WHERE action = 'contributions.arrears_exported' ORDER BY id DESC LIMIT 1");
        $this->assertSame($before + 3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'contributions.arrears_exported'"));
        $this->assertSame($this->admin, (int) $row['user_id']);
        $blob = json_encode($row);
        $this->assertStringNotContainsString('Secret', $blob);
        $this->assertStringNotContainsString('cmd', $blob);
        $this->assertStringContainsString('rows', $blob);
        $first = $this->db->fetch("SELECT * FROM audit_logs WHERE action = 'contributions.arrears_exported' ORDER BY id DESC LIMIT 1 OFFSET 2");
        $this->assertStringContainsString('zone_id', json_encode($first));
        $this->assertStringNotContainsString('bogus', json_encode($first));
    }

    public function test_screen_escapes_empty_state_and_totals(): void
    {
        $this->actingAs($this->admin);
        $body = $this->request('GET', '/admin/cotisations/impayes')->body();
        $this->assertStringContainsString('Aucun impayé échu', $body);
        $this->assertStringContainsString('0 appel impayé échu', $body);
        $m = $this->db->insert('members', ['member_number' => 'X1', 'last_name' => '<b>Evil</b>', 'first_names' => 'Yao', 'zone_id' => $this->zoneA, 'status' => 'actif']);
        $this->call($m, -7);
        $body = $this->request('GET', '/admin/cotisations/impayes')->body();
        $this->assertStringNotContainsString('<b>Evil', $body);
        $this->assertStringContainsString('&lt;b&gt;Evil', $body);
        $this->assertStringContainsString('1 appel impayé échu', $body);
        $this->assertSame(1, substr_count($body, '<h1'));
        $this->assertStringContainsString('/admin/cotisations/impayes', $this->request('GET', '/admin/adherents')->body());
    }

    public function test_performance_on_2000_members_and_24000_calls(): void
    {
        $this->db->query("INSERT INTO members (member_number, last_name, first_names, zone_id, status) SELECT CONCAT('P', seq), CONCAT('Perf', seq), 'Yao', ?, 'actif' FROM seq_1_to_2000", [$this->zoneA]);
        $this->db->query("INSERT INTO contribution_calls (member_id, contribution_type_id, period_label, period_start, period_end, amount_due, due_date, status)
            SELECT m.id, ?, CONCAT('M', s.seq), DATE_SUB(CURDATE(), INTERVAL s.seq MONTH), DATE_SUB(CURDATE(), INTERVAL s.seq MONTH), 10000.00,
            DATE_SUB(CURDATE(), INTERVAL (s.seq * 60 - 15) DAY), CASE WHEN s.seq % 4 = 0 THEN 'payee' WHEN s.seq % 4 = 1 THEN 'partielle' ELSE 'appelee' END
            FROM members m JOIN seq_1_to_6 s WHERE m.member_number LIKE 'P%'", [$this->typeId]);
        // 6 appels par adhérent et par type : 24 000 au total avec le second type
        $t2 = $this->db->insert('contribution_types', ['name' => 'T2 ' . bin2hex(random_bytes(3)), 'kind' => 'periodique', 'amount' => '5000.00', 'status' => 'actif']);
        $this->db->query("INSERT INTO contribution_calls (member_id, contribution_type_id, period_label, period_start, period_end, amount_due, due_date, status)
            SELECT c.member_id, ?, c.period_label, c.period_start, c.period_end, 5000.00, c.due_date, 'appelee' FROM contribution_calls c", [$t2]);
        $this->db->query("INSERT INTO payments (reference, member_id, contribution_call_id, amount, method, paid_at, idempotency_key)
            SELECT CONCAT('PR', c.id), c.member_id, c.id, 4000.00, 'especes', NOW(), CONCAT('k', c.id) FROM contribution_calls c WHERE c.status = 'partielle'");
        $this->assertSame(24000, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        $t = microtime(true);
        $page = $this->svc()->page($this->admin, [], 1);
        $tList = microtime(true) - $t;
        $t = microtime(true);
        $n = iterator_count($this->svc()->unpaid($this->admin, []));
        $tStream = microtime(true) - $t;
        $t = microtime(true);
        $csv = $this->svc()->file('csv', $this->admin, [], null, null);
        $tCsv = microtime(true) - $t;
        $t = microtime(true);
        $this->svc()->file('xlsx', $this->admin, ['min_age_days' => '60'], null, null);
        $tXlsx = microtime(true) - $t;
        fwrite(STDERR, sprintf("\n[perf] page+totaux %.3fs, flux %.3fs (%d lignes), export csv %.3fs, xlsx filtré %.3fs\n", $tList, $tStream, $n, $tCsv, $tXlsx));
        $this->assertSame($n, $page['total']);
        $this->assertSame(1_000 * 0 + $n, substr_count($csv, "\r\n") - 1);
        $this->assertLessThan(2.0, $tList);
        $this->assertLessThan(10.0, $tCsv);
        $this->assertLessThan(15.0, $tXlsx);
    }
}
