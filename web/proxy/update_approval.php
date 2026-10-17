<?php
require_once __DIR__ . '/_config.php';
/**
 * update_approval.php
 *
 * POST /proxy/update_approval.php
 * Body: { emp, id?, ids?, decision: 'approve'|'reject', reason?: string }
 *
 * Approve/Reject satu ATAU banyak baris realisasi KPI sekaligus (bulk). Hanya
 * admin yang boleh approve/reject. Reject WAJIB alasan. Setelah approve ->
 * 'Disetujui', reject -> 'Ditolak'. 'id' (single) atau 'ids' (array) dipakai
 * biar fleksibel — jika keduanya, 'ids' menang.
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
    echo json_encode(['status'=>'error','message'=>'Payload tidak valid']); exit;
}

$emp      = (int)($d['emp'] ?? 0);
$decision = trim((string)($d['decision'] ?? ''));
$reason   = trim((string)($d['reason'] ?? ''));

// Support single 'id' atau array 'ids' (bulk). 'ids' menang jika keduanya.
$ids = [];
if (isset($d['ids']) && is_array($d['ids'])) {
    foreach ($d['ids'] as $v) { $i = (int)$v; if ($i > 0) $ids[] = $i; }
}
if (empty($ids) && isset($d['id'])) {
    $i = (int)$d['id'];
    if ($i > 0) $ids[] = $i;
}
$ids = array_values(array_unique($ids));

$out = ['status'=>'error','message'=>'Aksi tidak valid'];
try {
    // Hanya admin yang boleh approve/reject
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) {
        $out = ['status'=>'error','message'=>'Anda tidak berhak melakukan approval'];
        echo json_encode($out); exit;
    }

    if (empty($ids) || !in_array($decision, ['approve','reject'], true)) {
        echo json_encode(['status'=>'error','message'=>'Parameter id/ids/decision tidak valid']); exit;
    }
    if ($decision === 'reject' && $reason === '') {
        echo json_encode(['status'=>'error','message'=>'Alasan penolakan wajib diisi']); exit;
    }

    // Pastikan semua baris ada (optional check; lompat baris yang tak ditemukan)
    $newStatus  = $decision === 'approve' ? 'Disetujui' : 'Ditolak';
    $reasonVal  = $decision === 'reject' ? $reason : null;
    $upd = $pdo->prepare("UPDATE ohrm_kpi_realisasi
        SET approval_status = ?, approved_by = ?, approved_at = NOW(),
            reject_reason = ?
        WHERE id = ?");
    $n = 0;
    $getRow = $pdo->prepare("SELECT setup_id, divisi, periode, realisasi, skor FROM ohrm_kpi_realisasi WHERE id = ?");
    $log = $pdo->prepare("INSERT INTO ohrm_kpi_approval_log
        (setup_id, divisi, periode, action, realisasi, skor, acted_by, acted_at, note)
        VALUES (:setup_id, :divisi, :periode, :action, :realisasi, :skor, :acted_by, NOW(), :note)");
    foreach ($ids as $id) {
        $getRow->execute([$id]);
        $row = $getRow->fetch(PDO::FETCH_ASSOC);
        $upd->execute([$newStatus, $emp, $reasonVal, $id]);
        $n += $upd->rowCount();
        if ($row) {
            $log->execute([
                ':setup_id' => (int)$row['setup_id'],
                ':divisi'   => (string)$row['divisi'],
                ':periode'  => (string)$row['periode'],
                ':action'   => $decision === 'approve' ? 'approved' : 'rejected',
                ':realisasi'=> $row['realisasi'] !== null ? (float)$row['realisasi'] : null,
                ':skor'     => $row['skor'] !== null ? (float)$row['skor'] : null,
                ':acted_by' => $emp,
                ':note'     => $decision === 'reject' ? $reason : 'Disetujui',
            ]);
        }
    }

    echo json_encode(['status'=>'success',
        'message'=>(($decision==='approve' ? 'Disetujui' : 'Ditolak')." $n indikator."),
        'ids'=>$ids, 'count'=>$n]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal update approval: '.$e->getMessage()]);
}