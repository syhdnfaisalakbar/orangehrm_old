<?php
/**
 * preview_candidate_import.php — pratinjau upload kandidat Excel (TANPA menulis DB).
 *
 * POST multipart/form-data
 *   file : file .xlsx (wajib)
 *
 * Response JSON:
 *   {status:'success', header_row, summary:{total,ok,skip,error}, rows:[...]}
 *   rows[] = {row, full_name, email, contact_number, vacancy_name, case_id,
 *             vacancy_status, date_of_application, consent, resume_url,
 *             status:'ok'|'skip'|'error', message}
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_xlsx_reader.php';
require_once __DIR__ . '/_candidate_import_lib.php';

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

$user = candidate_import_current_user();
if (!$user['authenticated']) {
    $fail('Sesi OrangeHRM tidak terbaca. Login ulang lalu coba lagi.', 401);
}

try {
    $pdo = candidate_import_pdo();
} catch (Throwable $e) {
    $fail('Database tidak tersambung: ' . $e->getMessage(), 500);
}
$user['name'] = candidate_import_resolve_user_name($pdo, $user['emp_number']);

if (!isset($_FILES['file'])) {
    $fail('Tidak ada file yang dikirim.');
}
if ($err = xlsx_validate_upload($_FILES['file'])) {
    $fail($err);
}

try {
    $parsed = candidate_import_parse_file($pdo, $_FILES['file']['tmp_name']);
} catch (Throwable $e) {
    $fail('Gagal memproses file: ' . $e->getMessage(), 422);
}

// Resume belum diunduh saat pratinjau (bisa lambat); cukup info akan diunduh.
foreach ($parsed['rows'] as &$row) {
    $row['resume_status'] = $row['resume_url'] !== '' ? 'akan diunduh saat simpan' : '';
}
unset($row);

echo json_encode([
    'status' => 'success',
    'header_row' => $parsed['header_row'],
    'summary' => $parsed['summary'],
    'rows' => $parsed['rows'],
    'previewed_by' => $user['name'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
