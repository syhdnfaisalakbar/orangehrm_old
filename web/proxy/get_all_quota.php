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

    // Sumber angka per baris (dept + tahun):
    //   total_additional      -> hasil sync Acumatica (ohrm_ext_department_quota)
    //   manual_total          -> SUM upload Excel (ohrm_ext_department_quota_manual, ledger)
    //   planned               -> SUM TOTPERSON case yang approval terakhirnya 'Completed'
    //   sisa                  -> total_additional + manual_total - planned
    //
    // Kunci baris diambil dari UNION kedua tabel, supaya department yang HANYA ada
    // dari upload Excel (belum punya baris kuota Acumatica) tetap tampil.
    //
    // Branch (berurutan): approved Acumatica -> branch departemen -> branch upload terakhir -> '-'
    $stmt = $pdo->query("
        SELECT
            q.id,
            k.dept_name,
            k.year,
            TRIM(COALESCE(approved.branch, dept_branch.branch, NULLIF(TRIM(man.latest_branch), ''), '-')) AS branch,
            COALESCE(q.total_additional, 0) AS total_additional,
            COALESCE(man.manual_total, 0) AS manual_total,
            COALESCE(q.replacement, 0) AS replacement,
            COALESCE(q.reduction, 0) AS reduction,
            COALESCE(q.unplanned, 0) AS unplanned,
            COALESCE(approved.planned_count, 0) AS planned,
            (COALESCE(q.total_additional, 0) + COALESCE(man.manual_total, 0) - COALESCE(approved.planned_count, 0)) AS sisa
        FROM (
            SELECT TRIM(dept_name) AS dept_name, year FROM ohrm_ext_department_quota
            UNION
            SELECT TRIM(dept_name) AS dept_name, year FROM ohrm_ext_department_quota_manual
        ) k
        LEFT JOIN ohrm_ext_department_quota q
               ON TRIM(q.dept_name) = k.dept_name AND q.year = k.year
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
        LEFT JOIN (
            -- Ledger upload: total per dept+tahun + branch dari upload terakhir
            SELECT
                TRIM(dept_name) AS dept_name,
                year,
                SUM(total_person) AS manual_total,
                SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(branch, '') ORDER BY id DESC SEPARATOR '||'), '||', 1) AS latest_branch
            FROM ohrm_ext_department_quota_manual
            GROUP BY TRIM(dept_name), year
        ) man ON man.dept_name = k.dept_name AND man.year = k.year
        ORDER BY k.year DESC, k.dept_name ASC, branch ASC
    ");

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cast ke integer: PDO mengembalikan string untuk hasil COALESCE/SUM
    foreach ($data as &$row) {
        $row['total_additional'] = (int) $row['total_additional'];
        $row['manual_total'] = (int) $row['manual_total'];
        $row['planned'] = (int) $row['planned'];
        $row['sisa'] = (int) $row['sisa'];
        $row['replacement'] = (int) $row['replacement'];
        $row['reduction'] = (int) $row['reduction'];
        $row['unplanned'] = (int) $row['unplanned'];
        $row['branch']  = trim((string) $row['branch']);
    }
    unset($row);
    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
