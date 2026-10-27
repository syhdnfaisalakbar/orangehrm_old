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
 *   rows[] = {row, branch, department, year, total_person, status, message,
 *             current_manual, current_total_additional}   // current_* untuk info "sebelum -> sesudah"
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_xlsx_reader.php';
require_once __DIR__ . '/_quota_upload_lib.php';

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

// Info angka manual yang sekarang tersimpan, supaya UI bisa menampilkan
// "sebelum -> sesudah". Sumbernya ledger upload (SUM per dept+tahun).
try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmtManual = $pdo->prepare(
        'SELECT COALESCE(SUM(total_person), 0) AS manual_total
           FROM ohrm_ext_department_quota_manual
          WHERE TRIM(dept_name) = :dept AND year = :year'
    );
    $stmtBase = $pdo->prepare(
        'SELECT total_additional
           FROM ohrm_ext_department_quota
          WHERE TRIM(dept_name) = :dept AND year = :year
          LIMIT 1'
    );

    foreach ($results as &$row) {
        $row['current_manual'] = 0;
        $row['current_total_additional'] = 0;
        $row['current_branch'] = null;

        if ($row['status'] !== 'ok') {
            continue;
        }
        $stmtManual->execute([':dept' => $row['department'], ':year' => $row['year']]);
        $row['current_manual'] = (int) $stmtManual->fetchColumn();

        $stmtBase->execute([':dept' => $row['department'], ':year' => $row['year']]);
        $base = $stmtBase->fetch(PDO::FETCH_ASSOC);
        if ($base) {
            $row['current_total_additional'] = (int) $base['total_additional'];
        }
    }
    unset($row);
} catch (Throwable $e) {
    // preview tetap boleh jalan walau DB tidak bisa dibaca; UI akan tampil 0
    error_log('[preview_quota_upload] DB: ' . $e->getMessage());
}

$okCount = 0;
foreach ($results as $r) {
    if ($r['status'] === 'ok') {
        $okCount++;
    }
}

echo json_encode([
    'status' => 'success',
    'header_row' => $header['row'],
    'summary' => [
        'total' => count($results),
        'ok' => $okCount,
        'error' => count($results) - $okCount,
    ],
    'rows' => $results,
]);
