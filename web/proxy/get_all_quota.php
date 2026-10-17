<?php
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Planned = SUM tot_person dari ohrm_quota_requests yang approval status = 'Completed'
    // Deduplicate per case_id dulu (1 case bisa punya >1 approval record),
    // lalu SUM tot_person per dept/year/branch.
    //
    // Branch:
    //   1) dari HRPLAN approved utk (dept,year) tsb (BRANCH attribute).
    //   2) fallback ke BRANCH departemen (dept_branch) — kalau dept/tahun punya baris
    //      kuota (mis. dari recompute replacement MPR) tapi belum ada HRPLAN approved
    //      utk tahun itu (mis. IT 2026) → tetap tampil JKT, bukan '-'.
    //
    // JOIN ke ohrm_ext_department_quota biar departemen yang belum ada request
    // tetap tampil (planned = 0).
    $stmt = $pdo->query("
        SELECT
            q.id,
            q.dept_name,
            q.year,
            TRIM(COALESCE(approved.branch, dept_branch.branch, '-')) AS branch,
            q.total_additional,
            q.replacement,
            q.reduction,
            q.unplanned,
            COALESCE(approved.planned_count, 0) AS planned
        FROM ohrm_ext_department_quota q
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
            INNER JOIN ohrm_case_attributes attr_tot ON attr_tot.ref_note_id = qr.note_id AND attr_tot.attribute_name = 'TOTPERSON'
            LEFT JOIN ohrm_case_attributes attr_branch ON attr_branch.ref_note_id = qr.note_id AND attr_branch.attribute_name = 'BRANCH'
            WHERE latest_appr.approval_status = 'Completed'
            GROUP BY TRIM(attr_dept.attribute_value), CAST(attr_year.attribute_value AS UNSIGNED), TRIM(COALESCE(attr_branch.attribute_value, '-'))
        ) approved ON approved.dept_name = TRIM(q.dept_name) AND approved.yr = q.year
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
        ) dept_branch ON dept_branch.dept_name = TRIM(q.dept_name)
        ORDER BY q.year DESC, q.dept_name ASC, branch ASC
    ");

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cast planned to integer (COALESCE returns string from PDO)
    foreach ($data as &$row) {
        $row['planned'] = (int) $row['planned'];
        $row['branch']  = trim((string) $row['branch']);
    }
    unset($row);
    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}