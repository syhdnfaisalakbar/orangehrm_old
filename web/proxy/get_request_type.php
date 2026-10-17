<?php
require_once __DIR__ . '/_config.php';
/**
 * Get Request Type for Vacancies
 * Mencari request_type (Planned/Replacement/Unplanned) dari ohrm_manpower_request
 * berdasarkan case_id di ohrm_job_vacancy
 * 
 * Panggil: GET /proxy/get_request_type.php?vacancy_ids[]=1&vacancy_ids[]=2
 * Response: { "status": "ok", "data": { "1": "Planned", "2": "Replacement" } }
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB gagal: ' . $e->getMessage()]);
    exit;
}

$vacancyIds = isset($_GET['vacancy_ids']) ? $_GET['vacancy_ids'] : [];
if (!is_array($vacancyIds) || empty($vacancyIds)) {
    echo json_encode(['status' => 'ok', 'data' => []]);
    exit;
}

$ids = array_map('intval', $vacancyIds);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

try {
    $stmt = $pdo->prepare("
        SELECT id AS vacancy_id, COALESCE(req_type, 'Unknown') AS request_type
        FROM ohrm_job_vacancy
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($rows as $row) {
        $result[$row['vacancy_id']] = $row['request_type'];
    }
    
    echo json_encode(['status' => 'ok', 'data' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Query gagal: ' . $e->getMessage()]);
}
