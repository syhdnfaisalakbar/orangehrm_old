<?php
/**
 * _quota_approved.php — SATU sumber definisi "kuota dari case HRPLAN Acumatica".
 *
 * Kuota yang berasal dari Acumatica = SUM(TOTPERSON) case HRPLAN yang approval
 * TERAKHIR-nya berstatus 'Completed' (case yang masih Open/Rejected tidak dihitung).
 * Sumber lokal: ohrm_quota_requests + ohrm_case_attributes + ohrm_approval_history
 * (ketiganya diisi oleh sync Acumatica di save_quota.php).
 *
 * 2026-10-27 (keputusan Pak): kapasitas kuota = planned (upload Excel) + approved (HRPLAN).
 * Supaya tidak ada 3 rumus berbeda di 3 file, definisi ini dipusatkan di sini:
 *   - get_resource_fulfillment.php  -> quota_akhir
 *   - save_manpower.php             -> kapasitas validasi FPTK Planned
 *   - sync_application_to_acumatica.php -> blok quota di catatan Acumatica
 *
 * Catatan: get_all_quota.php punya subquery sendiri (variannya mengelompokkan per
 * BRANCH untuk kebutuhan tampilan kolom branch), jadi tidak memakai file ini.
 */

/**
 * Subquery SQL: dept_name, yr, planned_count.
 * Pakai sebagai: LEFT JOIN (quota_approved_subquery()) approved
 *                    ON approved.dept_name = ... AND approved.yr = ...
 */
function quota_approved_subquery(): string
{
    return "
        SELECT
            TRIM(attr_dept.attribute_value) AS dept_name,
            CAST(attr_year.attribute_value AS UNSIGNED) AS yr,
            SUM(CAST(attr_tot.attribute_value AS UNSIGNED)) AS planned_count
        FROM ohrm_quota_requests qr
        INNER JOIN (
            SELECT
                ref_note_id,
                approval_status,
                ROW_NUMBER() OVER (
                    PARTITION BY ref_note_id
                    ORDER BY FIELD(approval_status, 'Completed', 'Open', 'Rejected') ASC,
                             approval_id DESC
                ) AS rn
            FROM ohrm_approval_history
        ) la ON la.ref_note_id = qr.note_id AND la.rn = 1
        INNER JOIN ohrm_case_attributes attr_dept
               ON attr_dept.ref_note_id = qr.note_id AND attr_dept.attribute_name = 'DEPARTMENT'
        INNER JOIN ohrm_case_attributes attr_year
               ON attr_year.ref_note_id = qr.note_id AND attr_year.attribute_name = 'YEAR'
        INNER JOIN ohrm_case_attributes attr_tot
               ON attr_tot.ref_note_id = qr.note_id AND attr_tot.attribute_name IN ('TOTPERSON', 'TOTPERSONN')
        WHERE la.approval_status = 'Completed'
        GROUP BY TRIM(attr_dept.attribute_value), CAST(attr_year.attribute_value AS UNSIGNED)
    ";
}

/**
 * Kuota approved (HRPLAN Acumatica) untuk satu department + tahun.
 * Dipakai di kode PHP yang butuh angka (bukan SQL builder).
 */
function quota_approved_for(PDO $pdo, string $dept, int $year): int
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CAST(attr_tot.attribute_value AS UNSIGNED)), 0)
            FROM ohrm_quota_requests qr
            INNER JOIN (
                SELECT
                    ref_note_id,
                    approval_status,
                    ROW_NUMBER() OVER (
                        PARTITION BY ref_note_id
                        ORDER BY FIELD(approval_status, 'Completed', 'Open', 'Rejected') ASC,
                                 approval_id DESC
                    ) AS rn
                FROM ohrm_approval_history
            ) la ON la.ref_note_id = qr.note_id AND la.rn = 1
            INNER JOIN ohrm_case_attributes attr_dept
                   ON attr_dept.ref_note_id = qr.note_id AND attr_dept.attribute_name = 'DEPARTMENT'
            INNER JOIN ohrm_case_attributes attr_year
                   ON attr_year.ref_note_id = qr.note_id AND attr_year.attribute_name = 'YEAR'
            INNER JOIN ohrm_case_attributes attr_tot
                   ON attr_tot.ref_note_id = qr.note_id AND attr_tot.attribute_name IN ('TOTPERSON', 'TOTPERSONN')
            WHERE la.approval_status = 'Completed'
              AND TRIM(attr_dept.attribute_value) = :dept
              AND CAST(attr_year.attribute_value AS UNSIGNED) = :yr
        ");
    }
    $stmt->execute([':dept' => $dept, ':yr' => $year]);
    return (int) $stmt->fetchColumn();
}
