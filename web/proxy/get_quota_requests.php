<?php
require_once __DIR__ . '/_config.php';
/**
 * Get Quota Requests dari DATABASE LOKAL (tanpa Sync Acumatica).
 *
 * Membaca:
 *   - ohrm_quota_requests       (master case)
 *   - ohrm_case_attributes      (attributes per case, via ref_note_id)
 *   - ohrm_approval_history     (approval tasks per case, via ref_note_id)
 *
 * Output diformat menyerupai response Acumatica supaya halaman QuotaRequest.vue
 * bisa langsung dipakai tanpa perubahan besar pada tabel & modal.
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
    $cases = $pdo->query(
        "SELECT * FROM ohrm_quota_requests ORDER BY sync_date DESC, case_id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmtAttr = $pdo->prepare(
        "SELECT attribute_name, attribute_value FROM ohrm_case_attributes WHERE ref_note_id = :note ORDER BY attribute_id"
    );
    $stmtAppr = $pdo->prepare(
        "SELECT approver_name, summary, priority, approval_status, approval_date FROM ohrm_approval_history WHERE ref_note_id = :note ORDER BY approval_id"
    );

    $data = [];
    foreach ($cases as $row) {
        // --- Attributes (dari ohrm_case_attributes) ---
        $stmtAttr->execute([':note' => $row['note_id']]);
        $attributes = [];
        $reason = '-';
        foreach ($stmtAttr->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $attributes[] = [
                'AttributeID' => ['value' => $a['attribute_name']],
                'ValueDescription' => ['value' => $a['attribute_value']],
            ];
            if (strtoupper((string)$a['attribute_name']) === 'REASON' && $a['attribute_value'] !== '') {
                $reason = $a['attribute_value'];
            }
        }

        // --- Approvals (dari ohrm_approval_history) ---
        $stmtAppr->execute([':note' => $row['note_id']]);
        $approvals = [];
        foreach ($stmtAppr->fetchAll(PDO::FETCH_ASSOC) as $app) {
            $approvals[] = [
                'Summary' => ['value' => $app['summary'] ?? '-'],
                'OwnerEmployeeName' => ['value' => $app['approver_name']],
                'Priority' => ['value' => $app['priority'] ?? '-'],
                'Status' => ['value' => $app['approval_status']],
                'ApprovalDate' => ['value' => $app['approval_date'] ?? null],
            ];
        }

        // Approval Task TERAKHIR — untuk kolom tabel (mirip Manpower Request).
        // ohrm_approval_history diurutkan approval_id ASC, jadi elemen terakhir
        // pada $approvals = task dengan approval_id terbesar (paling terakhir).
        $lastApproval = null;
        if (count($approvals) > 0) {
            $last = $approvals[count($approvals) - 1];
            $lastApproval = [
                'status' => $last['Status']['value'] ?? '-',
                'summary' => $last['Summary']['value'] ?? '-',
                'owner' => $last['OwnerEmployeeName']['value'] ?? '-',
                'priority' => $last['Priority']['value'] ?? '-',
            ];
        }

        $data[] = [
            'id' => $row['case_id'],
            'caseId' => $row['case_id'],
            'caseClass' => $row['case_class'] ?? '-',
            'status' => $row['current_status'],
            'reason' => $reason,
            'subject' => $row['subject'],
            'owner' => $row['owner'],
            'noteId' => $row['note_id'],
            'attributes' => $attributes,
            'approvals' => $approvals,
            'lastApproval' => $lastApproval,
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
