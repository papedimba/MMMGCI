<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;
use ZipArchive;

final class MemberExportTest extends AdminTestCase
{
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    private function member(string $n, string $last, int $zone, array $extra = []): int
    {
        return $this->db->insert('members', ['member_number' => $n, 'last_name' => $last, 'first_names' => 'Yao', 'zone_id' => $zone] + $extra);
    }

    private function exporter(): int
    {
        $u = $this->createUser('exp@x.test', ['secretaire_general']);
        $this->actingAs($u);
        return $u;
    }

    private function xlsxSheet(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $s = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);
        return $s;
    }

    public function test_export_requires_members_export_and_is_403_otherwise(): void
    {
        foreach (['/admin/adherents/export.csv', '/admin/adherents/export.xlsx', '/admin/adherents/imprimer'] as $url) {
            $this->assertSame(302, $this->request('GET', $url)->status(), $url);
        }
        $this->actingAs($this->createUser('pca@x.test', ['pca']));
        foreach (['/admin/adherents/export.csv', '/admin/adherents/export.xlsx', '/admin/adherents/imprimer'] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status(), $url);
        }
        $this->assertStringNotContainsString('export.csv', $this->request('GET', '/admin/adherents')->body());
        $this->actingAs($this->createUser('sg@x.test', ['secretaire_general']));
        $list = $this->request('GET', '/admin/adherents?status=actif');
        $this->assertStringContainsString('/admin/adherents/export.csv?status=actif', $list->body());
        foreach (['/admin/adherents/export.csv', '/admin/adherents/export.xlsx', '/admin/adherents/imprimer'] as $url) {
            $this->assertSame(200, $this->request('GET', $url)->status(), $url);
        }
    }

    public function test_csv_headers_columns_and_forbidden_fields(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA, ['joined_at' => '2024-03-05', 'phone' => '+22507000000', 'email' => 'k@x.test',
            'id_document_number' => 'CNI-SECRET-1', 'seaman_book_number' => 'LIVRET-SECRET-2', 'status' => 'actif']);
        $this->exporter();
        $res = $this->request('GET', '/admin/adherents/export.csv');
        $this->assertSame('text/csv; charset=UTF-8', $res->headers()['Content-Type']);
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $this->assertMatchesRegularExpression('/^attachment; filename="adherents-\d{8}\.csv"$/', $res->headers()['Content-Disposition']);
        $lines = explode("\r\n", $res->body());
        $this->assertSame("\xEF\xBB\xBFN°;Nom;Prénoms;Catégorie;Profession;Zone;Statut;Date d'adhésion;Téléphone;E-mail", $lines[0]);
        $this->assertStringStartsWith('M1;Kouassi;Yao;;;Abidjan;', $lines[1]);
        $this->assertStringContainsString('05/03/2024', $lines[1]);
        $this->assertStringContainsString("'+22507000000", $lines[1]); // texte commençant par « + » : apostrophe (sécurité d'abord)
        foreach (['CNI-SECRET', 'LIVRET-SECRET'] as $f) {
            $this->assertStringNotContainsString($f, $res->body());
        }
        $xlsx = $this->request('GET', '/admin/adherents/export.xlsx');
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->headers()['Content-Type']);
        $this->assertStringContainsString('no-store', $xlsx->headers()['Cache-Control']);
        $this->assertStringNotContainsString('SECRET', $this->xlsxSheet($xlsx->body()));
    }

    public function test_export_respects_delegate_scope(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA);
        $this->member('M2', 'Konan', $this->zoneB);
        $d = $this->createUser('del@x.test', ['delegue']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $d]);
        $this->db->query("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'members.export' WHERE r.name = 'delegue'");
        $this->actingAs($d);
        foreach (['csv' => $this->request('GET', '/admin/adherents/export.csv')->body(), 'xlsx' => $this->xlsxSheet($this->request('GET', '/admin/adherents/export.xlsx')->body()),
            'print' => $this->request('GET', '/admin/adherents/imprimer')->body()] as $fmt => $body) {
            $this->assertStringContainsString('Kouassi', $body, $fmt);
            $this->assertStringNotContainsString('Konan', $body, $fmt);
        }
        // Un filtre de zone forgé ne dépasse pas le périmètre.
        $this->assertStringNotContainsString('Konan', $this->request('GET', "/admin/adherents/export.csv?zone_id={$this->zoneB}")->body());
    }

    public function test_formula_like_cells_are_neutralised_in_csv_and_xlsx(): void
    {
        $this->member('M1', '=HYPERLINK("http://evil")', $this->zoneA, ['phone' => '@cmd', 'email' => '-1+1']);
        $this->exporter();
        $csv = $this->request('GET', '/admin/adherents/export.csv')->body();
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertStringContainsString("'-1+1", $csv);
        $sheet = $this->xlsxSheet($this->request('GET', '/admin/adherents/export.xlsx')->body());
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">&apos;=HYPERLINK(', $sheet);
        $this->assertStringContainsString('>&apos;@cmd</t>', $sheet);
        $this->assertStringContainsString('>&apos;-1+1</t>', $sheet);
        $this->assertDoesNotMatchRegularExpression('/<t[^>]*>[=+\-@]/', $sheet);
    }

    public function test_export_is_audited(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA);
        $this->member('M2', 'Konan', $this->zoneA);
        $u = $this->exporter();
        $this->request('GET', '/admin/adherents/export.csv?q=Kouassi&zone_id=' . $this->zoneA);
        $this->request('GET', '/admin/adherents/imprimer');
        $rows = $this->db->fetchAll("SELECT user_id, new_values FROM audit_logs WHERE action = 'member.exported' AND user_id = ? ORDER BY id", [$u]);
        $this->assertCount(2, $rows);
        $a = json_decode($rows[0]['new_values'], true);
        $this->assertSame($u, (int) $rows[0]['user_id']);
        $this->assertSame(1, $a['rows']);
        $this->assertSame('csv', $a['format']);
        $this->assertSame(['q' => true, 'zone_id' => (string) $this->zoneA], $a['criteria']);
        $this->assertStringNotContainsString('Kouassi', $rows[0]['new_values']);
        $b = json_decode($rows[1]['new_values'], true);
        $this->assertSame([2, 'print'], [$b['rows'], $b['format']]);
    }

    public function test_print_list_uses_print_layout(): void
    {
        $this->member('M1', 'Kouassi', $this->zoneA);
        $this->exporter();
        $res = $this->request('GET', '/admin/adherents/imprimer');
        $this->assertStringContainsString('Liste des adhérents', $res->body());
        $this->assertStringContainsString('class="sheet"', $res->body());
        $this->assertStringContainsString('Kouassi', $res->body());
        $this->assertStringContainsString('no-store', $res->headers()['Cache-Control']);
    }

    public function test_export_route_is_not_captured_by_id_route(): void
    {
        $this->exporter();
        $this->assertNotSame(404, $this->request('GET', '/admin/adherents/export.csv')->status());
        $this->assertSame(404, $this->request('GET', '/admin/adherents/export.pdf')->status());
    }

    public function test_export_streams_more_than_one_chunk(): void
    {
        $n = 2000;
        $this->db->query('SET SESSION max_recursive_iterations = 100000');
        $this->db->query("INSERT INTO members (member_number, last_name, first_names, zone_id, phone, email, joined_at) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < $n) SELECT CONCAT('B', i), CONCAT('Nom', i), 'Yao', {$this->zoneA}, '+2250700', 'a@x.test', '2024-01-01' FROM n");
        $this->exporter();
        $t = microtime(true);
        $lines = explode("\r\n", rtrim($this->request('GET', '/admin/adherents/export.csv')->body(), "\r\n"));
        $csv = microtime(true) - $t;
        $t = microtime(true);
        $this->request('GET', '/admin/adherents/export.xlsx');
        fwrite(STDERR, sprintf("\n[perf] export %d adhérents : CSV %.0f ms, XLSX %.0f ms\n", $n, $csv * 1000, (microtime(true) - $t) * 1000));
        $this->assertCount($n + 1, $lines);
        $this->assertCount($n + 1, array_unique($lines));
    }
}
