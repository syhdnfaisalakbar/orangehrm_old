<?php
require_once __DIR__ . '/_config.php';
// ============================================
// get_manpower_approval_history.php
//
// Riwayat PERUBAHAN approval task Manpower Request (FPTK) untuk sebuah case.
// Sumber: ohrm_manpower_approval_history (dicatat oleh save_manpower.php
// setiap kali approval task baru muncul / berubah, saat sync).
//
// GET /proxy/get_manpower_approval_history.php?case_id=000000xxxx
// Response: { "status": "success", "data": [ {id, case_id, task_id, summary,
//           owner, priority, status, changed_at}, ... ] }  (urut changed_at ASC)
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

$caseId = isset($_GET['case_id']) ? trim((string)$_GET['case_id']) : '';
if ($caseId === '') {
    echo json_encode(['status' => 'error', 'message' => 'case_id wajib diisi.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, case_id, task_id, summary, owner, priority, status, changed_at
         FROM ohrm_manpower_approval_history
         WHERE case_id = :case_id
         ORDER BY changed_at ASC, id ASC"
    );
    $stmt->execute([':case_id' => $caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'case_id' => $caseId, 'data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal ambil history: ' . $e->getMessage()]);
}