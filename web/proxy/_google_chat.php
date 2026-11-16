<?php
/**
 * _google_chat.php — helper notifikasi ke Google Chat (incoming webhook).
 *
 * Dipakai proxy standalone (save_kpi_realisasi.php) untuk memberi tahu space
 * Google Chat (mis. "Notification OrangeHRM") bahwa ada pengajuan verifikasi &
 * approve KPI baru, lengkap dengan link halaman Approval.
 *
 * Konfigurasi: `google_chat.webhook_url` di web/proxy/config.php (lihat
 * config.example.php). URL webhook = kredensial; jangan di-commit, jangan
 * ditulis di log.
 *
 * AMAN: kalau webhook belum diisi / cURL gagal / Google menolak, fungsi
 * mengembalikan status gagal tanpa melempar exception — alur simpan data
 * TIDAK boleh ikut gagal karena notifikasi.
 */

/**
 * Apakah webhook Google Chat sudah dikonfigurasi?
 */
function google_chat_is_configured(): bool
{
    return defined('GOOGLE_CHAT_WEBHOOK_URL') && trim((string)GOOGLE_CHAT_WEBHOOK_URL) !== '';
}

/**
 * Bangun URL absolut ke sebuah path aplikasi (mis. performance/approvalKpi).
 *
 * Front controller OrangeHRM ada di `web/index.php`, jadi segmen `/web` harus ikut:
 *   SCRIPT_NAME /orangehrm/web/proxy/save_kpi_realisasi.php -> /orangehrm/web/index.php/<path>
 *   SCRIPT_NAME /web/proxy/save_kpi_realisasi.php           -> /web/index.php/<path>
 */
function google_chat_app_url(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $root = preg_replace('#/web/proxy/[^/]+$#', '', $script);
    return $scheme . '://' . $host . $root . '/web/index.php/' . ltrim($path, '/');
}

/**
 * Kirim satu pesan teks ke webhook Google Chat.
 *
 * @return array{ok:bool,http:int,error:string}
 */
function google_chat_send(string $text): array
{
    if (!google_chat_is_configured()) {
        return ['ok' => false, 'http' => 0, 'error' => 'GOOGLE_CHAT_WEBHOOK_URL belum diisi'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'error' => 'ekstensi cURL tidak tersedia'];
    }
    if (trim($text) === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'pesan kosong'];
    }

    $payload = json_encode(
        ['text' => $text],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $ch = curl_init(GOOGLE_CHAT_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http < 200 || $http >= 300) {
        // Jangan catat URL webhook (berisi token) ke log.
        error_log('[kpi-chat] gagal kirim: http=' . $http . ' err=' . $err
            . ' resp=' . substr((string)$resp, 0, 300));
        return ['ok' => false, 'http' => $http, 'error' => $err !== '' ? $err : 'HTTP ' . $http];
    }
    return ['ok' => true, 'http' => $http, 'error' => ''];
}

/**
 * Notifikasi "ada pengajuan verifikasi & approve KPI".
 *
 * @param PDO   $pdo    koneksi DB (untuk nama pengaju)
 * @param int   $emp    emp_number pengaju
 * @param array $rows   baris yang berhasil disubmit (butuh `divisi`, `periode`)
 * @return bool true kalau pesan terkirim
 */
function google_chat_notify_kpi_submit(PDO $pdo, int $emp, array $rows): bool
{
    if (empty($rows)) return false;

    $nama = '';
    if ($emp > 0) {
        try {
            $st = $pdo->prepare(
                "SELECT TRIM(CONCAT(COALESCE(emp_firstname,''), ' ', COALESCE(emp_lastname,'')))
                 FROM hs_hr_employee WHERE emp_number = ?"
            );
            $st->execute([$emp]);
            $nama = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $nama = '';
        }
    }
    if ($nama === '') $nama = 'Karyawan #' . $emp;

    $divisi = [];
    $periode = [];
    foreach ($rows as $r) {
        $d = trim((string)($r['divisi'] ?? ''));
        $p = trim((string)($r['periode'] ?? ''));
        if ($d !== '') $divisi[$d] = true;
        if ($p !== '') $periode[$p] = true;
    }
    $divisi = array_keys($divisi);
    $periode = array_keys($periode);
    sort($periode);
    $periodeLabel = implode(', ', array_map(static function (string $p): string {
        $bulan = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                  '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                  '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];
        if (preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) {
            return ($bulan[$m[2]] ?? $m[2]) . ' ' . $m[1];
        }
        return $p;
    }, $periode));

    $link = google_chat_app_url('performance/approvalKpi');
    $lines = [
        '*Pengajuan Verifikasi & Approve KPI*',
        'Pengaju: *' . $nama . '*',
        'Divisi: ' . (empty($divisi) ? '-' : implode(', ', $divisi)),
        'Periode: ' . ($periodeLabel === '' ? '-' : $periodeLabel),
        'Jumlah indikator diajukan: ' . count($rows),
        '',
        'Buka halaman Approval:',
        $link,
    ];

    $res = google_chat_send(implode("\n", $lines));
    return (bool)$res['ok'];
}
