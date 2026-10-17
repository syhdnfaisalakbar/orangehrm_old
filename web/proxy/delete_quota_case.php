<?php
require_once __DIR__ . '/_config.php';
// ============================================
// delete_quota_case.php
//
// Hapus Case Quota Request dari tabel lokal beserta attribute & approval-nya.
// Body POST: { "caseIds": ["0000008640", ...] }
//
// GUARD: hanya case yang approval task TERAKHIR-nya berstatus 'Open' yang
// dihapus (sesuai aturan menu Quota Request: case dengan approval Completed
// / Rejected / tanpa approval TIDAK boleh dihapus). Case non-Open di-skip.
//
// ⚠ LOKAL & PRAKTIS PERMANEN:
// Sync Acumatica bersifat INKREMENTAL (`CaseID gt <max case_id lokal>`),
// jadi case yang dihapus di sini UMUMNYA tidak akan ditarik kembali otomatis.
// Data di Acumatica TETAP ADA; ini hanya menghapus cermin lokalnya.
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$caseIds = $input['caseIds'] ?? [];
$caseIds = array_values(array_filter(
    array_map('strval', $caseIds),
    fn($c) => $c !== '' && $c !== '-'
));
$caseIds = array_slice($caseIds, 0, 200);

if (count($caseIds) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'caseIds wajib diisi.']);
    exit;
}

// Ambil note_id + status approval terakhir tiap case (guard Open-only)
$stmtInfo = $pdo->prepare(
    "SELECT q.note_id,
            (SELECT a.approval_status
               FROM ohrm_approval_history a
              WHERE a.ref_note_id = q.note_id
              ORDER BY a.approval_id DESC
              LIMIT 1) AS last_status
       FROM ohrm_quota_requests q
      WHERE q.case_id = :c"
);
$delAttr = $pdo->prepare("DELETE FROM ohrm_case_attributes WHERE ref_note_id = :n");
$delAppr = $pdo->prepare("DELETE FROM ohrm_approval_history WHERE ref_note_id = :n");
$delCase = $pdo->prepare("DELETE FROM ohrm_quota_requests WHERE case_id = :c");

$deleted = 0;
$skipped = 0;

try {
    $pdo->beginTransaction();
    foreach ($caseIds as $cid) {
        $stmtInfo->execute([':c' => $cid]);
        $row = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        // Hanya hapus jika ada kandidat DAN approval terakhir = Open
        $isOpen = $row !== false && strtolower((string)($row['last_status'] ?? '')) === 'open';
        if (!$isOpen) {
            $skipped++;
            continue;
        }

        $noteId = $row['note_id'];
        // Hapus children dulu (kolom biasa, tidak ada FK cascade otomatis)
        $delAttr->execute([':n' => $noteId]);
        $delAppr->execute([':n' => $noteId]);
        // Hapus master case
        $delCase->execute([':c' => $cid]);
        if ($delCase->rowCount() > 0) {
            $deleted++;
        } else {
            $skipped++;
        }
    }
    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'deleted' => $deleted,
        'skipped' => $skipped,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus case: ' . $e->getMessage()]);
}