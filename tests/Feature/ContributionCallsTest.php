<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Scheduler;
use App\Modules\Contributions\CallService;
use App\Modules\Contributions\ContributionTypeService;
use App\Modules\Contributions\Periods;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Tests\Support\AdminTestCase;

final class ContributionCallsTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;
    private int $account;
    private int $admin;
    private ?int $delegate = null;
    private ?int $viewer = null;
    private ?int $manager = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['receipts', 'payments', 'contribution_calls', 'contribution_types', 'members'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('DELETE FROM financial_accounts WHERE name LIKE ?', ['Cot %']);
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'contributions' AND `key` = 'auto_generate'");
        $this->zoneA = $this->db->insert('zones', ['name' => 'ZA ' . bin2hex(random_bytes(3)), 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'ZB ' . bin2hex(random_bytes(3)), 'type' => 'port']);
        $this->account = $this->db->insert('financial_accounts', ['name' => 'Cot ' . bin2hex(random_bytes(3))]);
        foreach (['ct_view' => ['members.view', 'contributions.view', 'members.scope.all'], 'ct_manage' => ['members.view', 'contributions.view', 'contributions.manage', 'members.scope.all'], 'ct_deleg' => ['members.view', 'contributions.view', 'contributions.manage']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $n = bin2hex(random_bytes(3));
        $this->admin = $this->createUser("m$n@ct.test", ['ct_manage']);
        $this->manager = $this->admin;
        $this->viewer = $this->createUser("v$n@ct.test", ['ct_view']);
        $this->delegate = $this->createUser("d$n@ct.test", ['ct_deleg']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $this->delegate]);
    }

    private function member(string $n, int $zone, string $status = 'actif'): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => "Nom$n", 'first_names' => 'Yao', 'zone_id' => $zone, 'status' => $status]);
    }

    private function types(): ContributionTypeService
    {
        return new ContributionTypeService($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))));
    }

    private function calls(): CallService
    {
        $audit = new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32)));
        return new CallService($this->db, $audit, new MemberScope($this->db, new Gate($this->db)));
    }

    /** @param array<string,mixed> $over */
    private function type(array $over = []): int
    {
        return $this->types()->save(null, $over + ['name' => 'T ' . bin2hex(random_bytes(3)), 'kind' => 'periodique', 'amount' => '5 000', 'periodicity' => 'mensuelle',
            'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'], $this->admin);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
    }

    public function test_type_cannot_be_activated_without_amount_authorization_and_account(): void
    {
        $base = ['name' => 'X', 'kind' => 'periodique', 'amount' => '', 'periodicity' => '', 'authorization_ref' => '', 'affects_account_id' => '', 'status' => 'non_configure'];
        $id = $this->types()->save(null, $base, $this->admin); // non configuré, montant NULL : accepté
        $this->assertNull($this->db->fetchValue('SELECT amount FROM contribution_types WHERE id = ?', [$id]));
        foreach ([['amount' => ''], ['authorization_ref' => ''], ['affects_account_id' => '']] as $missing) {
            $full = ['amount' => '5000', 'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'];
            try {
                $this->types()->save($id, $missing + $full + $base, $this->admin);
                $this->fail('activation acceptée');
            } catch (DomainException) {
                $this->assertSame('non_configure', $this->db->fetchValue('SELECT status FROM contribution_types WHERE id = ?', [$id]));
            }
        }
        $this->db->update('financial_accounts', ['is_active' => 0], ['id' => $this->account]);
        try {
            $this->types()->save($id, ['amount' => '5000', 'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'] + $base, $this->admin);
            $this->fail('compte inactif accepté');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->db->update('financial_accounts', ['is_active' => 1], ['id' => $this->account]);
        $this->types()->save($id, ['amount' => '5000', 'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'] + $base, $this->admin);
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM contribution_types WHERE id = ?', [$id]));
        // Exceptionnelle : autorisation obligatoire dès l'enregistrement.
        $this->expectException(DomainException::class);
        $this->types()->save(null, ['name' => 'Exc', 'kind' => 'exceptionnelle'] + $base, $this->admin);
    }

    public function test_batch_generation_refuses_unvalidated_type(): void
    {
        $this->member('M1', $this->zoneA);
        $none = $this->type(['amount' => '', 'status' => 'non_configure']);
        $noPeriod = $this->type(['periodicity' => '']);
        foreach ([$none, $noPeriod] as $id) {
            try {
                $this->calls()->generateBatch($id, $this->today(), $this->admin);
                $this->fail('lot accepté');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        $this->expectException(DomainException::class); // période future
        $this->calls()->generateBatch($this->type(), $this->today()->modify('+2 years'), $this->admin);
    }

    public function test_batch_is_idempotent_no_duplicate_calls(): void
    {
        $this->member('M1', $this->zoneA);
        $this->member('M2', $this->zoneB);
        $this->member('M3', $this->zoneA, 'suspendu');
        $t = $this->type();
        $this->assertSame(['eligible' => 2, 'already' => 0, 'to_create' => 2], $this->calls()->previewBatch($t, $this->today(), $this->admin));
        $this->assertSame(['created' => 2, 'skipped' => 0], $this->calls()->generateBatch($t, $this->today(), $this->admin));
        $this->assertSame(['created' => 0, 'skipped' => 2], $this->calls()->generateBatch($t, $this->today(), $this->admin));
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        $this->assertSame('5000.00', $this->db->fetchValue('SELECT amount_due FROM contribution_calls LIMIT 1'));
        $m = $this->member('M4', $this->zoneA); // un nouvel adhérent est ajouté au rappel suivant
        $this->assertSame(['created' => 1, 'skipped' => 2], $this->calls()->generateBatch($t, $this->today(), $this->admin));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls WHERE member_id = ?', [$m]));
        $this->expectException(DomainException::class); // individuel : déjà appelé
        $this->calls()->createForMember($m, $t, ['start' => $this->today()->format('Y-m-d')], $this->admin);
    }

    public function test_cancelled_call_stays_in_history(): void
    {
        $m = $this->member('M1', $this->zoneA);
        $t = $this->type();
        $id = $this->calls()->createForMember($m, $t, ['start' => $this->today()->format('Y-m-d')], $this->admin);
        try {
            $this->calls()->cancel($id, '  ', $this->admin);
            $this->fail('motif vide accepté');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
        $this->calls()->cancel($id, 'Erreur de saisie', $this->admin);
        $row = $this->db->fetch('SELECT status, cancel_reason, cancelled_at FROM contribution_calls WHERE id = ?', [$id]);
        $this->assertSame(['annulee', 'Erreur de saisie'], [$row['status'], $row['cancel_reason']]);
        $this->assertNotNull($row['cancelled_at']);
        $this->actingAs($this->manager);
        $this->assertStringContainsString('Annulée', $this->request('GET', "/admin/adherents/$m?onglet=cotisations")->body());
        // Un appel payé ne s'annule pas.
        $m2 = $this->member('M2', $this->zoneA);
        $paid = $this->calls()->createForMember($m2, $t, ['start' => $this->today()->format('Y-m-d')], $this->admin);
        $this->db->insert('payments', ['reference' => 'P-' . bin2hex(random_bytes(3)), 'member_id' => $m2, 'contribution_call_id' => $paid, 'amount' => '1000.00', 'method' => 'especes',
            'paid_at' => '2026-01-01 10:00:00', 'idempotency_key' => bin2hex(random_bytes(8))]);
        $this->expectException(DomainException::class);
        $this->calls()->cancel($paid, 'Tentative', $this->admin);
    }

    public function test_member_tab_respects_scope(): void
    {
        $in = $this->member('M1', $this->zoneA);
        $out = $this->member('M2', $this->zoneB);
        $t = $this->type();
        $this->calls()->generateBatch($t, $this->today(), $this->admin);
        $this->actingAs((int) $this->delegate);
        $res = $this->request('GET', "/admin/adherents/$in?onglet=cotisations");
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('5&nbsp;000', str_replace("\u{202F}", '&nbsp;', $res->body()) ?: '');
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$out?onglet=cotisations")->status());
        // Le délégué ne peut ni appeler ni annuler hors zone, et le lot reste dans sa zone.
        $callOut = (int) $this->db->fetchValue('SELECT id FROM contribution_calls WHERE member_id = ?', [$out]);
        $this->assertSame(404, $this->request('POST', "/admin/cotisations/appels/$callOut/annuler", ['reason' => 'x'])->status());
        $this->assertSame(404, $this->request('GET', "/admin/cotisations/appels/$callOut/annuler")->status());
        $this->assertStringNotContainsString('NomM2', $this->request('GET', '/admin/cotisations/appels')->body());
        $this->expectException(\App\Core\NotFound::class);
        $this->calls()->createForMember($out, $t, ['start' => '2020-01-15'], (int) $this->delegate);
    }

    public function test_cron_task_does_nothing_when_no_type_is_active(): void
    {
        $this->member('M1', $this->zoneA);
        $this->type(['amount' => '', 'status' => 'non_configure']);
        $this->type(['periodicity' => '']);
        $sched = new Scheduler($this->db);
        CallService::register($sched, $this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))));
        $this->db->query("DELETE FROM scheduled_runs WHERE task = 'contributions.generate_calls'");
        $this->assertSame('ran', $sched->run(time())['contributions.generate_calls']);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        // Type actif valide mais paramètre non validé, puis faux : rien.
        $t = $this->type();
        foreach ([null, false] as $v) {
            if ($v !== null) {
                (new \App\Core\Settings($this->db))->validate('contributions', 'auto_generate', $v, $this->admin, 'PV-AUTO');
            }
            $this->db->query("DELETE FROM scheduled_runs WHERE task = 'contributions.generate_calls'");
            $sched->run(time());
            $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        }
        (new \App\Core\Settings($this->db))->validate('contributions', 'auto_generate', true, $this->admin, 'PV-AUTO');
        // Avec un type actif valide et le paramètre validé : un appel pour la période courante seulement, et pas de doublon au passage suivant.
        $this->db->query("DELETE FROM scheduled_runs WHERE task = 'contributions.generate_calls'");
        $sched->run(time());
        $this->db->query("DELETE FROM scheduled_runs WHERE task = 'contributions.generate_calls'");
        $sched->run(time());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls WHERE contribution_type_id = ?', [$t]));
        $this->assertSame(Periods::containing('mensuelle', $this->today())['start'], $this->db->fetchValue('SELECT period_start FROM contribution_calls WHERE contribution_type_id = ?', [$t]));
    }

    public function test_period_computation_is_pure_and_calendar_aligned(): void
    {
        $d = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s, new DateTimeZone('Africa/Abidjan'));
        $this->assertSame(['label' => 'Février 2028', 'start' => '2028-02-01', 'end' => '2028-02-29'], Periods::containing('mensuelle', $d('2028-02-17')));
        $this->assertSame(['label' => 'T2 2026', 'start' => '2026-04-01', 'end' => '2026-06-30'], Periods::containing('trimestrielle', $d('2026-05-31')));
        $this->assertSame(['label' => 'S2 2026', 'start' => '2026-07-01', 'end' => '2026-12-31'], Periods::containing('semestrielle', $d('2026-12-31')));
        $this->assertSame(['label' => '2026', 'start' => '2026-01-01', 'end' => '2026-12-31'], Periods::containing('annuelle', $d('2026-06-15')));
        $this->assertSame(Periods::containing('mensuelle', $d('2026-03-01')), Periods::containing('mensuelle', $d('2026-03-31')));
        $this->assertSame(['label' => 'Appel du 05/01/2026', 'start' => '2026-01-05', 'end' => '2026-01-05'], Periods::single($d('2026-01-05')));
        $this->expectException(\InvalidArgumentException::class);
        Periods::containing('hebdomadaire', $d('2026-01-05'));
    }

    public function test_screens_permissions_and_escaping(): void
    {
        $m = $this->member('M1', $this->zoneA);
        $t = $this->type(['name' => '<b>Cot</b>']);
        foreach ([$this->viewer, $this->manager] as $u) {
            $this->actingAs((int) $u);
            foreach (['/admin/cotisations/types', '/admin/cotisations/appels', "/admin/adherents/$m?onglet=cotisations"] as $url) {
                $res = $this->request('GET', $url);
                $this->assertSame(200, $res->status(), $url);
                $this->assertSame(1, substr_count($res->body(), '<h1'), $url);
                $this->assertStringNotContainsString('style="', $res->body(), $url);
                $this->assertStringNotContainsString('<b>Cot</b>', $res->body(), $url);
            }
        }
        $this->actingAs((int) $this->viewer);
        $this->assertStringContainsString('Aucun appel de cotisation', $this->request('GET', "/admin/adherents/$m?onglet=cotisations")->body());
        foreach (['/admin/cotisations/types/nouveau', '/admin/cotisations/appels/individuel', '/admin/cotisations/appels/lot'] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status(), $url);
        }
        $this->assertSame(403, $this->request('POST', '/admin/cotisations/appels/lot', ['type_id' => (string) $t, 'start' => $this->today()->format('Y-m-d')])->status());
        // Lot : la confirmation compte sans rien créer, puis le POST final crée.
        $this->actingAs((int) $this->manager);
        $body = $this->request('POST', '/admin/cotisations/appels/lot/confirmation', ['type_id' => (string) $t, 'start' => $this->today()->format('Y-m-d')])->body();
        $this->assertStringContainsString('en attente de validation par les instances', $body);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        $this->assertSame(302, $this->request('POST', '/admin/cotisations/appels/lot', ['type_id' => (string) $t, 'start' => $this->today()->format('Y-m-d')])->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls'));
        $this->assertSame(302, $this->request('POST', '/admin/cotisations/types/nouveau', ['name' => 'Droit', 'kind' => 'droit_adhesion', 'status' => 'non_configure'])->status());
    }

    public function test_batch_rerun_creates_nothing_and_burns_no_ids(): void
    {
        $this->member('M1', $this->zoneA);
        $this->member('M2', $this->zoneA);
        $t = $this->type();
        $this->calls()->generateBatch($t, $this->today(), $this->admin);
        $next = fn (): int => (int) $this->db->fetchValue("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contribution_calls'");
        $before = $next();
        $this->assertSame(['created' => 0, 'skipped' => 2], $this->calls()->generateBatch($t, $this->today(), $this->admin));
        $this->assertSame($before, $next());
    }

    public function test_call_refused_when_credited_account_deactivated(): void
    {
        $m = $this->member('M1', $this->zoneA);
        $t = $this->type();
        $this->db->update('financial_accounts', ['is_active' => 0], ['id' => $this->account]);
        foreach ([fn () => $this->calls()->createForMember($m, $t, ['start' => $this->today()->format('Y-m-d')], $this->admin),
            fn () => $this->calls()->generateBatch($t, $this->today(), $this->admin)] as $f) {
            try {
                $f();
                $this->fail('appel accepté');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_type_with_calls_keeps_kind_periodicity_and_amount(): void
    {
        $m = $this->member('M1', $this->zoneA);
        $t = $this->type(['name' => 'Fige']);
        $id = $this->calls()->createForMember($m, $t, ['start' => $this->today()->format('Y-m-d')], $this->admin);
        $base = ['name' => 'Fige', 'kind' => 'periodique', 'amount' => '5000', 'periodicity' => 'mensuelle', 'authorization_ref' => 'PV-1', 'affects_account_id' => $this->account, 'status' => 'actif'];
        foreach ([['amount' => '6000'], ['periodicity' => 'annuelle'], ['kind' => 'droit_adhesion']] as $change) {
            try {
                $this->types()->save($t, $change + $base, $this->admin);
                $this->fail('modification acceptée');
            } catch (DomainException $e) {
                $this->assertStringContainsString('déjà des appels', $e->getMessage());
            }
        }
        $this->types()->save($t, ['name' => 'Fige 2', 'authorization_ref' => 'PV-2'] + $base, $this->admin);
        $this->types()->save($t, ['status' => 'non_configure'] + $base, $this->admin);
        // Une fois l'appel annulé, la modification redevient possible.
        $this->calls()->cancel($id, 'Erreur', $this->admin);
        $this->types()->save($t, ['amount' => '6000'] + $base, $this->admin);
        $this->assertSame('6000.00', $this->db->fetchValue('SELECT amount FROM contribution_types WHERE id = ?', [$t]));
    }

    public function test_cron_skips_type_with_inactive_account_and_serves_the_others(): void
    {
        $this->member('M1', $this->zoneA);
        $badAccount = $this->db->insert('financial_accounts', ['name' => 'Cot bad ' . bin2hex(random_bytes(3))]);
        $bad = $this->type(['affects_account_id' => $badAccount]);
        $good = $this->type();
        $this->db->update('financial_accounts', ['is_active' => 0], ['id' => $badAccount]);
        (new \App\Core\Settings($this->db))->validate('contributions', 'auto_generate', true, $this->admin, 'PV-AUTO');
        $sched = new Scheduler($this->db);
        CallService::register($sched, $this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))));
        $this->db->query("DELETE FROM scheduled_runs WHERE task = 'contributions.generate_calls'");
        $this->assertSame('ran', $sched->run(time())['contributions.generate_calls']);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls WHERE contribution_type_id = ?', [$good]));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM contribution_calls WHERE contribution_type_id = ?', [$bad]));
    }

    public function test_auto_banner_follows_setting_and_gate_rejects_non_true_values(): void
    {
        $settings = new \App\Core\Settings($this->db);
        $this->assertFalse(CallService::autoEnabled($this->db));
        $this->assertStringContainsString('désactivée', CallService::autoBanner($this->db));
        foreach ([0, 'false', false, 'true', 1] as $v) {
            $settings->validate('contributions', 'auto_generate', $v, $this->admin, 'PV-AUTO');
            $this->assertFalse(CallService::autoEnabled($this->db), var_export($v, true));
        }
        $settings->validate('contributions', 'auto_generate', true, $this->admin, 'PV-AUTO');
        $this->assertTrue(CallService::autoEnabled($this->db));
        $this->assertStringContainsString('est active', CallService::autoBanner($this->db));
        $this->actingAs((int) $this->viewer);
        $this->assertStringContainsString('est active', $this->request('GET', '/admin/cotisations/appels')->body());
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'contributions' AND `key` = 'auto_generate'");
        $this->assertStringContainsString('désactivée', $this->request('GET', '/admin/cotisations/appels')->body());
    }
}
