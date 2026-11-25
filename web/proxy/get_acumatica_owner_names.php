<?php
/**
 * get_acumatica_owner_names.php
 *
 * Peta OwnerID Acumatica -> nama, untuk kolom/tombol Owner di UI (modal Approval
 * Tasks memuat Task LIVE dari Acumatica, jadi namanya harus diterjemahkan di
 * browser juga).
 *
 * Sumber: ohrm_acumatica_owner_name (harvest Case/Lead/Opportunity/Contact +
 * seed manual + GI dari IT) lalu ohrm_employee_acumatica_owner (mapping
 * karyawan). Tidak ada fallback ke nama owner Case.
 *
 * GET (tanpa parameter). Output: {"status":"success","count":N,"data":{ "202263": "..." }}
 */
require_once __DIR__ . '/_config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo = new PDO(
        "mysql:host=" . PROXY_DB_HOST . ";port=" . PROXY_DB_PORT . ";dbname=" . PROXY_DB_NAME . ";charset=utf8",
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi Database Gagal']);
    exit;
}

$map = [];

// 1) Cache utama (harvest + seed manual + GI).
try {
    foreach ($pdo->query("SELECT owner_id, owner_name FROM ohrm_acumatica_owner_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $oid = trim((string)($r['owner_id'] ?? ''));
        $nm  = trim((string)($r['owner_name'] ?? ''));
        if ($oid !== '' && $nm !== '') {
            $map[$oid] = $nm;
        }
    }
} catch (Throwable $e) {
    // tabel cache belum ada -> lanjut ke mapping
}

// 2) Mapping karyawan -> owner Acumatica (tidak menimpa cache).
try {
    foreach ($pdo->query("SELECT owner_id, owner_name FROM ohrm_employee_acumatica_owner")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $oid = trim((string)($r['owner_id'] ?? ''));
        $nm  = trim((string)($r['owner_name'] ?? ''));
        if ($oid !== '' && $nm !== '' && !isset($map[$oid])) {
            $map[$oid] = $nm;
        }
    }
} catch (Throwable $e) {
    // tabel mapping belum ada
}

echo json_encode([
    'status' => 'success',
    'count'  => count($map),
    'data'   => $map,
]);
