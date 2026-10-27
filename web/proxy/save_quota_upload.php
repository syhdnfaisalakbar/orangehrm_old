<?php
/**
 * save_quota_upload.php — simpan hasil upload Excel kuota ke database lokal.
 *
 * POST application/json
 *   {
 *     "uploaded_by": "Nama User",
 *     "file_name":   "kuota-2026.xlsx",
 *     "rows": [ {"department":"FNA","year":2026,"total_person":5,"branch":"JKT"}, ... ]
 *   }
 *
 * Sifat penulisan: MENGGANTI (tidak diakumulasi) pada ohrm_ext_department_quota.planned.
 *   planned = total_person  (upload ulang untuk dept+tahun yang sama MENIMPA angka lama)
 *   Jadi satu baris per department+tahun; angka langsung tampil di kolom "Planned"
 *   pada History Quota Department.
 *   Karena itu UI wajib menampilkan pratinjau "sebelum -> sesudah" sebelum menyimpan,
 *   supaya Pak sadar angka lama akan diganti.
 *
 * Yang TIDAK disentuh: total_additional, replacement, reduction, unplanned. Kolom-kolom
 *   itu milik sync Acumatica (save_quota.php), dan karena `planned` tidak ada di daftar
 *   ON DUPLICATE KEY UPDATE miliknya, angka upload tidak pernah tertimpa sync.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_guard.php';
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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['rows']) || !is_array($payload['rows'])) {
    $fail('Payload tidak berisi baris data.');
}

$fileName = preg_replace('/[^\p{L}\p{N} ._@-]/u', '', (string) ($payload['file_name'] ?? ''));
$fileName = mb_substr(trim($fileName), 0, 255);
$userName = preg_replace('/[^\p{L}\p{N} ._@-]/u', '', (string) ($payload['uploaded_by'] ?? 'unknown'));
$userName = mb_substr(trim($userName) !== '' ? trim($userName) : 'unknown', 0, 100);

// Validasi ulang di server dengan aturan yang sama seperti preview.
$map = ['branch' => 0, 'department' => 1, 'year' => 2, 'total_person' => 3];
$maxYear = (int) date('Y') + 2;
$seen = [];
$accepted = [];
$errors = [];

foreach ($payload['rows'] as $idx => $row) {
    $cells = [
        0 => (string) ($row['branch'] ?? ''),
        1 => (string) ($row['department'] ?? ''),
        2 => (string) ($row['year'] ?? ''),
        3 => (string) ($row['total_person'] ?? ''),
    ];
    $checked = quota_validate_row($cells, $map, (int) ($row['row'] ?? ($idx + 1)), $seen, $maxYear);
    if ($checked['status'] === 'ok') {
        $accepted[] = $checked;
    } else {
        $errors[] = ['row' => $checked['row'], 'message' => $checked['message']];
    }
}

if (empty($accepted)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Tidak ada baris valid untuk disimpan.',
        'errors' => $errors,
    ]);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare(
        'INSERT INTO ohrm_ext_department_quota
             (dept_name, year, total_additional, replacement, reduction, unplanned, planned, branch)
         VALUES (:dept, :year, 0, 0, 0, 0, :planned, :branch)
         ON DUPLICATE KEY UPDATE
             planned = VALUES(planned),
             branch  = IF(VALUES(branch) IS NULL OR VALUES(branch) = "", branch, VALUES(branch))'
    );

    $pdo->beginTransaction();
    $savedRows = 0;
    $savedTotal = 0;
    $replacedRows = 0;
    $affectedDepts = [];

    // Deteksi "sudah ada" sebelum menulis, supaya notifikasi bisa menyebut berapa
    // baris yang MENIMPA data lama (rowCount() ON DUPLICATE KEY tidak bisa membedakan
    // insert / update-tanpa-perubahan).
    $stmtExists = $pdo->prepare(
        'SELECT 1 FROM ohrm_ext_department_quota
          WHERE TRIM(dept_name) = :dept AND year = :year
          LIMIT 1'
    );

    foreach ($accepted as $row) {
        $stmtExists->execute([':dept' => $row['department'], ':year' => $row['year']]);
        $alreadyExists = (bool) $stmtExists->fetchColumn();

        $stmt->execute([
            ':dept' => $row['department'],
            ':year' => $row['year'],
            ':branch' => $row['branch'] !== '' ? $row['branch'] : null,
            ':planned' => $row['total_person'],
        ]);
        $savedRows++;
        $savedTotal += (int) $row['total_person'];
        if ($alreadyExists) {
            $replacedRows++;
        }
        $affectedDepts[mb_strtolower($row['department']) . '|' . $row['year']] = true;
    }

    $pdo->commit();

    $log = sprintf(
        "[%s] user=%s file=%s baris=%d baru=%d timpa=%d total_person=%d dept=%d ip=%s\n",
        date('Y-m-d H:i:s'),
        $userName,
        $fileName !== '' ? $fileName : '(tanpa nama)',
        $savedRows,
        $savedRows - $replacedRows,
        $replacedRows,
        $savedTotal,
        count($affectedDepts),
        $_SERVER['REMOTE_ADDR'] ?? '-'
    );
    @file_put_contents(__DIR__ . '/quota_upload_log.txt', $log, FILE_APPEND | LOCK_EX);

    echo json_encode([
        'status' => 'success',
        'message' => sprintf(
            '%d baris tersimpan: %d baru, %d menimpa data lama.',
            $savedRows,
            $savedRows - $replacedRows,
            $replacedRows
        ),
        'saved_rows' => $savedRows,
        'new_rows' => $savedRows - $replacedRows,
        'replaced_rows' => $replacedRows,
        'saved_total_person' => $savedTotal,
        'departments' => count($affectedDepts),
        'skipped' => $errors,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
}
