<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Export\Csv;
use App\Core\Export\SvgBarChart;
use App\Core\Export\Xlsx;
use DOMDocument;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ExportTest extends TestCase
{
    public function test_csv_has_bom_semicolon_crlf_and_keeps_accents(): void
    {
        $out = Csv::build(['Nom', 'Prénom'], [["N'Guessan", 'Kouamé']]);
        $this->assertSame("\xEF\xBB\xBFNom;Prénom\r\nN'Guessan;Kouamé\r\n", $out);
    }

    public function test_csv_quotes_special_fields_and_accepts_generators(): void
    {
        $gen = (function () { yield ['a;b', 'dit "oui"', "l1\nl2"]; })();
        $this->assertSame("\xEF\xBB\xBFx;y;z\r\n\"a;b\";\"dit \"\"oui\"\"\";\"l1\nl2\"\r\n", Csv::build(['x', 'y', 'z'], $gen));
    }

    public function test_csv_neutralizes_formula_injection_but_not_numbers(): void
    {
        $out = Csv::build(['h'], [['=HYPERLINK("x")'], ['+1+1'], ['@a'], ["\tx"], ["\rx"], ['-cmd'], ['-500'], [-5], [150000], [1.5], [null], [true], [false], ['150000']]);
        $lines = explode("\r\n", $out);
        $this->assertSame('"\'=HYPERLINK(""x"")"', $lines[1]);
        $this->assertSame("'+1+1", $lines[2]);
        $this->assertSame("'@a", $lines[3]);
        $this->assertSame("'\tx", $lines[4]);
        $this->assertSame("\"'\rx\"", $lines[5]);
        $this->assertSame("'-cmd", $lines[6]);
        $this->assertSame(['-500', '-5', '150000', '1.5', '', '1', '0', '150000'], array_slice($lines, 7, 8));
    }

    public function test_csv_rejects_arrays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Csv::build(['h'], [[['x']]]);
    }

    public function test_xlsx_is_a_valid_zip_with_sheet_and_values(): void
    {
        $bytes = Xlsx::build('Membres [2026]: a/b', ['Nom', 'Montant'], [["Kouamé <&> \x01", 150000], ['=1+1', 2.5]]);
        $tmp = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp));
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $f) {
            $dom = new DOMDocument();
            $this->assertTrue($dom->loadXML((string) $zip->getFromName($f)), $f);
        }
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Kouamé &lt;&amp;&gt; ', $sheet);
        $this->assertStringContainsString('<v>150000</v>', $sheet);
        $this->assertStringContainsString('<c r="A3" t="inlineStr"><is><t xml:space="preserve">&apos;=1+1</t></is></c>', $sheet);
        $this->assertStringContainsString('name="Membres 2026 ab"', (string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($tmp);
    }

    public function test_xlsx_neutralizes_formula_strings_but_keeps_numbers(): void
    {
        $sheet = $this->sheet(Xlsx::build('S', ['h'], [['=SUM(A1)'], ['+22507'], ['  =x'], ['-5'], [-5], ['ok']]));
        $this->assertStringContainsString('>&apos;=SUM(A1)</t>', $sheet);
        $this->assertStringContainsString('>&apos;+22507</t>', $sheet);
        $this->assertStringContainsString('>&apos;  =x</t>', $sheet);
        $this->assertStringContainsString('>-5</t>', $sheet);
        $this->assertStringContainsString('t="n"><v>-5</v>', $sheet);
        $this->assertStringContainsString('>ok</t>', $sheet);
    }

    public function test_xlsx_columns_beyond_z_and_long_sheet_name(): void
    {
        $row = range(1, 28);
        $bytes = Xlsx::build(str_repeat('é', 40), ['h'], [$row]);
        $tmp = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $zip->open($tmp);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('r="Z2"', $sheet);
        $this->assertStringContainsString('r="AA2"', $sheet);
        $this->assertStringContainsString('r="AB2"', $sheet);
        $this->assertStringContainsString('name="' . str_repeat('é', 31) . '"', (string) $zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($tmp);
        $this->assertSame([], glob(sys_get_temp_dir() . '/mmmgci_xlsx*') ?: []);
    }

    public function test_svg_chart_has_title_and_data_table(): void
    {
        $html = SvgBarChart::render(['Jan', 'Fév <b>'], ['Cotisations' => [10, 20], 'Dons' => [5, 0]], 'Recettes "2026" & co');
        $this->assertSame($html, SvgBarChart::render(['Jan', 'Fév <b>'], ['Cotisations' => [10, 20], 'Dons' => [5, 0]], 'Recettes "2026" & co'));
        $this->assertStringContainsString('role="img"', $html);
        $this->assertMatchesRegularExpression('/aria-labelledby="([^"]+)"/', $html);
        preg_match('/aria-labelledby="([^"]+)"/', $html, $m);
        $this->assertStringContainsString('<title id="' . $m[1] . '">Recettes &quot;2026&quot; &amp; co</title>', $html);
        $this->assertStringContainsString('#1A3F86', $html);
        $this->assertStringContainsString('#D2AE55', $html);
        $this->assertStringContainsString('<caption>', $html);
        $this->assertStringContainsString('<th scope="col">Cotisations</th>', $html);
        $this->assertStringContainsString('<th scope="row">Fév &lt;b&gt;</th>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML('<root>' . $html . '</root>'));
    }

    public function test_svg_chart_empty_and_all_zero(): void
    {
        $empty = SvgBarChart::render([], [], 'Vide');
        $this->assertStringContainsString('Vide', $empty);
        $this->assertStringContainsString('Aucune donnée pour cette période.', $empty);
        $zero = SvgBarChart::render(['a', 'b'], ['s' => [0, 0]], 'Zéro');
        $this->assertStringContainsString('<svg', $zero);
        $this->assertStringNotContainsString('NAN', $zero);
        $this->assertStringNotContainsString('INF', $zero);
    }

    public function test_non_finite_floats_become_empty_cells(): void
    {
        $this->assertSame("\xEF\xBB\xBFh\r\n\r\n\r\n", Csv::build(['h'], [[INF], [NAN]]));
        $sheet = $this->sheet(Xlsx::build('S', ['h'], [[INF, NAN, 1]]));
        $this->assertStringNotContainsString('INF', $sheet);
        $this->assertStringNotContainsString('NAN', $sheet);
        $this->assertStringContainsString('<c r="C2" t="n"><v>1</v></c>', $sheet);
    }

    public function test_xlsx_array_cell_throws_and_leaves_no_temp_file(): void
    {
        $before = glob(sys_get_temp_dir() . '/mmmgci_xlsx*') ?: [];
        try {
            Xlsx::build('S', ['h'], [[['x']]]);
            $this->fail('exception attendue');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame($before, glob(sys_get_temp_dir() . '/mmmgci_xlsx*') ?: []);
        $this->expectException(InvalidArgumentException::class);
        Xlsx::build('S', ['h'], [[new \stdClass()]]);
    }

    public function test_invalid_utf8_is_substituted(): void
    {
        $this->assertStringContainsString("bad\u{FFFD} é", $this->sheet(Xlsx::build('S', ['h'], [["bad\xff é"]])));
        $this->assertStringContainsString("bad\u{FFFD} é", SvgBarChart::render(["bad\xff é"], ['s' => [1]], 'T'));
    }

    public function test_svg_numeric_strings_render_and_bad_values_throw(): void
    {
        $html = SvgBarChart::render(['a'], ['s' => ['10.50'], 't' => [' 5 ']], 'T');
        $this->assertStringContainsString('<td>10.5</td>', $html);
        foreach ([['abc'], [-1], [[1]], [NAN]] as $bad) {
            try {
                SvgBarChart::render(['a'], ['s' => $bad], 'T');
                $this->fail('exception attendue');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        SvgBarChart::render(['a'], ['s' => 5], 'T');
    }

    public function test_svg_id_can_be_overridden(): void
    {
        $a = SvgBarChart::render(['a'], ['s' => [1]], 'T');
        $b = SvgBarChart::render(['a'], ['s' => [1]], 'T', 'autre');
        $this->assertStringContainsString('aria-labelledby="autre"', $b);
        $this->assertNotSame($a, $b);
    }

    private function sheet(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $zip->open($tmp);
        $s = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($tmp);
        return $s;
    }
}
