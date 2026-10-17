<?php
require_once __DIR__ . '/_config.php';
//
// save_manpower.php
//
// Endpoint HTTP: simpan hasil Sync Acumatica Manpower Request (FPTK) ke DB lokal.
//   POST /proxy/save_manpower.php  Body: { "casesDetail": [...], "approvalUpdates": [...] }
//
// Inti logika diekstrak ke fungsi reusabel `saveManpowerPayload(PDO $pdo, array $data)`
// supaya dipakai OLEH KEDUANYA:
//   - endpoint HTTP ini (baca php://input lalu panggil fungsi), dan
//   - script job scheduler (sync_manpower_scheduled.php) — include file ini,
//     panggil fungsi yang SAMA. Tidak ada dua cabang logika → tidak ada drift.
//
// Refactor 2026-08-27: sisi web tidak berubah perilakunya; hanya diwadahi fungsi.
//
header('Content-Type: application/json');

// ==========================================
// 1. KONFIGURASI KONEKSI DATABASE
// ==========================================
$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

// ==========================================
// HELPER (top-level, biar bisa di-include dari scheduler)
// ==========================================

/**
 * Cari quota request (HRPLAN) yang sudah COMPLETED untuk departemen + tahun.
 * - Cek 1: departemen harus punya kuota tahunan (tabel menu All Quota Department).
 * - Cek 2: quota request HRPLAN dept+tahun harus completed/closed/approved
 *          (current_status ATAU ada approval_status='Completed').
 * - Kembalikan note_id quota request kalau lolos, null kalau belum completed.
 */
function findCompletedQuotaNoteId(PDO $pdo, string $dept, int $year): ?string
{
    // Cek 1: row kuota tahunan di ohrm_ext_department_quota (All Quota Department)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ohrm_ext_department_quota
         WHERE TRIM(dept_name) = :dept AND year = :year"
    );
    $stmt->execute([':dept' => $dept, ':year' => $year]);
    if ((int)$stmt->fetchColumn() === 0) {
        return null;
    }

    // Cek 2: quota request HRPLAN dept+tahun yang completed
    $stmt = $pdo->prepare(
        "SELECT q.note_id
         FROM ohrm_quota_requests q
         JOIN ohrm_case_attributes a
           ON a.ref_note_id = q.note_id
          AND a.attribute_name = 'DEPARTMENT'
          AND TRIM(a.attribute_value) = :dept
         JOIN ohrm_case_attributes y
           ON y.ref_note_id = q.note_id
          AND y.attribute_name = 'YEAR'
          AND y.attribute_value = :year
         WHERE UPPER(q.current_status) IN ('CLOSED', 'COMPLETED', 'APPROVED')
            OR EXISTS (
                SELECT 1 FROM ohrm_approval_history h
                WHERE h.ref_note_id = q.note_id
                  AND h.approval_status = 'Completed'
            )
         LIMIT 1"
    );
    $stmt->execute([':dept' => $dept, ':year' => (string)$year]);
    $noteId = $stmt->fetchColumn();
    return $noteId === false ? null : (string)$noteId;
}

/**
 * Konversi timestamp Acumatica (ISO 8601 + offset) jadi datetime MySQL.
 * Return null kalau kosong/bukan format tanggal.
 */
function parseAcumaticaDateTime($raw)
{
    if (!$raw || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $raw, $m)) {
        return null;
    }
    return $m[1] . ' ' . $m[2];
}

/**
 * Resolusi nama owner sebuah Task/Approval (2026-10-12).
 *
 * Acumatica Task TIDAK punya field OwnerEmployeeName (request $select-nya
 * dijawab HTTP 500 "key not present in dictionary") — yang ada hanya Owner,
 * dan di instance staging isinya ID numerik (mis. "233") yang TIDAK selalu
 * bisa di-resolve: sudah diuji Case?$filter=Owner eq '233' -> 0 baris,
 * Contact?$filter=ContactID eq 233 -> 0 baris, entity Users/User -> 404
 * (tidak diekspos di endpoint Default/25.200.001).
 *
 * Karena itu: kalau owner masih murni numerik dan nama owner CASE diketahui
 * (bukan '-', bukan numerik), pakai nama owner case sebagai fallback supaya
 * UI tidak menampilkan ID mentah.
 */
