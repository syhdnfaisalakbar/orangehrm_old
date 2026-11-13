<?php
/**
 * _application_push.php — push aplikasi kandidat ke Acumatica dari sisi server
 * OrangeHRM (bukan dari browser), dipakai saat aksi status kandidat disimpan
 * (Shortlist, Interview Scheduled, Interview Passed/Failed, Job Offered,
 * Offer Declined).
 *
 * Alurnya SAMA dengan tombol "Sync Application to Acumatica": memanggil
 * web/proxy/sync_application_to_acumatica.php (tulis ulang Description Case,
 * buat/cari Task kandidat + CV, kirim Note status).
 *
 * Endpoint itu tidak memakai session PHP, jadi aman dipanggil dari dalam
 * request API OrangeHRM (tidak menahan session lock).
 */

/** URL endpoint sync_application_to_acumatica.php di server yang sama. */
function acumaticaPushApplicationUrl(): string
{
    $override = getenv('ACUMATICA_PUSH_APPLICATION_URL');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    // __DIR__ = <project>/web/proxy
    $projectRoot = str_replace('\\', '/', dirname(__DIR__, 2));
    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');

    $basePath = '';

    // 1) Paling andal: ambil dari SCRIPT_NAME ("/orangehrm/web/index.php" -> "/orangehrm").
    //    Ini juga benar saat project diakses lewat junction/symlink, di mana
    //    DOCUMENT_ROOT tidak sama dengan folder project sebenarnya.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = stripos($scriptName, '/web/');
    if ($pos !== false) {
        $basePath = substr($scriptName, 0, $pos);
    } elseif ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0) {
        // 2) Fallback: project berada di bawah DOCUMENT_ROOT.
        $basePath = substr($projectRoot, strlen($docRoot));
    }

    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $scheme = 'https';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . $basePath . '/web/proxy/sync_application_to_acumatica.php';
}

/**
 * Jalankan push aplikasi kandidat ke Acumatica.
 *
 * @return array{status:string,message:string,http:int,raw:?array}
 */
/**
 * Versi "best-effort" untuk hook di dalam request API OrangeHRM: tidak pernah
 * melempar exception dan mencatat hasilnya ke web/proxy/_cache/acumatica_action_sync.log.
 */
function acumaticaPushApplicationLogged(int $candidateId, int $vacancyId, string $tag = ''): ?array
{
    try {
        $result = acumaticaPushApplication($candidateId, $vacancyId);

        $logDir = __DIR__ . '/_cache';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents(
            $logDir . '/acumatica_action_sync.log',
            date('c') . ' candidate=' . $candidateId . ' vacancy=' . $vacancyId
            . ($tag !== '' ? ' ' . $tag : '') . ' ' . json_encode($result) . PHP_EOL,
            FILE_APPEND
        );

        return $result;
    } catch (\Throwable $e) {
        return null;
    }
}

function acumaticaPushApplication(int $candidateId, int $vacancyId, int $timeout = 120): array
{
    $url = acumaticaPushApplicationUrl();

    $payload = ['candidate_id' => $candidateId];
    if ($vacancyId > 0) {
        $payload['vacancy_id'] = $vacancyId;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['status' => 'error', 'message' => 'Gagal memanggil endpoint: ' . $err, 'http' => 0, 'raw' => null];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return [
            'status' => 'error',
            'message' => 'Respons tidak valid (HTTP ' . $http . '): ' . substr(strip_tags((string) $body), 0, 200),
            'http' => $http,
            'raw' => null,
        ];
    }

    $status = (string) ($json['status'] ?? '');
    if ($status === 'error' || $http < 200 || $http >= 300) {
        return [
            'status' => 'error',
            'message' => (string) ($json['message'] ?? ('HTTP ' . $http)),
            'http' => $http,
            'raw' => $json,
        ];
    }

    return [
        'status' => 'ok',
        'message' => (string) ($json['message'] ?? 'Aplikasi tersinkron ke Acumatica'),
        'http' => $http,
        'raw' => $json,
    ];
}
