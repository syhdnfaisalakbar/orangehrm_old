<?php
/**
 * _config.php — pemuat konfigurasi terpusat untuk semua endpoint web/proxy.
 *
 * Semua kredensial (DB, Acumatica, Google SSO) diambil dari config.php
 * (tidak di-commit). File ini hanya mengeksposnya sebagai konstanta:
 *
 *   PROXY_DB_HOST / PROXY_DB_PORT / PROXY_DB_NAME / PROXY_DB_USER / PROXY_DB_PASS
 *   ACUMATICA_BASE_URL / ACUMATICA_LOGIN_USER / ACUMATICA_LOGIN_PASS / ACUMATICA_COMPANY
 *   GOOGLE_SSO_CLIENT_ID / GOOGLE_SSO_CLIENT_SECRET
 *   GOOGLE_SSO_REDIRECT_URI / GOOGLE_SSO_ALLOWED_DOMAIN
 *
 * Kalau config.php belum ada, dipakai config.example.php (placeholder) dan
 * peringatan ditulis ke error_log — supaya penyebabnya kelihatan, bukan
 * sekadar "Access denied for user ''@'localhost'".
 *
 * Dibungkus closure agar tidak ada variabel yang bocor ke file pemanggil.
 */

(static function (): void {
    $envFile     = __DIR__ . '/config.php';
    $exampleFile = __DIR__ . '/config.example.php';

    if (is_file($envFile)) {
        $cfg = require $envFile;
    } elseif (is_file($exampleFile)) {
        $cfg = require $exampleFile;
        error_log('[orangehrm] web/proxy/config.php BELUM ADA. Memakai config.example.php '
            . '(nilai placeholder). Salin config.example.php menjadi config.php lalu isi kredensial.');
    } else {
        $cfg = [];
    }
    if (!is_array($cfg)) {
        $cfg = [];
    }

    $define = static function (string $name, $value): void {
        if (!defined($name)) {
            define($name, $value);
        }
    };

    $db = (isset($cfg['db']) && is_array($cfg['db'])) ? $cfg['db'] : [];
    $define('PROXY_DB_HOST', (string)($db['host'] ?? '127.0.0.1'));
    $define('PROXY_DB_PORT', (string)($db['port'] ?? '3306'));
    $define('PROXY_DB_NAME', (string)($db['name'] ?? 'orangehrm'));
    $define('PROXY_DB_USER', (string)($db['user'] ?? ''));
    $define('PROXY_DB_PASS', (string)($db['pass'] ?? ''));

    $ac = (isset($cfg['acumatica']) && is_array($cfg['acumatica'])) ? $cfg['acumatica'] : [];
    $define('ACUMATICA_BASE_URL', rtrim((string)($ac['base_url'] ?? ''), '/'));
    $define('ACUMATICA_LOGIN_USER', (string)($ac['login_user'] ?? ''));
    $define('ACUMATICA_LOGIN_PASS', (string)($ac['login_pass'] ?? ''));
    $define('ACUMATICA_COMPANY', (string)($ac['company'] ?? ''));

    $gs = (isset($cfg['google_sso']) && is_array($cfg['google_sso'])) ? $cfg['google_sso'] : [];
    $define('GOOGLE_SSO_CLIENT_ID', (string)($gs['client_id'] ?? ''));
    $define('GOOGLE_SSO_CLIENT_SECRET', (string)($gs['client_secret'] ?? ''));
    $define('GOOGLE_SSO_REDIRECT_URI', (string)($gs['redirect_uri'] ?? ''));
    $define('GOOGLE_SSO_ALLOWED_DOMAIN', (string)($gs['allowed_email_domain'] ?? ''));
})();