function resolveTaskOwnerName(array $task, ?string $caseOwnerName = null): string
{
    $raw = $task['OwnerEmployeeName']['value'] ?? ($task['Owner']['value'] ?? '');
    $raw = is_scalar($raw) ? trim((string)$raw) : '';
    if ($raw === '') {
        return '-';
    }
    if (preg_match('/^\d+$/', $raw)) {
        $fb = trim((string)$caseOwnerName);
        if ($fb !== '' && $fb !== '-' && !preg_match('/^\d+$/', $fb)) {
            return $fb;
        }
    }
    return $raw;
}

/**
 * INTI LOGIKA SYNC — reusable oleh endpoint HTTP & job scheduler.
 *
 * Menulis ohrm_manpower_request + ohrm_manpower_approval (upsert), validasi
 * kuota Planned, auto-link vacancy, dan auto-push approval REJECTED ke Acumatica.
 *
 * @param PDO   $pdo  Koneksi DB lokal.
 * @param array $data  Harus berisi 'casesDetail' (array). Opsional 'approvalUpdates'.
 * @return array  ['status','message','skipped','overQuota'] — bentuk sama dgn respons web lama.
 * @throws Exception kalau transaksi gagal (sudah di-rollback).
 */
function saveManpowerPayload(PDO $pdo, array $data): array
{
    if (!isset($data['casesDetail']) || !is_array($data['casesDetail'])) {
        throw new InvalidArgumentException('Data casesDetail tidak ditemukan atau format salah.');
    }

    $casesDetail = $data['casesDetail'];
    $insertedCases = 0;
    $insertedApprovals = 0;
    $countApprovalUpdates = 0;
    $skipped = [];     // Case Planned yang di-skip karena kuota belum completed
    $overQuota = [];   // Case Planned yang SUM tot_person-nya melebihi kuota planned

    try {
        // Mulai transaksi database agar aman
        $pdo->beginTransaction();

        // ==========================================
        // PREPARE STATEMENT UNTUK TABEL REQUEST
        // ==========================================
        $stmtRequest = $pdo->prepare("
            INSERT INTO ohrm_manpower_request (
                            case_id, case_class, status, subject, owner, note_id, quota_note_id,
                            department, branch, position, req_type, tot_person, gender,
                            empl_status, skill, target_date, job_desc, reason, raw_attributes
                        ) VALUES (
                            :case_id, :case_class, :status, :subject, :owner, :note_id, :quota_note_id,
                            :department, :branch, :position, :req_type, :tot_person, :gender,
                            :empl_status, :skill, :target_date, :job_desc, :reason, :raw_attributes
                        ) ON DUPLICATE KEY UPDATE
                            -- Status 'Rejected' lokal di-LOCK (tidak ditimpa sync).
                            status = IF(UPPER(COALESCE(status, '')) = 'REJECTED', status, VALUES(status)),
                            subject = VALUES(subject),
                            owner = VALUES(owner),
                            department = VALUES(department),
                            branch = VALUES(branch),
                            position = VALUES(position),
                req_type = VALUES(req_type),
                tot_person = VALUES(tot_person),
                gender = VALUES(gender),
                empl_status = VALUES(empl_status),
                skill = VALUES(skill),
                target_date = VALUES(target_date),
                job_desc = VALUES(job_desc),
                reason = VALUES(reason),
                raw_attributes = VALUES(raw_attributes),
                quota_note_id = COALESCE(VALUES(quota_note_id), quota_note_id),
                updated_at = CURRENT_TIMESTAMP
        ");

        // ==========================================
        // PREPARE STATEMENT UNTUK TABEL APPROVAL
        // ==========================================
        $stmtApproval = $pdo->prepare("
                INSERT INTO ohrm_manpower_approval (
                    case_id, task_id, summary, owner, priority, status, approval_date
                ) VALUES (
                    :case_id, :task_id, :summary, :owner, :priority, :status, :approval_date
                ) ON DUPLICATE KEY UPDATE
                    summary = VALUES(summary),
                    owner = VALUES(owner),
                    priority = VALUES(priority),
                    status = VALUES(status),
                    approval_date = VALUES(approval_date),
                    -- updated_at hanya berubah kalau data approval BENAR-BENAR berubah.
                    updated_at = IF(
                        COALESCE(status, '') = COALESCE(VALUES(status), '')
                        AND COALESCE(summary, '') = COALESCE(VALUES(summary), '')
                        AND COALESCE(owner, '') = COALESCE(VALUES(owner), '')
                        AND COALESCE(priority, '') = COALESCE(VALUES(priority), '')
                        AND COALESCE(approval_date, '') = COALESCE(VALUES(approval_date), ''),
                        updated_at,
                        CURRENT_TIMESTAMP
                    )
        ");

                // ==========================================
                // PREPARE STATEMENT UNTUK TABEL APPROVAL HISTORY (2026-09-14)
                // ==========================================
                $stmtApprovalHistory = $pdo->prepare("
                    INSERT INTO ohrm_manpower_approval_history
                        (case_id, task_id, summary, owner, priority, status, changed_at)
                    VALUES
                        (:case_id, :task_id, :summary, :owner, :priority, :status, NOW())
                ");

                // ==========================================
                // PROSES LOOPING DATA
                // ==========================================
        foreach ($casesDetail as $case) {
            // Nama owner case (dipakai fallback kalau owner task masih numerik)
            $caseOwnerFallbackName = $case['owner'] ?? null;

            // Format tanggal (Jika '-' jadikan null agar tidak error di MySQL)
            $targetDate = ($case['targetDate'] !== '-' && !empty($case['targetDate']))
                ? date('Y-m-d', strtotime($case['targetDate']))
                : null;

            // Encode raw attributes ke JSON string
            $rawAttributes = isset($case['attributes']) ? json_encode($case['attributes']) : null;

            // ==========================================
            // REQUEST TYPE = PLANNED
            // Validasi kuota tahunan completed DILEPAS (keputusan user 2026-08).
            // quota_note_id tetap diisi kalau kuota sudah completed, kalau belum → null.
            // ==========================================
            $quotaNoteId = null;
            $isPlanned = strtoupper(trim($case['reqType'] ?? '')) === 'PLANNED';
            if ($isPlanned) {
                $dept = trim($case['department'] ?? '');
                $yr = $targetDate ? (int)date('Y', strtotime($targetDate)) : (int)date('Y');
                if ($dept !== '') {
                    $quotaNoteId = findCompletedQuotaNoteId($pdo, $dept, $yr);
                }
            }

            // ==========================================
            // VALIDASI KUOTA PLANNED (2026-08-06, rev: tolak simpan)
            // Per departemen: SUM(tot_person) MPR Planned dept+tahun dibandingkan
            // dengan kuota planned (total_additional). Kalau SUM > kuota → TIDAK
            // disimpan (continue), dicatat di $overQuota.
            // ==========================================
            if ($isPlanned) {
                $chkDept = trim($case['department'] ?? '');
                if ($chkDept !== '') {
                    $chkYr = $targetDate ? (int)date('Y', strtotime($targetDate)) : (int)date('Y');

                    $stmtSum = $pdo->prepare(
                        "SELECT COALESCE(SUM(tot_person), 0)
                         FROM ohrm_manpower_request
                         WHERE TRIM(department) = :dept
                           AND UPPER(req_type) = 'PLANNED'
                           AND YEAR(target_date) = :yr
                           AND case_id <> :caseId"
                    );
                    $stmtSum->execute([
                        ':dept' => $chkDept,
                        ':yr' => $chkYr,
                        ':caseId' => $case['caseId']
                    ]);
                    // SUM DB TANPA case ini + tot_person case ini — hindari double-count.
                    $sumPlanned = (int)$stmtSum->fetchColumn() + (int)$case['totPerson'];

                    $stmtQuota = $pdo->prepare(
                        "SELECT total_additional
                         FROM ohrm_ext_department_quota
                         WHERE TRIM(dept_name) = :dept AND year = :yr"
                    );
                    $stmtQuota->execute([':dept' => $chkDept, ':yr' => $chkYr]);
                    $quotaPlanned = (int)$stmtQuota->fetchColumn();

                    if ($sumPlanned > $quotaPlanned) {
                        $overQuota[] = 'Case ' . $case['caseId'] . ' melebihi dari quota planned ('
                            . $chkDept . ': ' . $sumPlanned . ' >= ' . $quotaPlanned . ')';
                        continue; // TIDAK disimpan ke DB
                    }
                }
            }

            // Eksekusi Simpan/Update Master Request
            $stmtRequest->execute([
                ':case_id' => $case['caseId'],
                ':case_class' => $case['caseClass'],
                ':status' => $case['status'],
                ':subject' => $case['subject'],
                ':owner' => $case['owner'],
                ':note_id' => $case['noteId'],
                ':quota_note_id' => $quotaNoteId,
                ':department' => $case['department'],
                                ':branch' => $case['branch'] ?? null,
                                ':position' => $case['position'],
                ':req_type' => $case['reqType'],
                ':tot_person' => (int)$case['totPerson'],
                ':gender' => $case['gender'],
                ':empl_status' => $case['emplStatus'],
                ':skill' => $case['skill'],
                ':target_date' => $targetDate,
                ':job_desc' => $case['jobDesc'],
                ':reason' => $case['reason'],
                ':raw_attributes' => $rawAttributes
            ]);
            $insertedCases++;

            // Eksekusi Simpan/Update Detail Approvals
            if (isset($case['approvals']) && is_array($case['approvals'])) {
                foreach ($case['approvals'] as $task) {
                    $stmtApproval->execute([
                        ':case_id' => $case['caseId'],
                        ':task_id' => $task['NoteID']['value'] ?? '-',
                        ':summary' => $task['Summary']['value'] ?? '-',
                        ':owner' => resolveTaskOwnerName($task, $caseOwnerFallbackName ?? null),
                        ':priority' => $task['Priority']['value'] ?? '-',
                        ':status' => $task['Status']['value'] ?? '-',
                        ':approval_date' => parseAcumaticaDateTime($task['CompletedAt']['value'] ?? null)
                    ]);
                    $insertedApprovals++;
                                        // LOG HISTORY — hanya jika ada baris baru / terjadi perubahan
                                        // (rowCount MySQL: INSERT=1, UPDATE berubah=2, tak berubah=0).
                                        if ($stmtApproval->rowCount() > 0) {
                                            $stmtApprovalHistory->execute([
                                                ':case_id' => $case['caseId'],
                                                ':task_id' => $task['NoteID']['value'] ?? '-',
                                                ':summary' => $task['Summary']['value'] ?? '-',
                                                ':owner' => resolveTaskOwnerName($task, $caseOwnerFallbackName ?? null),
                                                ':priority' => $task['Priority']['value'] ?? '-',
                                                ':status' => $task['Status']['value'] ?? '-',
                                            ]);
                                        }
                                    }
                                }
                            }

        // ==========================================
        // UPDATE STATUS APPROVAL TASK CASE LAMA (2026-08-06)
        // Frontend mengirim approvalUpdates [{caseId, status, approvals}] → di-simpan via
        // upsert yang sama (unique idx_case_task) supaya status selalu sesuai Acumatica.
        // Juga update status case jika berubah (mis. Closed → Open).
        // ==========================================
        $approvalUpdates = $data['approvalUpdates'] ?? [];
        if (!empty($approvalUpdates) && is_array($approvalUpdates)) {
            // Prepare statement update status case
            $stmtUpdateCaseStatus = $pdo->prepare("
                UPDATE ohrm_manpower_request
                SET status = :status, updated_at = CURRENT_TIMESTAMP
                WHERE case_id = :case_id
                  AND UPPER(COALESCE(status, '')) <> 'REJECTED'
                  AND UPPER(COALESCE(status, '')) <> UPPER(:status)
            ");

            // Prepare statement update owner case
            $stmtUpdateCaseOwner = $pdo->prepare("
                UPDATE ohrm_manpower_request
                SET owner = :owner, updated_at = CURRENT_TIMESTAMP
                WHERE case_id = :case_id
                  AND COALESCE(owner, '') <> :owner
            ");

            foreach ($approvalUpdates as $auEntry) {
                $auCaseId = $auEntry['caseId'] ?? null;
                if ($auCaseId === null || $auCaseId === '') {
                    continue;
                }
                // Nama owner case terbaru (fallback owner task numerik), dikirim sync
                // lewat $auEntry['caseOwner'] kalau ada.
                $caseOwnerFallbackName = $auEntry['caseOwner'] ?? ($auEntry['owner'] ?? null);

                // Update status case jika ada perubahan
                $auStatus = $auEntry['status'] ?? null;
                if ($auStatus !== null && $auStatus !== '') {
                    $stmtUpdateCaseStatus->execute([
                        ':case_id' => $auCaseId,
                        ':status' => $auStatus,
                    ]);
                }

                // Update owner case jika ada perubahan
                $auOwner = $auEntry['owner'] ?? null;
                if ($auOwner !== null && $auOwner !== '') {
                    $stmtUpdateCaseOwner->execute([
                        ':case_id' => $auCaseId,
                        ':owner' => $auOwner,
                    ]);
                }

                $auApprovals = $auEntry['approvals'] ?? [];
                if (is_array($auApprovals)) {
                    foreach ($auApprovals as $task) {
                        $stmtApproval->execute([
                            ':case_id' => $auCaseId,
                            ':task_id' => $task['NoteID']['value'] ?? '-',
                            ':summary' => $task['Summary']['value'] ?? '-',
                            ':owner' => resolveTaskOwnerName($task, $caseOwnerFallbackName ?? null),
                            ':priority' => $task['Priority']['value'] ?? '-',
                            ':status' => $task['Status']['value'] ?? '-',
                            ':approval_date' => parseAcumaticaDateTime($task['CompletedAt']['value'] ?? null)
                        ]);
                        $countApprovalUpdates++;
                                                // LOG HISTORY — sama seperti loop case baru
                                                if ($stmtApproval->rowCount() > 0) {
                                                    $stmtApprovalHistory->execute([
                                                        ':case_id' => $auCaseId,
                                                        ':task_id' => $task['NoteID']['value'] ?? '-',
                                                        ':summary' => $task['Summary']['value'] ?? '-',
                                                        ':owner' => resolveTaskOwnerName($task, $caseOwnerFallbackName ?? null),
                                                        ':priority' => $task['Priority']['value'] ?? '-',
                                                        ':status' => $task['Status']['value'] ?? '-',
                                                    ]);
                                                }
                                            }
                                        }
                                    }
                                }

        // Commit transaksi jika semua berhasil
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // ==========================================
    // AUTO-LINK VACANCY berdasarkan case_id yang cocok
    // ==========================================
    $linkMessage = '';
    try {
        require_once __DIR__ . '/link_manpower_vacancies.php';
        $linkResult = linkManpowerVacancies($pdo);
        $linkMessage = isset($linkResult['message']) ? ' | ' . $linkResult['message'] : '';
    } catch (Exception $e) {
        $linkMessage = ' | Auto-link gagal: ' . $e->getMessage();
    }

    // ============================================================
    // AUTO PUSH APPROVAL REJECTED (2026-08-11, Opsi B)
    // Jika ada approval task berstatus REJECTED yang BELUM pernah
    // di-push (rejected_pushed_at IS NULL): PUT Description + Status
    // -> Closed di Acumatica, stempel rejected_pushed_at 1x.
    // Best-effort: kegagalan push TIDAK menggagalkan sync.
    // ============================================================
    $rejectedPushMessages = [];
    try {
        require_once __DIR__ . '/close_case_acumatica.php';

        $stmtRej = $pdo->prepare(
            "SELECT a.id AS approval_id, a.case_id, a.summary,
                    mr.subject, mr.req_type, mr.department, mr.created_at
             FROM ohrm_manpower_approval a
             JOIN ohrm_manpower_request mr ON mr.case_id = a.case_id
             WHERE UPPER(TRIM(a.status)) = 'REJECTED'
               AND a.rejected_pushed_at IS NULL"
        );
        $stmtRej->execute();
        $rejectedApprovals = $stmtRej->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rejectedApprovals as $rej) {
            $caseId = (string)$rej['case_id'];
            $descDate = date('Y-m-d H:i:s', strtotime($rej['created_at'] ?: 'now'));
            $description = "=== Manpower Request ({$descDate}) ===<br>" .
                "Case: {$caseId} | Department: " . ($rej['department'] ?? '-') . "<br>" .
                "Request Type: " . ($rej['req_type'] ?? '-') . " | Approval: <span style=\"background-color:#ff0000; color:#ffffff\"><b>APPROVAL: REJECTED</b></span>";

            $pushResult = closeCaseInAcumatica($caseId, $description);

            if (($pushResult['status'] ?? '') === 'ok') {
                $stmtStamp = $pdo->prepare(
                    "UPDATE ohrm_manpower_approval SET rejected_pushed_at = NOW() WHERE id = :id"
                );
                $stmtStamp->execute([':id' => (int)$rej['approval_id']]);

                // Sinkronkan MPR lokal ke 'Rejected' — idempotent.
                $stmtMprRejected = $pdo->prepare(
                    "UPDATE ohrm_manpower_request
                     SET status = 'Rejected', updated_at = CURRENT_TIMESTAMP
                     WHERE case_id = :case_id
                       AND UPPER(COALESCE(status, '')) <> 'REJECTED'"
                );
                $stmtMprRejected->execute([':case_id' => $caseId]);

                $rejectedPushMessages[] = $caseId . ' (Approval REJECTED)';
            } else {
                $rejectedPushMessages[] = $caseId . ' GAGAL: ' . ($pushResult['message'] ?? 'unknown');
            }
        }
    } catch (Throwable $e) {
        $rejectedPushMessages[] = 'Error: ' . $e->getMessage();
    }

    $skipMessage = '';
    if (!empty($skipped)) {
        $skipMessage = ' | SKIPPED (Planned tanpa kuota completed): ' . implode(', ', $skipped);
    }

    $overQuotaMessage = '';
    if (!empty($overQuota)) {
        $overQuotaMessage = ' | OVER QUOTA PLANNED: ' . implode(', ', $overQuota);
    }

    $rejectedPushMessage = '';
    if (!empty($rejectedPushMessages)) {
        $rejectedPushMessage = ' | APPROVAL REJECTED PUSH: ' . implode(', ', $rejectedPushMessages);
    }

    return [
        'status' => 'success',
        'message' => "Berhasil menyimpan $insertedCases Request dan $insertedApprovals Approval, $countApprovalUpdates Approval case lama di-update." . $linkMessage . $skipMessage . $overQuotaMessage . $rejectedPushMessage,
        'skipped' => $skipped,
        'overQuota' => $overQuota
    ];
}

// ==========================================
// JALANKAN LANGSUNG jika diakses sebagai endpoint HTTP (bukan via include)
// ==========================================
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()]);
        exit;
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['casesDetail']) || !is_array($data['casesDetail'])) {
        echo json_encode(['status' => 'error', 'message' => 'Data casesDetail tidak ditemukan atau format salah.']);
        exit;
    }

    try {
        $result = saveManpowerPayload($pdo, $data);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal menyimpan ke database: ' . $e->getMessage()
        ]);
    }
}