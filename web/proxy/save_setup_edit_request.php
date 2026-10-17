<?php
require_once __DIR__ . '/_config.php';
/**
 * save_setup_edit_request.php
 *
 * POST JSON: { emp, setup_id, target, bobot, schedule }
 *
 * Membuat PENG AJUAN EDIT definisi indikator (target/bobot/schedule) berstatus
 * 'Menunggu' — TIDAK mengubah ohrm_kpi_setup langsung. Dipakai user non-admin
 * dari modal Edit di halaman Input Realisasi KPI. Admin mengedit langsung lewat
 * save_setup_kpi.php (tanpa request). Setelah request disetujui (menu Approval
 * tab "Edit Definisi"), baru ohrm_kpi_setup di-UPDATE nilainya.
 *
 * Scoping: non-admin hanya boleh mengajukan utk divisi mapping-nya.
 * Pencegahan duplikat: SATU setup hanya boleh punya 1 request 'Menunggu'.
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    echo json_encode(['status'=>'error','message'=>'Format data salah.']); exit;
}

$emp      = isset($d['emp']) ? (int)$d['emp'] : 0;
$setupId  = isset($d['setup_id']) ? (int)$d['setup_id'] : 0;
$target   = isset($d['target']) && $d['target'] !== '' ? (float)$d['target'] : null;
$bobot    = isset($d['bobot'])  && $d['bobot']  !== '' ? (float)$d['bobot']  : null;
$schedule = trim((string)($d['schedule'] ?? 'Monthly'));

if ($setupId <= 0) {
    echo json_encode(['status'=>'error','message'=>'Indikator tidak valid.']); exit;
}
if ($target === null || $target <= 0) {
    echo json_encode(['status'=>'error','message'=>'Target wajib diisi dan > 0.']); exit;
}
if ($bobot === null || $bobot < 0 || $bobot > 100) {
    echo json_encode(['status'=>'error','message'=>'Bobot wajib diisi (0–100).']); exit;
}

try {
    // Role + scope divisi (multi-divisi: empat user boleh punya beberapa divisi)
    $isAdmin = false;
    $memberDivisi = [];
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
        if (!$isAdmin) {
            $st = $pdo->prepare("SELECT DISTINCT divisi FROM ohrm_kpi_division_member WHERE emp_number = ?");
            $st->execute([$emp]);
            $memberDivisi = $st->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    // Setup target harus ada & aktif
    $st = $pdo->prepare("SELECT id, divisi, aktif, indikator FROM ohrm_kpi_setup WHERE id = :id");
    $st->execute([':id' => $setupId]);
    $setup = $st->fetch(PDO::FETCH_ASSOC);
    if (!$setup || (int)$setup['aktif'] !== 1) {
        echo json_encode(['status'=>'error','message'=>'Indikator tidak ditemukan untuk divisi tersebut.']); exit;
    }
    if (!$isAdmin && !in_array($setup['divisi'], $memberDivisi, true)) {
        echo json_encode(['status'=>'error','message'=>'Anda tidak berhak mengedit indikator untuk divisi: '.$setup['divisi']]); exit;
    }

    // Pencegahan duplikat: 1 setup hanya boleh 1 request 'Menunggu'
    $st = $pdo->prepare("SELECT id FROM ohrm_kpi_setup_edit_request WHERE setup_id = :sid AND request_status = 'Menunggu' LIMIT 1");
    $st->execute([':sid' => $setupId]);
    if ($st->fetchColumn()) {
        echo json_encode(['status'=>'error','message'=>'Terdapat pengajuan edit yang masih menunggu untuk indikator ini.']); exit;
    }

    $ins = $pdo->prepare(
        "INSERT INTO ohrm_kpi_setup_edit_request
            (setup_id, divisi, target, bobot, schedule, request_status, requested_by, requested_at)
         VALUES
            (:sid, :div, :target, :bobot, :schedule, 'Menunggu', :by, NOW())"
    );
    $ins->execute([
        ':sid'      => $setupId,
        ':div'      => $setup['divisi'],
        ':target'   => $target,
        ':bobot'    => (int)$bobot,
        ':schedule' => $schedule,
        ':by'       => $emp > 0 ? $emp : null,
    ]);
    $rid = (int)$pdo->lastInsertId();

    // Notifikasi email ke SEMUA ADMIN (user_role_id=1) yang punya email ttg
    // pengajuan edit yang menunggu persetujuan. Opsional & aman: gagal/tak ada
    // admin ber-email/tak ada config email → TIDAK menggagalkan simpan request.
    // (2026-10-15: penerima diubah dari atasan pengaju ke admin, karena hanya
    // admin yang boleh memutuskan pengajuan ini.)
    @include_once __DIR__ . '/kpi_mailer.php';
    if (function_exists('kpi_notify_edit_request') && $emp > 0) {
        try {
            kpi_notify_edit_request($pdo, $emp, $setup, $target, $bobot, $schedule);
        } catch (Throwable $e) {
            // Email gagal — biarkan request tetap tersimpan.
        }
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Pengajuan edit diajukan dan menunggu persetujuan.',
        'data'    => ['request_id' => $rid, 'setup_id' => $setupId],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengajukan edit: '.$e->getMessage()]);
}