<?php
/**
 * sync_manpower_owner.php — Fork ASG
 *
 * Dipanggil dari menu Manpower Request (FPTK) SESUDAH tombol "Sync Acumatica"
 * selesai menarik data ke DB lokal. Tugasnya: mengubah field `Owner` pada Case
 * Acumatica (ClassID REQFPTK) menjadi user OrangeHRM yang SEDANG LOGIN, khusus
 * case berstatus New/Open.
 *
 * Kenapa endpoint terpisah:
 *  - Sync yang ada hanya PULL (Acumatica -> OrangeHRM). Penulisan Owner adalah
 *    PUSH, jadi dipisah supaya kegagalan Acumatica tidak menggagalkan sync data.
 *  - Identitas user harus dibaca dari SESSION OrangeHRM (bukan dikirim browser),
 *    karena endpoint web/proxy tidak memakai autentikasi lain.
 *
 * Alur:
 *  1. baca session OrangeHRM -> emp_number + role
 *  2. resolusi Owner ID (angka) Acumatica: cache tabel ohrm_employee_acumatica_owner
 *     -> kalau kosong, cocokkan nama dengan OwnerEmployeeName dari daftar Case
 *  3. ambil daftar Case New/Open dari Acumatica (1x GET) -> bandingkan Owner sekarang
 *  4. PUT Owner hanya untuk case yang nilainya berbeda
 *  5. GET ulang untuk verifikasi, kembalikan ringkasan
 *
 * Reuse sesi: kalau cookie jar milik acumatica.php (dipakai tombol Sync) masih
 * valid, sesi itu yang dipakai — jangan login lagi, karena kuota
 * "concurrent API logins" Acumatica praktis 1.
 *
 * Tabel: deploy/sql/manpower-owner-acumatica.sql
 * Log   : web/proxy/_cache/manpower_owner.log
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';
require_once __DIR__ . '/_guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ------------------------------------------------------------
// 0. Guard dasar (POST + origin sama)
// ------------------------------------------------------------
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

// ------------------------------------------------------------
// 1. Konfigurasi Acumatica + koneksi DB
// ------------------------------------------------------------
$acBase = rtrim((string) ACUMATICA_BASE_URL, '/');
if (!preg_match('#^https?://#i', $acBase)) {
    $acBase = 'http://' . $acBase;
}
$entity = rtrim($acBase, '/') . '/entity/Default/25.200.001';
$jarFile = __DIR__ . '/acumatica_cookies.txt';
$logFile = __DIR__ . '/_cache/manpower_owner.log';
$statuses = ['New', 'Open'];

/** @return PDO */
function mpOwnerPdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        PROXY_DB_HOST,
        defined('PROXY_DB_PORT') ? PROXY_DB_PORT : '3306',
        PROXY_DB_NAME
    );
    return new PDO($dsn, PROXY_DB_USER, PROXY_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function mpOwnerLog(string $file, array $entry): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $entry['at'] = date('Y-m-d H:i:s');
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

function mpOwnerRespond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------
// 2. Baca user OrangeHRM dari session
//    Session OrangeHRM: name `orangehrm` (https) / `_orangehrm` (http),
//    atribut Symfony di $_SESSION['_sf2_attributes'].
// ------------------------------------------------------------
function mpOwnerCurrentUser(): array
{
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $sessionName = $isHttps ? 'orangehrm' : '_orangehrm';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name($sessionName);
        @session_start();
    }
    $attrs = $_SESSION['_sf2_attributes'] ?? [];
    $authenticated = (bool) ($attrs['user.is_authenticated'] ?? false);
    $empNumber = (int) ($attrs['user.user_employee_number'] ?? 0);
    $roleName = (string) ($attrs['user.user_role_name'] ?? '');
    // Lepas lock file session secepat mungkin; proses selanjutnya bisa lama.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return [
        'authenticated' => $authenticated && $empNumber > 0,
        'emp_number' => $empNumber,
        'role' => $roleName,
        'session_name' => $sessionName,
    ];
}

