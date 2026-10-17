<?php
require_once __DIR__ . '/_config.php';
/**
 * Get Manpower Request List
 * Mengembalikan data manpower request + jumlah vacancy terlink
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal']);
    exit;
}

try {
    $sql = "SELECT 
                mr.id,
                mr.id AS request_id,
                mr.case_id,
                CONCAT('MPR-', YEAR(mr.created_at), '-', LPAD(mr.id, 3, '0')) AS request_no,
                mr.req_type AS request_type,
                mr.department,
                mr.position AS position_name,
                mr.tot_person AS quantity,
                mr.status,
                mr.gender,
                mr.empl_status AS employment_status,
                mr.skill,
                mr.target_date,
                mr.job_desc AS job_description,
                mr.reason AS description,
                mr.owner AS requested_by,
                mr.created_at AS requested_date,
                (SELECT COUNT(*) FROM ohrm_manpower_vacancy_link l WHERE l.manpower_request_id = mr.id) AS vacancy_count,
                (SELECT GROUP_CONCAT(v.name SEPARATOR ', ') 
                 FROM ohrm_manpower_vacancy_link l 
                 JOIN ohrm_job_vacancy v ON l.vacancy_id = v.id 
                 WHERE l.manpower_request_id = mr.id) AS vacancy_names
            FROM ohrm_manpower_request mr
            ORDER BY mr.created_at DESC";

    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
