<?php
require_once __DIR__ . '/_config.php';
/**
 * delete_kpi_division_member.php
 *
 * POST /proxy/delete_kpi_division_member.php
 * Body: { emp, ids: [int, ...] }
 *
 * Hapus satu/banyak mapping member-ke-divisi(+cabang). Admin-only.
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
$emp = (int)($d['emp'] ?? 0);
$ids = [];
if (isset($d['ids']) && is_array($d['ids'])) {
    foreach ($d['ids'] as $v) { $i = (int)$v; if ($i > 0) $ids[] = $i; }
}
$ids = array_values(array_unique($ids));

try {
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) {
        echo json_encode(['status'=>'error','message'=>'Anda tidak berhak mengelola member KPI.']); exit;
    }
    if (empty($ids)) {
        echo json_encode(['status'=>'error','message'=>'Tidak ada member yang dipilih.']); exit;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM ohrm_kpi_division_member WHERE id IN ($in)");
    $st->execute($ids);
    echo json_encode(['status'=>'success','message'=>(count($ids).' mapping member dihapus.'),'deleted'=>$st->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menghapus member: '.$e->getMessage()]);
}