// ------------------------------------------------------------
// 3. Normalisasi nama supaya "JKT - DANY NUGRAHA" == "Dany Nugraha"
// ------------------------------------------------------------
function mpOwnerNormalizeName(string $name): string
{
    $name = trim($name);
    // buang suffix status Acumatica
    $name = preg_replace('/\s+inactive\s*$/i', '', $name);
    // buang prefix branch: "JKT - ", "PLG - "
    $name = preg_replace('/^[A-Za-z]{2,4}\s*-\s*/', '', $name);
    // buang koma sisa format "Nama, " dan tanda baca
    $name = str_replace(',', ' ', $name);
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return mb_strtolower(trim($name), 'UTF-8');
}

// ------------------------------------------------------------
// 4. Sesi Acumatica: pakai cookie jar milik admin sync kalau masih hidup
// ------------------------------------------------------------
function mpOwnerGetCookie(string $entity, string $jarFile): array
{
    $cookie = acumaticaCookieFromJar($jarFile);
    if ($cookie !== '') {
        $probe = acumaticaCurlJson($entity . '/Case?$select=CaseID&$top=1', 'GET', null, $cookie);
        if ((int) $probe['http'] >= 200 && (int) $probe['http'] < 300) {
            return ['ok' => true, 'cookie' => $cookie, 'source' => 'jar'];
        }
        @unlink($jarFile);
    }

    $shared = acumaticaActivitySession(rtrim(ACUMATICA_BASE_URL, '/'));
    if (!empty($shared['ok'])) {
        return ['ok' => true, 'cookie' => (string) $shared['cookie'], 'source' => $shared['reused'] ? 'shared-cache' : 'shared-login'];
    }

    return ['ok' => false, 'message' => (string) ($shared['message'] ?? 'Gagal login Acumatica')];
}

// ------------------------------------------------------------
// 5. Resolusi Owner ID user OrangeHRM
// ------------------------------------------------------------
function mpOwnerResolveFromAcumatica(string $entity, string $cookie, string $fullName): array
{
    $target = mpOwnerNormalizeName($fullName);
    if ($target === '') {
        return ['ok' => false, 'message' => 'Nama karyawan kosong.'];
    }

    // Cari case yang OwnerEmployeeName-nya memuat nama ini, lalu verifikasi
    // kesamaan nama SETELAH normalisasi (substringof bisa ikut menangkap nama
    // yang mirip, mis. "Ahmad" vs "Ahmad Irpan").
    // Catatan: scan "$top=1000 tanpa filter" TIDAK bisa diandalkan — case yang
    // dimiliki user bisa berada di luar 1000 baris pertama.
    $quoted = str_replace("'", "''", $fullName);
    $filters = [
        "substringof('$quoted',OwnerEmployeeName)",
        "OwnerEmployeeName eq '$quoted'",
    ];

    $matches = [];
    foreach ($filters as $filter) {
        $res = acumaticaCurlJson(
            $entity . '/Case?' . ('$filter=' . rawurlencode($filter)) . '&$top=50',
            'GET',
            null,
            $cookie
        );
        if ((int) $res['http'] < 200 || (int) $res['http'] >= 300) {
            continue;
        }
        $json = json_decode($res['body'], true);
        $rows = is_array($json) ? ($json['value'] ?? (array_is_list($json) ? $json : [])) : [];
        foreach ($rows as $row) {
            $ownerId = $row['Owner']['value'] ?? null;
            $ownerName = $row['OwnerEmployeeName']['value'] ?? null;
            if (!$ownerId || !$ownerName) {
                continue;
            }
            if (mpOwnerNormalizeName((string) $ownerName) === $target) {
                $matches[(string) $ownerId] = (string) $ownerName;
            }
        }
        if (count($matches) > 0) {
            break;
        }
    }

    if (count($matches) === 1) {
        $ownerId = array_key_first($matches);
        return ['ok' => true, 'owner_id' => $ownerId, 'owner_name' => $matches[$ownerId]];
    }
    if (count($matches) > 1) {
        return ['ok' => false, 'message' => 'Nama cocok dengan lebih dari satu owner Acumatica: ' . implode(', ', array_keys($matches))];
    }
    return ['ok' => false, 'message' => 'Nama tidak ada di daftar owner Case Acumatica.'];
}

