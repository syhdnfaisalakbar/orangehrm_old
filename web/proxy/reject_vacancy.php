<?php
require_once __DIR__ . '/_config.php';
/**
 * Reject Vacancy — set status Reject di vacancy & Manpower Request.
 *
 * POST /proxy/reject_vacancy.php
 * Body: { "vacancy_id": 68 }
 *
 * Efek (satu transaksi):
 *   - ohrm_job_vacancy:
 *       rejected        = 1
 *       status          = 0 (tidak aktif)
 *       no_of_positions = 0
 *   - ohrm_manpower_request ter-link (kolom langsung ATAU link table):
 *       status   = 'Rejected'
 *       tot_person = 0
 *
 * Response: { "status": "ok", "message": "...", "vacancy_id": 68, "mpr_id": 12 }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$vacancyId = isset($input['vacancy_id']) ? intval($input['vacancy_id']) : 0;

if ($vacancyId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Parameter vacancy_id wajib diisi']);
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
    echo json_encode(['status' => 'error', 'message' => 'Koneksi DB gagal: ' . $e->getMessage()]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Cek vacancy ada
    $stmtCheck = $pdo->prepare('SELECT id FROM ohrm_job_vacancy WHERE id = :id');
    $stmtCheck->execute([':id' => $vacancyId]);
    if (!$stmtCheck->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['status' => 'error', 'message' => "Vacancy id {$vacancyId} tidak ditemukan"]);
        exit;
    }

    // 2. Update vacancy: rejected = 1, status = 0, no_of_positions = 0
    $stmtUpdV = $pdo->prepare(
        "UPDATE ohrm_job_vacancy
         SET rejected = 1, status = 0, no_of_positions = 0, updated_time = NOW()
         WHERE id = :id"
    );
    $stmtUpdV->execute([':id' => $vacancyId]);

    // 3. Cari MPR ter-link (kolom langsung ATAU link table)
    $stmtMpr = $pdo->prepare("
        SELECT mr.id
        FROM ohrm_job_vacancy v
        JOIN ohrm_manpower_request mr
             ON COALESCE(v.manpower_request_id,
                 (SELECT MAX(l.manpower_request_id)
                  FROM ohrm_manpower_vacancy_link l
                  WHERE l.vacancy_id = v.id)) = mr.id
        WHERE v.id = :id
        LIMIT 1
    ");
    $stmtMpr->execute([':id' => $vacancyId]);
    $mprRow = $stmtMpr->fetch(PDO::FETCH_ASSOC);

    $mprId = $mprRow ? (int) $mprRow['id'] : null;

    // 4. Update MPR: status = 'Rejected', tot_person = 0
    if ($mprId !== null) {
        $stmtUpdM = $pdo->prepare(
            "UPDATE ohrm_manpower_request
             SET status = 'Rejected', tot_person = 0, updated_at = NOW()
             WHERE id = :id"
        );
        $stmtUpdM->execute([':id' => $mprId]);
    }

    $pdo->commit();

    // ============================================================
    // 5a. RECOMPUTE KUOTA REPLACEMENT (2026-08-26)
    // Reject vacancy harus melepas kontribusinya dari kolom Replacement
    // ohrm_ext_department_quota. Daripada decrement naif (bisa salah
    // kalau vacancy belum pernah di-close), hitung ulang replacement
    // untuk SEMUA dept/tahun dari vacancy yang MASIH VALID:
    //   req_type='REPLACEMENT' AND status=0 (closed) AND rejected != 1
    // Rejected vacancy no_of_positions-nya sudah 0 → otomatis keluar.
    // Idempotent → aman dijalankan tiap reject (sekaligus backfill data
    // lama yang masih nyangkut). Best-effort: gagal pun reject tetap ok.
    // ============================================================
    $recomputeResult = null;
    try {
        // UPSERT (2026-09-09): selain UPDATE, INSERT baris dept/tahun yang belum ada —
        // replacement di-record meski belum ada baris kuota thn itu.
        $recomputeSql = "
            INSERT INTO ohrm_ext_department_quota
                (dept_name, year, total_additional, replacement, reduction, unplanned)
            SELECT
                vm.dept_name,
                vm.yr,
                0,
                COALESCE(vm.repl, 0),
                0,
                COALESCE(vm.unpl, 0)
            FROM (
                SELECT TRIM(mr.department) AS dept_name,
                       YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                       SUM(COALESCE(v.no_of_positions, 1)) AS repl,
                       0 AS unpl
                FROM ohrm_job_vacancy v
                JOIN ohrm_manpower_request mr
                     ON (COALESCE(v.manpower_request_id,
                         (SELECT MAX(l.manpower_request_id)
                          FROM ohrm_manpower_vacancy_link l
                          WHERE l.vacancy_id = v.id)) = mr.id
                         OR (v.manpower_request_id IS NULL
                             AND NOT EXISTS(
                                 SELECT 1 FROM ohrm_manpower_vacancy_link x
                                 WHERE x.vacancy_id = v.id)
                             AND mr.case_id IS NOT NULL
                             AND v.case_id IS NOT NULL
                             AND (mr.case_id = v.case_id
                                  OR CAST(mr.case_id AS UNSIGNED) = CAST(v.case_id AS UNSIGNED))))
                WHERE UPPER(COALESCE(v.req_type, '')) = 'REPLACEMENT'
                  AND v.status = 0
                  AND v.rejected != 1
                GROUP BY TRIM(mr.department),
                         YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
            ) vm
            ON DUPLICATE KEY UPDATE
                replacement = VALUES(replacement)
        ";
        $pdo->exec($recomputeSql);
        $recomputeResult = ['status' => 'ok'];
    } catch (\Throwable $e) {
        $recomputeResult = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // ============================================================
    // 5. PUSH CLOSE KE ACUMATICA (2026-08-07)
    // Reject vacancy → Case REQFPTK terkait di Acumatica ikut di-close
    // (Status = Closed) supaya sinkron saat sync berikutnya. Description
    // case ikut di-update jadi blok REJECTED (replace total). Best-effort:
    // kalau push gagal, reject lokal TETAP sukses (tidak menggagalkan).
    // ============================================================
    $pushResult = null;
    try {
        $stmtCase = $pdo->prepare(
            "SELECT v.case_id,
                    v.name AS vacancy_name,
                    COALESCE(NULLIF(v.req_type, ''), mr.req_type, '-') AS req_type,
                    COALESCE(NULLIF(mr.created_at, '0000-00-00 00:00:00'), mr.created_at, NOW()) AS created_at
             FROM ohrm_job_vacancy v
             LEFT JOIN ohrm_manpower_request mr
                  ON COALESCE(v.manpower_request_id,
                     (SELECT MAX(l.manpower_request_id)
                      FROM ohrm_manpower_vacancy_link l
                      WHERE l.vacancy_id = v.id)) = mr.id
             WHERE v.id = :id"
        );
        $stmtCase->execute([':id' => $vacancyId]);
        $caseRow = $stmtCase->fetch(PDO::FETCH_ASSOC);

        if ($caseRow && $caseRow['case_id']) {
            // Bangun Description format blok REJECTED (replace total)
            $descDate = date('Y-m-d H:i:s', strtotime($caseRow['created_at'] ?: 'now'));
            $description = "=== Recruitment ({$descDate}) ===<br>" .
                "Vacancy: " . ($caseRow['vacancy_name'] ?? '') . "<br>" .
                "Request Type: " . ($caseRow['req_type'] ?? '-') . "<br>" .
                "Status: <b>REJECTED</b>";

            require_once __DIR__ . '/close_case_acumatica.php';
            $pushResult = closeCaseInAcumatica((string)$caseRow['case_id'], $description);
        }
    } catch (\Throwable $e) {
        // best-effort — jangan sampai reject gagal karena push error
        $pushResult = ['status' => 'error', 'message' => 'Push gagal: ' . $e->getMessage()];
    }

    echo json_encode([
        'status'     => 'ok',
        'message'    => "Vacancy {$vacancyId} berhasil di-Reject" . ($mprId !== null ? " (MPR {$mprId} ikut Rejected)" : ''),
        'vacancy_id' => $vacancyId,
        'mpr_id'     => $mprId,
        'recompute'  => $recomputeResult,
        'push'       => $pushResult,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Gagal reject: ' . $e->getMessage()]);
}
