<?php
require_once __DIR__ . '/_config.php';
/**
 * get_setup_edit_requests.php
 *
 * GET ?emp=<empNumber>
 *
 * Mengembalikan daftar PENG AJUAN EDIT definisi indikator (target/bobot/schedule)
 * utk tab "Edit Definisi" di menu Approval KPI. Termasuk yang Menunggu/Disetujui/
 * Ditolak (riwayat). Scoping: admin semua divisi; non-admin divisi mapping saja.
 *
 * Per baris menyertakan nilai LAMA (setup saat ini: target_old/bobot_old/
 * schedule_old) dan yang DIAJUKAN (target/bobot/schedule), buat perbandingan
 * indikator & divisi serta nama pengaju.
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;

try {
    // Scope divisi (multi-divisi)
    $isAdmin = false;
    $divisions = [];
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if ($isAdmin) {
        $sql = "SELECT e.*, s.indikator,
                       s.target AS target_old, s.bobot AS bobot_old, s.schedule AS schedule_old,
                       CONCAT(emp.emp_firstname, ' ', emp.emp_lastname) AS requester_name
                FROM ohrm_kpi_setup_edit_request e
                JOIN ohrm_kpi_setup s ON s.id = e.setup_id
                LEFT JOIN hs_hr_employee emp ON emp.emp_number = e.requested_by
                ORDER BY (e.request_status = 'Menunggu') DESC, e.requested_at DESC, e.id DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT DISTINCT divisi FROM ohrm_kpi_division_member WHERE emp_number = ?");
        $st->execute([$emp]);
        $divisions = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($divisions === []) {
            echo json_encode(['status'=>'success','data'=>['is_admin'=>false,'divisions'=>[],'rows'=>[]]]); exit;
        }
        $in = implode(',', array_fill(0, count($divisions), '?'));
        $st = $pdo->prepare(
            "SELECT e.*, s.indikator,
                    s.target AS target_old, s.bobot AS bobot_old, s.schedule AS schedule_old,
                    CONCAT(emp.emp_firstname, ' ', emp.emp_lastname) AS requester_name
             FROM ohrm_kpi_setup_edit_request e
             JOIN ohrm_kpi_setup s ON s.id = e.setup_id
             LEFT JOIN hs_hr_employee emp ON emp.emp_number = e.requested_by
             WHERE e.divisi IN ($in)
             ORDER BY (e.request_status = 'Menunggu') DESC, e.requested_at DESC, e.id DESC"
        );
        $st->execute($divisions);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int)$r['id'],
            'setup_id'         => (int)$r['setup_id'],
            'divisi'           => $r['divisi'],
            'indikator'        => $r['indikator'],
            'target_old'       => (float)$r['target_old'],
            'bobot_old'        => (int)$r['bobot_old'],
            'schedule_old'     => $r['schedule_old'],
            'target'           => (float)$r['target'],
            'bobot'            => (int)$r['bobot'],
            'schedule'         => $r['schedule'],
            'request_status'   => $r['request_status'],
            'requested_by'     => $r['requested_by'] !== null ? (int)$r['requested_by'] : null,
            'requester_name'   => $r['requester_name'],
            'requested_at'     => $r['requested_at'],
            'approved_by'      => $r['approved_by'] !== null ? (int)$r['approved_by'] : null,
            'approved_at'      => $r['approved_at'],
            'reject_reason'    => $r['reject_reason'],
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data'   => ['is_admin' => $isAdmin, 'divisions' => $divisions, 'rows' => $out],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil pengajuan edit: '.$e->getMessage()]);
}