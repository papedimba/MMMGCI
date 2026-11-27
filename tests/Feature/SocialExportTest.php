<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Social\SocialExport;
use Tests\Support\AdminTestCase;
use Tests\Support\SocialFixtures;
use ZipArchive;

final class SocialExportTest extends AdminTestCase
{
    use SocialFixtures;

    private int $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_export', 'so_export')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_export' AND p.name IN ('social.view', 'social.export')");
        $this->exporter = $this->socialUser('exporter', ['so_export'], $this->zoneA);
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }

    private function sheet(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp));
        $s = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);
        return $s;
    }

    public function test_export_requires_social_export(): void
    {
        $this->registerRequest();
        foreach (['csv', 'xlsx'] as $f) {
            self::assertSame(302, $this->request('GET', "/admin/demandes-sociales/export.$f")->status());
        }
        $this->as($this->plain); // social.view, pas d'export
        foreach (['csv', 'xlsx'] as $f) {
            self::assertSame(403, $this->request('GET', "/admin/demandes-sociales/export.$f")->status());
        }
        self::assertStringNotContainsString('export.csv', $this->request('GET', '/admin/demandes-sociales')->body());
        $this->as($this->exporter);
        self::assertStringContainsString('/admin/demandes-sociales/export.csv?status=recue', $this->request('GET', '/admin/demandes-sociales?status=recue')->body());
        foreach (['csv', 'xlsx'] as $f) {
            self::assertSame(200, $this->request('GET', "/admin/demandes-sociales/export.$f")->status());
        }
    }

    public function test_export_columns_are_fixed_and_contain_no_sensitive_text(): void
    {
        $id = $this->requestIn('cloturee');
        $this->db->query("UPDATE social_requests SET description = 'S-DESCRIPTION', documents_requested_text = 'S-PIECES', closure_note = 'S-CLOTURE', contains_medical_data = 1 WHERE id = ?", [$id]);
        $this->db->query("UPDATE social_decisions SET reason = 'S-MOTIF', member_message = 'S-MESSAGE' WHERE social_request_id = ?", [$id]);
        $this->db->query("UPDATE social_request_reviews SET comment = 'S-AVIS' WHERE social_request_id = ?", [$id]);
        $this->db->query("UPDATE social_request_events SET comment = 'S-EVENEMENT' WHERE social_request_id = ?", [$id]);
        $this->db->query("INSERT INTO social_request_notes (social_request_id, author_id, body) VALUES (?, ?, 'S-NOTE')", [$id, $this->manager]);
        $this->db->query("INSERT INTO social_request_medical (social_request_id, details) VALUES (?, 'S-SANTE')", [$id]);
        $this->as($this->exporter);
        $res = $this->request('GET', '/admin/demandes-sociales/export.csv');
        self::assertSame('text/csv; charset=UTF-8', $res->headers()['Content-Type']);
        self::assertStringContainsString('no-store', $res->headers()['Cache-Control']);
        $lines = explode("\r\n", $res->body());
        self::assertSame("\xEF\xBB\xBF" . implode(';', SocialExport::HEADERS), $lines[0]);
        $cells = str_getcsv($lines[1], ';');
        self::assertCount(12, $cells);
        self::assertMatchesRegularExpression('/^AS-\d{4}-\d{4}$/', $cells[0]);
        self::assertSame(['Clôturée', 'Accordée', 'Orientation'], [$cells[6], $cells[8], $cells[9]]);
        $x = $this->request('GET', '/admin/demandes-sociales/export.xlsx');
        self::assertStringContainsString('no-store', $x->headers()['Cache-Control']);
        foreach ([$res->body(), $this->sheet($x->body())] as $body) {
            foreach (['S-DESCRIPTION', 'S-PIECES', 'S-CLOTURE', 'S-MOTIF', 'S-MESSAGE', 'S-AVIS', 'S-EVENEMENT', 'S-NOTE', 'S-SANTE', 'Dossier complet', 'Demande d'] as $s) {
                self::assertStringNotContainsString($s, $body, $s);
            }
        }
    }

    public function test_export_neutralises_formulas_and_is_audited_without_search_text(): void
    {
        $this->registerRequest();
        $this->db->query("UPDATE zones SET name = '=HYPERLINK(\"http://evil\")' WHERE id = ?", [$this->zoneA]);
        $this->as($this->exporter);
        $csv = $this->request('GET', '/admin/demandes-sociales/export.csv?q=Kouassi&priority=normale&zone_id=' . $this->zoneB)->body();
        self::assertStringContainsString("\"'=HYPERLINK(", $csv);
        $sheet = $this->sheet($this->request('GET', '/admin/demandes-sociales/export.xlsx')->body());
        self::assertStringContainsString('&apos;=HYPERLINK(', $sheet);
        self::assertDoesNotMatchRegularExpression('/<t[^>]*>[=+\-@]/', $sheet);
        $rows = $this->db->fetchAll("SELECT new_values FROM audit_logs WHERE action = 'social.exported' AND user_id = ? ORDER BY id", [$this->exporter]);
        self::assertCount(2, $rows);
        $v = json_decode($rows[0]['new_values'], true);
        self::assertSame(['rows' => 1, 'format' => 'csv', 'criteria' => ['q' => true, 'priority' => 'normale']], $v); // zone ignorée sans members.scope.all
        self::assertStringNotContainsString('Kouassi', $rows[0]['new_values']);
        // Neutralisation aussi sur la référence et le libellé du type (valeurs forcées en base : la référence n'est jamais saisie, le libellé l'est par settings.validate).
        $this->db->query("UPDATE social_requests SET reference = '+SUM(A1)' WHERE member_id = ?", [$this->memberA]);
        $this->db->query("UPDATE social_request_types SET name = '@cmd' WHERE id = ?", [$this->typeId]);
        $csv = $this->request('GET', '/admin/demandes-sociales/export.csv')->body();
        self::assertStringContainsString("'+SUM(A1)", $csv);
        self::assertStringContainsString("'@cmd", $csv);
        self::assertDoesNotMatchRegularExpression('/(^|;|")[=+\-@]/m', explode("\r\n", $csv, 2)[1]);
        $sheet = $this->sheet($this->request('GET', '/admin/demandes-sociales/export.xlsx')->body());
        self::assertStringContainsString('&apos;+SUM(A1)', $sheet);
        self::assertStringContainsString('&apos;@cmd', $sheet);
        self::assertDoesNotMatchRegularExpression('/<t[^>]*>[=+\-@]/', $sheet);
        // Périmètre : la zone B n'apparaît jamais pour ce compte.
        $this->registerRequest(['member_id' => $this->memberB]);
        self::assertStringNotContainsString((string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$this->memberB]), $this->request('GET', '/admin/demandes-sociales/export.csv')->body());
        self::assertCount(2, array_filter(explode("\r\n", $this->request('GET', '/admin/demandes-sociales/export.csv')->body())));
    }
}
