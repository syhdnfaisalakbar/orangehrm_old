<?php
/**
 * _kpi_scope.php — helper scope modul KPI (dashboard divisi, detail, approval).
 *
 * Default: hanya Admin yang melihat SEMUA divisi & boleh approve/reject.
 * Role lain dibatasi oleh mapping `ohrm_kpi_division_member`.
 *
 * BOD (direksi) diikutkan sebagai full-scope supaya bisa:
 *   - melihat dashboard semua divisi + detail,
 *   - approve / reject realisasi KPI dan pengajuan edit definisi.
 *
 * Ubah daftar role tanpa menyentuh tiap endpoint: KPI_FULL_SCOPE_ROLES.
 * Pencocokan memakai NAMA role (bukan id) supaya tahan rename / id berubah.
 */

if (!defined('KPI_FULL_SCOPE_ROLES')) {
    define('KPI_FULL_SCOPE_ROLES', ['Admin', 'BOD']);
}

if (!function_exists('kpiUserRoleName')) {
    function kpiUserRoleName(PDO $pdo, int $empNumber): string
    {
        if ($empNumber <= 0) {
            return '';
        }
        $st = $pdo->prepare(
            "SELECT r.name FROM ohrm_user u
               JOIN ohrm_user_role r ON r.id = u.user_role_id
              WHERE u.emp_number = ? AND u.deleted = 0
              LIMIT 1"
        );
        $st->execute([$empNumber]);
        $name = $st->fetchColumn();
        return $name === false ? '' : (string)$name;
    }
}

if (!function_exists('kpiIsFullScope')) {
    /**
     * True = boleh melihat semua divisi / melakukan approval.
     */
    function kpiIsFullScope(PDO $pdo, int $empNumber): bool
    {
        $role = kpiUserRoleName($pdo, $empNumber);
        return $role !== '' && in_array($role, KPI_FULL_SCOPE_ROLES, true);
    }
}
