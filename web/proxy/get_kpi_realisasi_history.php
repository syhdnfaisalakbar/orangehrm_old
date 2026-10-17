<?php
require_once __DIR__ . '/_config.php';
/**
 * get_kpi_realisasi_history.php
 *
 * GET /proxy/get_kpi_realisasi_history.php?emp=<emp>&setup_id=<id>&periode=YYYY-MM&cabang=<cabang>
 *
 * Riwayat perubahan nilai Achievement (ohrm_kpi_realisasi_history) untuk satu
 * baris realisasi (setup_id+periode+cabang), urut terbaru dulu, dilengkapi nama
 * pengubah (join hs_hr_employee). Scoping: admin boleh semua divisi/cabang;
 * non-admin wajib punya mapping divisi/cabang utk setup tsb.
 *
 * Respons: { status, data: { setup_id, periode, cabang, sejarah: [ { id,
 *           old_value, new_value, changed_by, changed_at, changer_name } ] } }
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$emp      = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;
$setupId  = isset($_GET['setup_id']) ? (int)$_GET['setup_id'] : 0;
$periode  = trim((string)($_GET['periode'] ?? ''));
$cabang   = trim((string)($_GET['cabang'] ?? ''));

if ($setupId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['status'=>'error','message'=>'setup_id & periode (YYYY-MM) wajib.']); exit;
}

function kpiScope(PDO $pdo, int $emp): array {
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    $cabByDiv = [];
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT divisi, cabang FROM ohrm_kpi_division_member WHERE emp_number = ? AND aktif = 1");
        $st->execute([$emp]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $div = (string)$r['divisi'];
            $cab = $r['cabang'] === null ? null : (string)$r['cabang'];
            if ($cab === 'HO') $cab = '';
            if ($cab === null) {
                $cabByDiv[$div] = '*';
            } elseif (($cabByDiv[$div] ?? null) !== '*') {
                if (!isset($cabByDiv[$div])) $cabByDiv[$div] = [];
                if (!in_array($cab, $cabByDiv[$div], true)) $cabByDiv[$div][] = $cab;
            }
        }
    }
    return ['is_admin' => $isAdmin, 'cabang_by_divisi' => $cabByDiv];
}

try {
    $scope = kpiScope($pdo, $emp);
    // Ambil setup utk cek divisi & cabang milik setup (scoping)
    $st = $pdo->prepare("SELECT divisi, cabang, indikator FROM ohrm_kpi_setup WHERE id = ? AND aktif = 1");
    $st->execute([$setupId]);
    $setup = $st->fetch(PDO::FETCH_ASSOC);
    if (!$setup) {
        echo json_encode(['status'=>'error','message'=>'Indikator tidak ditemukan.']); exit;
    }
    $divisi = (string)$setup['divisi'];
    $setupCabang = (string)($setup['cabang'] ?? '');

    // Scope: non-admin harus berhak divisi & cabang untuk setup ini.
    if (!$scope['is_admin']) {
        $allowed = $scope['cabang_by_divisi'][$divisi] ?? null;
        if ($allowed === null) {
            echo json_encode(['status'=>'error','message'=>'Anda tidak berhak melihat history indikator ini.']); exit;
        }
        $canSee = false;
        if ($allowed === '*') {
            $canSee = true;
        } else {
            // Indikator berlaku semua cabang (setup.cabang '') → user boleh lihat
            // cabang yang ia punya; indikator cabang-spesifik → harus cabangnya.
            if ($setupCabang === '') {
                $canSee = in_array($cabang, $allowed, true);
            } else {
                $canSee = in_array($setupCabang, $allowed, true);
            }
        }
        if (!$canSee) {
            echo json_encode(['status'=>'error','message'=>'Anda tidak berhak melihat history indikator ini.']); exit;
        }
    }

    $q = $pdo->prepare(
        "SELECT h.id, h.old_value, h.new_value, h.changed_by, h.changed_at,
                COALESCE(TRIM(CONCAT(COALESCE(e.emp_firstname,''),' ',COALESCE(e.emp_lastname,''))),'') AS changer_name
         FROM ohrm_kpi_realisasi_history h
         LEFT JOIN hs_hr_employee e ON e.emp_number = h.changed_by
         WHERE h.setup_id = :sid AND h.periode = :periode AND h.cabang = :cabang
         ORDER BY h.changed_at DESC, h.id DESC"
    );
    $q->execute([':sid' => $setupId, ':periode' => $periode, ':cabang' => $cabang]);
    $sejarah = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sejarah[] = [
            'id'          => (int)$r['id'],
            'old_value'   => $r['old_value'] !== null ? (float)$r['old_value'] : null,
            'new_value'   => $r['new_value'] !== null ? (float)$r['new_value'] : null,
            'changed_by'  => $r['changed_by'] !== null ? (int)$r['changed_by'] : null,
            'changed_at'  => $r['changed_at'],
            'changer_name'=> $r['changer_name'] !== '' ? $r['changer_name'] : ('User #'.$r['changed_by']),
        ];
    }
    echo json_encode([
        'status' => 'success',
        'data' => [
            'setup_id' => $setupId,
            'periode'  => $periode,
            'cabang'   => $cabang,
            'indikator'=> $setup['indikator'],
            'sejarah'  => $sejarah,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil history: '.$e->getMessage()]);
}