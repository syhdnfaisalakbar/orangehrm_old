<?php
require_once __DIR__ . '/_config.php';

/**
 * Sync Application Data to Acumatica Cases
 * 
 * Mengirim SEMUA data aplikasi untuk suatu Case ID ke Acumatica Description.
 * Tidak perlu GET dulu dari Acumatica — data diambil dari database lokal.
 * 
 * Panggil:   POST /proxy/sync_application_to_acumatica.php
 * Body:      { "candidate_id": 16, "vacancy_id": 62 }
 * Response:  { "status": "ok", "message": "...", "updated": 1 }
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// =============================================
// 1. KONEKSI DATABASE LOKAL
// =============================================
$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi DB gagal: ' . $e->getMessage()]);
    exit;
}

// =============================================
// HELPER: DATA PEMENUHAN KUOTA DEPARTEMEN (Resource Fulfillments)
// Logika sama dengan get_resource_fulfillment.php, tapi per departemen+tahun.
// =============================================
function getDeptFulfillment(PDO $pdo, string $deptName, int $year): ?array
{
    // Driver = MPR di dept+thn itu. Keputusan user 2026-09-09:
    // quota_akhir = SUM(tot_person) MPR (bukan ohrm_ext_department_quota),
    // terpenuhi/lowongan juga dikunci ke tahun MPR (bukan tahun baris kuota).
    $yrExpr  = "YEAR(COALESCE(NULLIF(mm.target_date, '0000-00-00'), mm.created_at))";
    $mapping = "COALESCE(vv.manpower_request_id,
                            (SELECT MAX(l.manpower_request_id) FROM ohrm_manpower_vacancy_link l
                             WHERE l.vacancy_id = vv.id)) = mm.id
                OR (vv.manpower_request_id IS NULL
                    AND NOT EXISTS(
                        SELECT 1 FROM ohrm_manpower_vacancy_link x
                        WHERE x.vacancy_id = vv.id)
                    AND mm.case_id IS NOT NULL
                    AND vv.case_id IS NOT NULL
                    AND (mm.case_id = vv.case_id
                         OR CAST(mm.case_id AS UNSIGNED) = CAST(vv.case_id AS UNSIGNED)))";

    $sql = "
        SELECT
            m.dept_name,
            :year AS year,
            (COALESCE(pl.planned, 0) + COALESCE(qq.replacement, 0) + COALESCE(qq.unplanned, 0) - COALESCE(qq.reduction, 0)) AS quota_akhir,
            COALESCE(h.hired_count, 0)     AS terpenuhi,
            COALESCE(v.open_positions, 0)  AS lowongan_berjalan
        FROM (
            SELECT TRIM(department) AS dept_name
            FROM ohrm_manpower_request
            WHERE TRIM(department) = :dept
              AND YEAR(COALESCE(NULLIF(target_date, '0000-00-00'), created_at)) = :year
            LIMIT 1
        ) m
        LEFT JOIN (
            -- planned (All Quota Department): SUM tot_person approved HRPLAN utk dept+yr
            SELECT TRIM(attr_dept.attribute_value) AS dept_name,
                   CAST(attr_year.attribute_value AS UNSIGNED) AS yr,
                   SUM(CAST(attr_tot.attribute_value AS UNSIGNED)) AS planned
            FROM ohrm_quota_requests qr
            INNER JOIN (
                SELECT ref_note_id, approval_status,
                       ROW_NUMBER() OVER (
                           PARTITION BY ref_note_id
                           ORDER BY FIELD(approval_status, 'Completed', 'Open', 'Rejected') ASC,
                                    approval_id DESC
                       ) AS rn
                FROM ohrm_approval_history
            ) la ON la.ref_note_id = qr.note_id AND la.rn = 1
            INNER JOIN ohrm_case_attributes attr_dept ON attr_dept.ref_note_id = qr.note_id AND attr_dept.attribute_name = 'DEPARTMENT'
            INNER JOIN ohrm_case_attributes attr_year ON attr_year.ref_note_id = qr.note_id AND attr_year.attribute_name = 'YEAR'
            INNER JOIN ohrm_case_attributes attr_tot ON attr_tot.ref_note_id = qr.note_id AND attr_tot.attribute_name = 'TOTPERSON'
            WHERE la.approval_status = 'Completed'
            GROUP BY TRIM(attr_dept.attribute_value), CAST(attr_year.attribute_value AS UNSIGNED)
        ) pl ON pl.dept_name = m.dept_name AND pl.yr = :year
        LEFT JOIN (
            -- kolom kuota dept (All Quota Department): replacement/unplanned/reduction
            SELECT TRIM(dept_name) AS dept_name, year,
                   COALESCE(replacement, 0) AS replacement,
                   COALESCE(unplanned, 0)   AS unplanned,
                   COALESCE(reduction, 0)   AS reduction
            FROM ohrm_ext_department_quota
        ) qq ON qq.dept_name = m.dept_name AND qq.year = :year
        LEFT JOIN (
            SELECT TRIM(mm.department) AS dept_name, COUNT(DISTINCT cv.candidate_id) AS hired_count
            FROM ohrm_manpower_request mm
            JOIN ohrm_job_vacancy vv ON $mapping
            JOIN ohrm_job_candidate_vacancy cv ON cv.vacancy_id = vv.id
            WHERE cv.status = 'HIRED'
              AND $yrExpr = :year
            GROUP BY TRIM(mm.department)
        ) h ON h.dept_name = m.dept_name
        LEFT JOIN (
            SELECT TRIM(mm.department) AS dept_name, SUM(COALESCE(vv.no_of_positions, 1)) AS open_positions
            FROM ohrm_manpower_request mm
            JOIN ohrm_job_vacancy vv ON $mapping
            WHERE vv.status = 1
              AND $yrExpr = :year
            GROUP BY TRIM(mm.department)
        ) v ON v.dept_name = m.dept_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':dept' => $deptName, ':year' => $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Tidak ada MPR dept+thn ini → tidak ada data fulfillment.
        return null;
    }

    $quotaAkhir = (int) $row['quota_akhir'];
    $terpenuhi  = (int) $row['terpenuhi'];
    $lowongan   = (int) $row['lowongan_berjalan'];
    $tersedia   = $quotaAkhir - $terpenuhi - $lowongan;

    if ($lowongan > 0) {
        $status = 'In Progress';
    } elseif ($tersedia < 0) {
        $status = 'Lebih';
    } elseif ($tersedia === 0) {
        $status = 'Terpenuhi';
    } else {
        $status = 'Kurang';
    }

    return [
        'dept_name'         => $row['dept_name'],
        'year'              => (int) $row['year'],
        'quota_akhir'       => $quotaAkhir,
        'terpenuhi'         => $terpenuhi,
        'lowongan_berjalan' => $lowongan,
        'quota_tersedia'    => $tersedia,
        'status'            => $status,
    ];
}

/**
 * Recompute kolom replacement & unplanned di ohrm_ext_department_quota
 * dari vacancy yang SUDAH CLOSED (status=0, rejected!=1):
 *   - Replacement → kolom replacement
 *   - Unplanned   → kolom unplanned
 * IDEMPOTEN — bukan tambah (+), tapi hitung ulang dari kondisi aktual,
 * jadi aman dipanggil berulang (dan menyembuhkan double-count lama —
 * vacancy yang di-close lewat dua jalur tidak akan ditambah 2x).
 * Atribusi tahun pakai YEAR(mr.target_date) (fallback created_at) — SAMA
 * dengan get_resource_fulfillment.php / reject_vacancy recompute.
 *
 * @return string|null pesan ringkas kalau eksekusi sukses, null kalau gagal
 */
