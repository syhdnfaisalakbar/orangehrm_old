<?php
require_once __DIR__ . '/_config.php';
/**
 * save_setup_kpi.php
 *
 * POST /proxy/save_setup_kpi.php   Body (JSON):
 *   {
 *     "emp": 1403,
 *     "divisi": "Sales & Marketing",
 *     "indikator": "Cakupan training sales",
 *     "pengukuran": "Jumlah sesi yang diikuti",
 *     "rumus": "(Realisasi ÷ Target) × 100%",
 *     "target": 90, "satuan": "%", "bobot": 10,
 *     "schedule": "Monthly", "lower_better": 0
 *   }
 *
 * Menambah indikator KPI BARU ke ohrm_kpi_setup (aktif = 1). Dipakai tombol
 * "+ Add" di halaman Input Realisasi KPI. Scoping: non-admin hanya boleh
 * menambah untuk divisi mapping-nya (ohrm_kpi_division_member).
 *
 * Setelah baris setup dibuat, baris tersebut otomatis muncul di tabel
 * realisasi (get_kpi_realisasi.php LEFT JOIN per periode).
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

$emp        = isset($d['emp']) ? (int)$d['emp'] : 0;
$setupId    = isset($d['setup_id']) ? (int)$d['setup_id'] : 0;
$divisi     = trim((string)($d['divisi'] ?? ''));
$cabang     = trim((string)($d['cabang'] ?? ''));
$periode    = trim((string)($d['periode'] ?? ''));
$indikator  = trim((string)($d['indikator'] ?? ''));
$pengukuran = trim((string)($d['pengukuran'] ?? ''));
$rumus      = trim((string)($d['rumus'] ?? ''));
$target     = isset($d['target']) && $d['target'] !== '' ? (float)$d['target'] : null;
$satuan     = trim((string)($d['satuan'] ?? '%'));
$bobot      = isset($d['bobot']) && $d['bobot'] !== '' ? (float)$d['bobot'] : null;
$schedule   = trim((string)($d['schedule'] ?? 'Monthly'));
$lower      = !empty($d['lower_better']) ? 1 : 0;

if ($divisi === '') {
    echo json_encode(['status'=>'error','message'=>'Divisi wajib diisi.']); exit;
}
if ($setupId <= 0 && $indikator === '') {
    echo json_encode(['status'=>'error','message'=>'Indikator wajib diisi.']); exit;
}
if ($target === null || $target <= 0) {
    echo json_encode(['status'=>'error','message'=>'Target wajib diisi dan > 0.']); exit;
}
if ($bobot === null || $bobot < 0 || $bobot > 100) {
    echo json_encode(['status'=>'error','message'=>'Bobot wajib diisi (0–100).']); exit;
}
// Periode opsional. Jika diisi, wajib format YYYY-MM (null/'' = berlaku semua periode).
if ($periode !== '' && !preg_match('/^\d{4}-\d{2}$/', $periode)) {
    echo json_encode(['status'=>'error','message'=>'Format Periode tidak valid (YYYY-MM).']); exit;
}

try {
    // Scope: role + divisi mapping non-admin (multi-divisi)
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
    if (!$isAdmin && !in_array($divisi, $memberDivisi, true)) {
        echo json_encode(['status'=>'error','message'=>'Anda tidak berhak menambah indikator untuk divisi: '.$divisi]); exit;
    }

    if ($setupId > 0) {
        // EDIT: hanya admin yang mengubah definisi langsung. Non-admin WAJIB lewat
        // pengajuan approval (save_setup_edit_request.php) sehinga belum berubah
        // sampai disetujui di menu Approval.
        if (!$isAdmin) {
            echo json_encode(['status'=>'error','message'=>'Edit definisi harus melalui pengajuan approval.']); exit;
        }
        // EDIT: HANYA admin (sudah ditolak di atas utk non-admin). Divisi BISA
        // diubah admin (pindah indikator ke divisi lain). Validasi cukup bahwa
        // setup ada & aktif — TIDAK cocokkan divisi lama (utk mengizinkan
        // pindah divisi); admin boleh menetapkan divisi baru apa pun.
        $chk = $pdo->prepare("SELECT id FROM ohrm_kpi_setup WHERE id = :id AND aktif = 1");
        $chk->execute([':id' => $setupId]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['status'=>'error','message'=>'Indikator tidak ditemukan.']); exit;
        }
        $upd = $pdo->prepare(
            "UPDATE ohrm_kpi_setup SET divisi = :divisi, target = :target, bobot = :bobot,
                    schedule = :schedule, updated_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            ':divisi'   => $divisi,
            ':target'   => $target,
            ':bobot'    => $bobot,
            ':schedule' => $schedule,
            ':id'       => $setupId,
        ]);
        // Sinkronkan divisi pada baris realisasi (denormalisasi) supaya grouping
        // & seeding periode di get_kpi_realisasi.php ikut ke divisi baru.
        $pdo->prepare("UPDATE ohrm_kpi_realisasi SET divisi = ? WHERE setup_id = ?")
            ->execute([$divisi, $setupId]);
        echo json_encode([
            'status'  => 'success',
            'message' => 'Indikator berhasil diperbarui',
            'data'    => ['setup_id' => $setupId, 'divisi' => $divisi],
        ]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO ohrm_kpi_setup
                (divisi, cabang, periode, indikator, pengukuran, rumus, target, satuan, bobot, schedule, lower_better, aktif, updated_at)
             VALUES
                (:divisi, :cabang, :periode, :indikator, :pengukuran, :rumus, :target, :satuan, :bobot, :schedule, :lower, 1, NOW())"
        );
        $ins->execute([
            ':divisi'      => $divisi,
            ':cabang'      => $cabang !== '' ? $cabang : null,
            ':periode'     => $periode !== '' ? $periode : null,
            ':indikator'   => $indikator,
            ':pengukuran'  => $pengukuran !== '' ? $pengukuran : $indikator,
            ':rumus'       => $rumus !== '' ? $rumus : ($lower ? '(Target ÷ Realisasi) × 100%' : '(Realisasi ÷ Target) × 100%'),
            ':target'      => $target,
            ':satuan'      => $satuan !== '' ? $satuan : '%',
            ':bobot'       => $bobot,
            ':schedule'    => $schedule,
            ':lower'       => $lower,
        ]);
        $id = (int)$pdo->lastInsertId();

        echo json_encode([
            'status'  => 'success',
            'message' => 'Indikator berhasil ditambahkan',
            'data'    => ['setup_id' => $id, 'divisi' => $divisi],
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menyimpan indikator: '.$e->getMessage()]);
}