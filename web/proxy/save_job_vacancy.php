<?php
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');

// ==========================================
// 1. KONEKSI DATABASE
// ==========================================
$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME; // Pastikan nama DB ini sudah sesuai dengan punya Bapak
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['casesDetail']) || !is_array($data['casesDetail'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$inserted = 0;
$skipped = 0;
$unmatchedManagers = []; // Array detektif untuk mencatat data Acumatica yang aneh
$openApprovals = []; // Case yang approval manpower request-nya belum Completed

try {
    $pdo->beginTransaction();

    $stmtCheckVacancy = $pdo->prepare("SELECT id FROM ohrm_job_vacancy WHERE name = :name LIMIT 1");
    $stmtUpdateCaseId = $pdo->prepare("UPDATE ohrm_job_vacancy SET case_id = :case_id WHERE id = :id");
    $stmtJobCheck = $pdo->prepare("SELECT id FROM ohrm_job_title WHERE job_title = :job_title LIMIT 1");
    $stmtJobInsert = $pdo->prepare("INSERT INTO ohrm_job_title (job_title, is_deleted) VALUES (:job_title, 0)");

    // Kueri pencarian Manager yang sangat fleksibel (berdasarkan NIK, Nama Depan, atau Full Name)
    $stmtManager = $pdo->prepare("
        SELECT emp_number FROM hs_hr_employee 
        WHERE CONCAT(emp_firstname, ' ', emp_lastname) = :exact_text
           OR CONCAT(emp_firstname, ' ', emp_lastname) LIKE :like_text 
           OR emp_firstname LIKE :like_text 
           OR emp_lastname LIKE :like_text
        LIMIT 1
    ");

    // MENGAMBIL 1 KARYAWAN ACAK SEBAGAI CADANGAN JIKA ACUMATICA GAGAL MATCH
    $stmtFallback = $pdo->prepare("SELECT emp_number FROM hs_hr_employee LIMIT 1");
    $stmtFallback->execute();
    $fallbackData = $stmtFallback->fetch(PDO::FETCH_ASSOC);
    $fallbackManagerId = $fallbackData ? $fallbackData['emp_number'] : null;

    $stmtInsert = $pdo->prepare("
        INSERT INTO ohrm_job_vacancy (
            name, description, no_of_positions, status, published_in_feed, job_title_code, hiring_manager_id, case_id, req_type
        ) VALUES (
            :name, :description, :no_of_positions, 1, 1, :job_title_code, :hiring_manager_id, :case_id, :req_type
        )
    ");

    // Cek status approval manpower request (ohrm_manpower_approval)
    $stmtApproval = $pdo->prepare(
        "SELECT status FROM ohrm_manpower_approval
         WHERE case_id = :case_id
         ORDER BY updated_at DESC, id DESC LIMIT 1"
    );

    foreach ($data['casesDetail'] as $case) {

        // --- 0. VALIDASI APPROVAL MANPOWER REQUEST ---
        // Hanya simpan vacancy kalau approval MPR sudah Completed/Approved.
        // Open / tanpa data approval → SKIP + alert (konsisten dengan Manpower Request).
        $caseIdForApproval = $case['caseId'] ?? null;
        $approvalStatus = null;
        if ($caseIdForApproval) {
            $stmtApproval->execute([':case_id' => $caseIdForApproval]);
            $approvalRow = $stmtApproval->fetch(PDO::FETCH_ASSOC);
            $approvalStatus = $approvalRow ? $approvalRow['status'] : null;
        }
        if ($approvalStatus === null || !in_array(strtoupper($approvalStatus), ['COMPLETED', 'APPROVED'], true)) {
            $openApprovals[] = $caseIdForApproval . ' (approval manpower request: ' . ($approvalStatus ?: 'belum ada data approval') . ')';
            continue; // SKIP — vacancy tidak disimpan
        }

        // --- 1. VALIDASI DATA EXISTING ---
        $stmtCheckVacancy->execute([':name' => $case['name']]);
        $existing = $stmtCheckVacancy->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            // Update case_id & req_type untuk data yang sudah ada
            $stmtUpdate = $pdo->prepare("UPDATE ohrm_job_vacancy SET case_id = :case_id, req_type = :req_type WHERE id = :id");
            $stmtUpdate->execute([
                ':case_id' => $case['caseId'] ?? null,
                ':req_type' => $case['reqType'] ?? null,
                ':id' => $existing['id']
            ]);
            $skipped++;
            continue;
        }

        // --- 2. PROSES JOB TITLE ---
        $jobTitleId = null;
        $jobTitleText = (!empty($case['jobTitleText']) && $case['jobTitleText'] !== '-')
            ? trim($case['jobTitleText'])
            : 'Unknown Position';

        $stmtJobCheck->execute([':job_title' => $jobTitleText]);
        $jobResult = $stmtJobCheck->fetch(PDO::FETCH_ASSOC);

        if ($jobResult) {
            $jobTitleId = $jobResult['id'];
        } else {
            $stmtJobInsert->execute([':job_title' => $jobTitleText]);
            $jobTitleId = $pdo->lastInsertId();
        }

        // --- 3. PROSES HIRING MANAGER (DENGAN FALLBACK) ---
        $managerId = null;
        $rawManagerText = $case['managerText'] ?? '-';

        if (!empty($rawManagerText) && $rawManagerText !== '-') {
            $cleanManagerName = str_replace('JKT - ', '', $rawManagerText);
            $cleanText = trim($cleanManagerName);

            $stmtManager->execute([
                ':exact_text' => $cleanText,
                ':like_text'  => '%' . $cleanText . '%'
            ]);
            $mgrResult = $stmtManager->fetch(PDO::FETCH_ASSOC);

            if ($mgrResult) {
                // KETEMU!
                $managerId = $mgrResult['emp_number'];
            } else {
                // GAGAL KETEMU! Catat teksnya dan pakai nama cadangan
                $unmatchedManagers[] = $cleanText;
                $managerId = $fallbackManagerId;
            }
        } else {
            // JIKA ACUMATICA MENGIRIM KOSONG ATAU "-", PAKAI CADANGAN
            $managerId = $fallbackManagerId;
        }

        // --- 4. EKSEKUSI INSERT ---
        $stmtInsert->execute([
            ':name' => $case['name'],
            ':description' => $case['description'],
            ':no_of_positions' => $case['no_of_positions'],
            ':job_title_code' => $jobTitleId,
            ':hiring_manager_id' => $managerId,
            ':case_id' => $case['caseId'] ?? null,
            ':req_type' => $case['reqType'] ?? null
        ]);

        $inserted++;
    }

    $pdo->commit();

    // Auto-link vacancy berdasarkan case_id yang cocok (MPR sync terpisah)
    $linkMessage = '';
    try {
        require_once __DIR__ . '/link_manpower_vacancies.php';
        $linkResult = linkManpowerVacancies($pdo);
        $linkMessage = isset($linkResult['message']) ? ' | ' . $linkResult['message'] : '';
    } catch (Exception $e) {
        // Jangan sampai sync gagal gara-gara auto-link error
        $linkMessage = ' | Auto-link gagal: ' . $e->getMessage();
    }

    // Siapkan pesan balasan ke Vue.js
    $msg = "Berhasil menyimpan $inserted Job Vacancies. ($skipped data sudah ada)." . $linkMessage;
    if (count($unmatchedManagers) > 0) {
        $unik = array_unique($unmatchedManagers);
        // Tambahkan bocoran teks Acumatica ke pesan Alert
        $msg .= " INFO: Teks ini dari Acumatica [" . implode(", ", $unik) . "] gagal ditemukan di Employee OrangeHRM, sistem menggunakan Manager Cadangan.";
    }
    $openMsg = '';
    if (!empty($openApprovals)) {
        $openMsg = ' | APPROVAL OPEN: ' . implode(', ', $openApprovals);
    }

    echo json_encode([
        'status' => 'success',
        'message' => $msg . $openMsg,
        'open_approval' => $openApprovals
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
