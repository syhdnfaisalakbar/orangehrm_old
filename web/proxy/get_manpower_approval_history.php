<?php
require_once __DIR__ . '/_config.php';
// ============================================
// get_manpower_approval_history.php
//
// Riwayat PERUBAHAN approval task Manpower Request (FPTK) untuk sebuah case.
// Sumber: ohrm_manpower_approval_history (dicatat oleh save_manpower.php
// setiap kali approval task baru muncul / berubah, saat sync).
//
// GET /proxy/get_manpower_approval_history.php?case_id=000000xxxx[&status=Completed|All]
// Response: { "status": "success", "data": [ {id, case_id, task_id, summary,
//           owner, priority, status, changed_at}, ... ] }  (urut changed_at ASC)
//
// FILTER STATUS (permintaan user 2026-11-02): default HANYA menampilkan task
// berstatus "Completed". Modal "History Approval Task" dulu menampilkan semua
// status (Open/Completed/Rejected) sehingga riwayatnya campur. Kirim
// &status=All kalau memang butuh semua baris.
// ============================================
header('Content-Type: application/json');
// Modal history selalu butuh data terbaru (hasil sync bisa berubah tiap saat).
header('Cache-Control: no-store, no-cache, must-revalidate');

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

$caseId = isset($_GET['case_id']) ? trim((string)$_GET['case_id']) : '';
if ($caseId === '') {
    echo json_encode(['status' => 'error', 'message' => 'case_id wajib diisi.']);
    exit;
}

// Filter status: default 'Completed' (hanya task yang sudah selesai).
// 'All' atau string kosong = tanpa filter (perilaku lama).
$statusParam = isset($_GET['status']) ? trim((string)$_GET['status']) : 'Completed';
$filterAll = ($statusParam === '' || strtoupper($statusParam) === 'ALL');

try {
    $sql = "SELECT id, case_id, task_id, summary, owner, priority, status, changed_at
         FROM ohrm_manpower_approval_history
         WHERE case_id = :case_id";
    $params = [':case_id' => $caseId];

    if (!$filterAll) {
        // UPPER(TRIM(...)) supaya tahan terhadap nilai 'completed'/'Completed ' dari sync.
        $sql .= " AND UPPER(TRIM(COALESCE(status, ''))) = :status";
        $params[':status'] = strtoupper($statusParam);
    }

    $sql .= " ORDER BY changed_at ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'case_id' => $caseId,
        'filter_status' => $filterAll ? 'All' : strtoupper($statusParam),
        'data' => $rows,
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal ambil history: ' . $e->getMessage()]);
}