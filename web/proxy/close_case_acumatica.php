<?php
/**
 * Close Case di Acumatica (Class REQFPTK) via API.
 *
 * Dipakai ketika SEMUA vacancy yang ter-link ke sebuah Manpower Request sudah
 * closed — selain update status lokal di ohrm_manpower_request, status Case
 * di Acumatica ikut di-close supaya sinkron saat sync berikutnya.
 *
 * PENTING: pakai define() (constant) bukan variable global, karena file ini
 * bisa di-include dari dalam method (VacancyAPI) — kalau pakai variable,
 * scope-nya jadi local ke method itu dan global di fungsi tidak terlihat.
 *
 * 2026-08-11 — PENULISAN ULANG AUTH:
 * Sebelumnya pakai COOKIEJAR/COOKIEFILE (file cookie). Ternyata di cURL
 * PHP/Windows 8.4.0 file cookie jar DITULIS TERTUNDA (saat script exit /
 * garbage collection), BUKAN saat curl_close() — jadi request berikutnya
 * dalam script yang SAMA tidak pernah membawa cookie → semua PUT/GET 401.
 * Solusi: login → tangkap header Set-Cookie (CURLOPT_HEADER) → parse →
 * kirim header `Cookie: ...` langsung di setiap request. TANPA file cookie.
 * (Diverifikasi: GET status 200 dengan metode ini.)
 *
 * Panggil langsung (test/manual):
 *   POST /proxy/close_case_acumatica.php   Body: { "case_id": "0000003663" }
 *   GET  /proxy/close_case_acumatica.php?case_id=0000003663
 *   POST dengan description juga didukung: { "case_id": "...", "description": "..." }
 *
 * Atau dipanggil dari kode lain (link_manpower_vacancies.php, VacancyAPI.php,
 * reject_vacancy.php):
 *   require_once __DIR__ . '/close_case_acumatica.php';
 *   $result = closeCaseInAcumatica('0000003663');
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Konfigurasi — constant supaya global walau di-include dari dalam method.
// Kredensial (ACUMATICA_BASE_URL, ACUMATICA_LOGIN_USER, ACUMATICA_LOGIN_PASS)
// sudah didefinisikan oleh _config.php dari web/proxy/config.php.
if (!defined('ACUMATICA_LOGIN_COMPANY')) {
    // alias: _config.php memakai nama ACUMATICA_COMPANY
    define('ACUMATICA_LOGIN_COMPANY', ACUMATICA_COMPANY);
}
if (!defined('ACUMATICA_PUSH_LOG_FILE')) {
    define('ACUMATICA_PUSH_LOG_FILE', __DIR__ . '/acumatica_push_log.txt');
}

/**
 * Catat hasil push ke file log (diagnostik kalau push gagal diam-diam).
 */
