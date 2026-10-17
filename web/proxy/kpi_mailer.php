<?php
/**
 * kpi_mailer.php — helper kirim notifikasi email PMS KPI (approval).
 *
 * Dipakai dari proxy standalone (save_setup_edit_request.php). Membaca konfigurasi
 * SMTP dari tabel ohrm_email_configuration (diset lewat Admin > Configuration >
 * Email), lalu kirim via Symfony Mailer (autoload src/vendor).
 *
 * AMAN: bila email belum dikonfigurasi / tidak ada atasan / tidak ada email / gagal
 * kirim, fungsi mengembalikan false tanpa melempar error — tidak mengganggu caller.
 */

/**
 * Bangun link menuju halaman Approval (berdasarkan request saat ini).
 *
 * 2026-10-15: PENTING — sebelumnya hasilnya `/orangehrm/index.php/...` (segmen
 * `/web` hilang) sehingga link di email mentok di redirect loop. Front controller
 * OrangeHRM ada di `web/index.php`, jadi segmen `/web` harus ikut.
 *   SCRIPT_NAME /orangehrm/web/proxy/save_setup_edit_request.php -> /orangehrm/web/index.php/performance/approvalKpi
 *   SCRIPT_NAME /web/proxy/save_setup_edit_request.php           -> /web/index.php/performance/approvalKpi
 */
function kpi_approval_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Buang hanya bagian "/web/proxy/<file>.php" -> sisanya adalah base app.
    $root = preg_replace('#/web/proxy/[^/]+$#', '', $script);
    return $scheme . '://' . $host . $root . '/web/index.php/performance/approvalKpi';
}

/**
 * Kirim satu email via config SMTP OrangeHRM.
 * @return bool true terkirim / false di-skip (belum dikonfigurasi / gagal).
 */
