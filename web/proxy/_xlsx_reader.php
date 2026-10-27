<?php
/**
 * _xlsx_reader.php — pembaca XLSX minimal tanpa dependency (tanpa PhpSpreadsheet).
 *
 * Dipakai oleh preview_quota_upload.php. Hanya butuh ext-zip + ext-dom/simplexml
 * yang sudah wajib di composer.json project ini.
 *
 * Batasan yang disengaja:
 *  - hanya format .xlsx (Office Open XML). .xls lama (BIFF) DITOLAK.
 *  - hanya sheet pertama yang dibaca.
 *  - nilai sel dikembalikan sebagai string apa adanya (tanpa format tanggal).
 */

/** Batas ukuran file upload (byte). */
const XLSX_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Validasi file upload sebelum di-parse.
 *
 * @return string|null pesan error, atau null kalau lolos
 */
function xlsx_validate_upload(array $file, int $maxBytes = XLSX_MAX_BYTES): ?string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return 'File tidak ter-upload dengan benar.';
    }
    if (!empty($file['error'])) {
        return 'Upload gagal (kode ' . (int) $file['error'] . ').';
    }
    if (($file['size'] ?? 0) <= 0) {
        return 'File kosong.';
    }
    if ($file['size'] > $maxBytes) {
        return 'Ukuran file melebihi ' . round($maxBytes / 1024 / 1024, 1) . ' MB.';
    }

    $name = strtolower((string) ($file['name'] ?? ''));
    if (!preg_match('/\.xlsx$/', $name)) {
        if (preg_match('/\.xls$/', $name)) {
            return 'Format .xls lama tidak didukung. Buka di Excel lalu "Save As" ke .xlsx.';
        }
        return 'Hanya file .xlsx yang diterima.';
    }

    // Magic byte ZIP: XLSX selalu arsip ZIP.
    $fh = fopen($file['tmp_name'], 'rb');
    $sig = $fh ? fread($fh, 4) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($sig !== "PK\x03\x04") {
        return 'Isi file bukan arsip XLSX yang valid (magic byte ZIP tidak cocok).';
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        return 'File tidak bisa dibuka sebagai arsip XLSX.';
    }
    $hasWorkbook = $zip->locateName('xl/workbook.xml') !== false;
    $zip->close();
    if (!$hasWorkbook) {
        return 'Struktur XLSX tidak lengkap (xl/workbook.xml tidak ditemukan).';
    }

    return null;
}

/** Kolom "C" -> 2 (0-based). */
function xlsx_col_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/**
 * Baca sheet pertama jadi array baris.
 *
 * @return array<int, array<int, string>> [rowNumber => [colIndex => value]]
 */
function xlsx_read_rows(string $path, int $maxRows = 5000): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Tidak bisa membuka file XLSX.');
    }

    // --- shared strings ---
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false && $ssXml !== '') {
        $sx = @simplexml_load_string($ssXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    // rich text: gabungkan semua <r><t>
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }

    // --- sheet pertama ---
    $sheetPath = null;
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relsXml !== false) {
        $wb = @simplexml_load_string($wbXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb !== false && $rels !== false) {
            $rid = null;
            foreach ($wb->sheets->sheet ?? [] as $sheet) {
                $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string) ($attrs['id'] ?? '');
                break;
            }
            if ($rid !== '') {
                foreach ($rels->Relationship ?? [] as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                        break;
                    }
                }
            }
        }
    }
    if ($sheetPath === null || $zip->locateName($sheetPath) === false) {
        for ($i = 1; $i <= 20; $i++) {
            $candidate = 'xl/worksheets/sheet' . $i . '.xml';
            if ($zip->locateName($candidate) !== false) {
                $sheetPath = $candidate;
                break;
            }
        }
    }
    if ($sheetPath === null) {
        $zip->close();
        throw new RuntimeException('Sheet pertama tidak ditemukan di file XLSX.');
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Isi sheet tidak bisa dibaca.');
    }

    $doc = new DOMDocument();
    if (!@$doc->loadXML($sheetXml)) {
        throw new RuntimeException('XML sheet tidak valid.');
    }

    $rows = [];
    $xmlRows = $doc->getElementsByTagNameNS('*', 'row');
    foreach ($xmlRows as $rowNode) {
        if (count($rows) >= $maxRows) {
            break;
        }
        $rowNumber = (int) $rowNode->getAttribute('r');
        $cells = [];
        $hasValue = false;

        foreach ($rowNode->getElementsByTagNameNS('*', 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            $type = $cell->getAttribute('t');
            $colIdx = xlsx_col_index($ref);

            $value = '';
            if ($type === 'inlineStr') {
                foreach ($cell->getElementsByTagNameNS('*', 't') as $t) {
                    $value .= $t->textContent;
                }
            } else {
                $vNodes = $cell->getElementsByTagNameNS('*', 'v');
                if ($vNodes->length > 0) {
                    $raw = $vNodes->item(0)->textContent;
                    if ($type === 's') {
                        $idx = (int) $raw;
                        $value = $shared[$idx] ?? '';
                    } else {
                        $value = $raw;
                    }
                }
            }

            $value = trim($value);
            if ($value !== '') {
                $hasValue = true;
            }
            $cells[$colIdx] = $value;
        }

        if ($hasValue) {
            $rows[$rowNumber > 0 ? $rowNumber : count($rows) + 1] = $cells;
        }
    }

    return $rows;
}
