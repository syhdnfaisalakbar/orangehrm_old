<?php
require_once __DIR__ . '/_config.php';
/**
 * save_kpi_realisasi.php
 *
 * POST /proxy/save_kpi_realisasi.php   Body (JSON):
 *   {
 *     "emp": 1403,
 *     "submit": false,
 *     "rows": [
 *       { "setup_id": 1, "divisi": "Sales & Marketing", "cabang": "Jakarta",
 *         "periode": "2026-01", "realisasi": 96.0,
 *         "bukti": "uploads/bukti/.../file.pdf" },
 *       ...
 *     ]
 *   }
 *
 * 2026-09-25: filter divisi/periode DILIHAP di UI -> tiap baris membawa
 * divisi & periode sendiri (bisa campur divisi/periode dalam satu request).
 * Upsert ke ohrm_kpi_realisasi per (setup_id, periode). Skor & status
 * dihitung server-side. Scoping per baris: non-admin dipaksa ke divisi
 * mapping-nya, dan setup_id harus milik divisi yang dikirim.
 *
 * 2026-10-05 (cabang scope): realisasi kini dibedakan per cabang (unique
 * setup_id+periode+cabang). Scoping non-admin membatasi divisi DAN cabang.
 * `cabang` HARUS konkret (tidak boleh NULL) — untuk indikator yang berlaku
 * semua cabang (setup.cabang NULL) dipakai cabang user; untuk indikator
 * cabang-spesifik, cabang harus sama dengan setup.cabang.
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
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
    echo json_encode(['status'=>'error','message'=>'Format data salah.']); exit;
}
$emp    = isset($data['emp']) ? (int)$data['emp'] : 0;
$submitted = !empty($data['submit']);

function computeScore($lower, $target, $real) {
    if ($real === null || $real === '') return [null, null];
    $s = $lower ? ($target / ((float)$real ?: 1)) * 100 : ((float)$real / $target) * 100;
    $s = min(100, (float)$s);
    $st = $s >= 100 ? 'Biru' : ($s >= 90 ? 'Hijau' : ($s >= 80 ? 'Kuning' : 'Merah'));
    return [round($s, 2), $st];
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
            // Normalisasi Head Office: literal 'HO' di mapping == '' (canonical HO).
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

/** apakah user boleh isi cabang $cab untuk divisi $div? */
function cabangAllowed(array $cabByDiv, string $div, string $cab): bool {
    $a = $cabByDiv[$div] ?? null;
    if ($a === null) return false;
    if ($a === '*') return true;
    return in_array($cab, $a, true);
}

