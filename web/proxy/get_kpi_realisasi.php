<?php
require_once __DIR__ . '/_config.php';
/**
 * get_kpi_realisasi.php
 *
 * GET /proxy/get_kpi_realisasi.php?emp=<empNumber>
 *
 * 2026-09-25: filter divisi/periode DILIHAP (permintaan user). Proxy sekarang
 * mengembalikan SEMUA divisi × SEMUA periode dalam satu daftar rows. Setiap
 * baris membawa divisi & periode sendiri, siap di-save per baris.
 *
 * 2026-10-05 (cabang scope): ohrm_kpi_division_member kini punya kolom cabang
 * (multi-cabang per user; NULL cabang = semua cabang divisi itu). Scoping
 * non-admin sekarang membatasi divisi DAN cabang:
 *   - Admin (user_role_id=1)      -> semua divisi, semua cabang.
 *   - Non-admin                   -> hanya divisi & cabang yang ia punya mapping.
 * Baris realisasi dibedakan per cabang (unique setup_id+periode+cabang).
 * Untuk indikator 'berlaku semua cabang' (setup.cabang NULL), tiap cabang yang
 * berhak mendapat baris sendiri dengan cabang = cabang user (default) + baris
 * ekstra untuk cabang yang sudah punya realisasi.
 *
 * Respons:
 *   { status, data: { is_admin, current_division, divisions[],
 *                     locations[], allowed_cabangs{...},
 *                     rows: [{ divisi, cabang, periode, setup_id, indikator,
 *                              pengukuran, rumus, target, satuan, bobot,
 *                              schedule, lower, realisasi, bukti, skor, status,
 *                              submitted, approval_status }] } }
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;

function computeScore($lower, $target, $real) {
    if ($real === null) return [null, null];
    $s = $lower ? ($target / (($real ?: 1))) * 100 : ($real / $target) * 100;
    $s = min(100, (float)$s);
    $st = $s >= 100 ? 'Biru' : ($s >= 90 ? 'Hijau' : ($s >= 80 ? 'Kuning' : 'Merah'));
    return [round($s, 2), $st];
}

/**
 * Himpunan scope user non-admin. NULL cabang di mapping => semua cabang ('*').
 * Mengembalikan: is_admin, divisions[], cabang_by_divisi{ divisi: '*' | [cabang,...] }
 */
function kpiScope(PDO $pdo, int $emp): array {
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    $divisions = [];
    $cabByDiv = [];
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT divisi, cabang FROM ohrm_kpi_division_member WHERE emp_number = ? AND aktif = 1");
        $st->execute([$emp]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $div = (string)$r['divisi'];
            if (!in_array($div, $divisions, true)) $divisions[] = $div;
            $cab = $r['cabang'] === null ? null : (string)$r['cabang'];
            // Normalisasi Head Office: literal 'HO' di mapping == '' (canonical HO).
            // Mapping lama disimpan 'HO', padahal realisasi & save pakai '' — tanpa
            // ini non-admin divisi HO malah lookup realMap['HO'] yang tak ada (kosong).
            if ($cab === 'HO') $cab = '';
            if ($cab === null) {
                $cabByDiv[$div] = '*';
            } elseif (($cabByDiv[$div] ?? null) !== '*') {
                if (!isset($cabByDiv[$div])) $cabByDiv[$div] = [];
                if (!in_array($cab, $cabByDiv[$div], true)) $cabByDiv[$div][] = $cab;
            }
        }
    }
    return ['is_admin' => $isAdmin, 'divisions' => $divisions, 'cabang_by_divisi' => $cabByDiv];
}

/**
 * Cabang default untuk indikator 'berlaku semua cabang' (setup.cabang NULL)
 * menurut scope user pada suatu divisi.
 *  - '*'  -> '' (HO / bebas semua)
 *  - array -> elemen pertama (contoh cabang izin)
 *  - token -> '' (tidak berhak divisi, jarang)
 */
function defaultCabangFor(array $cabByDiv, string $divisi): string {
    $cb = $cabByDiv[$divisi] ?? null;
    if ($cb === '*') return '';
    if (is_array($cb) && count($cb) > 0) return $cb[0];
    return '';
}

