<?php
require_once __DIR__ . '/_config.php';
/**
 * delete_setup_kpi.php
 *
 * POST /proxy/delete_setup_kpi.php
 * Body: { emp, items: [ {setup_id, periode}, ... ] }
 *
 * Hapus indikator HANYA UNTUK PERIODE TERPILIH (per pasangan setup_id+periode).
 * Penanda ditulis ke ohrm_kpi_setup_period_delete; get_kpi_realisasi.php
 * menyembunyikan baris tsb — periode lain dari indikator yang sama tetap tampil.
 * Data realisasi (ohrm_kpi_realisasi) TIDAK dihapus.
 *
 * Scoping: admin (user_role_id=1) boleh hapus divisi mana pun; non-admin HANYA
 * divisi mapping-nya (ohrm_kpi_division_member). Setup harus aktif=1, dan
 * pasangan (setup, periode) yang sudah diajukan (submitted=1) ditolak.
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

$emp = (int)($d['emp'] ?? 0);

// items: [{setup_id:int, periode:'YYYY-MM'}]
$items = [];
if (isset($d['items']) && is_array($d['items'])) {
    foreach ($d['items'] as $it) {
        if (!is_array($it)) continue;
        $sid = (int)($it['setup_id'] ?? 0);
        $per = trim((string)($it['periode'] ?? ''));
        if ($sid > 0 && preg_match('/^\d{4}-\d{2}$/', $per)) {
            $items[] = ['setup_id' => $sid, 'periode' => $per];
        }
    }
}
// de-dup pasangan (setup_id, periode)
$seen = [];
$uniq = [];
foreach ($items as $it) {
    $k = $it['setup_id'] . '|' . $it['periode'];
    if (!isset($seen[$k])) { $seen[$k] = true; $uniq[] = $it; }
}
$items = $uniq;

try {
    if (empty($items)) {
        echo json_encode(['status'=>'error','message'=>'Tidak ada indikator yang dipilih']); exit;
    }

    // Role & scoping (multi-divisi non-admin)
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

    // Ambil setup (aktif=1) utk semua setup_id yang terlibat + cek scope divisi
    $setupIds = array_values(array_unique(array_map(fn($i) => $i['setup_id'], $items)));
    $in = implode(',', array_fill(0, count($setupIds), '?'));
    $sel = $pdo->prepare("SELECT id, divisi FROM ohrm_kpi_setup WHERE aktif = 1 AND id IN ($in)");
    $sel->execute($setupIds);
    $setupDivisi = [];
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) $setupDivisi[(int)$r['id']] = $r['divisi'];

    $allowed = [];
    foreach ($items as $it) {
        $div = $setupDivisi[$it['setup_id']] ?? null;
        if ($div === null) continue; // setup tidak ada / tidak aktif
        if (!$isAdmin && !in_array($div, $memberDivisi, true)) continue;
        $allowed[] = $it;
    }
    if (empty($allowed)) {
        echo json_encode(['status'=>'error','message'=>'Tidak ada indikator yang berhak dihapus']); exit;
    }

    // Defense per (setup, periode): tolak yang sudah disubmit utk periode itu
    $chk = $pdo->prepare("SELECT 1 FROM ohrm_kpi_realisasi WHERE setup_id = ? AND periode = ? AND submitted = 1 LIMIT 1");
    $blocked = 0;
    foreach ($allowed as $it) {
        $chk->execute([$it['setup_id'], $it['periode']]);
        if ($chk->fetchColumn()) $blocked++;
    }
    if ($blocked > 0) {
        echo json_encode(['status'=>'error','message'=>'Indikator yang dipilih sudah diajukan/menunggu approval, tidak bisa dihapus']); exit;
    }

    // Tulis penanda per-periode (idempoten)
    $ins = $pdo->prepare("INSERT IGNORE INTO ohrm_kpi_setup_period_delete (setup_id, periode, deleted_by) VALUES (?, ?, ?)");
    $n = 0;
    foreach ($allowed as $it) {
        $ins->execute([$it['setup_id'], $it['periode'], $emp > 0 ? $emp : null]);
        $n += $ins->rowCount();
    }

    echo json_encode(['status'=>'success','message'=>"$n indikator dihapus (periode terpilih).", 'deleted' => $n]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menghapus indikator: '.$e->getMessage()]);
}