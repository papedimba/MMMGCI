<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Uploads;
use Tests\Support\AdminTestCase;

final class MemberDocumentsTest extends AdminTestCase
{
    private string $root;
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-docs-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        foreach (['sensitive_access_logs', 'member_documents', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function member(string $n, int $zone): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => 'Kouassi', 'first_names' => 'Yao', 'zone_id' => $zone]);
    }

    /** @return array<string,mixed> */
    private function pdf(string $name = 'cni.pdf'): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    /** @return array<string,mixed> */
    private function png(): array
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, ob_get_clean());
        return ['name' => 'photo.png', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'image/png'];
    }

    private function deposit(int $memberId, string $type = 'identite'): int
    {
        $res = $this->request('POST', "/admin/adherents/$memberId/pieces", ['type' => $type], ['piece' => $this->pdf()]);
        $this->assertSame(302, $res->status());
        return (int) $this->db->fetchValue('SELECT file_id FROM member_documents WHERE member_id = ? ORDER BY id DESC LIMIT 1', [$memberId]);
    }

    public function test_document_download_is_logged_and_needs_documents_view(): void
    {
        $id = $this->member('M1', $this->zoneA);
        $this->actingAs($this->createUser('doc@x.test', ['gest_adherents']));
        $fid = $this->deposit($id);
        $this->assertSame('private', $this->db->fetchValue('SELECT disk FROM files WHERE id = ?', [$fid]));
        $this->assertStringContainsString('cni.pdf', $this->request('GET', "/admin/adherents/$id?onglet=pieces")->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        $res = $this->request('GET', "/admin/adherents/$id/pieces/$fid");
        $this->assertSame(200, $res->status());
        $this->assertStringStartsWith('%PDF-', $res->body());
        $this->assertSame('application/pdf', $res->headers()['Content-Type']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE subject_id = ? AND field_group = 'document'", [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'member.document_added'"));

        $this->actingAs($this->createUser('adm@x.test', ['admin_general'])); // members.view sans members.documents.view
        $this->assertSame(403, $this->request('GET', "/admin/adherents/$id/pieces/$fid")->status());
        $this->assertSame(403, $this->request('POST', "/admin/adherents/$id/pieces", ['type' => 'identite'], ['piece' => $this->pdf()])->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
    }

    public function test_document_of_out_of_scope_member_is_404(): void
    {
        $other = $this->member('M2', $this->zoneB);
        $this->actingAs($this->createUser('doc@x.test', ['gest_adherents']));
        $fid = $this->deposit($other);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('zone_docs', 'Docs de zone')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p
            WHERE r.name = 'zone_docs' AND p.name IN ('members.view', 'members.documents.view')");
        $u = $this->createUser('zd@x.test', ['zone_docs']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $u]);
        $this->actingAs($u);
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$other/pieces/$fid")->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$other/pieces", ['type' => 'identite'], ['piece' => $this->pdf()])->status());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$other/photo")->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
    }

    public function test_document_of_another_member_is_not_reachable_by_guessing_file_id(): void
    {
        $a = $this->member('M1', $this->zoneA);
        $b = $this->member('M2', $this->zoneA);
        $this->actingAs($this->createUser('doc@x.test', ['gest_adherents']));
        $fidB = $this->deposit($b);
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$a/pieces/$fidB")->status());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$a/pieces/999999")->status());
        $this->assertSame(200, $this->request('GET', "/admin/adherents/$b/pieces/$fidB")->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
    }

    public function test_upload_rejects_bad_type_and_bad_file_without_writing(): void
    {
        $id = $this->member('M1', $this->zoneA);
        $this->actingAs($this->createUser('doc@x.test', ['gest_adherents']));
        $bad = $this->pdf('x.exe');
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$id/pieces", ['type' => 'identite'], ['piece' => $bad])->status());
        $this->assertStringContainsString('Format non accepté', $this->request('GET', "/admin/adherents/$id?onglet=pieces")->body());
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$id/pieces", ['type' => 'inconnu'], ['piece' => $this->pdf()])->status());
        $this->assertStringContainsString('Type de pièce invalide', $this->request('GET', "/admin/adherents/$id?onglet=pieces")->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_documents'));
    }

    public function test_photo_is_private_and_served_only_through_the_controller(): void
    {
        $this->actingAs($this->createUser('g@x.test', ['gest_adherents']));
        $res = $this->request('POST', '/admin/adherents/nouveau', ['last_name' => 'Kouassi', 'first_names' => 'Yao', 'zone_id' => (string) $this->zoneA], ['photo' => $this->png()]);
        $this->assertSame(302, $res->status());
        $m = $this->db->fetch('SELECT id, photo_file_id FROM members');
        $this->assertSame('private', $this->db->fetchValue('SELECT disk FROM files WHERE id = ?', [$m['photo_file_id']]));
        $photo = $this->request('GET', '/admin/adherents/' . $m['id'] . '/photo');
        $this->assertSame(200, $photo->status());
        $this->assertSame('image/png', $photo->headers()['Content-Type']);
        $this->assertStringContainsString('/admin/adherents/' . $m['id'] . '/photo', $this->request('GET', '/admin/adherents/' . $m['id'])->body());
        $bad = $this->request('POST', '/admin/adherents/' . $m['id'] . '/modifier', ['last_name' => 'Kouassi', 'first_names' => 'Yao'], ['photo' => $this->pdf('p.pdf')]);
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('Format non accepté', $bad->body());
    }
}
