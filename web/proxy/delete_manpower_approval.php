<?php
require_once __DIR__ . '/_config.php';
// ============================================
// delete_manpower_approval.php
//
// Hapus approval task yang masih OPEN dari tabel lokal ohrm_manpower_approval
// (skenario "Delete Selected" — LOKAL & SEMENTARA).
//
// ⚠ PENTING: karena ohrm_manpower_approval adalah MIRROR data Task Acumatica,
// task yang dihapus akan MUNCUL KEMBALI saat Sync Acumatica berikutnya — baik
// lewat tombol "Sync Acumatica" di UI maupun scheduler otomatis
// (sync_manpower_scheduled.php, tiap ~1 jam), selama task-nya masih ada &
// masih Open di Acumatica. Penghapusan di sini TIDAK menghapus task di Acumatica.
//
// Dua mode body POST:
//   1) BULK PER CASE (utama, dari tombol "Delete Selected" tabel FPTK):
//      { "caseIds": ["0000008626", ...] }  → hapus SEMUA task OPEN dari case tsb
//   2) PER TASK (legacy): { "caseId": "...", "taskIds": ["<NoteID>", ...] }
// Guard: hanya baris dengan status 'OPEN' yang boleh dihapus.
// ============================================
header('Content-Type: application/json');

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
$caseId  = $input['caseId'] ?? null;
$taskIds = $input['taskIds'] ?? [];

$where = "UPPER(COALESCE(status, '')) = 'OPEN'";
$params = [];

// ---- MODE 1: bulk per case ----
$caseIds = is_array($caseIds)
    ? array_values(array_filter(array_map('strval', $caseIds), fn($c) => $c !== '' && $c !== '-'))
    : [];
$caseIds = array_slice($caseIds, 0, 200);

if (count($caseIds) > 0) {
    // Hapus SEMUA task Open pada case yang dipilih
    $inP = [];
    foreach ($caseIds as $i => $c) {
        $k = ':c' . $i;
        $inP[] = $k;
        $params[$k] = $c;
    }
    $where .= " AND case_id IN (" . implode(',', $inP) . ")";
} elseif ($caseId !== null && $caseId !== '' && is_array($taskIds)) {
    // ---- MODE 2 (legacy): per task ----
    $taskIds = array_values(array_filter(array_map('strval', $taskIds), fn($t) => $t !== '' && $t !== '-'));
    $taskIds = array_slice($taskIds, 0, 200);
    if (count($taskIds) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada task yang dipilih untuk dihapus.']);
        exit;
    }
    $inP = [];
    $params[':cid'] = (string)$caseId;
    foreach ($taskIds as $i => $t) {
        $k = ':t' . $i;
        $inP[] = $k;
        $params[$k] = $t;
    }
    $where .= " AND case_id = :cid AND task_id IN (" . implode(',', $inP) . ")";
} else {
    echo json_encode(['status' => 'error', 'message' => 'caseIds (atau caseId + taskIds) wajib diisi.']);
    exit;
}

try {
    $sql = "DELETE FROM ohrm_manpower_approval WHERE " . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deleted = $stmt->rowCount();

    echo json_encode([
        'status' => 'success',
        'deleted' => $deleted,
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()]);
}