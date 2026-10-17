<?php
require_once __DIR__ . '/_config.php';
/**
 * update_setup_edit_request.php
 *
 * POST JSON: { emp, decision:'approve'|'reject', reason?, id?|ids?:[] }
 *
 * Admin menyetujui / menolak PENG AJUAN EDIT definisi indikator (tab "Edit Definisi"
 * di menu Approval KPI). HANYA admin (user_role_id=1) boleh.
 *   - approve: UPDATE ohrm_kpi_setup (target/bobot/schedule) ke nilai yang diajukan,
 *     lalu tandai request 'Disetujui'.
 *   - reject : WAJIB reason, request 'Ditolak', ohrm_kpi_setup TIDAK diubah.
 * Terima `ids` (array) ATAU `id` tunggal; kalau duanya, `ids` menang.
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
$decision = trim((string)($d['decision'] ?? ''));
$reason   = trim((string)($d['reason'] ?? ''));

// ids (array) menang; fallback id tunggal
$ids = [];
if (isset($d['ids']) && is_array($d['ids'])) {
    foreach ($d['ids'] as $v) $ids[] = (int)$v;
} elseif (isset($d['id'])) {
    $ids[] = (int)$d['id'];
}
$ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));

if (!in_array($decision, ['approve', 'reject'], true)) {
    echo json_encode(['status'=>'error','message'=>'Keputusan tidak valid.']); exit;
}
if (empty($ids)) {
    echo json_encode(['status'=>'error','message'=>'Tidak ada pengajuan yang dipilih.']); exit;
}
if ($decision === 'reject' && $reason === '') {
    echo json_encode(['status'=>'error','message'=>'Alasan penolakan wajib diisi.']); exit;
}

try {
    // HANYA admin
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) {
        echo json_encode(['status'=>'error','message'=>'Hanya admin yang dapat memutuskan pengajuan edit.']); exit;
    }

    $sqlUpd = "SELECT id, setup_id, request_status FROM ohrm_kpi_setup_edit_request WHERE id = :id";
    $stGet  = $pdo->prepare($sqlUpd);
    $stUpdReq = $pdo->prepare(
        "UPDATE ohrm_kpi_setup_edit_request
         SET request_status = :st, approved_by = :by, approved_at = NOW(), reject_reason = :rr
         WHERE id = :id"
    );
    $stUpdSetup = $pdo->prepare(
        "UPDATE ohrm_kpi_setup SET target = :target, bobot = :bobot, schedule = :schedule, updated_at = NOW()
         WHERE id = :id"
    );
    $stVal = $pdo->prepare("SELECT target, bobot, schedule FROM ohrm_kpi_setup_edit_request WHERE id = :id");

    $count = 0;
    $done = [];
    foreach ($ids as $rid) {
        $stGet->execute([':id' => $rid]);
        $req = $stGet->fetch(PDO::FETCH_ASSOC);
        if (!$req) continue;                       // request tak ada
        if ($req['request_status'] !== 'Menunggu') continue; // sudah diputuskan

        if ($decision === 'approve') {
            $stVal->execute([':id' => $rid]);
            $v = $stVal->fetch(PDO::FETCH_ASSOC);
            if (!$v) continue;
            $stUpdSetup->execute([
                ':target'   => $v['target'],
                ':bobot'    => (int)$v['bobot'],
                ':schedule' => $v['schedule'],
                ':id'       => (int)$req['setup_id'],
            ]);
            $stUpdReq->execute([':st' => 'Disetujui', ':by' => $emp, ':rr' => null, ':id' => $rid]);
        } else {
            $stUpdReq->execute([':st' => 'Ditolak', ':by' => $emp, ':rr' => $reason, ':id' => $rid]);
        }
        $count++;
        $done[] = $rid;
    }

    if ($count === 0) {
        echo json_encode(['status'=>'error','message'=>'Tidak ada pengajuan valid yang bisa diputuskan (mungkin sudah diputuskan sebelumnya).']); exit;
    }

    $word = $decision === 'approve' ? 'Disetujui' : 'Ditolak';
    echo json_encode([
        'status'  => 'success',
        'message' => "$word $count pengajuan edit.",
        'data'    => ['count' => $count, 'ids' => $done],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal memproses pengajuan: '.$e->getMessage()]);
}