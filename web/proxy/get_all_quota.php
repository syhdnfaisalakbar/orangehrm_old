<?php
require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_quota_approved.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Sumber angka per baris (dept + tahun):
    //   planned_upload   -> kolom `planned` di ohrm_ext_department_quota (hasil upload Excel)
    //   planned_approved -> SUM TOTPERSON case yang approval terakhirnya 'Completed'
    //   planned          -> planned_upload + planned_approved (angka yang tampil di UI)
    //   sisa             -> (planned_upload + planned_approved) - fptk_used
    //                       kapasitas (upload Excel + HRPLAN approved) dikurangi FPTK Planned
    //                       yang sudah terpakai. Sejak 2026-10-27 approved HRPLAN dihitung
    //                       sebagai KAPASITAS (bukan pemakaian) dan total_additional (sync
    //                       Acumatica) tidak dipakai di hitungan.
    //
    // Branch (berurutan): approved Acumatica -> branch departemen -> branch dari upload -> '-'
    // Definisi "kuota approved" dipusatkan di _quota_approved.php. Subquery `approved`
    // di bawah tetap ada khusus untuk kolom BRANCH (dikelompokkan per branch).
    $APPROVED_SQL = quota_approved_subquery();

    $stmt = $pdo->query("
        SELECT
            q.id,
            k.dept_name,
            k.year,
            TRIM(COALESCE(approved.branch, dept_branch.branch, NULLIF(TRIM(q.branch), ''), '-')) AS branch,
            COALESCE(q.total_additional, 0) AS total_additional,
            COALESCE(q.planned, 0) AS planned_upload,
            COALESCE(approved_total.planned_count, 0) AS planned_approved,
            (COALESCE(q.planned, 0) + COALESCE(approved_total.planned_count, 0)) AS planned,
            COALESCE(q.replacement, 0) AS replacement,
            COALESCE(q.reduction, 0) AS reduction,
            COALESCE(q.unplanned, 0) AS unplanned,
            COALESCE(fptk.used_count, 0) AS fptk_used,
            ((COALESCE(q.planned, 0) + COALESCE(approved_total.planned_count, 0))
             - COALESCE(fptk.used_count, 0)) AS sisa
        FROM (
            SELECT TRIM(dept_name) AS dept_name, year FROM ohrm_ext_department_quota
        ) k
        LEFT JOIN ohrm_ext_department_quota q
               ON TRIM(q.dept_name) = k.dept_name AND q.year = k.year
        LEFT JOIN ( {$APPROVED_SQL} ) approved_total
               ON approved_total.dept_name = k.dept_name AND approved_total.yr = k.year
        LEFT JOIN (
            -- FPTK (manpower request) Planned yang sudah dipakai = pemakaian kuota.
            -- Revisi 2026-10-27: approved HRPLAN sekarang dihitung sebagai KAPASITAS,
            -- jadi Sisa = (planned upload + approved HRPLAN) - FPTK Planned terpakai.
            SELECT TRIM(mr.department) AS dept_name,
                   YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                   SUM(COALESCE(mr.tot_person, 0)) AS used_count
            FROM ohrm_manpower_request mr
            WHERE UPPER(COALESCE(mr.req_type, '')) = 'PLANNED'
              AND COALESCE(mr.status, '') <> 'Rejected'
            GROUP BY TRIM(mr.department), YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
        ) fptk ON fptk.dept_name = k.dept_name AND fptk.yr = k.year
        LEFT JOIN (
            SELECT
                TRIM(attr_dept.attribute_value) AS dept_name,
                CAST(attr_year.attribute_value AS UNSIGNED) AS yr,
                TRIM(COALESCE(attr_branch.attribute_value, '-')) AS branch,
                SUM(CAST(attr_tot.attribute_value AS UNSIGNED)) AS planned_count
            FROM ohrm_quota_requests qr
            INNER JOIN (
                -- Ambil 1 approval per case ( Completed > Open )
                SELECT
                    ref_note_id,
                    approval_status,
                    ROW_NUMBER() OVER (
                        PARTITION BY ref_note_id
                        ORDER BY FIELD(approval_status, 'Completed', 'Open', 'Rejected') ASC,
                                 approval_id DESC
                    ) AS rn
                FROM ohrm_approval_history
            ) latest_appr ON latest_appr.ref_note_id = qr.note_id AND latest_appr.rn = 1
            INNER JOIN ohrm_case_attributes attr_dept ON attr_dept.ref_note_id = qr.note_id AND attr_dept.attribute_name = 'DEPARTMENT'
            INNER JOIN ohrm_case_attributes attr_year ON attr_year.ref_note_id = qr.note_id AND attr_year.attribute_name = 'YEAR'
            INNER JOIN ohrm_case_attributes attr_tot ON attr_tot.ref_note_id = qr.note_id AND attr_tot.attribute_name IN ('TOTPERSON', 'TOTPERSONN')
            LEFT JOIN ohrm_case_attributes attr_branch ON attr_branch.ref_note_id = qr.note_id AND attr_branch.attribute_name = 'BRANCH'
            WHERE latest_appr.approval_status = 'Completed'
            GROUP BY TRIM(attr_dept.attribute_value), CAST(attr_year.attribute_value AS UNSIGNED), TRIM(COALESCE(attr_branch.attribute_value, '-'))
        ) approved ON approved.dept_name = k.dept_name AND approved.yr = k.year
        LEFT JOIN (
            -- Branch per departemen (dari HRPLAN BRANCH attribute mana pun) — fallback
            -- utk dept/tahun yang belum punya HRPLAN approved utk thn itu.
            SELECT
                TRIM(attr_dept.attribute_value) AS dept_name,
                TRIM(MIN(COALESCE(attr_branch.attribute_value, ''))) AS branch
            FROM ohrm_quota_requests qr
            INNER JOIN ohrm_case_attributes attr_dept ON attr_dept.ref_note_id = qr.note_id AND attr_dept.attribute_name = 'DEPARTMENT'
            LEFT JOIN ohrm_case_attributes attr_branch ON attr_branch.ref_note_id = qr.note_id AND attr_branch.attribute_name = 'BRANCH'
            WHERE attr_branch.attribute_value IS NOT NULL AND attr_branch.attribute_value != ''
            GROUP BY TRIM(attr_dept.attribute_value)
        ) dept_branch ON dept_branch.dept_name = k.dept_name
        ORDER BY k.year DESC, k.dept_name ASC, branch ASC
    ");

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cast ke integer: PDO mengembalikan string untuk hasil COALESCE/SUM
    foreach ($data as &$row) {
        foreach ([
            'total_additional', 'planned', 'planned_upload', 'planned_approved',
            'replacement', 'reduction', 'unplanned', 'fptk_used', 'sisa',
        ] as $numCol) {
            $row[$numCol] = (int) $row[$numCol];
        }
        $row['branch']  = trim((string) $row['branch']);
    }
    unset($row);
    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
