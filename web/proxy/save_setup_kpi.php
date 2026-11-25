<?php
require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_kpi_lock_helper.php';
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
        $chk = $pdo->prepare("SELECT id, divisi, indikator, target, bobot, schedule FROM ohrm_kpi_setup WHERE id = :id AND aktif = 1");
        $chk->execute([':id' => $setupId]);
        $oldSetup = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$oldSetup) {
            echo json_encode(['status'=>'error','message'=>'Indikator tidak ditemukan.']); exit;
        }
        // Kunci bobot/target bulan yang sudah lewat dengan nilai LAMA
        // (permintaan Pak 2026-11-23): edit bobot hanya mengubah bulan berjalan.
        kpiLockIfChanged($pdo, (int)$setupId, $oldSetup['bobot'], $oldSetup['target'], $bobot, $target);

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
        // NOTIFIKASI EMAIL ke role BOD (permintaan Pak 2026-11-24) untuk edit
        // target/bobot/schedule lewat ikon pensil. Response dikirim DULU dengan
        // Content-Length supaya UI tidak menunggu koneksi SMTP (~4 s/email).
        $payload = json_encode([
            'status'  => 'success',
            'message' => 'Indikator berhasil diperbarui',
            'data'    => ['setup_id' => $setupId, 'divisi' => $divisi],
        ]);
        header('Content-Length: ' . strlen($payload));
        header('Connection: keep-alive');
        echo $payload;
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();

        @include_once __DIR__ . '/kpi_mailer.php';
        if (defined('KPI_EMAIL_NOTIFY_ENABLED') && KPI_EMAIL_NOTIFY_ENABLED === true
            && function_exists('kpi_notify_setup_change')) {
            try {
                $mailOld = [
                    'id'        => $setupId,
                    'indikator' => (string)($oldSetup['indikator'] ?? ''),
                    // Tampilkan divisi TUJUAN (admin boleh pindah divisi).
                    'divisi'    => $divisi !== '' ? $divisi : (string)($oldSetup['divisi'] ?? ''),
                    'target'    => $oldSetup['target'] ?? null,
                    'bobot'     => $oldSetup['bobot'] ?? null,
                    'schedule'  => $oldSetup['schedule'] ?? null,
                ];
                kpi_notify_setup_change($pdo, $emp, $mailOld, $target, $bobot, $schedule);
            } catch (Throwable $e) {
                // diabaikan: email best-effort, data sudah tersimpan
            }
        }
    } else {
        // Validasi duplikat (permintaan Pak): indikator dianggap kembar HANYA bila
        // nama sama di divisi + cabang yang sama DAN periodenya sama persis.
        // '' (kosong) = "Semua Periode". Nama sama dengan periode BERBEDA tetap
        // diizinkan karena tiap periode boleh punya daftar indikatornya sendiri.
        $dup = $pdo->prepare(
            "SELECT id FROM ohrm_kpi_setup
             WHERE aktif = 1 AND divisi = :divisi
               AND TRIM(LOWER(indikator)) = TRIM(LOWER(:indikator))
               AND COALESCE(cabang, '') = :cabang
               AND COALESCE(periode, '') = :periode
             LIMIT 1"
        );
        $dup->execute([
            ':divisi'    => $divisi,
            ':indikator' => $indikator,
            ':cabang'    => $cabang,
            ':periode'   => $periode,
        ]);
        if ($dup->fetchColumn()) {
            // Label periode yang enak dibaca: 2026-05 -> "Mei 2026".
            $bulanId = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
                        '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
                        '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
            $labelPeriode = 'semua periode';
            if ($periode !== '' && preg_match('/^(\d{4})-(\d{2})$/', $periode, $mm)) {
                $labelPeriode = 'periode ' . ($bulanId[$mm[2]] ?? $mm[2]) . ' ' . $mm[1];
            }
            echo json_encode([
                'status'  => 'error',
                'message' => 'Indikator "' . $indikator . '" untuk ' . $labelPeriode
                    . ' sudah ada di divisi ini. Silakan pakai nama lain atau ubah lewat tombol Edit.',
            ]);
            exit;
        }

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