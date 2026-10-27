<?php
/**
 * preview_quota_upload.php — parse file Excel kuota TANPA menulis ke database.
 *
 * POST multipart/form-data
 *   file        : file .xlsx (wajib)
 *   uploaded_by : nama user untuk log (opsional)
 *
 * Response JSON:
 *   {status:'success', header_row, summary:{total,ok,error}, rows:[...]}
 *   rows[] = {row, branch, department, year, planned, total_person, status, message,
 *             current_manual, current_total_additional}   // current_* untuk info "sebelum -> sesudah"
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_xlsx_reader.php';
require_once __DIR__ . '/_quota_upload_lib.php';
require_once __DIR__ . '/_quota_approved.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$fail = static function (string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
};

if ($err = proxy_require_post()) {
    $fail($err, 405);
}
if ($err = proxy_require_same_origin()) {
    $fail($err, 403);
}
if (!isset($_FILES['file'])) {
    $fail('Tidak ada file yang dikirim.');
}
if ($err = xlsx_validate_upload($_FILES['file'])) {
    $fail($err);
}

try {
    $rows = xlsx_read_rows($_FILES['file']['tmp_name']);
} catch (Throwable $e) {
    $fail('Gagal membaca file: ' . $e->getMessage(), 422);
}

if (empty($rows)) {
    $fail('File tidak berisi data.', 422);
}

$header = quota_detect_header($rows);
if ($header === null) {
    $fail(
        'Header tidak dikenali. File harus punya kolom: branch, department, total person, year.',
        422
    );
}

$maxYear = (int) date('Y') + 2;
$seen = [];
$results = [];
$dataStart = false;

foreach ($rows as $rowNumber => $cells) {
    if (!$dataStart) {
        if ((int) $rowNumber === $header['row']) {
            $dataStart = true;
        }
        continue;
    }
    $results[] = quota_validate_row($cells, $header['map'], (int) $rowNumber, $seen, $maxYear);
}

if (empty($results)) {
    $fail('Tidak ada baris data di bawah header.', 422);
}

// Info angka "Planned" yang sekarang tersimpan, supaya UI bisa menampilkan
// "sebelum -> jadi". Sumbernya kolom planned di ohrm_ext_department_quota,
// ditambah kuota HRPLAN Acumatica yang sudah approved (kapasitas = planned + approved).
try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmtBase = $pdo->prepare(
        'SELECT COALESCE(planned, 0) AS planned, COALESCE(total_additional, 0) AS total_additional
           FROM ohrm_ext_department_quota
          WHERE TRIM(dept_name) = :dept AND year = :year
          LIMIT 1'
    );

    foreach ($results as &$row) {
        $row['current_planned'] = 0;
        $row['current_manual'] = 0; // nama lama, dipertahankan agar bundle lama tidak rusak
        $row['current_total_additional'] = 0;
        $row['exists'] = false;       // true = akan DILEWATI (dept+tahun sudah punya kuota)
        $row['row_exists'] = false;   // true = baris dept+tahun ada di DB (mis. dibuat sync Acumatica)
        $row['current_approved'] = 0; // HRPLAN Acumatica approved (ikut jadi kapasitas)
        $row['capacity_before'] = 0;  // planned + approved sebelum upload
        $row['capacity_after'] = 0;   // planned + approved kalau baris ini disimpan
        $row['skip'] = false;

        if ($row['status'] !== 'ok') {
            continue;
        }
        $stmtBase->execute([':dept' => $row['department'], ':year' => $row['year']]);
        $base = $stmtBase->fetch(PDO::FETCH_ASSOC);
        if ($base) {
            $row['row_exists'] = true;
            $row['current_planned'] = (int) $base['planned'];
            $row['current_manual'] = (int) $base['planned'];
            $row['current_total_additional'] = (int) $base['total_additional'];
        }
        $row['current_approved'] = quota_approved_for($pdo, (string) $row['department'], (int) $row['year']);
        $row['capacity_before'] = $row['current_planned'] + $row['current_approved'];
        $row['capacity_after'] = $row['capacity_before'] + (int) $row['planned'];

        // Aturan skip (pilihan Pak): department+tahun yang SUDAH PUNYA kuota tidak diisi lagi.
        // Baris kosong hasil sync Acumatica (planned 0, tanpa HRPLAN) tetap boleh diisi.
        if ($row['current_planned'] > 0 || $row['current_approved'] > 0) {
            $row['skip'] = true;
            $row['exists'] = true;
            $row['status'] = 'skip';
            $row['capacity_after'] = $row['capacity_before'];
            $rincian = [];
            if ($row['current_planned'] > 0) {
                $rincian[] = 'upload ' . $row['current_planned'];
            }
            if ($row['current_approved'] > 0) {
                $rincian[] = 'HRPLAN ' . $row['current_approved'];
            }
            $row['message'] = 'sudah punya kuota (' . implode(' + ', $rincian) . ') — dilewati';
        }
    }
    unset($row);
} catch (Throwable $e) {
    // preview tetap boleh jalan walau DB tidak bisa dibaca; UI akan tampil 0
    error_log('[preview_quota_upload] DB: ' . $e->getMessage());
}

$okCount = 0;        // akan disimpan
$skipCount = 0;      // dilewati (sudah punya kuota)
$fillCount = 0;      // baris sudah ada (mis. dari sync) tapi masih kosong -> diisi
$errorCount = 0;
foreach ($results as $r) {
    if ($r['status'] === 'ok') {
        $okCount++;
        if (!empty($r['row_exists'])) {
            $fillCount++;
        }
    } elseif ($r['status'] === 'skip') {
        $skipCount++;
    } else {
        $errorCount++;
    }
}

echo json_encode([
    'status' => 'success',
    'header_row' => $header['row'],
    'summary' => [
        'total' => count($results),
        'ok' => $okCount,
        'skip' => $skipCount,
        'error' => $errorCount,
        'existing' => $skipCount,   // nama lama: baris yang dilewati
        'new' => $okCount,
        'fill_empty' => $fillCount, // baris sudah ada tapi kuotanya masih 0
        'with_approved' => $skipCount,
    ],
    'rows' => $results,
]);
