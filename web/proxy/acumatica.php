<?php
require_once __DIR__ . '/_config.php';

/**
 * Acumatica API Proxy
 * 
 * Proxy untuk menghindari CORS. Panggil dari frontend via:
 *   /web/proxy/acumatica.php?action=login
 *   /web/proxy/acumatica.php?action=query&endpoint=Case/...
 *   /web/proxy/acumatica.php?action=logout
 */

// --- Konfigurasi ---
$BASE = ACUMATICA_BASE_URL;
$COOKIE_FILE = __DIR__ . '/acumatica_cookies.txt';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'login':
            $payload = json_decode(file_get_contents('php://input'), true) ?: [
                'name' => ACUMATICA_LOGIN_USER,
                'password' => ACUMATICA_LOGIN_PASS,
                'company' => ACUMATICA_COMPANY,
            ];
            $resp = acumaticaRequest('/entity/auth/login/', 'POST', $payload);
            echo json_encode(['status' => 'ok', 'http_code' => $resp['http_code']]);
            break;

        case 'query':
            $endpoint = $_GET['endpoint'] ?? '';

            // --- PERBAIKAN DI SINI ---
            $filter = $_GET['$filter'] ?? '';
            $expand = $_GET['$expand'] ?? ''; // Tangkap parameter expand

            $url = '/entity/Default/25.200.001/' . ltrim($endpoint, '/');

            $queryParams = [];
            if ($filter) {
                $queryParams[] = '$filter=' . urlencode($filter);
            }
            if ($expand) {
                $queryParams[] = '$expand=' . urlencode($expand);
            }

            // Gabungkan parameter menggunakan & jika ada
            if (!empty($queryParams)) {
                $url .= '?' . implode('&', $queryParams);
            }
            // ------------------------

            $resp = acumaticaRequest($url, 'GET');
            echo $resp['body'];
            break;

        case 'logout':
            $resp = acumaticaRequest('/entity/auth/logout/', 'POST');
            // Hapus cookie file
            if (file_exists($COOKIE_FILE)) {
                unlink($COOKIE_FILE);
            }
            echo json_encode(['status' => 'ok']);
            break;

        case 'update':
            $endpoint = $_GET['endpoint'] ?? '';
            $payload = json_decode(file_get_contents('php://input'), true);
            if (!$payload) {
                throw new Exception('Request body required for update');
            }
            $url = '/entity/Default/25.200.001/' . ltrim($endpoint, '/');
            $resp = acumaticaRequest($url, 'PUT', $payload);
            echo $resp['body'];
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action. Use: login, query, logout']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Kirim request ke Acumatica dengan cookie persistence
 */
function acumaticaRequest(string $path, string $method = 'GET', ?array $body = null): array
{
    global $BASE, $COOKIE_FILE;

    $url = $BASE . $path;
    $ch = curl_init($url);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $COOKIE_FILE,
        CURLOPT_COOKIEJAR => $COOKIE_FILE,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($body) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    } elseif ($method === 'PUT') {
        $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if ($body) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL error: $error");
    }

    return ['http_code' => $httpCode, 'body' => $response];
}
