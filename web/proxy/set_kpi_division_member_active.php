<?php
require_once __DIR__ . '/_config.php';
/**
 * set_kpi_division_member_active.php
 *
 * POST /proxy/set_kpi_division_member_active.php
 * Body: { emp, id, aktif }
 *
 * Aktif/nonaktifkan satu baris mapping member KPI (Admin-only). Saat aktif=0,
 * baris ini DIABAIKAN oleh scoping KPI (kpiScope di get/save_kpi_realisasi.php &
 * get_kpi_realisasi_history.php) sehingga user kehilangan hak input KPI utk
 * baris mapping tsb. Data mapping TIDAK dihapus.
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
if (!is_array($d)) { echo json_encode(['status'=>'error','message'=>'Format data salah.']); exit; }

$emp   = (int)($d['emp'] ?? 0);
$id    = (int)($d['id'] ?? 0);
$aktif = !empty($d['aktif']) ? 1 : 0;

if ($id <= 0) { echo json_encode(['status'=>'error','message'=>'ID member tidak valid.']); exit; }

try {
    // Admin-only
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Anda tidak berhak mengelola member KPI.']); exit; }

    $upd = $pdo->prepare("UPDATE ohrm_kpi_division_member SET aktif = :aktif WHERE id = :id");
    $upd->execute([':aktif' => $aktif, ':id' => $id]);
    if ($upd->rowCount() === 0) {
        // bisa jadi nilai sama (no-op) — pastikan row ada
        $chk = $pdo->prepare("SELECT id FROM ohrm_kpi_division_member WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetchColumn()) { echo json_encode(['status'=>'error','message'=>'Member tidak ditemukan.']); exit; }
    }
    echo json_encode([
        'status'  => 'success',
        'message' => $aktif ? 'Member diaktifkan.' : 'Member dinonaktifkan.',
        'data'    => ['id' => $id, 'aktif' => $aktif],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menyimpan: '.$e->getMessage()]);
}