function recomputeClosedVacancyQuota(PDO $pdo): ?string
{
    // UPSERT (2026-09-09): selain UPDATE baris existing, INSERT baris dept/tahun
    // yang BELUM ada di ohrm_ext_department_quota — biar replacement/unplanned dari
    // vacancy closed tetap ke-record meski belum ada baris kuota thn itu (mis. IT 2026).
    // Idempotent: nilai dihitung ulang dari kondisi aktual (aman dipanggil berulang).
    $sql = "
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
            SELECT
                TRIM(mr.department) AS dept_name,
                YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                SUM(CASE WHEN UPPER(COALESCE(v.req_type, '')) = 'REPLACEMENT'
                         THEN COALESCE(v.no_of_positions, 1) ELSE 0 END) AS repl,
                SUM(CASE WHEN UPPER(COALESCE(v.req_type, '')) = 'UNPLANNED'
                         THEN COALESCE(v.no_of_positions, 1) ELSE 0 END) AS unpl
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
            WHERE v.status = 0
              AND v.rejected != 1
              AND UPPER(COALESCE(v.req_type, '')) IN ('REPLACEMENT', 'UNPLANNED')
            GROUP BY TRIM(mr.department),
                     YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
        ) vm
        ON DUPLICATE KEY UPDATE
            replacement = VALUES(replacement),
            unplanned   = VALUES(unplanned)
    ";
    $affected = $pdo->exec($sql);
    return $affected === false
        ? null
        : "Quota Replacement/Unplanned di-recompute ({$affected} baris upsert)";
}