try {
    $scope = kpiScope($pdo, $emp);
    $isAdmin = $scope['is_admin'];

    // Daftar divisi: admin = semua setup aktif; non-admin = divisi mapping
    $divisions = $scope['divisions'];
    if ($isAdmin) {
        $divisions = [];
        foreach ($pdo->query("SELECT DISTINCT divisi FROM ohrm_kpi_setup WHERE aktif=1 ORDER BY divisi") as $r)
            $divisions[] = $r['divisi'];
    }

    // Daftar lokasi/cabang (dari ohrm_location) utk dropdown Cabang
    $locations = [];
    foreach ($pdo->query("SELECT id, name FROM ohrm_location ORDER BY name") as $r)
        $locations[] = ['id' => (int)$r['id'], 'name' => $r['name']];

    // Baris flat: divisi × periode × cabang
    $curYm = date('Y-m');
    $rows = [];
    $stmtSetup = $pdo->prepare(
        "SELECT s.id AS setup_id, s.cabang AS setup_cabang, s.periode AS setup_periode,
                s.indikator, s.pengukuran, s.rumus, s.target, s.satuan,
                s.bobot, s.schedule, s.lower_better AS `lower`,
                EXISTS(SELECT 1 FROM ohrm_kpi_setup_edit_request er
                       WHERE er.setup_id = s.id AND er.request_status = 'Menunggu') AS edit_pending,
                EXISTS(SELECT 1 FROM ohrm_kpi_setup_edit_request er2
                       WHERE er2.setup_id = s.id AND er2.request_status = 'Disetujui') AS edit_approved
         FROM ohrm_kpi_setup s
         WHERE s.divisi = :divisi AND s.aktif = 1
               AND (s.periode IS NULL OR s.periode = :periode)
               AND NOT EXISTS (
                   SELECT 1 FROM ohrm_kpi_setup_period_delete pd
                   WHERE pd.setup_id = s.id AND pd.periode = :periode
               )
         ORDER BY s.id"
    );
    $stmtReal = $pdo->prepare(
        "SELECT setup_id, COALESCE(cabang,'') AS cabang, realisasi, bukti, skor, status,
                submitted, approval_status
         FROM ohrm_kpi_realisasi WHERE setup_id = :setup_id AND periode = :periode"
    );

    foreach ($divisions as $dept) {
        // Semua periode yang pernah diisi divisi ini (desc) + bulan berjalan
        $periodes = [];
        $st = $pdo->prepare("SELECT DISTINCT periode FROM ohrm_kpi_realisasi WHERE divisi = ? ORDER BY periode DESC");
        $st->execute([$dept]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $periodes[] = $p;
        // Periode dari definisi setup (KPI baru yang terikat periode)
        $st = $pdo->prepare("SELECT DISTINCT periode FROM ohrm_kpi_setup WHERE divisi = ? AND periode IS NOT NULL AND aktif = 1 ORDER BY periode DESC");
        $st->execute([$dept]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $periodes[] = $p;
        if (!in_array($curYm, $periodes, true)) $periodes[] = $curYm;
        $periodes = array_values(array_unique($periodes));
        usort($periodes, function($a, $b) { return strcmp($b, $a); });

        foreach ($periodes as $periode) {
            $stmtSetup->execute([':periode' => $periode, ':divisi' => $dept]);
            $setups = $stmtSetup->fetchAll(PDO::FETCH_ASSOC);
            // Muat realisasi semua setup divisi+periode ini
            $realMap = []; // setup_id => cabang => row
            if (!empty($setups)) {
                $ids = array_column($setups, 'setup_id');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $par = array_merge($ids, [$periode]);
                $st = $pdo->prepare("SELECT setup_id, COALESCE(cabang,'') AS cabang, realisasi, bukti, skor, status, submitted, approval_status
                                     FROM ohrm_kpi_realisasi WHERE setup_id IN ($in) AND periode = ?");
                $st->execute($par);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                    // Normalisasi Head Office: literal 'HO' diperlakukan SAMA dgn ''
                    // (canonical HO). Data lama pernah tersimpan 'HO', dua-duanya
                    // merepresentasikan HO yang sama — dikunci ke satu kunci '' supaya
                    // indikator 'berlaku semua cabang' tidak di-expand jadi 2 baris.
                    $c = (string)($rr['cabang'] ?? '');
                    $key = ($c === 'HO') ? '' : $c;
                    if (!isset($realMap[$rr['setup_id']][$key])) {
                        $realMap[$rr['setup_id']][$key] = $rr;
                    } elseif (
                        $rr['realisasi'] !== null && $rr['realisasi'] !== '' &&
                        empty($realMap[$rr['setup_id']][$key]['realisasi'])
                    ) {
                        // prefer baris yang punya realisasi (yang asli) atas duplikat kosong
                        $realMap[$rr['setup_id']][$key] = $rr;
                    }
                }
            }

            foreach ($setups as $row) {
                $sid = (int)$row['setup_id'];
                $setupCabang = $row['setup_cabang'] !== null ? (string)$row['setup_cabang'] : null;

                // Tentukan cabang yang TAMPIL untuk setup ini
                if ($isAdmin) {
                    if ($setupCabang === null) {
                        // berlaku semua cabang: default '' + cabang yang sudah punya realisasi
                        $cabList = [''];
                        foreach (array_keys($realMap[$sid] ?? []) as $c) if (!in_array($c, $cabList, true)) $cabList[] = $c;
                    } else {
                        $cabList = [$setupCabang];
                    }
                } else {
                    $allowed = $scope['cabang_by_divisi'][$dept] ?? null;
                    if ($allowed === null) continue; // user tidak berhak divisi ini
                    if ($setupCabang === null) {
                        // berlaku semua cabang: default cabang user + realisasi cabang yg dia izinkan
                        $def = defaultCabangFor($scope['cabang_by_divisi'], $dept);
                        $cabList = [$def];
                        foreach (array_keys($realMap[$sid] ?? []) as $c) {
                            $ok = ($allowed === '*') || in_array($c, $allowed, true);
                            if ($ok && !in_array($c, $cabList, true)) $cabList[] = $c;
                        }
                    } else {
                        // setup cabang spesifik: hanya jika cabang itu diizinkan
                        $ok = ($allowed === '*') || in_array($setupCabang, $allowed, true);
                        if (!$ok) continue;
                        $cabList = [$setupCabang];
                    }
                }

                foreach ($cabList as $cab) {
                    $rr = $realMap[$sid][$cab] ?? null;
                    $real = $rr['realisasi'] ?? null;
                    $skor = $rr['skor'] ?? null;
                    $stat = $rr['status'] ?? null;
                    if ($real === null) { $skor = null; $stat = null; }
                    $rows[] = [
                        'divisi'      => $dept,
                        'cabang'      => $cab,
                        'periode'     => $periode,
                        'setup_id'    => $sid,
                        'indikator'   => $row['indikator'],
                        'pengukuran'  => $row['pengukuran'],
                        'rumus'       => $row['rumus'],
                        'target'      => (float)$row['target'],
                        'satuan'      => $row['satuan'],
                        'bobot'       => (int)$row['bobot'],
                        'schedule'    => $row['schedule'],
                        'lower'       => (bool)$row['lower'],
                        'realisasi'   => $real !== null ? (float)$real : null,
                        'bukti'       => $rr['bukti'] ?? null,
                        'skor'        => $skor !== null ? (float)$skor : null,
                        'status'      => $stat,
                        'submitted'   => (int)($rr['submitted'] ?? 0),
                        'approval_status' => $rr['approval_status'] ?? null,
                        'edit_pending' => (bool)$row['edit_pending'],
                        'edit_approved' => (bool)$row['edit_approved'],
                    ];
                }
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'is_admin'         => $isAdmin,
            'current_division' => $isAdmin ? '' : ($divisions[0] ?? ''),
            'divisions'        => $divisions,
            'locations'        => $locations,
            'allowed_cabangs'  => $isAdmin ? null : $scope['cabang_by_divisi'],
            'rows'             => $rows,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil data KPI: '.$e->getMessage()]);
}