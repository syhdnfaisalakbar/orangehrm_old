<?php
/**
 * _xlsx_writer.php — penulis XLSX minimal tanpa dependency (tanpa PhpSpreadsheet).
 *
 * Dipakai untuk membuat template upload kandidat (candidate_import_template.php).
 * Hanya butuh ext-zip. Semua sel ditulis sebagai inline string; baris pertama
 * tiap sheet otomatis bold kalau 'bold_header' !== false.
 *
 * Pemakaian:
 *   $bin = xlsx_build([
 *       ['name' => 'Kandidat', 'cols' => [22, 16, 22], 'rows' => [[...], [...]]],
 *       ['name' => 'Petunjuk', 'cols' => [30, 90], 'rows' => [[...]]],
 *   ]);
 */

if (!function_exists('xlsx_build')) {
    function xlsx_esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    function xlsx_col_name(int $index): string
    {
        $index++;
        $name = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $name = chr(65 + $rem) . $name;
            $index = (int) (($index - $rem) / 26);
        }
        return $name;
    }

    /**
     * @param array<int, array{name:string, rows:array<int, array<int, string|int|float|null>>, cols?:array<int,int|float>, bold_header?:bool}> $sheets
     */
    function xlsx_build(array $sheets): string
    {
        if (empty($sheets)) {
            throw new RuntimeException('Tidak ada sheet untuk ditulis.');
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $_) {
            $n = $i + 1;
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $contentTypes .= '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $name = xlsx_esc((string) ($sheet['name'] ?? ('Sheet' . $n)));
            $workbook .= '<sheet name="' . $name . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $stylesRid = count($sheets) + 1;
        $wbRels .= '<Relationship Id="rId' . $stylesRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $workbook .= '</sheets></workbook>';
        $wbRels .= '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF666666"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
            . '<alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsxb');
        if ($tmp === false) {
            throw new RuntimeException('Gagal membuat file sementara.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat arsip XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/styles.xml', $styles);

        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $rows = $sheet['rows'] ?? [];
            $boldHeader = ($sheet['bold_header'] ?? true) !== false;
            $cols = $sheet['cols'] ?? [];

            // Lebar kolom: <cols> WAJIB sebelum <sheetData>
            $colsXml = '';
            if (!empty($cols)) {
                $colsXml = '<cols>';
                foreach (array_values($cols) as $ci => $width) {
                    $colsXml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="'
                        . rtrim(rtrim(number_format((float) $width, 2, '.', ''), '0'), '.')
                        . '" customWidth="1"/>';
                }
                $colsXml .= '</cols>';
            }

            $maxCols = 1;
            foreach ($rows as $r) {
                $maxCols = max($maxCols, is_array($r) ? count($r) : 1);
            }
            $dimension = 'A1:' . xlsx_col_name($maxCols - 1) . max(1, count($rows));

            $sheetData = '<sheetData>';
            foreach (array_values($rows) as $ri => $r) {
                $rowNumber = $ri + 1;
                $style = ($boldHeader && $ri === 0) ? '1' : '0';
                $sheetData .= '<row r="' . $rowNumber . '">';
                foreach (array_values(is_array($r) ? $r : [$r]) as $ci => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $ref = xlsx_col_name($ci) . $rowNumber;
                    $text = (string) $value;
                    $sheetData .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '">'
                        . '<is><t xml:space="preserve">' . xlsx_esc($text) . '</t></is></c>';
                }
                $sheetData .= '</row>';
            }
            $sheetData .= '</sheetData>';

            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="' . $dimension . '"/>'
                . '<sheetViews><sheetView workbookViewId="0"' . ($boldHeader ? ' tabSelected="1"' : '') . '>'
                . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>'
                . '<sheetFormatPr defaultRowHeight="15"/>'
                . $colsXml
                . $sheetData
                . '</worksheet>';

            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $sheetXml);
        }

        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }
}