try {
    $scope = kpiScope($pdo, $emp);
    $isAdmin = $scope['is_admin'];

    $upsert = $pdo->prepare(
        "INSERT INTO ohrm_kpi_realisasi (setup_id, divisi, cabang, periode, realisasi, bukti, skor, status, submitted, approval_status, input_by)
         VALUES (:setup_id, :divisi, :cabang, :periode, :realisasi, :bukti, :skor, :status, :submitted, :approval_status, :input_by)
         ON DUPLICATE KEY UPDATE
            realisasi = VALUES(realisasi),
            bukti     = IF(VALUES(bukti) IS NULL OR VALUES(bukti) = '', bukti, VALUES(bukti)),
            skor      = VALUES(skor),
            status    = VALUES(status),
            submitted = GREATEST(submitted, VALUES(submitted)),
            approval_status = IF(VALUES(submitted) = 1, 'Menunggu', approval_status),
            updated_at = CURRENT_TIMESTAMP"
    );
    $getSetup = $pdo->prepare("SELECT target, lower_better AS `lower`, divisi, cabang FROM ohrm_kpi_setup WHERE id = ?");

    // History Achievement: pembaca nilai lama + penulis riwayat perubahan.
    $getOld = $pdo->prepare(
        "SELECT realisasi FROM ohrm_kpi_realisasi WHERE setup_id = ? AND periode = ? AND cabang = ?"
    );
    $insHist = $pdo->prepare(
        "INSERT INTO ohrm_kpi_realisasi_history
            (setup_id, divisi, cabang, periode, old_value, new_value, changed_by, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    $saved = 0;
    $skippedEmpty = 0;
    $errors = [];
    foreach ($data['rows'] as $row) {
        $setupId = (int)($row['setup_id'] ?? 0);
        $divisi  = trim((string)($row['divisi'] ?? ''));
        $cabang  = trim((string)($row['cabang'] ?? ''));
        $periode = trim((string)($row['periode'] ?? ''));
        $real    = (isset($row['realisasi']) && $row['realisasi'] !== '') ? (float)$row['realisasi'] : null;
        $bukti   = trim((string)($row['bukti'] ?? ''));

        if ($setupId <= 0 || $divisi === '' || $periode === '') continue;
        if (!preg_match('/^\d{4}-\d{2}$/', $periode)) continue;
        // Cabang '' (HO) adalah nilai valid; tidak NULL. Unique (setup,periode,cabang)
        // bekerja karena '' bukan NULL. Scope cabang disaring di bawah.

        // Scope baris
        if (!$isAdmin) {
            $cabByDiv = $scope['cabang_by_divisi'];
            if (!isset($cabByDiv[$divisi]) || !cabangAllowed($cabByDiv, $divisi, $cabang)) {
                $errors[] = "Anda tidak berhak mengisi divisi/cabang: $divisi / $cabang";
                continue;
            }
        }

        // Setup harus milik divisi & konsisten dgn cabang
        $getSetup->execute([$setupId]);
        $s = $getSetup->fetch(PDO::FETCH_ASSOC);
        if (!$s || trim((string)$s['divisi']) !== $divisi) continue;
        $setupCabang = (string)($s['cabang'] ?? '');
        // Indikator cabang-spesifik: cabang baris harus sama dengan setup.cabang
        if ($setupCabang !== '' && $cabang !== $setupCabang) {
            $errors[] = "Indikator ($setupId) khusus cabang '$setupCabang', tidak boleh diisi utk cabang '$cabang'.";
            continue;
        }

        // Submit hanya untuk baris yang terisi; baris kosong tidak dikunci
        if ($submitted && $real === null) { $skippedEmpty++; continue; }

        list($skor, $status) = computeScore((bool)$s['lower'], (float)$s['target'], $real);
        $approvalStatus = $submitted ? 'Menunggu' : null;
        // Ambil nilai realisasi lama (sebelum upsert) utk deteksi perubahan.
        $getOld->execute([$setupId, $periode, $cabang]);
        $oldVal = $getOld->fetchColumn();
        $oldVal = ($oldVal === false || $oldVal === null) ? null : (float)$oldVal;
        $upsert->execute([
            ':setup_id' => $setupId,
            ':divisi'   => $divisi,
            ':cabang'   => $cabang,
            ':periode'  => $periode,
            ':realisasi'=> $real,
            ':bukti'    => $bukti !== '' ? $bukti : null,
            ':skor'     => $skor,
            ':status'   => $status,
            ':submitted'=> $submitted ? 1 : 0,
            ':approval_status' => $approvalStatus,
            ':input_by' => $emp > 0 ? $emp : null,
        ]);
        $saved++;
        // Catat riwayat perubahan nilai Achievement (lama -> baru). Mencakup juga
        // pengisian pertama (old null). Perubahan == nilai berbeda dari tersimpan.
        if ($oldVal !== $real) {
            $insHist->execute([
                $setupId,
                $divisi,
                $cabang,
                $periode,
                $oldVal,
                $real,
                $emp > 0 ? $emp : null,
            ]);
        }
        if ($submitted) {
            // CATATAN: tabel ohrm_kpi_approval_log TIDAK punya kolom `cabang`
            // (lihat update_approval.php yg juga insert tanpa cabang). JANGAN
            // tambahkan cabang di sini -> "Unknown column 'cabang'" saat submit.
            $log = $pdo->prepare("INSERT INTO ohrm_kpi_approval_log
                (setup_id, divisi, periode, action, realisasi, skor, acted_by, acted_at, note)
                VALUES (:setup_id, :divisi, :periode, 'submitted', :realisasi, :skor, :acted_by, NOW(), 'Diajukan ke verifikasi')");
            $log->execute([
                ':setup_id' => $setupId,
                ':divisi'   => $divisi,
                ':periode'  => $periode,
                ':realisasi'=> $real,
                ':skor'     => $skor,
                ':acted_by' => $emp > 0 ? $emp : null,
            ]);
        }
    }

    $msg = ($submitted ? 'Diajukan ke verifikasi' : 'Draft disimpan') . " ($saved indikator)";
    if ($submitted && $skippedEmpty > 0) $msg .= ", $skippedEmpty baris kosong dilewati";
    if (!empty($errors)) $msg .= ". " . implode(' | ', array_slice($errors, 0, 5));
    echo json_encode(['status'=>'success','message'=>$msg,'saved'=>$saved,'skippedEmpty'=>$skippedEmpty,'errors'=>$errors]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menyimpan: '.$e->getMessage()]);
}