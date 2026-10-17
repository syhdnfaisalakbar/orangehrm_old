<?php
require_once __DIR__ . '/_config.php';
/**
 * Resource Fulfillments — SDM / SLA Tracking per Department
 *
 * GET /proxy/get_resource_fulfillment.php          → semua tahun
 * GET /proxy/get_resource_fulfillment.php?year=2026 → filter tahun
 *
 * Kolom (keputusan 2026-09-01, REVISI oleh Faisal):
 *   quota_akhir        = total_additional + replacement - reduction
 *                        (ohrm_ext_department_quota). Planned IKUT dihitung.
 *                        Contoh: IT 2026 planned 1 + replacement 2 = 3.
 *                        (Sejenak diubah ke SUM(mr.tot_person) MPR saja, tapi
 *                        user minta planned dihitung lagi → balik ke rumus ini.
 *                        Double-count replacement sudah diperbaiki: VacancyAPI
 *                        & sync_application pakai RECOMPUTE idempotent, jadi
 *                        replacement tidak akan numpuk lagi.)
 *   terpenuhi          = COUNT kandidat HIRED di ohrm_job_candidate_vacancy,
 *                        dipetakan ke department lewat vacancy → MPR.
 *                        EXCLUDE Request Type = Unplanned (di luar kuota tahunan).
 *   lowongan_berjalan  = SUM(no_of_positions) vacancy aktif (status = 1),
 *                        dipetakan ke department lewat vacancy → MPR
 *   quota_tersedia     = quota_akhir - terpenuhi - lowongan_berjalan
 *   status             = In Progress (lowongan > 0) / Terpenuhi (0) /
 *                        Kurang (> 0) / Lebih (< 0)
 *   pemenuhan_persen   = hybrid:
 *                        1) lowongan > 0 → terpenuhi / (terpenuhi + lowongan)
 *                        2) lowongan = 0 → terpenuhi / quota_akhir
 *                        3) quota 0      → NULL (N/A)
 *
 * SUMBER BARIS = UNION dept/tahun dari ohrm_ext_department_quota DAN
 * ohrm_manpower_request — supaya dept/tahun yang punya MPR TAPI belum punya
 * baris kuota tetap muncul (tidak hilang seperti bug "lowongan 0" sebelumnya).
 *
 * Mapping vacancy → department pakai COALESCE dua jalur:
 *   1) ohrm_job_vacancy.manpower_request_id (kolom proxy)
 *   2) fallback ohrm_manpower_vacancy_link (vacancy_id → manpower_request_id)
 *
 * Atribusi tahun pakai YEAR(mr.target_date) dari MPR (target pemenuhan),
 * fallback ke mr.created_at. TIDAK pakai v.defined_time — data live
 * vacancy banyak yang zero date (0000-00-00) → YEAR() = 0, ga pernah match.
 *
 * PITFALL SQL: komentar di dalam ekspresi CASE WAJIB pakai /* ... *\/ (bukan
 * -- baris) — MariaDB bisa salah-parse `--` di antara CASE dan WHEN (err 1064).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $year = isset($_GET['year']) ? intval($_GET['year']) : 0;

    // Ekspresi quota berulang — biar SQL di bawah lebih pendek.
    $QUOTA = '(COALESCE(q.total_additional, 0) + COALESCE(q.replacement, 0) + COALESCE(q.unplanned, 0) - COALESCE(q.reduction, 0))';

    // Hybrid (keputusan user 2026-09-09): kalau TIDAK ada baris kuota untuk
    // (dept,tahun) → quota_akhir = SUM(tot_person) MPR dept+thn itu (dari subquery
    // `mpr`), biar tidak 0 (mis. IT 2026: ada MPR tapi belum ada baris kuota 2026).
    $QUOTA_EF = "(CASE WHEN q.id IS NULL THEN COALESCE(mpr.quota_sum, 0) ELSE {$QUOTA} END)";

    $sql = "
        SELECT
            COALESCE(q.id, 0) AS id,
            s.dept_name,
            s.year,
            COALESCE(q.total_additional, 0) AS planned,
            COALESCE(q.replacement, 0) AS replacement,
            COALESCE(q.reduction, 0) AS reduction,
            COALESCE(q.unplanned, 0) AS unplanned,
            {$QUOTA_EF} AS quota_akhir,
            COALESCE(h.hired_count, 0)   AS terpenuhi,
            COALESCE(v.open_positions, 0) AS lowongan_berjalan,
            ({$QUOTA_EF} - COALESCE(h.hired_count, 0) - COALESCE(v.open_positions, 0)) AS quota_tersedia,
            CASE
                WHEN COALESCE(v.open_positions, 0) > 0 THEN 'In Progress'
                WHEN ({$QUOTA_EF} - COALESCE(h.hired_count, 0) - COALESCE(v.open_positions, 0)) < 0 THEN 'Lebih'
                WHEN ({$QUOTA_EF} - COALESCE(h.hired_count, 0) - COALESCE(v.open_positions, 0)) = 0 THEN 'Terpenuhi'
                ELSE 'Kurang'
            END AS status,
            CASE
                /* Pemenuhan (%) hybrid (2026-09-01, opsi B):
                   1) Masih ada lowongan berjalan -> progress terhadap TOTAL SLOT
                      (terpenuhi + lowongan) — misal 1 terisi + 2 lowongan = 33,3%,
                      TIDAK boleh 100% selama masih ada vacancy.
                   2) Tanpa lowongan -> progress terhadap quota akhir.
                   3) Quota 0 -> NULL (N/A). */
                WHEN COALESCE(v.open_positions, 0) > 0
                    THEN ROUND(
                        COALESCE(h.hired_count, 0)
                        / (COALESCE(h.hired_count, 0) + COALESCE(v.open_positions, 0)) * 100, 1)
                WHEN {$QUOTA_EF} > 0
                    THEN ROUND(COALESCE(h.hired_count, 0) / {$QUOTA_EF} * 100, 1)
                ELSE NULL
            END AS pemenuhan_persen
        FROM (
            /* Sumber baris: dept/tahun dari kuota ATAU MPR */
            SELECT TRIM(dept_name) AS dept_name, year
            FROM ohrm_ext_department_quota
            UNION
            SELECT TRIM(mr.department) AS dept_name,
                   YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS year
            FROM ohrm_manpower_request mr
            WHERE mr.status <> 'Rejected'
                  AND TRIM(COALESCE(mr.department, '')) <> ''
        ) s
        LEFT JOIN ohrm_ext_department_quota q
            ON TRIM(q.dept_name) = s.dept_name AND q.year = s.year
        LEFT JOIN (
            SELECT TRIM(mr.department) AS dept_name,
                   YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                   COUNT(DISTINCT cv.candidate_id) AS hired_count
            FROM ohrm_job_candidate_vacancy cv
            JOIN ohrm_job_vacancy v ON cv.vacancy_id = v.id
            JOIN ohrm_manpower_request mr
                 ON COALESCE(v.manpower_request_id,
                     (SELECT MAX(l.manpower_request_id)
                      FROM ohrm_manpower_vacancy_link l
                      WHERE l.vacancy_id = v.id)) = mr.id
            WHERE cv.status = 'HIRED'
            GROUP BY TRIM(mr.department), YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
        ) h ON h.dept_name = s.dept_name AND h.yr = s.year
        LEFT JOIN (
            SELECT TRIM(mr.department) AS dept_name,
                   YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                   SUM(COALESCE(v.no_of_positions, 1)) AS open_positions
            FROM ohrm_job_vacancy v
            JOIN ohrm_manpower_request mr
                 ON COALESCE(v.manpower_request_id,
                     (SELECT MAX(l.manpower_request_id)
                      FROM ohrm_manpower_vacancy_link l
                      WHERE l.vacancy_id = v.id)) = mr.id
            WHERE v.status = 1
            GROUP BY TRIM(mr.department), YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
        ) v ON v.dept_name = s.dept_name AND v.yr = s.year
        LEFT JOIN (
            /* Fallback quota_akhir = SUM(tot_person) MPR utk dept+tahun tanpa baris kuota
               (keputusan hybrid 2026-09-09). */
            SELECT TRIM(mr.department) AS dept_name,
                   YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                   SUM(COALESCE(mr.tot_person, 0)) AS quota_sum
            FROM ohrm_manpower_request mr
            WHERE mr.status <> 'Rejected'
              AND EXISTS (
                  /* Hanya MPR yang approval task TERAKHIR-nya sudah Completed/Approved
                     yang dihitung ke quota_akhir (2026-09-14). Open / Canceled /
                     tanpa approval TIDAK dihitung. Task terakhir = id terbesar
                     (pola sama dgn lastApproval). */
                  SELECT 1
                  FROM ohrm_manpower_approval ac
                  WHERE ac.case_id = mr.case_id
                    AND ac.id = (
                        SELECT MAX(ac2.id)
                        FROM ohrm_manpower_approval ac2
                        WHERE ac2.case_id = mr.case_id
                    )
                    AND UPPER(COALESCE(ac.status, '')) IN ('COMPLETED', 'APPROVED')
              )
            GROUP BY TRIM(mr.department), YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
        ) mpr ON mpr.dept_name = s.dept_name AND mpr.yr = s.year
    ";

    if ($year > 0) {
        $sql .= " WHERE s.year = :year";
    }
    $sql .= " ORDER BY s.year DESC, s.dept_name ASC";

    $stmt = $pdo->prepare($sql);
    if ($year > 0) {
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast integer supaya JSON number, bukan string dari PDO.
    foreach ($rows as &$row) {
        $quotaAkhir = (int) $row['quota_akhir'];
        $terpenuhi  = (int) $row['terpenuhi'];
        $lowongan   = (int) $row['lowongan_berjalan'];
        $tersedia   = (int) $row['quota_tersedia'];

        $row['quota_akhir']       = $quotaAkhir;
        $row['terpenuhi']         = $terpenuhi;
        $row['lowongan_berjalan'] = $lowongan;
        $row['quota_tersedia']    = $tersedia;
        $row['planned']           = (int) $row['planned'];
        $row['replacement']       = (int) $row['replacement'];
        $row['reduction']         = (int) $row['reduction'];
        $row['unplanned']         = (int) $row['unplanned'];
        // Pemenuhan (%) — null kalau quota/hired+lowongan <= 0
        $row['pemenuhan_persen'] = $row['pemenuhan_persen'] !== null
            ? (float) $row['pemenuhan_persen']
            : null;
    }
    unset($row);

    echo json_encode(['status' => 'success', 'data' => $rows]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}