function kpi_send_approval_mail(PDO $pdo, string $toEmail, string $subject, string $htmlBody): bool
{
    $cfg = $pdo->query("SELECT * FROM ohrm_email_configuration LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$cfg) return false; // email belum dikonfigurasi (tabel kosong)
    $mailType = strtolower(trim((string)($cfg['mail_type'] ?? '')));
    if ($mailType !== '' && $mailType !== 'smtp') return false; // hanya smtp didukung di helper ini
    $host   = trim((string)($cfg['smtp_host'] ?? ''));
    $port   = (int)($cfg['smtp_port'] ?? 0);
    $user   = trim((string)($cfg['smtp_username'] ?? ''));
    $pass   = (string)($cfg['smtp_password'] ?? '');
    $sentAs = trim((string)($cfg['sent_as'] ?? ''));
    $security = strtolower(trim((string)($cfg['smtp_security_type'] ?? '')));
    if ($host === '' || $port <= 0 || $sentAs === '' || $toEmail === '') return false;

    $authFile = realpath(__DIR__ . '/../../src/vendor/autoload.php');
    if (!$authFile || !is_file($authFile)) return false;
    static $loaded = false;
    if (!$loaded) { require_once $authFile; $loaded = true; }

    // DSN: smtp://user:pass@host:port[?encryption=ssl|tls&verify_peer=0]
    $dsn = 'smtp://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host . ':' . $port;
    $query = [];
    if ($security === 'ssl') $query[] = 'encryption=ssl';
    if ($security === 'tls') $query[] = 'encryption=tls';
    $query[] = 'verify_peer=0'; // mirror perilaku MailTransport non-TLS
    if ($query) $dsn .= '?' . implode('&', $query);

    $transport = Symfony\Component\Mailer\Transport::fromDsn($dsn);
    $mailer = new Symfony\Component\Mailer\Mailer($transport);
    $email = (new Symfony\Component\Mime\Email())
        ->from($sentAs)
        ->to($toEmail)
        ->subject($subject)
        ->html($htmlBody);
    $mailer->send($email);
    return true;
}

/**
 * Kirim notifikasi "pengajuan edit KPI menunggu persetujuan" ke SEMUA ADMIN
 * (ohrm_user.user_role_id = 1) yang punya email.
 *
 * 2026-10-15: penerima DIUBAH dari atasan pengaju (hs_hr_emp_reportto) menjadi
 * admin. Alasan: keputusan approve/reject pengajuan edit HANYA boleh admin
 * (lihat update_setup_edit_request.php), sementara di data nyata tidak ada satu
 * pun anggota KPI yang punya baris hs_hr_emp_reportto -> email tidak pernah
 * terkirim walau SMTP sudah dikonfigurasi.
 *
 * @return bool true jika minimal 1 email terkirim.
 */
function kpi_notify_edit_request(PDO $pdo, int $requesterEmp, array $setup, $target, $bobot, $schedule): bool
{
    $setupId   = (int)($setup['id'] ?? 0);
    $indikator = (string)($setup['indikator'] ?? '');
    $divisi    = (string)($setup['divisi'] ?? '');

    // Nilai yang berlaku sekarang = nilai LAMA (pengajuan belum mengubah
    // ohrm_kpi_setup), jadi bisa langsung dipakai sebagai pembanding "lama -> baru".
    $old = ['target' => null, 'bobot' => null, 'schedule' => null];
    if ($setupId > 0) {
        $st = $pdo->prepare("SELECT target, bobot, schedule FROM ohrm_kpi_setup WHERE id = ?");
        $st->execute([$setupId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) $old = $row;
    }

    // Nama pengaju (utk konteks di badan email).
    $requester = '';
    if ($requesterEmp > 0) {
        $st = $pdo->prepare(
            "SELECT TRIM(CONCAT(COALESCE(emp_firstname,''), ' ', COALESCE(emp_lastname,'')))
             FROM hs_hr_employee WHERE emp_number = ?"
        );
        $st->execute([$requesterEmp]);
        $requester = (string)($st->fetchColumn() ?: '');
    }

    // Penerima: SEMUA admin aktif yang punya email (work diutamakan, lalu other).
    $admins = $pdo->query(
        "SELECT e.emp_work_email, e.emp_oth_email
         FROM ohrm_user u
         JOIN hs_hr_employee e ON e.emp_number = u.emp_number
         WHERE u.user_role_id = 1 AND u.deleted = 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $fmt = static function ($v): string {
        return ($v === null || $v === '') ? '-' : (string)$v;
    };
    $link    = kpi_approval_url();
    $subject = '[KPI] Pengajuan Edit Indikator menunggu persetujuan';

    $sent = false;
    $seen = [];
    foreach ($admins as $a) {
        $to = trim((string)($a['emp_work_email'] ?? ''));
        if ($to === '') $to = trim((string)($a['emp_oth_email'] ?? ''));
        if ($to === '') continue;
        // Hindari kirim dobel bila beberapa akun admin memakai alamat yang sama.
        $key = strtolower($to);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $body =
            '<p>Halo,</p><p>Ada pengajuan <b>edit definisi indikator KPI</b> yang menunggu persetujuan Anda:</p>'
            . '<ul>'
            . '<li>Pengaju: <b>' . htmlspecialchars($requester) . '</b></li>'
            . '<li>Indikator: <b>' . htmlspecialchars($indikator) . '</b></li>'
            . '<li>Divisi: <b>' . htmlspecialchars($divisi) . '</b></li>'
            . '<li>Target: ' . htmlspecialchars($fmt($old['target'])) . ' &rarr; <b>' . htmlspecialchars($fmt($target)) . '</b></li>'
            . '<li>Bobot: ' . htmlspecialchars($fmt($old['bobot'])) . '% &rarr; <b>' . htmlspecialchars($fmt($bobot)) . '%</b></li>'
            . '<li>Schedule: ' . htmlspecialchars($fmt($old['schedule'])) . ' &rarr; <b>' . htmlspecialchars($fmt($schedule)) . '</b></li>'
            . '</ul>'
            . '<p>Silakan tinjau lalu setujui/tolak di menu Approval:</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">Buka Halaman Approval</a></p>'
            . '<p>' . htmlspecialchars($link) . '</p>';
        if (kpi_send_approval_mail($pdo, $to, $subject, $body)) $sent = true;
    }
    return $sent;
}