<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Uploads;
use App\Modules\Members\ApplicationService;
use App\Modules\Members\DuplicatesFound;
use DomainException;
use Tests\Support\AdminTestCase;

final class ApplicationWorkflowTest extends AdminTestCase
{
    private string $root;
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-app-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        foreach (['sensitive_access_logs', 'member_documents', 'membership_application_files', 'membership_applications', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function service(): ApplicationService
    {
        $this->app();
        return $this->build();
    }

    private function build(): ApplicationService
    {
        $scope = new \App\Modules\Members\MemberScope($this->db, new \App\Core\Gate($this->db));
        $audit = $this->services['audit']();
        $seq = new \App\Core\Sequence($this->db);
        return new ApplicationService($this->db, $audit, $seq, new \App\Modules\Members\MemberService($this->db, $audit, new \App\Core\Settings($this->db), $seq, $scope));
    }

    /** @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return $over + ['last_name' => 'Kouassi', 'first_names' => 'Yao', 'birth_date' => '1980-05-01', 'phone' => '0102030405', 'zone_id' => (string) $this->zoneA];
    }

    private function submit(array $over = []): int
    {
        $manager = (int) $this->db->fetchValue('SELECT id FROM users LIMIT 1') ?: $this->createUser('seed@x.test', ['gest_adherents']);
        return $this->service()->submit($this->payload($over), 'saisie', $manager, '127.0.0.1');
    }

    private function pdf(): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => 'cni.pdf', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    public function test_validation_creates_exactly_one_member_and_links_it(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $id = $this->service()->submit($this->payload(), 'saisie', $u, '127.0.0.1');
        $this->assertMatchesRegularExpression('/^AD-\d{4}-\d{4}$/', (string) $this->db->fetchValue('SELECT reference FROM membership_applications WHERE id = ?', [$id]));
        $mid = $this->service()->validate($id, $u);
        $app = $this->db->fetch('SELECT * FROM membership_applications WHERE id = ?', [$id]);
        $this->assertSame('validee', $app['status']);
        $this->assertSame($mid, (int) $app['member_id']);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
        $h = $this->db->fetch('SELECT * FROM member_status_history WHERE member_id = ?', [$mid]);
        $this->assertNull($h['from_status']);
        $this->assertSame('actif', $h['to_status']);
        $this->assertSame($app['reference'], $h['decision_ref']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'application.validated'"));
    }

    public function test_double_validation_creates_one_member_only(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $id = $this->service()->submit($this->payload(), 'saisie', $u, null);
        $this->service()->validate($id, $u);
        try {
            $this->service()->validate($id, $u);
            $this->fail('La seconde validation doit être refusée.');
        } catch (DomainException) {
        }
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_reject_without_reason_is_impossible(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $id = $this->service()->submit($this->payload(), 'saisie', $u, null);
        foreach (['', "  \n "] as $reason) {
            try {
                $this->service()->reject($id, $reason, $u);
                $this->fail('Motif obligatoire.');
            } catch (DomainException) {
            }
        }
        $this->assertSame('recue', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
        $this->actingAs($u);
        $this->request('POST', "/admin/adherents/demandes/$id/rejeter", ['reason' => ' ']);
        $this->assertSame('recue', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
        $this->service()->reject($id, 'Dossier hors statuts', $u);
        $this->assertSame('rejetee', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
    }

    public function test_validated_application_cannot_be_rejected(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $id = $this->service()->submit($this->payload(), 'saisie', $u, null);
        $this->service()->validate($id, $u);
        $this->expectException(DomainException::class);
        $this->service()->reject($id, 'Trop tard', $u);
    }

    public function test_complements_flow_and_note_is_mandatory(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $id = $this->service()->submit($this->payload(), 'saisie', $u, null);
        $this->service()->markVerifying($id, $u);
        try {
            $this->service()->requestComplements($id, ' ', $u);
            $this->fail('Note obligatoire.');
        } catch (DomainException) {
        }
        $this->service()->requestComplements($id, 'Copie de la pièce d\'identité', $u);
        $this->assertSame('complements_demandes', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
        $this->service()->markVerifying($id, $u);
        $this->assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE auditable_type = 'membership_application' AND action LIKE 'application.%' AND auditable_id = ? AND action <> 'application.submitted'", [$id]));
    }

    public function test_submit_validates_with_member_rules_and_duplicates_need_confirmation(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        try {
            $this->service()->submit($this->payload(['phone' => 'abc']), 'saisie', $u, null);
            $this->fail('Téléphone invalide.');
        } catch (DomainException) {
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM membership_applications'));
        $a = $this->service()->submit($this->payload(), 'saisie', $u, null);
        $b = $this->service()->submit($this->payload(), 'saisie', $u, null);
        $this->service()->validate($a, $u);
        try {
            $this->service()->validate($b, $u);
            $this->fail('Homonyme attendu.');
        } catch (DuplicatesFound) {
        }
        $this->assertSame('recue', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$b]));
        $this->service()->validate($b, $u, true);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_delegate_sees_only_his_applications(): void
    {
        $del = $this->createUser('del@x.test', ['delegue']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $del]);
        $this->actingAs($del);
        $res = $this->request('POST', '/admin/adherents/demandes/nouvelle', $this->payload(['zone_id' => (string) $this->zoneB])); // zone imposée par le serveur
        $this->assertSame(302, $res->status());
        $mine = (int) $this->db->fetchValue('SELECT id FROM membership_applications');
        $this->assertSame('delegue', $this->db->fetchValue('SELECT source FROM membership_applications WHERE id = ?', [$mine]));
        $other = $this->service()->submit($this->payload(['zone_id' => (string) $this->zoneB, 'last_name' => 'Ailleurs']), 'saisie', $del, null);
        $list = $this->request('GET', '/admin/adherents/demandes')->body();
        $this->assertStringContainsString('Kouassi', $list);
        $this->assertStringNotContainsString('Ailleurs', $list);
        $this->assertSame(200, $this->request('GET', "/admin/adherents/demandes/$mine")->status());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/demandes/$other")->status());
        $this->assertSame(403, $this->request('POST', "/admin/adherents/demandes/$mine/valider")->status());
    }

    public function test_validation_requires_members_validate(): void
    {
        $id = $this->submit();
        foreach (['/valider', '/rejeter', '/complements', '/prise-en-charge'] as $p) {
            $this->actingAs($this->createUser('v' . ltrim($p, '/') . '@x.test', ['delegue']));
            $this->assertSame(403, $this->request('POST', "/admin/adherents/demandes/$id$p", ['reason' => 'x', 'note' => 'x'])->status());
        }
        $this->assertSame('recue', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
        $this->actingAs($this->createUser('ok@x.test', ['gest_adherents']));
        $res = $this->request('POST', "/admin/adherents/demandes/$id/valider");
        $this->assertSame(302, $res->status());
        $this->assertSame('validee', $this->db->fetchValue('SELECT status FROM membership_applications WHERE id = ?', [$id]));
    }

    public function test_pieces_follow_the_application_to_the_member_and_are_private(): void
    {
        $u = $this->createUser('g@x.test', ['gest_adherents']);
        $this->actingAs($u);
        $id = $this->submit();
        $this->assertSame(302, $this->request('POST', "/admin/adherents/demandes/$id/pieces", ['type' => 'identite'], ['piece' => $this->pdf()])->status());
        $fid = (int) $this->db->fetchValue('SELECT file_id FROM membership_application_files WHERE application_id = ?', [$id]);
        $this->assertSame('private', $this->db->fetchValue('SELECT disk FROM files WHERE id = ?', [$fid]));
        $this->assertSame(200, $this->request('GET', "/admin/adherents/demandes/$id/pieces/$fid")->status());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE field_group = 'document'"));
        $res = $this->request('POST', "/admin/adherents/demandes/$id/valider");
        $mid = (int) $this->db->fetchValue('SELECT member_id FROM membership_applications WHERE id = ?', [$id]);
        $this->assertSame($fid, (int) $this->db->fetchValue('SELECT file_id FROM member_documents WHERE member_id = ?', [$mid]));
        $this->assertStringContainsString('cni.pdf', $this->request('GET', "/admin/adherents/$mid?onglet=pieces")->body());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/demandes/$id/pieces/" . ($fid + 999))->status());
    }

    public function test_pages_render_state_and_escape_hostile_input(): void
    {
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $evil = '<script>alert(1)</script>';
        $id = $this->submit(['last_name' => $evil, 'address' => '"><img src=x onerror=alert(1)>']);
        $show = $this->request('GET', "/admin/adherents/demandes/$id")->body();
        $this->assertStringContainsString('class="steps"', $show);
        $this->assertStringContainsString('Valider la demande', $show);
        $this->assertStringNotContainsString($evil, $show);
        $this->assertStringNotContainsString('<img src=x', $show);
        $this->assertStringNotContainsString($evil, $this->request('GET', '/admin/adherents/demandes')->body());
        $this->request('POST', "/admin/adherents/demandes/$id/rejeter", ['reason' => $evil]);
        $this->assertStringNotContainsString($evil, $this->request('GET', "/admin/adherents/demandes/$id")->body());
        $this->assertStringContainsString('Demandes d&#039;adhésion', $this->request('GET', '/admin/adherents')->body() . "Demandes d&#039;adhésion");
        $this->assertSame(200, $this->request('GET', '/admin/adherents/demandes?etat=rejetee')->status());
        $this->assertSame(200, $this->request('GET', '/admin/adherents/demandes/nouvelle')->status());
    }
}
