<?php
/**
 * save_candidate_import.php — simpan kandidat hasil upload Excel ke database.
 *
 * POST multipart/form-data
 *   file : file .xlsx (wajib)  — divalidasi ulang dari awal (tidak percaya preview)
 *
 * Perilaku:
 *  - baris status 'error'  -> tidak disimpan, dilaporkan
 *  - baris status 'skip'   -> dilewati (email sudah melamar di vacancy yang sama)
 *  - baris status 'ok'     -> INSERT kandidat + lamaran + riwayat aksi
 *  - Resume (link) diunduh dan disimpan sebagai attachment kandidat; kalau gagal,
 *    kandidat tetap dibuat dan kegagalannya dicatat sebagai peringatan per baris.
 *
 * Response JSON:
 *   {status:'success', summary:{total,inserted,skipped,failed,resume_attached},
 *    rows:[{row,candidate_id,full_name,status,message}], inserted_by}
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_xlsx_reader.php';
require_once __DIR__ . '/_candidate_import_lib.php';
require_once __DIR__ . '/_candidate_import_push.php';

// Push ke Acumatica bisa memakan waktu (PUT Description + Task + Note per Case).
@set_time_limit(900);

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

$applied = candidate_import_apply($pdo, $parsed['rows'], $user['emp_number']);
$counters = $applied['summary'];
$rows = $applied['rows'];

// ---------------------------------------------------------------- push Acumatica
$pushRequested = (($_POST['push'] ?? '') === '1');
$counters['pushed'] = 0;
$counters['push_failed'] = 0;

if ($pushRequested) {
    $insertedIds = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'inserted' && !empty($row['candidate_id'])) {
            $insertedIds[] = (int) $row['candidate_id'];
        }
    }

    if (empty($insertedIds)) {
        $counters['push_message'] = 'Tidak ada kandidat baru untuk di-push';
    } else {
        try {
            $pushResults = candidate_import_push($pdo, $insertedIds);
        } catch (Throwable $e) {
            $pushResults = [];
            $errorMessage = 'Push gagal: ' . $e->getMessage();
        }

        foreach ($rows as $idx => $row) {
            $cid = (int) ($row['candidate_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $result = $pushResults[$cid] ?? [
                'push_status' => 'error',
                'push_message' => $errorMessage ?? 'Push tidak dijalankan',
            ];
            $rows[$idx]['push_status'] = $result['push_status'];
            $rows[$idx]['push_message'] = $result['push_message'];
            if ($result['push_status'] === 'ok') {
                $counters['pushed']++;
            } elseif ($result['push_status'] === 'error') {
                $counters['push_failed']++;
            }
        }
    }
}

// Audit log
$logDir = __DIR__ . '/_cache';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
@file_put_contents(
    $logDir . '/candidate_import.log',
    date('c') . ' user=' . $user['name']
    . ' file=' . preg_replace('/[^\p{L}\p{N} ._-]/u', '', (string) ($_FILES['file']['name'] ?? ''))
    . ' total=' . $counters['total'] . ' masuk=' . $counters['inserted']
    . ' lewat=' . $counters['skipped'] . ' gagal=' . $counters['failed']
    . ' resume=' . $counters['resume_attached']
    . ' push=' . ($pushRequested ? 'ya' : 'tidak')
    . ' pushed=' . $counters['pushed'] . ' push_gagal=' . $counters['push_failed'] . PHP_EOL,
    FILE_APPEND
);

echo json_encode([
    'status' => 'success',
    'summary' => $counters,
    'rows' => $rows,
    'inserted_by' => $user['name'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
