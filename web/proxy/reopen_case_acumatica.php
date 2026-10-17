<?php
/**
 * Reopen Case di Acumatica (Class REQFPTK) via API — Status -> Open.
 *
 * Kebalikan dari close_case_acumatica.php. Dipanggil ketika sebuah kandidat
 * yang sudah HIRED ternyata batal masuk (Cancel Hire): vacancy + MPR dibuka
 * kembali di lokal, dan Case di Acumatica ikut di-Open supaya sinkron saat
 * sync berikutnya (kalau di sini tidak di-open, case di Acumatica tetap Closed
 * padahal MPR/vacancy-nya sudah aktif lagi).
 *
 * Reuse helper auth dari close_case_acumatica.php (login, request, logout, log)
 * — pakai require_once supaya fungsi/konstanta dipakai ulang, tidak duplikasi.
 *
 * Panggil langsung (test/manual):
 *   POST /proxy/reopen_case_acumatica.php   Body: { "case_id": "0000003663" }
 *   GET  /proxy/reopen_case_acumatica.php?case_id=0000003663
 *   POST dengan description juga didukung: { "case_id": "...", "description": "..." }
 *
 * Atau dipanggil dari kode lain (AbstractCandidateActionAPI):
 *   require_once __DIR__ . '/reopen_case_acumatica.php';
 *   $result = reopenCaseInAcumatica('0000003663');
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Reuse helper dari close_case_acumatica.php (login/request/logout/log + konstanta).
if (file_exists(__DIR__ . '/close_case_acumatica.php')) {
    require_once __DIR__ . '/close_case_acumatica.php';
}

/**
 * Buka Case di Acumatica (Status -> Open) + optional update Description.
 * Best-effort: tidak melempar exception — hasil dikembalikan sebagai array.
 *
 * URUTAN disamakan dengan closeCaseInAcumatica: login -> GET status ->
 * (opsional) PUT Description -> PUT Status -> Open -> logout.
 * Idempotent: case yang sudah Open -> PUT Open kembali -> 200, aman.
 *
 * @param string $caseId
 * @param string|null $description Opsional: Description case di-PUT.
 * @return array{status:string, case_id:string, http_code?:int, message?:string, body?:string}
 */
function reopenCaseInAcumatica(string $caseId, ?string $description = null): array
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
        logAcumaticaPush($caseId, 'reopen-login-ok', 'Login sukses, cookie len=' . strlen($cookie));

        $filter = urlencode("CaseID eq '$caseId'");
        $url = ACUMATICA_BASE_URL . "/entity/Default/25.200.001/Case?\$filter=$filter";

        // ---- 2. Cek status case sekarang (GET) ----
        // Penting: case yang Closed punya ClosingDate. Untuk reopen, ClosingDate
        // HARUS dikosongkan sekalian — kalau cuma set Status=Open, Acumatica
        // menolak 422 ("Inserting 'Case' record raised at least one error").
        $currentStatus = null;
        $get = acumaticaCaseRequest($url, 'GET', $cookie);
        if ($get['http'] >= 200 && $get['http'] < 300) {
            $getData = json_decode($get['body'], true);
            if (is_array($getData) && isset($getData[0]['Status']['value'])) {
                $currentStatus = strtolower((string)$getData[0]['Status']['value']);
            }
            logAcumaticaPush($caseId, 'reopen-get-ok', 'GET status HTTP ' . $get['http'] . ' status=' . ($currentStatus ?? '?'));
        } else {
            logAcumaticaPush($caseId, 'reopen-get-error', 'GET status HTTP ' . $get['http'] . ' body=' . substr($get['body'], 0, 200));
        }

        // ---- 3. PUT Description (opsional) ----
        if ($description !== null && $description !== '') {
            $desc = acumaticaCaseRequest($url, 'PUT', $cookie, ['Description' => ['value' => $description]]);
            logAcumaticaPush($caseId, $desc['http'] >= 200 && $desc['http'] < 300 ? 'reopen-desc-ok' : 'reopen-desc-error', 'Description HTTP ' . $desc['http'] . ' body=' . substr($desc['body'], 0, 300));
        }

        // ---- 4. PUT Status -> Open (+ kosongkan ClosingDate kalau case Closed) ----
        $payload = ['Status' => ['value' => 'Open']];
        if ($currentStatus === 'closed') {
            $payload['ClosingDate'] = ['value' => '']; // wajib dibersihkan agar case bisa re-open
        }
        $reopen = acumaticaCaseRequest($url, 'PUT', $cookie, $payload);
        $httpCode = $reopen['http'];

        if ($reopen['error']) {
            $r = ['status' => 'error', 'case_id' => $caseId, 'message' => 'cURL error: ' . $reopen['error']];
            logAcumaticaPush($caseId, $r['status'], $r['message']);
            return $r;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $r = ['status' => 'ok', 'case_id' => $caseId, 'http_code' => $httpCode];
            logAcumaticaPush($caseId, $r['status'], 'Reopen HTTP ' . $httpCode);
            return $r;
        }

        $r = [
            'status' => 'error',
            'case_id' => $caseId,
            'http_code' => $httpCode,
            'message' => 'Gagal reopen case ' . $caseId,
            'body' => substr($reopen['body'], 0, 300),
        ];
        logAcumaticaPush($caseId, $r['status'], $r['message'] . ' http=' . $httpCode . ' body=' . ($r['body'] ?? ''));
        return $r;
    } catch (\Throwable $e) {
        $r = ['status' => 'error', 'case_id' => $caseId, 'message' => $e->getMessage()];
        logAcumaticaPush($caseId, $r['status'], $r['message']);
        return $r;
    } finally {
        // ---- 4. Logout (selalu) ----
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
    echo json_encode(reopenCaseInAcumatica($caseId, $description));
}