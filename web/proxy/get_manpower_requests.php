<?php
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');

// 1. KONEKSI DATABASE
$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME; // Sesuaikan jika berbeda
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()]);
    exit;
}

try {
    // 2. AMBIL DATA MANPOWER REQUEST (Tabel Utama)
    $stmt = $pdo->prepare("SELECT * FROM ohrm_manpower_request ORDER BY id DESC");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. AMBIL DATA APPROVAL (Tabel Relasi)
    // Asumsi: tabel ohrm_manpower_approval terhubung menggunakan case_id
    // ORDER BY created_at DESC supaya task paling baru (Created On terbaru) muncul duluan
    $stmtApproval = $pdo->prepare("SELECT * FROM ohrm_manpower_approval WHERE case_id = :case_id ORDER BY created_at DESC, id DESC");

    // 3b. AMBIL APPROVAL TASK TERAKHIR per case (kolom tabel FPTK — yang paling terakhir)
    $stmtLastApproval = $pdo->prepare(
        "SELECT summary, owner, priority, status, approval_date
         FROM ohrm_manpower_approval
         WHERE case_id = :case_id
         ORDER BY id DESC
         LIMIT 1"
    );

    $formattedData = [];

    // Helper: extract attribute value from raw_attributes JSON (fallback)
    function extractRawAttr($rawJson, $attrName) {
        if (!$rawJson) return null;
        $attrs = json_decode($rawJson, true);
        if (!is_array($attrs)) return null;
        $search = strtolower($attrName);
        foreach ($attrs as $a) {
            $id = $a['AttributeID']['value'] ?? '';
            if (strtolower($id) === $search) {
                return $a['ValueDescription']['value'] ?? $a['Value']['value'] ?? null;
            }
        }
        return null;
    }

    foreach ($requests as $row) {
        // --- Susun ulang Atribut agar terbaca oleh Modal Vue Bapak ---
        // Branch fallback: column → raw_attributes
        $branchVal = $row['branch'] ?: extractRawAttr($row['raw_attributes'] ?? null, 'BRANCH') ?: '-';

        $attributes = [
                    ['AttributeID' => ['value' => 'Department'], 'ValueDescription' => ['value' => $row['department'] ?? '-']],
                    ['AttributeID' => ['value' => 'Branch'], 'ValueDescription' => ['value' => $branchVal]],
                    ['AttributeID' => ['value' => 'Position'], 'ValueDescription' => ['value' => $row['position'] ?? '-']],
            ['AttributeID' => ['value' => 'Request Type'], 'ValueDescription' => ['value' => $row['req_type'] ?? '-']],
            ['AttributeID' => ['value' => 'Total Person'], 'ValueDescription' => ['value' => $row['tot_person'] ?? '0']],
            ['AttributeID' => ['value' => 'Gender'], 'ValueDescription' => ['value' => $row['gender'] ?? '-']],
            ['AttributeID' => ['value' => 'Employee Status'], 'ValueDescription' => ['value' => $row['empl_status'] ?? '-']],
            ['AttributeID' => ['value' => 'Skill'], 'ValueDescription' => ['value' => $row['skill'] ?? '-']],
            ['AttributeID' => ['value' => 'Target Date'], 'ValueDescription' => ['value' => $row['target_date'] ?? '-']],
            ['AttributeID' => ['value' => 'Job Desc'], 'ValueDescription' => ['value' => $row['job_desc'] ?? '-']],
        ];

        // --- Susun ulang Approval Tasks agar terbaca oleh Modal Vue Bapak ---
        $stmtApproval->execute([':case_id' => $row['case_id']]);
        $approvalsData = $stmtApproval->fetchAll(PDO::FETCH_ASSOC);

        // --- Ambil Approval Task TERAKHIR (kolom tabel FPTK) ---
        $stmtLastApproval->execute([':case_id' => $row['case_id']]);
        $lastApprovalRow = $stmtLastApproval->fetch(PDO::FETCH_ASSOC);

        $approvals = [];
        foreach ($approvalsData as $app) {
            $approvals[] = [
                            // Sesuaikan nama kolom $app['...'] dengan nama kolom di tabel ohrm_manpower_approval Bapak
                            'Summary' => ['value' => $app['summary'] ?? '-'],
                            'OwnerEmployeeName' => ['value' => $app['owner'] ?? '-'],
                            'Priority' => ['value' => $app['priority'] ?? '-'],
                            'Status' => ['value' => $app['status'] ?? '-'],
                            // NoteID = task_id (NoteID Acumatica). WAJIB ada supaya modal bisa
                            // mengidentifikasi task untuk fitur "Delete Selected Open".
                            'NoteID' => ['value' => $app['task_id'] ?? '-'],
                            'ApprovalDate' => ['value' => $app['approval_date'] ?? null],
                            'CreatedDateTime' => ['value' => $app['created_at'] ?? null]
                        ];
        }

        // --- Gabungkan Semuanya ---
        $formattedData[] = [
                    'id' => $row['id'],
                    'caseId' => $row['case_id'],
                    // noteId wajib ada supaya tombol Sync bisa fetch Task approval case lama
                    // (scheduler baca note_id langsung dari DB, tombol ambil dari respons ini).
                    'noteId' => $row['note_id'] ?? null,
                    'caseClass' => $row['case_class'],
                    'status' => $row['status'],
                    'subject' => $row['subject'],
                    'reason' => $row['reason'],
                    'owner' => $row['owner'],
                    'reqType' => $row['req_type'] ?? '-',

                    // Approval Task TERAKHIR — untuk kolom tabel FPTK
                    'lastApproval' => $lastApprovalRow ? [
                'summary'       => $lastApprovalRow['summary'] ?? '-',
                'owner'         => $lastApprovalRow['owner'] ?? '-',
                'priority'      => $lastApprovalRow['priority'] ?? '-',
                'status'        => $lastApprovalRow['status'] ?? '-',
                'approval_date' => $lastApprovalRow['approval_date'] ?? null,
            ] : null,

            // Masukkan data bersarang untuk modal
            'attributes' => $attributes,
            'approvals' => $approvals
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $formattedData
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data lokal: ' . $e->getMessage()]);
}