function logAcumaticaPush(string $caseId, string $status, string $detail): void
{
    try {
        $line = sprintf(
            "[%s] case=%s status=%s detail=%s\n",
            date('Y-m-d H:i:s'),
            $caseId,
            $status,
            str_replace("\n", ' ', $detail)
        );
        file_put_contents(ACUMATICA_PUSH_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // abaikan — log tidak boleh mengganggu proses utama
    }
}

/**
 * Login ke Acumatica dan kembalikan header cookie (string) untuk dipakai
 * di request berikutnya. TANPA file cookie (lihat catatan di atas).
 *
 * @return array{ok:bool, cookie?:string, message?:string}
 */
function acumaticaLogin(): array
{
    $ch = curl_init(ACUMATICA_BASE_URL . '/entity/auth/login/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode([
            'name' => ACUMATICA_LOGIN_USER,
            'password' => ACUMATICA_LOGIN_PASS,
            'company' => ACUMATICA_LOGIN_COMPANY,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_HEADER => true, // tangkap header Set-Cookie
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $loginHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($loginHttp < 200 || $loginHttp >= 300) {
        return ['ok' => false, 'message' => 'Gagal login Acumatica (HTTP ' . $loginHttp . ')'];
    }

    // Parse Set-Cookie → string "k=v; k2=v2"
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', (string)$resp, $matches);
    $pairs = [];
    foreach ($matches[1] as $c) {
        $parts = explode('=', $c, 2);
        if (count($parts) === 2) {
            $pairs[] = trim($parts[0]) . '=' . trim($parts[1]);
        }
    }
    if (empty($pairs)) {
        return ['ok' => false, 'message' => 'Login sukses tapi tidak ada Set-Cookie'];
    }
    return ['ok' => true, 'cookie' => implode('; ', $pairs)];
}

/**
 * Request HTTP ke endpoint Case Acumatica dengan header Cookie.
 *
 * @return array{http:int, body:string, error:string}
 */
function acumaticaCaseRequest(string $url, string $method, string $cookie, ?array $payload = null): array
{
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Cookie: ' . $cookie],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    if ($method === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
        unset($opts[CURLOPT_CUSTOMREQUEST]);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => (int)$http, 'body' => (string)$body, 'error' => (string)$err];
}

/**
 * Logout dari Acumatica (best-effort).
 */
function acumaticaLogout(string $cookie): void
{
    try {
        $ch = curl_init(ACUMATICA_BASE_URL . '/entity/auth/logout/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $cookie],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // abaikan
    }
}

/**
 * Tutup Case di Acumatica (Status -> Closed).
 * Best-effort: tidak melempar exception — hasil dikembalikan sebagai array.
 *
 * URUTAN (2026-08-11): kalau ada description dan case masih Closed, BUKA
 * dulu (Status -> Open), PUT Description, baru Status -> Closed. Case Closed
 * tidak bisa di-PUT Description (401) dan 401 pertama meng-invalidate session.
 *
 * @param string $caseId
 * @param string|null $description Opsional: kalau diisi, Description case
 *        di-PUT dulu (buka case dulu kalau Closed), baru Status -> Closed.
 * @return array{status:string, case_id:string, http_code?:int, message?:string, body?:string}
 */
function closeCaseInAcumatica(string $caseId, ?string $description = null): array
{
    $caseId = trim($caseId);
    if ($caseId === '') {
        $r = ['status' => 'error', 'case_id' => $caseId, 'message' => 'case_id kosong'];
        logAcumaticaPush($caseId, $r['status'], $r['message']);
        return $r;
    }

    $cookie = null;
    try {
        // ---- 1. Login ----
        $login = acumaticaLogin();
        if (!$login['ok']) {
            $r = ['status' => 'error', 'case_id' => $caseId, 'message' => $login['message'] ?? 'Login gagal'];
            logAcumaticaPush($caseId, $r['status'], $r['message']);
            return $r;
        }
        $cookie = $login['cookie'];
        logAcumaticaPush($caseId, 'login-ok', 'Login sukses, cookie len=' . strlen($cookie));

        $filter = urlencode("CaseID eq '$caseId'");
        $url = ACUMATICA_BASE_URL . "/entity/Default/25.200.001/Case?\$filter=$filter";

        // ---- 2. Cek status case sekarang (GET) ----
        $currentStatus = null;
        $get = acumaticaCaseRequest($url, 'GET', $cookie);
        if ($get['http'] >= 200 && $get['http'] < 300) {
            $getData = json_decode($get['body'], true);
            if (is_array($getData) && isset($getData[0]['Status']['value'])) {
                $currentStatus = strtolower((string)$getData[0]['Status']['value']);
            }
            logAcumaticaPush($caseId, 'get-ok', 'GET status HTTP ' . $get['http'] . ' status=' . ($currentStatus ?? '?'));
        } else {
            logAcumaticaPush($caseId, 'get-error', 'GET status HTTP ' . $get['http'] . ' body=' . substr($get['body'], 0, 200));
        }

        // ---- 3. Kalau ada description dan case Closed → buka dulu ----
        if ($description !== null && $description !== '' && $currentStatus === 'closed') {
            $reopen = acumaticaCaseRequest($url, 'PUT', $cookie, ['Status' => ['value' => 'Open']]);
            logAcumaticaPush($caseId, $reopen['http'] >= 200 && $reopen['http'] < 300 ? 'reopen-ok' : 'reopen-error', 'Reopen HTTP ' . $reopen['http'] . ' body=' . substr($reopen['body'], 0, 300));
        }

        // ---- 4. PUT Description (kalau ada) ----
        if ($description !== null && $description !== '') {
            $desc = acumaticaCaseRequest($url, 'PUT', $cookie, ['Description' => ['value' => $description]]);
            logAcumaticaPush($caseId, $desc['http'] >= 200 && $desc['http'] < 300 ? 'desc-ok' : 'desc-error', 'Description HTTP ' . $desc['http'] . ' body=' . substr($desc['body'], 0, 300));
        }

        // ---- 5. PUT Status -> Closed ----
        $close = acumaticaCaseRequest($url, 'PUT', $cookie, ['Status' => ['value' => 'Closed']]);
        $httpCode = $close['http'];

        if ($close['error']) {
            $r = ['status' => 'error', 'case_id' => $caseId, 'message' => 'cURL error: ' . $close['error']];
            logAcumaticaPush($caseId, $r['status'], $r['message']);
            return $r;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $r = ['status' => 'ok', 'case_id' => $caseId, 'http_code' => $httpCode];
            logAcumaticaPush($caseId, $r['status'], 'HTTP ' . $httpCode);
            return $r;
        }

        $r = [
            'status' => 'error',
            'case_id' => $caseId,
            'http_code' => $httpCode,
            'message' => 'Gagal close case ' . $caseId,
            'body' => substr($close['body'], 0, 300),
        ];
        logAcumaticaPush($caseId, $r['status'], $r['message'] . ' http=' . $httpCode . ' body=' . ($r['body'] ?? ''));
        return $r;
    } catch (\Throwable $e) {
        $r = ['status' => 'error', 'case_id' => $caseId, 'message' => $e->getMessage()];
        logAcumaticaPush($caseId, $r['status'], $r['message']);
        return $r;
    } finally {
        // ---- 6. Logout (selalu) ----
        if ($cookie !== null) {
            acumaticaLogout($cookie);
        }
    }
}

// Jalankan langsung jika diakses sebagai script mandiri (test/manual)
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_GET;
    $caseId = $input['case_id'] ?? '';
    $description = $input['description'] ?? null;
    echo json_encode(closeCaseInAcumatica($caseId, $description));
}
