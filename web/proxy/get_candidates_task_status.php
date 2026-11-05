<?php
require_once __DIR__ . '/_config.php';
// ============================================
// get_candidates_task_status.php
//
// Peta status Task Acumatica untuk BANYAK kandidat sekaligus (dipakai kolom
// "Acumatica Task Status" di tabel Recruitment -> Candidates).
// Hanya membaca tabel ohrm_candidate_acumatica_task -> cepat, TANPA login Acumatica.
//
// GET /proxy/get_candidates_task_status.php[?candidate_ids=1,2,3]
// Response:
//   {"status":"ok","data":{"2":"Processing"},"count":1}
// ============================================
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal.', 'data' => new stdClass()]);
    exit;
}

$ids = [];
if (isset($_GET['candidate_ids']) && $_GET['candidate_ids'] !== '') {
    foreach (explode(',', (string)$_GET['candidate_ids']) as $v) {
        $v = (int)trim($v);
        if ($v > 0) {
            $ids[] = $v;
        }
    }
    $ids = array_values(array_unique($ids));
}

try {
    if (count($ids) > 0) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT candidate_id, task_status, synced_at
             FROM ohrm_candidate_acumatica_task
             WHERE candidate_id IN ($in)"
        );
        $stmt->execute($ids);
    } else {
        $stmt = $pdo->query(
            "SELECT candidate_id, task_status, synced_at FROM ohrm_candidate_acumatica_task"
        );
    }

    $data = [];
    $synced = [];
    foreach ($stmt as $row) {
        $cid = (string)$row['candidate_id'];
        if ($row['task_status'] !== null && $row['task_status'] !== '') {
            $data[$cid] = $row['task_status'];
            $synced[$cid] = $row['synced_at'];
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal membaca status task.', 'data' => new stdClass()]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'data'   => count($data) ? $data : new stdClass(),
    'synced' => count($synced) ? $synced : new stdClass(),
    'count'  => count($data),
]);
