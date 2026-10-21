<?php
/**
 * config.example.php — TEMPLATE konfigurasi web/proxy.
 *
 * CARA PAKAI:
 *   copy web/proxy/config.example.php  ->  web/proxy/config.php
 *   isi semua nilai bertanda ISI_...
 *
 * File config.php TIDAK ikut git (lihat .gitignore) karena berisi password.
 * Kalau config.php belum ada, endpoint web/proxy akan memakai nilai placeholder
 * di file ini dan menulis peringatan ke error_log PHP.
 *
 * Nilai yang berbeda antara lokal dan produksi:
 *   db.host        lokal: 127.0.0.1   produksi: 127.0.0.1 (MySQL satu server)
 *   db.user/pass   sesuai user MySQL di server tersebut
 *   acumatica.*    kredensial integrasi Acumatica
 *   google_sso.redirect_uri  HARUS persis sama dengan yang didaftarkan di
 *                            Google Cloud Console (domain produksi, bukan localhost)
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'orangehrm',
        'user' => 'ISI_USER_DB',
        'pass' => 'ISI_PASSWORD_DB',
    ],

    'acumatica' => [
        'base_url'   => 'https://asg.acumatica.com',
        'login_user' => 'ISI_USER_ACUMATICA',
        'login_pass' => 'ISI_PASSWORD_ACUMATICA',
        'company'    => 'ISI_COMPANY_ACUMATICA',
    ],

    'google_sso' => [
        'client_id'            => 'ISI_CLIENT_ID.apps.googleusercontent.com',
        'client_secret'        => 'ISI_CLIENT_SECRET',
        'redirect_uri'         => 'https://DOMAIN-PRODUKSI/web/auth/google/callback',
        'allowed_email_domain' => 'aditya.co.id',

        // 'select_account' = selalu tampilkan layar pilih akun,
        // 'none'           = login senyap tanpa UI,
        // ''               = biarkan Google memutuskan.
        'prompt'               => 'select_account',

        // Batasi daftar akun ke satu domain Workspace (mis. 'aditya.co.id').
        // '' = semua domain Google.
        'hd'                   => 'aditya.co.id',

        // Sorot email terakhir yang berhasil SSO (login_hint).
        'login_hint_enabled'   => true,
    ],
];
