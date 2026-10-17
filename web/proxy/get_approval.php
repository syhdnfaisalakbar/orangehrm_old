<?php
require_once __DIR__ . '/_config.php';
/**
 * get_approval.php
 *
 * GET /proxy/get_approval.php?emp=<empNumber>
 *
 * Baris realisasi KPI yang SUDAH diajukan (submitted=1) untuk halaman Approval.
 * Rows digabung dengan definisi indikator (ohrm_kpi_setup) + info inputer
 * (hs_hr_employee) supaya bisa ditampilkan indikator & siapa yang mengajukan.
 *
 * Response: { status, data: { is_admin, current_division, rows:[
 *    { id, setup_id, divisi, cabang, periode, indikator, target, satuan, bobot,
 *      schedule, lower, realisasi, skor, status, bukti, submitted,
 *      approval_status, approved_by, approved_at, reject_reason, input_by,
 *      inputer_name } ] } }
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

try {
    // 1. Role user
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }

    // 2. Scope divisi + cabang: admin = semua; non-admin = mapping-nya
    //    (namun approval biasanya dijalankan admin; tetap di-scope supaya aman).
    $divisions = [];
    $cabByDiv = [];
    if ($isAdmin) {
        foreach ($pdo->query("SELECT DISTINCT divisi FROM ohrm_kpi_setup WHERE aktif=1 ORDER BY divisi") as $r)
            $divisions[] = $r['divisi'];
    } else {
        $st = $pdo->prepare("SELECT divisi, cabang FROM ohrm_kpi_division_member WHERE emp_number = ?");
        $st->execute([$emp]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $div = (string)$r['divisi'];
            if (!in_array($div, $divisions, true)) $divisions[] = $div;
            $cab = $r['cabang'] === null ? null : (string)$r['cabang'];
            if ($cab === null) {
                $cabByDiv[$div] = '*';
            } elseif (($cabByDiv[$div] ?? null) !== '*') {
                $cabByDiv[$div][] = $cab;
            }
        }
        $divisions = array_values($divisions);
    }

    // 3. Ambil baris submitted yang ada di dalam scope divisi (+ cabang utk non-admin).
    $rows = [];
    if (count($divisions) > 0) {
        $in = implode(',', array_fill(0, count($divisions), '?'));
        $where = "r.submitted = 1 AND r.divisi IN ($in)";
        $params = $divisions;
        if (!$isAdmin) {
            // force cabang sesuai mapping
            $cnd = [];
            foreach ($cabByDiv as $div => $cabSet) {
                if ($cabSet === '*') { $cnd[] = "(r.divisi = ?)"; $params[] = $div; }
                elseif (is_array($cabSet) && count($cabSet) > 0) {
                    $inC = implode(',', array_fill(0, count($cabSet), '?'));
                    $cnd[] = "(r.divisi = ? AND r.cabang IN ($inC))";
                    $params[] = $div;
                    $params = array_merge($params, $cabSet);
                }
            }
            if (count($cnd) > 0) $where .= " AND (" . implode(' OR ', $cnd) . ")";
            else $where .= " AND 1=0";
        }
        $q = "SELECT r.id, r.setup_id, r.divisi, r.cabang, r.periode,
                     s.indikator, s.target, s.satuan, s.bobot, s.schedule,
                     s.lower_better AS `lower`, s.aktif,
                     r.realisasi, r.bukti, r.skor, r.status, r.submitted,
                     r.approval_status, r.approved_by, r.approved_at, r.reject_reason,
                     r.input_by,
                     e.emp_firstname, e.emp_lastname
              FROM ohrm_kpi_realisasi r
              JOIN ohrm_kpi_setup s ON s.id = r.setup_id
              LEFT JOIN hs_hr_employee e ON e.emp_number = r.input_by
              WHERE $where
              ORDER BY r.approval_status = 'Menunggu' DESC, r.periode DESC, r.divisi, r.id";
        $st = $pdo->prepare($q);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inputer = trim(($row['emp_firstname'] ?? '') . ' ' . ($row['emp_lastname'] ?? ''));
            $rows[] = [
                'id'              => (int)$row['id'],
                'setup_id'        => (int)$row['setup_id'],
                'indikator'       => $row['indikator'],
                'divisi'          => $row['divisi'],
                'cabang'          => $row['cabang'],
                'periode'         => $row['periode'],
                'target'          => (float)$row['target'],
                'satuan'          => $row['satuan'],
                'bobot'           => (int)$row['bobot'],
                'schedule'        => $row['schedule'],
                'lower'           => (bool)$row['lower'],
                'realisasi'       => $row['realisasi'] !== null ? (float)$row['realisasi'] : null,
                'skor'            => $row['skor'] !== null ? (float)$row['skor'] : null,
                'status'          => $row['status'],
                'bukti'           => $row['bukti'],
                'submitted'       => (int)$row['submitted'],
                'approval_status' => $row['approval_status'],
                'approved_by'     => $row['approved_by'] !== null ? (int)$row['approved_by'] : null,
                'approved_at'     => $row['approved_at'],
                'reject_reason'   => $row['reject_reason'],
                'input_by'        => $row['input_by'] !== null ? (int)$row['input_by'] : null,
                'inputer_name'    => $inputer,
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'is_admin'         => $isAdmin,
            'current_division' => $isAdmin ? '' : ($divisions[0] ?? ''),
            'divisions'        => $divisions,
            'rows'             => $rows,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil data approval: '.$e->getMessage()]);
}