<?php
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

// --- SESUAIKAN DENGAN KONEKSI DATABASE ORANGEHRM BAPAK ---
$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME; // Ganti nama database
$user = PROXY_DB_USER;      // Ganti user db
$pass = PROXY_DB_PASS;

/**
 * Konversi timestamp Acumatica (ISO 8601 + offset, mis. '2026-08-20T15:17:45.803+07:00')
 * jadi datetime MySQL ('2026-08-20 15:17:45') — pakai wall-clock yang dilaporkan
 * Acumatica (offset dipertahankan). Return null kalau kosong/bukan format tanggal.
 */
function parseAcumaticaDateTime($raw)
{
    if (!$raw || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $raw, $m)) {
        return null;
    }
    return $m[1] . ' ' . $m[2];
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Ambil JSON dari Vue.js
    $input = file_get_contents('php://input');
    $requestData = json_decode($input, true);

    if (empty($requestData)) {
        echo json_encode(['status' => 'error', 'message' => 'Tidak ada data payload JSON yang diterima.']);
        exit;
    }

    // 2. Pisahkan data berdasarkan strukturnya
    //    Bisa berbentuk {quotaSummary, casesDetail} ATAU array polos berisi baris quota
    $quotaSummary = $requestData['quotaSummary'] ?? [];
    $casesDetail  = $requestData['casesDetail'] ?? [];
    if (empty($quotaSummary) && isset($requestData[0]['dept_name'])) {
        $quotaSummary = $requestData; // Payload berupa array polos (grup quota departemen)
    }

    // 3. MULAI TRANSAKSI DATABASE
    $pdo->beginTransaction();
    $countQuota = 0;
    $countCases = 0;
    $countApprovalUpdates = 0;
    $countStatusUpdates = 0;
    $countStatusClose = 0;

    // ========================================================================
    // A. PROSES UPDATE TOTAL KUOTA DEPARTEMEN
    // ========================================================================
    if (!empty($quotaSummary)) {
        $stmtQuota = $pdo->prepare("
            INSERT INTO ohrm_ext_department_quota (dept_name, year, total_additional, replacement, reduction, unplanned) 
            VALUES (:dept_name, :year, :total_additional, :replacement, :reduction, :unplanned)
            ON DUPLICATE KEY UPDATE 
                total_additional = VALUES(total_additional),
                replacement = IF(VALUES(replacement) = 0, replacement, VALUES(replacement)),
                reduction = IF(VALUES(reduction) = 0, reduction, VALUES(reduction)),
                unplanned = IF(VALUES(unplanned) = 0, unplanned, VALUES(unplanned))
        ");

        foreach ($quotaSummary as $row) {
            $stmtQuota->execute([
                ':dept_name'        => $row['dept_name'],
                ':year'             => $row['year'],
                ':total_additional' => $row['total_additional'],
                ':replacement'      => $row['replacement'],
                ':reduction'        => $row['reduction'],
                ':unplanned'        => $row['unplanned'] ?? 0
            ]);
            $countQuota++;
        }
    }

    // ========================================================================
    // B. PROSES UPSERT DATA CASE, ATTRIBUTES, & APPROVALS (TABEL BARU)
    // ========================================================================
    if (!empty($casesDetail)) {

        // Prepare Statement untuk Tabel Utama (UPDATE NAMA TABEL)
        $sqlMain = "INSERT INTO ohrm_quota_requests (note_id, case_id, case_class, subject, owner, current_status, sync_date) 
                    VALUES (:note_id, :case_id, :case_class, :subject, :owner, :current_status, NOW())
                    ON DUPLICATE KEY UPDATE 
                    case_class = VALUES(case_class),
                    current_status = VALUES(current_status),
                    owner = VALUES(owner),
                    -- sync_date hanya berubah kalau data case BENAR-BENAR berubah
                    -- (case_class/current_status/owner). Tanpa ini, sync ulang
                    -- case yang sudah Closed mereset sync_date → muncul lagi di
                    -- bell HRPLAN (case lama mengintip di dropdown).
                    sync_date = IF(
                        COALESCE(case_class, '') = COALESCE(VALUES(case_class), '')
                        AND COALESCE(current_status, '') = COALESCE(VALUES(current_status), '')
                        AND COALESCE(owner, '') = COALESCE(VALUES(owner), ''),
                        sync_date,
                        NOW()
                    )";
        $stmtMain = $pdo->prepare($sqlMain);

        // Prepare Statement untuk Delete Relasi Lama (UPDATE NAMA TABEL)
        $stmtDelAttr = $pdo->prepare("DELETE FROM ohrm_case_attributes WHERE ref_note_id = :ref_note_id");
        $stmtDelAppr = $pdo->prepare("DELETE FROM ohrm_approval_history WHERE ref_note_id = :ref_note_id");

        // Prepare Statement untuk Insert Relasi Baru (UPDATE NAMA TABEL)
        $sqlAttr = "INSERT INTO ohrm_case_attributes (ref_note_id, attribute_name, attribute_value) VALUES (:ref_note_id, :attr_name, :attr_val)";
        $stmtAttr = $pdo->prepare($sqlAttr);

        $sqlAppr = "INSERT INTO ohrm_approval_history (ref_note_id, approver_name, summary, priority, approval_status, approval_date) VALUES (:ref_note_id, :approver, :summary, :priority, :status, :approval_date)";
        $stmtAppr = $pdo->prepare($sqlAppr);

        // Looping setiap Case
        foreach ($casesDetail as $case) {
            $noteId = $case['noteId'] ?? null;
            if (!$noteId || $noteId === '-') continue; // Skip jika tidak ada Note ID (UUID Acumatica)

            // 1. Upsert Tabel Utama
            $stmtMain->execute([
                ':note_id'        => $noteId,
                ':case_id'        => $case['caseId'] ?? '-',
                ':case_class'     => $case['caseClass'] ?? '-',
                ':subject'        => $case['subject'] ?? '-',
                ':owner'          => $case['owner'] ?? '-',
                ':current_status' => $case['status'] ?? '-'
            ]);

            // 2. Hapus Relasi Lama (agar data tidak duplikat menumpuk)
            $stmtDelAttr->execute([':ref_note_id' => $noteId]);
            $stmtDelAppr->execute([':ref_note_id' => $noteId]);

            // 3. Insert Atribut Baru
            if (!empty($case['attributes']) && is_array($case['attributes'])) {
                foreach ($case['attributes'] as $attr) {
                    $attrName = $attr['AttributeID']['value'] ?? '';
                    $attrVal  = $attr['ValueDescription']['value'] ?? $attr['Value']['value'] ?? '';

                    if ($attrName) {
                        $stmtAttr->execute([
                            ':ref_note_id' => $noteId,
                            ':attr_name'   => $attrName,
                            ':attr_val'    => $attrVal
                        ]);
                    }
                }
            }

            // 4. Insert Approval Baru
            if (!empty($case['approvals']) && is_array($case['approvals'])) {
                foreach ($case['approvals'] as $appr) {
                    $approverName = $appr['OwnerEmployeeName']['value'] ?? $appr['Owner']['value'] ?? $appr['OwnerName']['value'] ?? '-';
                    $apprSummary  = $appr['Summary']['value'] ?? '-';
                    $apprPriority = $appr['Priority']['value'] ?? '-';
                    $apprStatus   = $appr['Status']['value'] ?? '-';

                    $stmtAppr->execute([
                                            ':ref_note_id'    => $noteId,
                                            ':approver'       => $approverName,
                                            ':summary'        => $apprSummary,
                                            ':priority'       => $apprPriority,
                                            ':status'         => $apprStatus,
                                            ':approval_date'  => parseAcumaticaDateTime($appr['CompletedAt']['value'] ?? null)
                                        ]);
                }
            }
            $countCases++;
        }
    }

    // ========================================================================
    // C. PROSES UPDATE STATUS APPROVAL CASE LAMA (2026-08-06)
    // Sync incremental hanya menarik case baru — approval task case lama tidak
    // ikut di-refresh. Frontend mengirim approvalUpdates [{noteId, approvals}]
    // untuk case yang SUDAH ada di DB; di sini di-rebuild (delete + insert)
    // supaya status Open/Completed selalu sesuai Acumatica.
    // ========================================================================
    $approvalUpdates = $requestData['approvalUpdates'] ?? [];
    if (!empty($approvalUpdates) && is_array($approvalUpdates)) {
        $stmtDelAppr2 = $pdo->prepare("DELETE FROM ohrm_approval_history WHERE ref_note_id = :ref_note_id");
        $stmtAppr2 = $pdo->prepare(
                    "INSERT INTO ohrm_approval_history (ref_note_id, approver_name, summary, priority, approval_status, approval_date)
                     VALUES (:ref_note_id, :approver, :summary, :priority, :status, :approval_date)"
                );

        foreach ($approvalUpdates as $entry) {
            $noteId = $entry['noteId'] ?? null;
            if (!$noteId || $noteId === '-') {
                continue;
            }

            $stmtDelAppr2->execute([':ref_note_id' => $noteId]);

            if (!empty($entry['approvals']) && is_array($entry['approvals'])) {
                foreach ($entry['approvals'] as $appr) {
                    $approverName = $appr['OwnerEmployeeName']['value'] ?? $appr['Owner']['value'] ?? $appr['OwnerName']['value'] ?? '-';
                    $apprSummary  = $appr['Summary']['value'] ?? '-';
                    $apprPriority = $appr['Priority']['value'] ?? '-';
                    $apprStatus   = $appr['Status']['value'] ?? '-';

                    $stmtAppr2->execute([
                                            ':ref_note_id'    => $noteId,
                                            ':approver'       => $approverName,
                                            ':summary'        => $apprSummary,
                                            ':priority'       => $apprPriority,
                                            ':status'         => $apprStatus,
                                            ':approval_date'  => parseAcumaticaDateTime($appr['CompletedAt']['value'] ?? null)
                                        ]);
                }
            }
            $countApprovalUpdates++;
        }
    }

    // ========================================================================
    // D. PROSES UPDATE STATUS + OWNER + SUBJECT HEADER CASE LAMA
    // Sync incremental hanya menarik case baru — header case lama bisa basi.
    // Frontend mengirim statusUpdates [{caseId, status, owner, subject}]
    // (terkini dari Acumatica); di sini di-update ke ohrm_quota_requests.
    // ========================================================================
    $statusUpdates = $requestData['statusUpdates'] ?? [];
    if (!empty($statusUpdates) && is_array($statusUpdates)) {
        $stmtStatus = $pdo->prepare(
            "UPDATE ohrm_quota_requests
             SET current_status = :status,
                 owner = COALESCE(:owner, owner),
                 subject = COALESCE(:subject, subject),
                 sync_date = IF(
                     COALESCE(current_status, '') = :status2
                     AND COALESCE(owner, '') = COALESCE(:owner2, owner)
                     AND COALESCE(subject, '') = COALESCE(:subject2, subject),
                     sync_date,
                     NOW()
                 )
             WHERE case_id = :case_id"
        );

        foreach ($statusUpdates as $su) {
            $suCaseId  = $su['caseId'] ?? null;
            $suStatus  = $su['status'] ?? null;
            $suOwner   = $su['owner'] ?? null;
            $suSubject = $su['subject'] ?? null;
            if ($suCaseId === null || $suStatus === null || $suStatus === '') {
                continue;
            }
            $stmtStatus->execute([
                ':case_id'   => $suCaseId,
                ':status'    => $suStatus,
                ':owner'     => $suOwner,
                ':subject'   => $suSubject,
                ':status2'   => $suStatus,
                ':owner2'    => $suOwner,
                ':subject2'  => $suSubject,
            ]);
            $countStatusUpdates++;
        }
    }

    // ========================================================================
    // E. PUSH CLOSE KE ACUMATICA (2026-08-06)
    // Frontend mengirim closeCaseIds = case yang SEMUA approval task-nya
    // sudah Completed. Status header case di Acumatica di-update jadi Closed
    // (best-effort, tidak throw — reuse close_case_acumatica.php pola MPR).
    // Kalau push sukses → status lokal ikut di-set Closed biar langsung
    // sinkron (tidak nunggu sync berikutnya).
    // ========================================================================
    $closeCaseIds = $requestData['closeCaseIds'] ?? [];
    if (!empty($closeCaseIds) && is_array($closeCaseIds)) {
        require_once __DIR__ . '/close_case_acumatica.php';
        $stmtStatusClose = $pdo->prepare(
            "UPDATE ohrm_quota_requests
             SET current_status = 'Closed', sync_date = NOW()
             WHERE case_id = :case_id"
        );

        foreach ($closeCaseIds as $cid) {
            $cid = trim((string)$cid);
            if ($cid === '') {
                continue;
            }
            $result = closeCaseInAcumatica($cid);
            if (($result['status'] ?? '') === 'ok') {
                $stmtStatusClose->execute([':case_id' => $cid]);
                $countStatusClose++;
            }
        }
    }

    // 4. SIMPAN SEMUA PERUBAHAN
    $pdo->commit();
    echo json_encode([
        'status' => 'success',
        'message' => "Sync Selesai! $countQuota Kuota Departemen, $countCases Case Details, $countApprovalUpdates Approval, $countStatusUpdates Status header, dan $countStatusClose Case di-close di Acumatica berhasil disinkronisasi."
    ]);
} catch (PDOException $e) {
    // 5. BATALKAN SEMUA JIKA TERJADI ERROR (Rollback)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
}
