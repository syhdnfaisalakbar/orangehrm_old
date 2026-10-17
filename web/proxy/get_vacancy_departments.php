<?php
require_once __DIR__ . '/_config.php';
/**
 * Get Departments for Vacancy Filter
 * Mengambil daftar department unik (DISTINCT) dari ohrm_manpower_request
 * yang terhubung ke vacancy (via manpower_request_id langsung ATAU
 * ohrm_manpower_vacancy_link fallback).
 *
 * Panggil: GET /proxy/get_vacancy_departments.php
 * Response: { "status": "ok", "data": ["Finance & Accounting", "Human Resource", ...] }
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

try {
    $stmt = $pdo->query("
        SELECT DISTINCT TRIM(COALESCE(mr.department, mr2.department)) AS department
        FROM ohrm_job_vacancy v
        LEFT JOIN ohrm_manpower_request mr
               ON mr.id = v.manpower_request_id
        LEFT JOIN ohrm_manpower_vacancy_link l
               ON l.vacancy_id = v.id
        LEFT JOIN ohrm_manpower_request mr2
               ON mr2.id = l.manpower_request_id
        WHERE COALESCE(mr.department, mr2.department) IS NOT NULL
          AND TRIM(COALESCE(mr.department, mr2.department)) <> ''
        ORDER BY department
    ");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['status' => 'ok', 'data' => $departments]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Query gagal: ' . $e->getMessage()]);
}