// ------------------------------------------------------------
// MULAI
// ------------------------------------------------------------
try {
    $user = mpOwnerCurrentUser();
    if (!$user['authenticated']) {
        mpOwnerRespond([
            'status' => 'not_authenticated',
            'message' => 'Sesi OrangeHRM tidak terbaca. Login ulang lalu klik Sync lagi.',
        ]);
    }

    $pdo = mpOwnerPdo();

    $stmt = $pdo->prepare(
        'SELECT emp_number, TRIM(CONCAT_WS(" ", NULLIF(TRIM(emp_firstname), ""), NULLIF(TRIM(emp_middle_name), ""), NULLIF(TRIM(emp_lastname), ""))) AS full_name
           FROM hs_hr_employee
          WHERE emp_number = ?
          LIMIT 1'
    );
    $stmt->execute([$user['emp_number']]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        mpOwnerRespond([
            'status' => 'error',
            'message' => 'Karyawan untuk user login (emp_number ' . $user['emp_number'] . ') tidak ditemukan.',
        ]);
    }
    $fullName = trim((string) $emp['full_name']);
    if ($fullName === '') {
        $stmt = $pdo->prepare(
            'SELECT TRIM(CONCAT_WS(" ", NULLIF(TRIM(emp_firstname), ""), NULLIF(TRIM(emp_lastname), ""))) AS full_name
               FROM hs_hr_employee WHERE emp_number = ? LIMIT 1'
        );
        $stmt->execute([$user['emp_number']]);
        $fullName = trim((string) $stmt->fetchColumn());
    }

    // mapping cache
    $ownerId = null;
    $ownerName = null;
    $mappingSource = 'auto';
    try {
        $stmt = $pdo->prepare('SELECT owner_id, owner_name FROM ohrm_employee_acumatica_owner WHERE emp_number = ? LIMIT 1');
        $stmt->execute([$user['emp_number']]);
        $mapped = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mapped && trim((string) $mapped['owner_id']) !== '') {
            $ownerId = (string) $mapped['owner_id'];
            $ownerName = (string) ($mapped['owner_name'] ?? '');
        }
    } catch (Throwable $e) {
        // tabel belum dibuat -> lanjut tanpa cache (fitur tetap jalan, lambat)
        mpOwnerLog($logFile, ['event' => 'mapping_table_missing', 'message' => $e->getMessage()]);
    }

    $cookieInfo = mpOwnerGetCookie($entity, $jarFile);
    if (empty($cookieInfo['ok'])) {
        mpOwnerRespond(['status' => 'error', 'message' => (string) $cookieInfo['message']]);
    }
    $cookie = (string) $cookieInfo['cookie'];

    if ($ownerId === null) {
        $resolved = mpOwnerResolveFromAcumatica($entity, $cookie, $fullName);
        if (empty($resolved['ok'])) {
            mpOwnerLog($logFile, [
                'event' => 'owner_not_found',
                'emp_number' => $user['emp_number'],
                'name' => $fullName,
                'message' => $resolved['message'] ?? '',
            ]);
            mpOwnerRespond([
                'status' => 'owner_not_found',
                'employee_name' => $fullName,
                'message' => (string) ($resolved['message'] ?? 'Owner tidak ditemukan'),
            ]);
        }
        $ownerId = (string) $resolved['owner_id'];
        $ownerName = (string) $resolved['owner_name'];
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ohrm_employee_acumatica_owner (emp_number, owner_id, owner_name, source)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id), owner_name = VALUES(owner_name)'
            );
            $stmt->execute([$user['emp_number'], $ownerId, $ownerName, $mappingSource]);
        } catch (Throwable $e) {
            mpOwnerLog($logFile, ['event' => 'mapping_save_failed', 'message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------
    // 6. Ambil Case New/Open + Owner sekarang (1 request)
    // ------------------------------------------------------------
    $filter = "(Status eq 'New' or Status eq 'Open') and ClassID eq 'REQFPTK'";
    $res = acumaticaCurlJson($entity . '/Case?' . ('$filter=' . rawurlencode($filter)) . '&$top=1000', 'GET', null, $cookie);
    if ((int) $res['http'] < 200 || (int) $res['http'] >= 300) {
        mpOwnerRespond([
            'status' => 'error',
            'message' => 'Gagal membaca Case New/Open dari Acumatica (HTTP ' . $res['http'] . ').',
        ]);
    }
    $json = json_decode($res['body'], true);
    $caseRows = is_array($json) ? ($json['value'] ?? (array_is_list($json) ? $json : [])) : [];

    // Batasi ke case yang memang ada di DB lokal (hasil sync yang baru masuk)
    $localCases = [];
    try {
        foreach ($pdo->query("SELECT case_id FROM ohrm_manpower_request WHERE UPPER(TRIM(status)) IN ('NEW','OPEN')") as $row) {
            $localCases[(string) $row['case_id']] = true;
        }
    } catch (Throwable $e) {
        mpOwnerLog($logFile, ['event' => 'local_cases_read_failed', 'message' => $e->getMessage()]);
    }

    $updated = 0;          // case yang Owner-nya BARU diisi (sebelumnya kosong)
    $skipped = 0;          // case yang dilewati karena Owner-nya sudah terisi
    $skippedSame = 0;      // subset: sudah terisi dengan user yang sama
    $failed = [];
    $verified = [];
    $filledCases = [];

    foreach ($caseRows as $row) {
        $caseId = (string) ($row['CaseID']['value'] ?? '');
        if ($caseId === '') {
            continue;
        }
        if (!empty($localCases) && !isset($localCases[$caseId])) {
            continue;
        }

        // ATURAN (permintaan Pak): Owner HANYA diisi kalau masih kosong.
        // Owner yang sudah terisi — siapa pun orangnya — TIDAK ditimpa.
        $currentOwner = $row['Owner']['value'] ?? null;
        $hasOwner = $currentOwner !== null && trim((string) $currentOwner) !== '' && (string) $currentOwner !== '0';
        if ($hasOwner) {
            $skipped++;
            if ((string) $currentOwner === $ownerId) {
                $skippedSame++;
            }
            continue;
        }

        $put = acumaticaCurlJson(
            $entity . '/Case?' . ('$filter=' . rawurlencode("CaseID eq '$caseId'")),
            'PUT',
            ['Owner' => ['value' => $ownerId]],
            $cookie
        );
        if ((int) $put['http'] >= 200 && (int) $put['http'] < 300) {
            $updated++;
            $filledCases[] = $caseId;
        } else {
            $failed[] = ['case_id' => $caseId, 'http' => (int) $put['http']];
        }
    }

    // ------------------------------------------------------------
    // 7. Verifikasi: baca ulang Owner case yang baru diubah
    // ------------------------------------------------------------
    if ($updated > 0) {
        $chk = acumaticaCurlJson($entity . '/Case?' . ('$filter=' . rawurlencode($filter)) . '&$top=1000', 'GET', null, $cookie);
        $cjson = json_decode($chk['body'], true);
        $crows = is_array($cjson) ? ($cjson['value'] ?? (array_is_list($cjson) ? $cjson : [])) : [];
        $stillFilled = 0;
        foreach ($crows as $row) {
            $cid = (string) ($row['CaseID']['value'] ?? '');
            if ($cid === '' || !in_array($cid, $filledCases, true)) {
                continue;
            }
            if ((string) ($row['Owner']['value'] ?? '') === $ownerId) {
                $stillFilled++;
            }
        }
        $verified['filled_cases_verified'] = $stillFilled;
        $verified['filled_cases_requested'] = count($filledCases);
    }

    mpOwnerLog($logFile, [
        'event' => 'done',
        'emp_number' => $user['emp_number'],
        'name' => $fullName,
        'owner_id' => $ownerId,
        'owner_name' => $ownerName,
        'session' => $cookieInfo['source'] ?? '',
        'filled' => $updated,
        'skipped_existing_owner' => $skipped,
        'failed' => count($failed),
    ]);

    mpOwnerRespond([
        'status' => 'ok',
        'employee_name' => $fullName,
        'owner_id' => $ownerId,
        'owner_name' => $ownerName,
        'session_source' => $cookieInfo['source'] ?? '',
        'total_new_open' => count($caseRows),
        'rule' => 'owner_hanya_diisi_jika_kosong',
        'filled' => $updated,
        'skipped_existing_owner' => $skipped,
        'skipped_same_owner' => $skippedSame,
        'failed' => $failed,
        'verified' => $verified,
    ]);
} catch (Throwable $e) {
    mpOwnerLog($logFile, ['event' => 'exception', 'message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
