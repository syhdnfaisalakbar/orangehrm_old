<?php
require_once __DIR__ . '/_config.php';
/**
 * Get max case_id vacancy yang SUDAH tersimpan di DB lokal.
 *
 * Dipakai ViewJobVacancy.vue syncAcumatica() untuk sync incremental:
 * hanya menarik case REQFPTK yang CaseID-nya di atas window dari max ini.
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $rows = $pdo->query(
        "SELECT case_id FROM ohrm_job_vacancy WHERE case_id IS NOT NULL AND case_id <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);

    $max = 0;
    foreach ($rows as $cid) {
        $n = (int)preg_replace('/\D/', '', (string)$cid);
        if ($n > $max) {
            $max = $n;
        }
    }

    echo json_encode(['status' => 'success', 'case_ids' => $rows, 'max' => $max]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
