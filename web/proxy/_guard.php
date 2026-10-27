<?php
/**
 * _guard.php — penjagaan dasar untuk endpoint web/proxy yang menerima upload.
 *
 * Endpoint proxy lain di folder ini TIDAK memakai autentikasi (grep
 * session_start/_SESSION = 0 hasil). Untuk endpoint yang MENULIS data, itu
 * terlalu terbuka: siapa pun yang bisa menjangkau URL bisa mengubah angka.
 *
 * Guard di sini sengaja berlapis tapi sederhana:
 *   1. hanya POST
 *   2. Origin/Referer harus host yang sama (kalau header dikirim browser)
 *   3. request tanpa Origin hanya diterima dari loopback (uji lokal / CLI)
 *
 * Ini bukan pengganti autentikasi. Kalau nanti mau benar-benar terikat sesi
 * login OrangeHRM, guard ini titik yang harus diganti.
 */

/** @return string|null pesan error, null kalau lolos */
function proxy_require_post(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return 'Endpoint ini hanya menerima POST.';
    }
    return null;
}

/** @return string|null pesan error, null kalau lolos */
function proxy_require_same_origin(): ?string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');

    if ($source !== '') {
        $srcHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        $srcPort = parse_url($source, PHP_URL_PORT);
        $srcWithPort = $srcPort ? $srcHost . ':' . $srcPort : $srcHost;
        $hostNoPort = preg_replace('/:\d+$/', '', $host);

        if ($srcWithPort !== $host && $srcHost !== $host && $srcHost !== $hostNoPort) {
            return 'Origin tidak diizinkan untuk endpoint ini.';
        }
        return null;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isLoopback = in_array($remote, ['127.0.0.1', '::1'], true)
        || strpos($remote, '172.') === 0
        || strpos($remote, '192.168.') === 0;

    return $isLoopback ? null : 'Permintaan tanpa Origin ditolak (bukan dari jaringan lokal).';
}

/** Nama pengguna untuk log audit (dikirim UI; tidak dipakai untuk otorisasi). */
function proxy_audit_user(): string
{
    $raw = (string) ($_POST['uploaded_by'] ?? '');
    if ($raw === '') {
        $raw = 'unknown';
    }
    $raw = preg_replace('/[^\p{L}\p{N} ._@-]/u', '', $raw);
    return mb_substr(trim($raw) !== '' ? trim($raw) : 'unknown', 0, 100);
}
