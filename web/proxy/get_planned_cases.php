<?php
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Sesuaikan koneksi database
$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Query untuk mengambil Case ID dan melakukan PIVOT pada atributnya
    // Pastikan nama atribut ('DEPARTMENT', 'POSITION', 'TOTPERSON') sesuai dengan yang ada di DB
    $sql = "
        SELECT 
            q.case_id, 
            q.note_id,
            MAX(CASE WHEN UPPER(a.attribute_name) = 'DEPARTMENT' THEN a.attribute_value END) as department,
            MAX(CASE WHEN UPPER(a.attribute_name) = 'POSITION' THEN a.attribute_value END) as position,
            MAX(CASE WHEN UPPER(a.attribute_name) = 'TOTPERSON' THEN a.attribute_value END) as total_person
        FROM ohrm_quota_requests q
        LEFT JOIN ohrm_case_attributes a ON q.note_id = a.ref_note_id
        GROUP BY q.case_id, q.note_id
        ORDER BY q.sync_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $cases]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
