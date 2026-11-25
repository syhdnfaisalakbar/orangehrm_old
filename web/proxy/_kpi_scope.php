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

if (!function_exists('kpiApprovalDivisionScope')) {
    /**
     * Scope divisi untuk menu APPROVAL (permintaan Pak 2026-11-24):
     * mapping `ohrm_kpi_division_member` MENANG atas full-scope, jadi user yang
     * punya mapping hanya melihat pengajuan divisinya sendiri walau role-nya
     * Admin/BOD. Admin/BOD tanpa mapping tetap melihat semua divisi.
     *
     * Return: ['divisions' => string[], 'cabang_by_divisi' => array, 'has_mapping' => bool]
     */
    function kpiApprovalDivisionScope(PDO $pdo, int $empNumber): array
    {
        $res = ['divisions' => [], 'cabang_by_divisi' => [], 'has_mapping' => false];
        if ($empNumber <= 0) {
            return $res;
        }
        $st = $pdo->prepare("SELECT divisi, cabang FROM ohrm_kpi_division_member WHERE emp_number = ?");
        $st->execute([$empNumber]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $div = (string)$r['divisi'];
            if ($div === '') {
                continue;
            }
            if (!in_array($div, $res['divisions'], true)) {
                $res['divisions'][] = $div;
            }
            $cab = $r['cabang'] === null ? null : (string)$r['cabang'];
            if ($cab === null || $cab === '') {
                $res['cabang_by_divisi'][$div] = '*';
            } elseif (($res['cabang_by_divisi'][$div] ?? null) !== '*') {
                $res['cabang_by_divisi'][$div][] = $cab;
            }
        }
        $res['has_mapping'] = count($res['divisions']) > 0;
        return $res;
    }
}
