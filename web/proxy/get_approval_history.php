<?php
require_once __DIR__ . '/_config.php';
/**
 * get_approval_history.php
 *
 * GET /proxy/get_approval_history.php?setup_id=<id>[&periode=YYYY-MM]
 *
 * Riwayat approval indikator (audit) dari ohrm_kpi_approval_log, urut OLDEST.
 * Kalau 'periode' dikirim -> log utk setup+periode itu; tanpa periode -> semua
 * log utk semua periode setup itu. Tiap baris = 1 aksi (submitted/approved/
 * rejected) berurutan, join nama aktor dari hs_hr_employee.
 *
 * Response: { status, setup_id, periode, rows:[{ id, action, realisasi, skor,
 *   acted_by, acted_at, note, actor_name }] }
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$setupId = isset($_GET['setup_id']) ? (int)$_GET['setup_id'] : 0;
$periode = trim((string)($_GET['periode'] ?? ''));

try {
    if ($periode !== '' && !preg_match('/^\d{4}-\d{2}$/', $periode)) {
        echo json_encode(['status'=>'error','message'=>'Format periode tidak valid']); exit;
    }

    $sql = "SELECT l.id, l.action, l.realisasi, l.skor, l.acted_by, l.acted_at, l.note,
                   e.emp_firstname, e.emp_lastname
            FROM ohrm_kpi_approval_log l
            LEFT JOIN hs_hr_employee e ON e.emp_number = l.acted_by
            WHERE l.setup_id = :sid";
    $params = [':sid' => $setupId];
    if ($periode !== '') {
        $sql .= " AND l.periode = :periode";
        $params[':periode'] = $periode;
    }
    $sql .= " ORDER BY l.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $actor = trim(($r['emp_firstname'] ?? '') . ' ' . ($r['emp_lastname'] ?? ''));
        $rows[] = [
            'id'          => (int)$r['id'],
            'action'      => $r['action'],
            'realisasi'   => $r['realisasi'] !== null ? (float)$r['realisasi'] : null,
            'skor'        => $r['skor'] !== null ? (float)$r['skor'] : null,
            'acted_by'    => $r['acted_by'] !== null ? (int)$r['acted_by'] : null,
            'acted_at'    => $r['acted_at'],
            'note'        => $r['note'],
            'actor_name'  => $actor,
        ];
    }

    echo json_encode(['status'=>'success','setup_id'=>$setupId,'periode'=>$periode,'rows'=>$rows]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil history: '.$e->getMessage()]);
}