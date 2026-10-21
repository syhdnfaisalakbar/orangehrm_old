<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA 02110-1301, USA
 */

namespace OrangeHRM\Authentication\Service;

use OrangeHRM\Framework\Http\Request;

/**
 * Helper untuk SSO login Google (OAuth2 Authorization Code flow).
 *
 * Membaca konfigurasi dari config/GoogleSSO.php dan menyediakan:
 *  - pengecekan status aktif
 *  - membangun Google authorization URL
 *  - tukar authorization code -> access token (cURL ke Google token endpoint)
 *  - ambil profil (email) via userinfo endpoint
 *  - validasi domain email (opsional)
 *
 * Tidak butuh library eksternal — pakai ekstensi PHP cURL + openssl yang sudah aktif.
 */
class GoogleSSOService
{
    /**
     * Cookie berisi email terakhir yang berhasil login via SSO.
     * Dipakai sebagai login_hint supaya akun yang benar langsung tersorot
     * di layar pilih akun Google.
     */
    public const COOKIE_LAST_EMAIL = 'orangehrm_sso_last_email';

    /**
     * Umur cookie email terakhir: 1 tahun.
     */
    private const COOKIE_LAST_EMAIL_TTL = 31536000;

    private ?array $config = null;

    /**
     * @return array
     */
    protected function getConfig(): array
    {
        if ($this->config === null) {
            $this->config = require __DIR__ . '/../config/GoogleSSO.php';
        }
        return $this->config;
    }

    /**
     * SSO aktif hanya kalau: enabled = true DAN client_id & client_secret terisi.
     * @return bool
     */
    public function isEnabled(): bool
    {
        $c = $this->getConfig();
        return (bool)($c['enabled'] ?? false)
            && !empty($c['client_id'])
            && !empty($c['client_secret']);
    }

    public function getClientId(): string
    {
        return (string)($this->getConfig()['client_id'] ?? '');
    }

    public function getClientSecret(): string
    {
        return (string)($this->getConfig()['client_secret'] ?? '');
    }

    /**
     * Email terakhir yang berhasil login SSO (dari cookie), untuk login_hint.
     * Mengembalikan '' kalau tidak ada / tidak valid.
     */
    public function getLastLoginHint(): string
    {
        if (empty($this->getConfig()['login_hint_enabled'])) {
            return '';
        }
        $email = strtolower(trim((string)($_COOKIE[self::COOKIE_LAST_EMAIL] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $email;
    }

    /**
     * Simpan email yang berhasil login supaya login berikutnya bisa langsung
     * disorot di layar pilih akun Google (login_hint).
     */
    public function rememberLastEmail(string $email): void
    {
        $email = strtolower(trim($email));
        if (empty($this->getConfig()['login_hint_enabled'])) {
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        setcookie(self::COOKIE_LAST_EMAIL, $email, [
            'expires'  => time() + self::COOKIE_LAST_EMAIL_TTL,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Supaya langsung terbaca di request yang sama (tanpa menunggu reload).
        $_COOKIE[self::COOKIE_LAST_EMAIL] = $email;
    }

    /**
     * Redirect URI — pakai nilai konfigurasi, atau auto-detect dari request
     * kalau konfigurasi kosong.
     * @param Request|null $request
     * @return string
     */
    public function getRedirectUri(?Request $request = null): string
    {
        $configured = (string)($this->getConfig()['redirect_uri'] ?? '');
        if ($configured !== '') {
            return $configured;
        }
        if ($request instanceof Request) {
            return $request->getSchemeAndHttpHost()
                . $request->getBasePath()
                . '/auth/google/callback';
        }
        return '';
    }

    /**
     * Bangun URL otorisasi Google (langkah pertama: redirect user ke sini).
     *
     * Parameter opsional yang ikut dikirim hanya kalau di-set di config:
     *  - prompt     : 'select_account' = selalu tampilkan pilih akun,
     *                 'none'           = login senyap tanpa UI
     *  - hd         : batasi daftar akun ke satu domain Workspace
     *  - login_hint : sorot email tertentu (email terakhir yang sukses SSO)
     *
     * @param string $state estado anti-CSRF
     * @param Request|null $request
     * @param string $loginHint email yang ingin disorot di layar pilih akun
     * @return string
     */
    public function buildAuthorizationUrl(
        string $state,
        ?Request $request = null,
        string $loginHint = ''
    ): string {
        $c = $this->getConfig();
        $params = [
            'client_id'     => $this->getClientId(),
            'redirect_uri'  => $this->getRedirectUri($request),
            'response_type' => 'code',
            'scope'         => $c['google_scope'] ?? 'openid email profile',
            'access_type'   => 'online',
            'state'         => $state,
        ];

        // Selalu minta user memilih akun (aman untuk komputer bersama).
        $prompt = trim((string)($c['prompt'] ?? ''));
        if ($prompt !== '') {
            $params['prompt'] = $prompt;
        }

        // Batasi layar pilih akun ke domain kantor (mis. aditya.co.id).
        $hd = trim((string)($c['hd'] ?? ''));
        if ($hd !== '') {
            $params['hd'] = $hd;
        }

        // Sorot email terakhir yang berhasil login, hemat satu klik.
        $loginHint = strtolower(trim($loginHint));
        if ($loginHint !== '' && !empty($c['login_hint_enabled'])) {
            $params['login_hint'] = $loginHint;
        }

        return $c['google_authorization_uri'] . '?' . http_build_query($params);
    }

    /**
     * Tukar authorization code dengan access token + id_token.
     * @param string $code
     * @param string $redirectUri
     * @return array|null null kalau gagal
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): ?array
    {
        $c = $this->getConfig();
        $payload = http_build_query([
            'code'          => $code,
            'client_id'     => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $response = $this->httpPost($c['google_token_uri'], $payload);
        if ($response === null) {
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }
        return $data;
    }

    /**
     * Ambil profil user Google (minimal email) pakai access token.
     * @param string $accessToken
     * @return array|null
     */
    public function fetchUserInfo(string $accessToken): ?array
    {
        $c = $this->getConfig();
        $ch = curl_init($c['google_userinfo_uri']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Cek apakah email boleh masuk (jika allowed_email_domain di-set).
     * @param string $email
     * @return bool
     */
    public function isEmailAllowed(string $email): bool
    {
        $domain = strtolower(trim((string)($this->getConfig()['allowed_email_domain'] ?? '')));
        if ($domain === '') {
            return true;
        }
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        return strtolower(substr($email, $at + 1)) === $domain;
    }

    /**
     * POST form-encoded via cURL. Mengembalikan response body atau null.
     * @param string $url
     * @param string $body
     * @return string|null
     */
    private function httpPost(string $url, string $body): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $raw === false) {
            return null;
        }
        return $raw;
    }
}