/**
 * Ambil baris kuota departemen (All Quota Department) untuk dept+tahun.
 * Fallback tahun: kalau dept punya baris kuota tapi DI TAHUN YANG BEDA dari
 * yang diminta (mis. MPR year 2026 vs kuota 2027), ambil baris kuota dept
 * tsb (year terbesar). Keputusan user 2026-09-09: blok quota wajib ikut
 * terkirim walau tahun MPR != tahun kuota — ikut tahun kuota.
 */
function getQuotaDepartmentRow(PDO $pdo, string $deptName, int $year): ?array
{
    $stmt = $pdo->prepare("
        SELECT dept_name, year, total_additional, replacement, reduction, unplanned
        FROM ohrm_ext_department_quota
        WHERE TRIM(dept_name) = :dept AND year = :year
        LIMIT 1
    ");
    $stmt->execute([':dept' => $deptName, ':year' => $year]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    // Fallback: dept punya kuota di tahun lain → pakai year terbesar.
    $stmt2 = $pdo->prepare("
        SELECT dept_name, year, total_additional, replacement, reduction, unplanned
        FROM ohrm_ext_department_quota
        WHERE TRIM(dept_name) = :dept AND year >= :year
        ORDER BY year ASC LIMIT 1
    ");
    $stmt2->execute([':dept' => $deptName, ':year' => $year]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row2) {
        return $row2;
    }
    return null;
}

// Build blok HTML tabel kuota departemen (All Quota Department) untuk Description Acumatica
function buildQuotaDepartmentBlock(?array $row, string $deptName, int $year): string
{
    $head = "=== Quota Department: " . htmlspecialchars($deptName) . " ({$year}) ===<br>";

    if (!$row) {
        return $head . "<i>Tidak ada data kuota untuk departemen ini pada tahun {$year}.</i><br>";
    }

    return $head .
        "<table border='1' cellpadding='4' cellspacing='0' style='border-collapse: collapse; font-size: 12px;'>" .
        "<tr style='background-color: #f2f2f2; font-weight: bold;'>" .
        "<th style='padding: 4px 8px;'>Department</th>" .
        "<th style='padding: 4px 8px;'>Year</th>" .
        "<th style='padding: 4px 8px;'>Planned</th>" .
        "<th style='padding: 4px 8px;'>Replacement</th>" .
        "<th style='padding: 4px 8px;'>Reduction</th>" .
        "<th style='padding: 4px 8px;'>Unplanned</th>" .
        "</tr>" .
        "<tr>" .
        "<td style='padding: 4px 8px;'>" . htmlspecialchars($row['dept_name']) . "</td>" .
        "<td style='padding: 4px 8px;'>{$row['year']}</td>" .
        "<td style='padding: 4px 8px;'>{$row['total_additional']}</td>" .
        "<td style='padding: 4px 8px;'>{$row['replacement']}</td>" .
        "<td style='padding: 4px 8px;'>{$row['reduction']}</td>" .
        "<td style='padding: 4px 8px;'>{$row['unplanned']}</td>" .
        "</tr></table><br>";
}

// Build blok HTML tabel pemenuhan kuota untuk Description Acumatica
function buildQuotaBlock(?array $f, string $deptName, int $year): string
{
    $head = "=== Quota Fulfillment: " . htmlspecialchars($deptName) . " ({$year}) ===<br>";

    if (!$f) {
        return $head . "<i>Tidak ada data kuota untuk departemen ini pada tahun {$year}.</i><br>";
    }

    // Warna badge status — konsisten dengan ResourceFulfillmentStatusBadge.vue
    $statusBg   = '#f1f3f4';
    $statusColor = '#5f6368';
    if ($f['status'] === 'Terpenuhi') {
        $statusBg   = '#e6f4ea'; // Hijau
        $statusColor = '#1e8e3e';
    } elseif ($f['status'] === 'Kurang') {
        $statusBg   = '#fce8e6'; // Merah
        $statusColor = '#c5221f';
    } elseif ($f['status'] === 'Lebih') {
        $statusBg   = '#e8f0fe'; // Biru
        $statusColor = '#1a73e8';
    } elseif ($f['status'] === 'In Progress') {
        $statusBg   = '#fef7e0'; // Kuning — konsisten ResourceFulfillmentStatusBadge.vue
        $statusColor = '#f9ab00';
    }

    return $head .
        "<table border='1' cellpadding='4' cellspacing='0' style='border-collapse: collapse; font-size: 12px;'>" .
        "<tr style='background-color: #f2f2f2; font-weight: bold;'>" .
        "<th style='padding: 4px 8px;'>Department</th>" .
        "<th style='padding: 4px 8px;'>Year</th>" .
        "<th style='padding: 4px 8px;'>Quota Akhir</th>" .
        "<th style='padding: 4px 8px;'>Terpenuhi</th>" .
        "<th style='padding: 4px 8px;'>Lowongan Berjalan</th>" .
        "<th style='padding: 4px 8px;'>Quota Tersedia</th>" .
        "<th style='padding: 4px 8px;'>Status</th>" .
        "</tr>" .
        "<tr>" .
        "<td style='padding: 4px 8px;'>" . htmlspecialchars($f['dept_name']) . "</td>" .
        "<td style='padding: 4px 8px;'>{$f['year']}</td>" .
        "<td style='padding: 4px 8px;'>{$f['quota_akhir']}</td>" .
        "<td style='padding: 4px 8px;'>{$f['terpenuhi']}</td>" .
        "<td style='padding: 4px 8px;'>{$f['lowongan_berjalan']}</td>" .
        "<td style='padding: 4px 8px;'>{$f['quota_tersedia']}</td>" .
        "<td style='padding: 4px 8px; background-color: {$statusBg}; color: {$statusColor};'><b>{$f['status']}</b></td>" .
        "</tr></table><br>";
}

// =============================================
// 2. INPUT & CASE ID
// =============================================
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$candidateId = isset($input['candidate_id']) ? intval($input['candidate_id']) : 0;
$vacancyId = isset($input['vacancy_id']) ? intval($input['vacancy_id']) : 0;

if ($candidateId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Parameter candidate_id wajib diisi']);
    exit;
}

// Kalau vacancy_id tidak dikirim (mis. panggilan otomatis dari Hire),
// resolve dari record candidate_vacancy — prioritas vacancy dengan
// status HIRED, fallback ke record terbaru.
if ($vacancyId <= 0) {
    try {
        $stmtV = $pdo->prepare("
            SELECT vacancy_id
            FROM ohrm_job_candidate_vacancy
            WHERE candidate_id = :cid
            ORDER BY (UPPER(status) = 'HIRED') DESC, id DESC
            LIMIT 1
        ");
        $stmtV->execute([':cid' => $candidateId]);
        $resolvedV = $stmtV->fetch(PDO::FETCH_ASSOC);
        if ($resolvedV) {
            $vacancyId = (int) $resolvedV['vacancy_id'];
        }
    } catch (Exception $e) {
        // abaikan — nanti error jelas di bawah kalau vacancy tetap 0
    }
}

if ($vacancyId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Vacancy tidak ditemukan untuk candidate ini']);
    exit;
}

// =============================================
// 3. CARI CASE_ID DARI VACANCY INI
// =============================================
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(ojv.case_id, r.case_id) AS case_id
        FROM ohrm_job_vacancy ojv
        LEFT JOIN ohrm_manpower_request r ON ojv.manpower_request_id = r.id
        WHERE ojv.id = :vacancy_id
        HAVING case_id IS NOT NULL AND case_id != ''
        LIMIT 1
    ");
    $stmt->execute([':vacancy_id' => $vacancyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['case_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Vacancy ini tidak memiliki Case ID yang terhubung']);
        exit;
    }
    $caseId = trim($row['case_id']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Query case_id gagal: ' . $e->getMessage()]);
    exit;
}

// =============================================
// 4. AMBIL SEMUA APLIKASI UNTUK CASE ID INI
// =============================================
try {
    $stmt = $pdo->prepare("
        SELECT 
            ojv.id AS vacancy_id,
            ojv.name AS vacancy_name,
            TRIM(CONCAT(
                COALESCE(ojc.first_name, ''),
                ' ',
                TRIM(COALESCE(ojc.middle_name, '')),
                ' ',
                COALESCE(ojc.last_name, '')
            )) AS candidate_name,
            ojcv.status AS app_status,
            ojcv.applied_date,
            ojc.id AS candidate_id,
            COALESCE(ojv.req_type, mr.req_type, '-') AS request_type
        FROM ohrm_job_candidate_vacancy ojcv
        JOIN ohrm_job_vacancy ojv ON ojcv.vacancy_id = ojv.id
        JOIN ohrm_job_candidate ojc ON ojcv.candidate_id = ojc.id
        LEFT JOIN ohrm_manpower_request mr ON ojv.manpower_request_id = mr.id
        WHERE ojv.case_id = :case_id OR ojv.case_id = CONCAT('0000000', :case_id2)
        ORDER BY ojcv.applied_date DESC, ojc.id DESC
    ");
    $stmt->execute([
        ':case_id' => $caseId,
        ':case_id2' => ltrim($caseId, '0'),
    ]);
    $allApplications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Query aplikasi gagal: ' . $e->getMessage()]);
    exit;
}

if (empty($allApplications)) {
    echo json_encode([
        'status' => 'ok',
        'message' => 'Tidak ada data aplikasi untuk Case ID ' . $caseId,
        'updated' => 0,
    ]);
    exit;
}

// =============================================
// 4c. AUTO-CLOSE JIKA HIRED SUDAH MEMENUHI TOTAL POSISI
// Hitung jumlah kandidat HIRED per vacancy; kalau jumlahnya sudah
// >= no_of_positions vacancy → tutup vacancy tsb (status=0),
// lanjut tutup MPR (kalau SEMUA vacancy di MPR itu closed),
// lalu push Status Closed ke Acumatica (best-effort).
// Dilakukan SEBELUM blok kuota (4b) supaya angka Resource Fulfillments
// di Description mencerminkan vacancy yang baru ditutup.
// =============================================
$autoCloseMessages = [];
$autoClosedMprs = [];
try {
    // 0. Hitung jumlah kandidat HIRED per vacancy
    $hiredCountByVacancy = [];
    foreach ($allApplications as $app) {
        if (strtoupper($app['app_status'] ?? '') === 'HIRED') {
            $vid = (int) $app['vacancy_id'];
            $hiredCountByVacancy[$vid] = ($hiredCountByVacancy[$vid] ?? 0) + 1;
        }
    }

    // 0b. Auto-close HANYA kalau jumlah HIRED sudah memenuhi total posisi
    //     (no_of_positions). Misal posisi 3 → butuh 3 kandidat HIRED.
    //     COALESCE(no_of_positions, 1): vacancy tanpa posisi eksplisit = 1.
    $hiredVacancyIds = [];
    if (!empty($hiredCountByVacancy)) {
        $ids = array_keys($hiredCountByVacancy);
        $posList = implode(',', $ids);
        $stmtPos = $pdo->prepare(
            "SELECT id, COALESCE(no_of_positions, 1) AS no_of_positions
             FROM ohrm_job_vacancy
             WHERE id IN ($posList)"
        );
        $stmtPos->execute();
        while ($rowPos = $stmtPos->fetch(PDO::FETCH_ASSOC)) {
            $vid = (int) $rowPos['id'];
            $hiredCount = (int) ($hiredCountByVacancy[$vid] ?? 0);
            $positions  = (int) $rowPos['no_of_positions'];
            if ($hiredCount >= $positions) {
                $hiredVacancyIds[] = $vid;
                $autoCloseMessages[] = "Vacancy {$vid} penuh (HIRED {$hiredCount}/{$positions} posisi)";
            }
        }
        $hiredVacancyIds = array_values(array_unique(array_filter($hiredVacancyIds)));
    }

    if (!empty($hiredVacancyIds)) {
        // 1. Tutup vacancy yang HIRED-nya sudah memenuhi total posisi
        $inList = implode(',', $hiredVacancyIds);
        $stmtCloseV = $pdo->prepare(
            "UPDATE ohrm_job_vacancy SET status = 0, updated_time = NOW() WHERE id IN ($inList)"
        );
        $stmtCloseV->execute();

        // 1b. Trigger quota: recompute replacement/unplanned dari SEMUA vacancy
        //     closed (idempotent — mencegah double-count kalau vacancy ditutup
        //     lewat lebih dari satu jalur / sync berulang).
        try {
            $recomputeMsg = recomputeClosedVacancyQuota($pdo);
            if ($recomputeMsg) {
                $autoCloseMessages[] = $recomputeMsg;
            }
        } catch (\Throwable $e) {
            $autoCloseMessages[] = 'Recompute quota gagal (dilewati): ' . $e->getMessage();
        }

        // 2. Cari MPR yang ter-link ke vacancy-vacancy tsb (via kolom langsung ATAU link table)
        $stmtFindMpr = $pdo->prepare("
            SELECT DISTINCT mr.id AS mpr_id
            FROM ohrm_job_vacancy v
            JOIN ohrm_manpower_request mr
                 ON COALESCE(v.manpower_request_id,
                     (SELECT MAX(l.manpower_request_id)
                      FROM ohrm_manpower_vacancy_link l
                      WHERE l.vacancy_id = v.id)) = mr.id
            WHERE v.id IN ($inList)
        ");
        $stmtFindMpr->execute();
        $mprRows = $stmtFindMpr->fetchAll(PDO::FETCH_ASSOC);

        $autoClosedMprs = [];
        foreach ($mprRows as $mprRow) {
            $mprId = (int) $mprRow['mpr_id'];
            // Cek: masih ada vacancy OPEN yang ter-link ke MPR ini?
            // Vacancy yang di-reject (rejected=1) TIDAK dihitung — kalau semua
            // vacancy ter-link rejected, MPR dibiarkan tetap 'Rejected'.
            $stmtOpen = $pdo->prepare("
                SELECT COUNT(*) FROM ohrm_job_vacancy v
                WHERE v.status = 1 AND v.rejected = 0
                  AND (v.manpower_request_id = :mpr
                       OR v.id IN (SELECT vacancy_id FROM ohrm_manpower_vacancy_link WHERE manpower_request_id = :mpr2))
            ");
            $stmtOpen->execute([':mpr' => $mprId, ':mpr2' => $mprId]);

            // Guard: minimal harus ada 1 vacancy non-rejected, baru boleh auto-close.
            $stmtTotal = $pdo->prepare("
                SELECT COUNT(*) FROM ohrm_job_vacancy v
                WHERE v.rejected = 0
                  AND (v.manpower_request_id = :mpr
                       OR v.id IN (SELECT vacancy_id FROM ohrm_manpower_vacancy_link WHERE manpower_request_id = :mpr2))
            ");
            $stmtTotal->execute([':mpr' => $mprId, ':mpr2' => $mprId]);

            if ((int) $stmtTotal->fetchColumn() > 0 && (int) $stmtOpen->fetchColumn() === 0) {
                $stmtUpdMpr = $pdo->prepare(
                    "UPDATE ohrm_manpower_request SET status = 'Closed' WHERE id = :id"
                );
                $stmtUpdMpr->execute([':id' => $mprId]);
                $autoClosedMprs[] = $mprId;
            }
        }

        // 3. Push Status Closed ke Acumatica DITUNDA — dijalankan SETELAH
        //    PUT Case Description (section 7b), biar Description ter-update
        //    dulu selagi case masih Open.

        $autoCloseMessages[] = 'Vacancy di-close otomatis (HIRED sudah memenuhi total posisi): ' . implode(', ', $hiredVacancyIds);
        if (!empty($autoClosedMprs)) {
            $autoCloseMessages[] = 'MPR di-close otomatis: ' . implode(', ', $autoClosedMprs);
        }
    }
} catch (\Throwable $e) {
    // Auto-close jangan sampai menghentikan sync description
    $autoCloseMessages[] = 'Auto-close gagal (dilewati): ' . $e->getMessage();
}

/**
 * Resolve MPR (manpower_request.id) dari sebuah vacancy —
 * fallback berantai: (1) kolom langsung manpower_request_id,
 * (2) ohrm_manpower_vacancy_link, (3) cocokkan case_id (normalisasi
 * strip leading zeros, mis. '3655' vs '0000003655').
 * Vacancy dari flow FPTK sering CUKUP punya case_id (tanpa link),
 * jadi wajib fallback by case_id kalau mau blok quota terkirim.
 */
function getVacancyMprId(PDO $pdo, int $vacancyId): ?int
{
    // 1) kolom langsung
    $s = $pdo->prepare(
        "SELECT manpower_request_id FROM ohrm_job_vacancy
         WHERE id = :id AND manpower_request_id IS NOT NULL LIMIT 1"
    );
    $s->execute([':id' => $vacancyId]);
    $mpr = $s->fetchColumn();
    if ($mpr) {
        return (int) $mpr;
    }

    // 2) link table
    $s = $pdo->prepare(
        "SELECT manpower_request_id FROM ohrm_manpower_vacancy_link
         WHERE vacancy_id = :id ORDER BY id DESC LIMIT 1"
    );
    $s->execute([':id' => $vacancyId]);
    $mpr = $s->fetchColumn();
    if ($mpr) {
        return (int) $mpr;
    }

    // 3) by case_id (normalisasi seperti link_manpower_vacancies.php)
    $s = $pdo->prepare(
        "SELECT case_id FROM ohrm_job_vacancy
         WHERE id = :id AND case_id IS NOT NULL AND case_id != '' LIMIT 1"
    );
    $s->execute([':id' => $vacancyId]);
    $vacCase = $s->fetchColumn();
    if ($vacCase) {
        $norm = strtolower(ltrim(trim($vacCase), '0'));
        $num  = ($norm === '' || !is_numeric($norm)) ? 0 : (int) $norm;
        $s = $pdo->prepare(
            "SELECT id FROM ohrm_manpower_request
             WHERE case_id IS NOT NULL AND case_id != ''
               AND (TRIM(case_id) = :raw
                    OR LTRIM(TRIM(case_id)) = :norm
                    OR CAST(case_id AS UNSIGNED) = :num)
             ORDER BY id ASC LIMIT 1"
        );
        $s->execute([':raw' => trim($vacCase), ':norm' => $norm, ':num' => $num]);
        $mpr = $s->fetchColumn();
        if ($mpr) {
            return (int) $mpr;
        }
    }

    return null;
}

// =============================================
// 4b. DATA PEMENUHAN KUOTA DEPARTEMEN (Resource Fulfillments)
// Department & tahun diambil dari MPR yang ter-link ke vacancy ini.
// =============================================
$quotaBlock = '';
$quotaDeptBlock = '';
try {
    $mprId = getVacancyMprId($pdo, $vacancyId);

    $deptName   = null;
    $targetYear = (int) date('Y');
    if ($mprId) {
        $stmtDept = $pdo->prepare(
            "SELECT department,
                    YEAR(COALESCE(NULLIF(target_date, '0000-00-00'), created_at)) AS yr
             FROM ohrm_manpower_request
             WHERE id = :id
             LIMIT 1"
        );
        $stmtDept->execute([':id' => $mprId]);
        $mrRow = $stmtDept->fetch(PDO::FETCH_ASSOC);
        $deptName  = $mrRow['department'] ?? null;
        $targetYear = isset($mrRow['yr']) && $mrRow['yr'] > 0
            ? (int) $mrRow['yr']
            : (int) date('Y');
    }

    if (!empty($deptName)) {
        $deptName = trim($deptName);

        // Blok 1: QUOTA DEPARTMENT (allokasi raw) → ikut tahun baris kuota.
        $quotaDeptRow = getQuotaDepartmentRow($pdo, $deptName, $targetYear);
        $quotaYear    = $quotaDeptRow ? (int) $quotaDeptRow['year'] : $targetYear;
        $quotaDeptBlock = buildQuotaDepartmentBlock($quotaDeptRow, $deptName, $quotaYear);

        // Blok 2: QUOTA FULFILLMENT → ikut TAHUN MPR (target_date/created_at),
        // quota_akhir = SUM(tot_person) MPR dept+thn itu (keputusan user 2026-09-09) —
        // BUKAN tahun baris kuota & BUKAN ohrm_ext_department_quota.
        $fulfillment = getDeptFulfillment($pdo, $deptName, $targetYear);
        $quotaBlock  = buildQuotaBlock($fulfillment, $deptName, $targetYear);
    }
} catch (Exception $e) {
    // Jangan sampai sync gagal gara-gara blok kuota error
    $quotaBlock = '';
    $quotaDeptBlock = '';
}

// =============================================
// 5. BUILD DESCRIPTION DARI SEMUA ENTRI
// =============================================
$entries = [];
foreach ($allApplications as $app) {
    $date = date('Y-m-d H:i:s', strtotime($app['applied_date'] ?: 'now'));
    $status = $app['app_status'] ?? '';

    // Cek jika status HIRED, beri background hijau dan teks putih agar tetap terbaca jelas
    $bgStyle = '';
    if (strtoupper($status) === 'HIRED') {
        $bgStyle = 'background-color: #28a745; padding: 2px 6px; border-radius: 4px;';
    }

    $reqType = $app['request_type'] ?? '-';
    $entries[] = "=== Recruitment ($date) ===<br>" .
        "Vacancy: " . ($app['vacancy_name'] ?? '') . "<br>" .
        "Candidate: " . ($app['candidate_name'] ?? '') . "<br>" .
        "Request Type: <b>" . $reqType . "</b><br>" .
        "Status: <span style='{$bgStyle}'><b>" . $status . "</b></span><br>";
}
$fullDescription = implode("<br><br>", $entries);

// Prepend blok kuota di atas daftar rekrutmen:
// 1) Quota Department (All Quota Department — data mentah)
// 2) Quota Fulfillment (Resource Fulfillments — hitungan pemenuhan)
$quotaPrefix = '';
if ($quotaDeptBlock !== '') {
    $quotaPrefix .= $quotaDeptBlock . "<br>";
}
if ($quotaBlock !== '') {
    $quotaPrefix .= $quotaBlock . "<br>";
}
if ($quotaPrefix !== '') {
    $fullDescription = $quotaPrefix . $fullDescription;
}

// =============================================
// 6. LOGIN ACUMATICA — reuse cookie kalo masih valid
// =============================================
$BASE_URL = ACUMATICA_BASE_URL;
$COOKIE_FILE = __DIR__ . '/acumatica_cookies.txt';

// Coba login. Kalo udah ada cookie, coba langsung PUT dulu
$needLogin = true;
if (file_exists($COOKIE_FILE) && filesize($COOKIE_FILE) > 0) {
    // Coba PUT dulu (mungkin cookie masih valid)
    $filter = urlencode("CaseID eq 'DUMMY'");
    $testUrl = $BASE_URL . "/entity/Default/25.200.001/Case?\$filter=$filter";
    $chTest = curl_init($testUrl);
    curl_setopt_array($chTest, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => '{"Description":{"value":"test"}}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_COOKIEFILE => $COOKIE_FILE,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($chTest);
    $testHttp = curl_getinfo($chTest, CURLINFO_HTTP_CODE);
    curl_close($chTest);
    if ($testHttp >= 200 && $testHttp < 300) {
        $needLogin = false; // Cookie masih valid
    }
}

if ($needLogin) {
    $ch = curl_init($BASE_URL . '/entity/auth/login/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => ACUMATICA_LOGIN_USER,
            'password' => ACUMATICA_LOGIN_PASS,
            'company' => ACUMATICA_COMPANY,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $COOKIE_FILE,
        CURLOPT_COOKIEFILE => $COOKIE_FILE,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $loginResp = curl_exec($ch);
    $loginHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($loginHttp < 200 || $loginHttp >= 300) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal login ke Acumatica (HTTP ' . $loginHttp . ')',
        ]);
        exit;
    }
}

// =============================================
// 7. UPDATE CASE (PUT dengan $filter)
// =============================================
$filter = urlencode("CaseID eq '$caseId'");
$url = $BASE_URL . "/entity/Default/25.200.001/Case?\$filter=$filter";

$updatePayload = json_encode([
    'Description' => ['value' => $fullDescription],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $updatePayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_COOKIEFILE => $COOKIE_FILE,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// =============================================
// 7b. PUSH STATUS CLOSED KE ACUMATICA
// Dilakukan SETELAH PUT Case Description — biar Description ter-update
// dulu selagi case masih Open, baru status case di-close.
// =============================================
if (!empty($autoClosedMprs)) {
    try {
        require_once __DIR__ . '/close_case_acumatica.php';
        $closeResult = closeCaseInAcumatica($caseId);
        if (($closeResult['status'] ?? '') === 'ok') {
            $autoCloseMessages[] = "Case {$caseId} di-close di Acumatica";
        } else {
            $autoCloseMessages[] = "Gagal close Acumatica: " . ($closeResult['message'] ?? 'unknown');
        }
    } catch (\Throwable $e) {
        $autoCloseMessages[] = 'Gagal close Acumatica (dilewati): ' . $e->getMessage();
    }
}

// =============================================
// 8. LOGOUT & RESPONSE
// =============================================
$ch = curl_init($BASE_URL . '/entity/auth/logout/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIEFILE => $COOKIE_FILE,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
curl_exec($ch);
curl_close($ch);
if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE);

if ($httpCode >= 200 && $httpCode < 300) {
    $autoCloseNote = empty($autoCloseMessages) ? '' : ' | ' . implode('; ', $autoCloseMessages);
    echo json_encode([
        'status' => 'ok',
        'message' => "Berhasil update Case {$caseId} dengan " . count($allApplications) . " data aplikasi" . $autoCloseNote,
        'updated' => 1,
        'total_apps' => count($allApplications),
        'auto_close' => $autoCloseMessages,
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => "Gagal update Case {$caseId}: HTTP {$httpCode}",
        'error_body' => substr($resp, 0, 300),
    ]);
}
