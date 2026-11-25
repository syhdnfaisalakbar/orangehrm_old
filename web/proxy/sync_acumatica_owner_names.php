<?php
/**
 * sync_acumatica_owner_names.php
 *
 * Menyegarkan cache nama owner Acumatica (tabel ohrm_acumatica_owner_name) dari
 * entity / Generic Inquiry yang dikonfigurasi di config.php:
 *
 *   'acumatica' => ['owner_lookup' => [
 *       'entity'     => 'Users',         // nama entity/GI di Acumatica
 *       'id_field'   => 'OwnerID',       // default
 *       'name_field' => 'DisplayName',   // default
 *   ]],
 *
 * Dipakai untuk kolom Owner di menu Manpower Request (FPTK): CRActivity.OwnerID
 * berisi ID user, bukan nama, dan API Default/25.200.001 tidak mengekspos
 * entity Users. Begitu IT menyediakan sumber namanya, cukup isi entity di atas
 * lalu panggil endpoint ini (tombol/curl) — nama langsung terpakai di tabel.
 *
 * Pemanggilan:
 *   - POST dari aplikasi (origin sama), tanpa body.
 *   - CLI (untuk scheduler): php sync_acumatica_owner_names.php
 *
 * Aman: kalau owner_lookup.entity kosong, endpoint menjawab status "disabled"
 * dan tidak melakukan apa pun.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_owner_lookup.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    require_once __DIR__ . '/_guard.php';
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $guardError = proxy_require_post();
    if ($guardError !== null) {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => $guardError]);
        exit;
    }
    $guardError = proxy_require_same_origin();
    if ($guardError !== null) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => $guardError]);
        exit;
    }
}

try {
    $pdo = new PDO(
        "mysql:host=" . PROXY_DB_HOST . ";port=" . PROXY_DB_PORT . ";dbname=" . PROXY_DB_NAME . ";charset=utf8",
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $payload = ['status' => 'error', 'message' => 'Koneksi Database Gagal: ' . $e->getMessage()];
    echo $isCli ? (json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL) : json_encode($payload);
    exit;
}

$result = acumaticaOwnerNamesSync($pdo);
$result['synced_at'] = acumaticaOwnerNamesSyncedAt($pdo);

// Tambahan 2026-11-25: harvest pasangan ID->nama dari Case/Lead/Opportunity/
// Contact. Ini yang bikin fitur tetap berguna walau IT belum menyediakan
// entity/GI user (entity Task hanya mengirim Owner berupa ID numerik).
$harvest = acumaticaOwnerNamesHarvest($pdo, null, true);
$result['harvest'] = $harvest;
$result['total_cached'] = (int)$pdo->query("SELECT COUNT(*) FROM ohrm_acumatica_owner_name")->fetchColumn();
$result['entity_field_map'] = [
    'id'   => defined('ACUMATICA_OWNER_LOOKUP_ID_FIELD') ? ACUMATICA_OWNER_LOOKUP_ID_FIELD : '',
    'name' => defined('ACUMATICA_OWNER_LOOKUP_NAME_FIELD') ? ACUMATICA_OWNER_LOOKUP_NAME_FIELD : '',
];

if (!$isCli && $result['status'] === 'error') {
    http_response_code(500);
}

echo $isCli
    ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL
    : json_encode($result, JSON_UNESCAPED_SLASHES);
