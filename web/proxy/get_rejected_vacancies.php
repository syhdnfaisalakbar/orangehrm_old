<?php
require_once __DIR__ . '/_config.php';
/**
 * Get daftar vacancy yang statusnya Rejected.
 *
 * GET /proxy/get_rejected_vacancies.php?vacancy_ids[]=68&vacancy_ids[]=67
 *
 * Response:
 * {
 *   "status": "ok",
 *   "data": { "68": 1, "67": 0 }   // 1 = rejected, 0 = tidak
 * }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ambil daftar id dari query string (vacancy_ids[]=...)
    $ids = isset($_GET['vacancy_ids']) ? $_GET['vacancy_ids'] : [];
    $ids = array_map('intval', (array) $ids);
    $ids = array_values(array_unique(array_filter($ids)));

    $result = [];
    if (!empty($ids)) {
        $inList = implode(',', $ids);
        $stmt = $pdo->query("SELECT id, rejected FROM ohrm_job_vacancy WHERE id IN ($inList)");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (int) $row['rejected'];
        }
    }

    echo json_encode(['status' => 'ok', 'data' => $result]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
