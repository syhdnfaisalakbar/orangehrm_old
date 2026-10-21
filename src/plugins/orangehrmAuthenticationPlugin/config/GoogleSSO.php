<?php
/**
 * Konfigurasi SSO Login Google untuk OrangeHRM.
 *
 * CARA MENGISI:
 * 1. Buka Google Cloud Console → *APIs & Services → Credentials* → *Create Credentials
 *    → OAuth client ID* → pilih jenis *Web application*.
 * 2. Tambahkan redirect URI persis seperti di bawah (default local):
 *      http://localhost:8080/orangehrm/web/auth/google/callback
 *    (untuk production, ganti host +[, redirect_uri] sesuai domain)
 * 3. Salin Client ID & Client Secret ke bawah.
 * 4. Kalau mau user di-restrict domain email tertentu (mis. 'asg.co.id'),
 *    isi allowed_email_domain. '' = semua domain Google.
 * 5. Ubah 'enabled' => true. SSO langsung aktif (tombol muncul di halaman login).
 *
 * WARNING: administratif lah — jangan commit secret ke repo publik.
 */

// Kredensial diambil dari web/proxy/config.php (tidak di-commit, lihat .gitignore).
// Kalau config.php belum ada, dipakai config.example.php (placeholder) supaya
// aplikasi tetap jalan dan penyebabnya kelihatan.
$__sso = (static function (): array {
    $base = __DIR__ . '/../../../../web/proxy/';
    foreach (['config.php', 'config.example.php'] as $name) {
        if (is_file($base . $name)) {
            $cfg = require $base . $name;
            if (is_array($cfg) && isset($cfg['google_sso']) && is_array($cfg['google_sso'])) {
                return $cfg['google_sso'];
            }
            return [];
        }
    }
    return [];
})();

return [
    'enabled'              => true,
    'enabled_hint'         => 'Set true untuk menyalakan tombol Sign in with Google.',

    // OAuth 2.0 Client credentials dari Google Cloud Console
    // (nilai sebenarnya ada di web/proxy/config.php)
    'client_id'            => (string)($__sso['client_id'] ?? ''),
    'client_secret'        => (string)($__sso['client_secret'] ?? ''),

    // Redirect URI — WAJIB sama persis dengan yang didaftarkan di Google Console.
    // Opsional: kalau kosong (''), dihitung otomatis dari URL permintaan saat ini
    // (host + base path + '/auth/google/callback'). Rekomendasi isi eksplisit
    // supaya tak terpengaruh IP/host yang berubah.
    // Nilai sebenarnya ada di web/proxy/config.php → google_sso.redirect_uri
    // (contoh staging: http://staging.aditya.group:8181/auth/google/callback)
    'redirect_uri'         => (string)($__sso['redirect_uri'] ?? ''),

    // Batasi email yang boleh masuk (contoh: 'asg.co.id'). '' = semua.
    'allowed_email_domain' => (string)($__sso['allowed_email_domain'] ?? ''),

    // ---------------------------------------------------------------
    // Tampilan & pembatasan akun di sisi Google (opsional)
    // ---------------------------------------------------------------
    // prompt:
    //   'select_account' = SELALU tampilkan layar pilih akun (rekomendasi
    //                      untuk kantor / komputer bersama: user sadar akun
    //                      mana yang dipakai).
    //   'none'           = login senyap, tanpa UI. Kalau sesi Google user
    //                      tidak ada, Google balas error=login_required dan
    //                      user dikembalikan ke halaman login.
    //   ''               = biarkan Google memutuskan (default perilaku lama).
    'prompt'               => (string)($__sso['prompt'] ?? ''),

    // hd: domain Workspace yang ditampilkan di layar pilih akun
    // (mis. 'aditya.co.id'). Akun pribadi seperti @gmail.com tidak muncul.
    // CATATAN: ini hanya memengaruhi tampilan Google. Penentu akhir tetap
    // allowed_email_domain + pencocokan work email karyawan di server.
    'hd'                   => (string)($__sso['hd'] ?? ''),

    // login_hint: sorot/prefill email terakhir yang berhasil login SSO
    // (disimpan di cookie oleh callback). Mengurangi satu langkah klik.
    'login_hint_enabled'   => (bool)($__sso['login_hint_enabled'] ?? true),

    // Endpoint Google (jangan diubah kecuali diperlukan)
    'google_authorization_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'google_token_uri'         => 'https://oauth2.googleapis.com/token',
    'google_userinfo_uri'      => 'https://openidconnect.googleapis.com/v1/userinfo',
    'google_scope'             => 'openid email profile',

    // Project Google Cloud tempat OAuth client ini dibuat (informatif).
    'project_id'               => 'eksternal-project-dev',
];