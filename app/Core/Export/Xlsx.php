<?php
declare(strict_types=1);

namespace App\Core\Export;

use RuntimeException;
use ZipArchive;

/** Classeur XLSX minimal (une feuille, chaînes inlineStr). */
final class Xlsx
{
    public static function build(string $sheetName, array $headers, iterable $rows): string
    {
        $name = mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $sheetName), 0, 31);
        $name = $name === '' ? 'Feuille1' : $name;

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $n = 0;
        foreach ((function () use ($headers, $rows) { yield $headers; yield from $rows; })() as $row) {
            $n++;
            $sheet .= '<row r="' . $n . '">';
            $i = 0;
            foreach ($row as $v) {
                $ref = self::col($i++) . $n;
                if ($v === null) {
                    continue;
                }
                if (is_bool($v)) {
                    $v = (int) $v;
                }
                $sheet .= is_int($v) || is_float($v)
                    ? '<c r="' . $ref . '" t="n"><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml((string) $v) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $files = [
            '[Content_Types].xml' => $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $head . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::xml($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $head . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'mmmgci_xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire de l\'export.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer le classeur Excel.');
            }
            foreach ($files as $path => $content) {
                $zip->addFromString($path, $content);
            }
            if (!$zip->close()) {
                throw new RuntimeException('Impossible de finaliser le classeur Excel.');
            }
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function xml(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
