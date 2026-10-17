<?php
require_once __DIR__ . '/_config.php';
// ============================================
// delete_manpower_case.php
//
// Hapus SELURUH baris case Manpower Request (FPTK) dari tabel lokal
// ohrm_manpower_request, beserta approval task & link vacancy-nya.
// Body POST: { "caseIds": ["0000008624", ...] }
//
// ⚠ LOKAL & PRAKTIS PERMANEN:
// Sync Acumatica (tombol manual maupun scheduler) itu INKREMENTAL —
// `CaseID gt <max case_id lokal>`, hanya menarik case dengan nomor LEBIH
// TINGGI. Jadi case yang dihapus di sini UMUMNYA TIDAK akan ditarik kembali
// otomatis. Data di Acumatica TETAP ADA; ini hanya menghapus cermin lokalnya.
//
// Relasi & cascade (otomatis oleh FK ON DELETE CASCADE):
//   - ohrm_manpower_approval     FK case_id -> ohrm_manpower_request(case_id) CASCADE
//   - ohrm_manpower_vacancy_link FK manpower_request_id -> ohrm_manpower_request(id) CASCADE
// Manual (kolom biasa tanpa FK):
//   - ohrm_job_vacancy.manpower_request_id & case_id di-NULL-kan utk vacancy ter-link.
// ============================================
header('Content-Type: application/json');

$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$caseIds = $input['caseIds'] ?? [];

$caseIds = array_values(array_filter(
    array_map('strval', $caseIds),
    fn($c) => $c !== '' && $c !== '-'
));
$caseIds = array_slice($caseIds, 0, 200);

if (count($caseIds) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'caseIds wajib diisi.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Build IN clause + params (dipakai utk SELECT id, UPDATE vacancy, DELETE request)
    $inP = [];
    $params = [];
    foreach ($caseIds as $i => $c) {
        $k = ':c' . $i;
        $inP[] = $k;
        $params[$k] = $c;
    }
    $inList = implode(',', $inP);

    // 1) Ambil PK id ohrm_manpower_request utk case terpilih (utk reset vacancy)
    $stmtSel = $pdo->prepare(
        "SELECT id FROM ohrm_manpower_request WHERE case_id IN ($inList)"
    );
    $stmtSel->execute($params);
    $pkIds = $stmtSel->fetchAll(PDO::FETCH_COLUMN);

    // 2) Reset kolom manual di ohrm_job_vacancy (kolom biasa, tidak ikut cascade)
    if (count($pkIds) > 0) {
        $inP2 = [];
        $p2 = [];
        foreach ($pkIds as $i => $pid) {
            $k = ':id' . $i;
            $inP2[] = $k;
            $p2[$k] = $pid;
        }
        $upd = $pdo->prepare(
            "UPDATE ohrm_job_vacancy
             SET manpower_request_id = NULL, case_id = NULL
             WHERE manpower_request_id IN (" . implode(',', $inP2) . ")"
        );
        $upd->execute($p2);
    }

    // 3) Hapus request — cascade otomatis ke ohrm_manpower_approval &
    //    ohrm_manpower_vacancy_link (FK ON DELETE CASCADE)
    $stmtDel = $pdo->prepare(
        "DELETE FROM ohrm_manpower_request WHERE case_id IN ($inList)"
    );
    $stmtDel->execute($params);
    $deleted = $stmtDel->rowCount();

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'deleted' => $deleted,
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus case: ' . $e->getMessage()]);
}