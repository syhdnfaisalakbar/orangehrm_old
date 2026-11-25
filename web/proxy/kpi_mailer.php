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
 * SAKLAR notifikasi email KPI.
 * false = MATI (default sejak 2026-11-24, permintaan Pak): notifikasi cukup
 *         lewat lonceng in-app, sehingga simpan pengajuan tidak menunggu SMTP.
 * true  = email dikirim seperti sebelumnya.
 */
if (!defined('KPI_EMAIL_NOTIFY_ENABLED')) {
    define('KPI_EMAIL_NOTIFY_ENABLED', true);
}

/**
 * Alamat PENGIRIM (From) notifikasi KPI.
 *
 * CATATAN PENTING: "alias" seperti ini BUKAN diatur di JavaScript/browser —
 * email hanya bisa dikirim dari server (SMTP). Yang dikerjakan kode di sini
 * hanya menulis header From. Supaya benar-benar terkirim sebagai
 * noreply-orangehrm@aditya.co.id, akun SMTP yang dipakai harus punya izin
 * "Send As" / "Send on Behalf" untuk alamat itu di mail server (Exchange /
 * Google Workspace / Zimbra), atau memakai mailbox tersendiri dengan kredensial
 * sendiri. Kalau tidak, server email akan menulis ulang From ke akun SMTP.
 */
if (!defined('KPI_MAIL_FROM_ALIAS')) {
    // From yang tampil di inbox penerima = konstanta ini (header MIME).
    // CATATAN: ini BUKAN "alias" di mail server. Supaya benar-benar diterima
    // sebagai noreply-orangehrm@aditya.co.id, akun SMTP yang dipakai
    // (ohrm_email_configuration.smtp_username) harus punya izin "Send As"
    // untuk alamat itu. Kalau server menolak/menulis ulang From, kirim
    // gagal -> kembalikan ke alamat akun SMTP sendiri.
    define('KPI_MAIL_FROM_ALIAS', 'noreply-orangehrm@aditya.co.id');
}
if (!defined('KPI_MAIL_FROM_NAME')) {
    define('KPI_MAIL_FROM_NAME', 'OrangeHRM KPI');
}
if (!defined('KPI_MAIL_REPLY_TO')) {
    define('KPI_MAIL_REPLY_TO', 'syahdan.faisal@aditya.co.id');
}

/**
 * Catat percobaan kirim email ke src/log/kpi_mail.log (tanpa isi body/credential).
 * Berguna untuk memastikan SMTP menerima pesan & From alias yang dipakai.
 */
function kpi_mail_log(string $line): void
{
    $dir = realpath(__DIR__ . '/../../src/log');
    $file = ($dir ? $dir : sys_get_temp_dir()) . '/kpi_mail.log';
    @file_put_contents(
        $file,
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Bangun link menuju halaman Approval (berdasarkan request saat ini).
 *
 * 2026-10-15: PENTING — sebelumnya hasilnya `/orangehrm/index.php/...` (segmen
 * `/web` hilang) sehingga link di email mentok di redirect loop. Front controller
 * OrangeHRM ada di `web/index.php`, jadi segmen `/web` harus ikut.
 *   SCRIPT_NAME /orangehrm/web/proxy/save_setup_edit_request.php -> /orangehrm/web/index.php/performance/approvalKpi
 *   SCRIPT_NAME /web/proxy/save_setup_edit_request.php           -> /web/index.php/performance/approvalKpi
 */
function kpi_app_url(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    // Buang hanya bagian "/web/proxy/<file>.php" -> sisanya adalah base app.
    $root = preg_replace('#/web/proxy/[^/]+$#', '', $script);
    return $scheme . '://' . $host . $root . '/web/index.php/' . ltrim($path, '/');
}

function kpi_approval_url(): string
{
    return kpi_app_url('performance/approvalKpi');
}

/**
 * Daftar alamat email penerima notifikasi KPI: user role BOD lebih dulu,
 * fallback ke Admin bila tidak ada BOD yang punya email.
 * @return string[] unik (lowercase dibandingkan), siap dipakai di ->to()
 */
function kpi_mail_recipients(PDO $pdo): array
{
    $fetch = static function (PDO $pdo, int $roleId): array {
        $st = $pdo->prepare(
            "SELECT e.emp_work_email, e.emp_oth_email
               FROM ohrm_user u
               JOIN hs_hr_employee e ON e.emp_number = u.emp_number
              WHERE u.user_role_id = ? AND u.deleted = 0"
        );
        $st->execute([$roleId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $rows = $fetch($pdo, 25); // BOD
    if (!$rows) {
        kpi_mail_log('WARN tidak ada user BOD ber-email; fallback ke Admin');
        $rows = $fetch($pdo, 1); // Admin
    }
    $out = [];
    $seen = [];
    foreach ($rows as $a) {
        $to = trim((string)($a['emp_work_email'] ?? ''));
        if ($to === '') $to = trim((string)($a['emp_oth_email'] ?? ''));
        if ($to === '') continue;
        $key = strtolower($to);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $to;
    }
    return $out;
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
    // 'smtp' = SMTP biasa (port 25/587, STARTTLS opsional),
    // 'smtps' = pilihan "Secure SMTP" di UI (port 465, TLS langsung) — WAJIB
    //           didukung, karena inilah yang dipakai untuk smtp.aditya.co.id:465.
    // 'sendmail' tidak didukung helper ini.
    if (!in_array($mailType, ['', 'smtp', 'smtps'], true)) return false;
    $host   = trim((string)($cfg['smtp_host'] ?? ''));
    $port   = (int)($cfg['smtp_port'] ?? 0);
    $user   = trim((string)($cfg['smtp_username'] ?? ''));
    $pass   = (string)($cfg['smtp_password'] ?? '');
    $sentAs = trim((string)($cfg['sent_as'] ?? ''));
    if ($host === '' || $port <= 0 || $sentAs === '' || $toEmail === '') return false;

    $authFile = realpath(__DIR__ . '/../../src/vendor/autoload.php');
    if (!$authFile || !is_file($authFile)) return false;
    static $loaded = false;
    if (!$loaded) { require_once $authFile; $loaded = true; }

    // DSN: smtps|smtp://user:pass@host:port?verify_peer=0
    // Skema 'smtps' = implicit TLS (port 465) sama seperti MailTransport core.
    $scheme = ($mailType === 'smtps') ? 'smtps' : 'smtp';
    $dsn = $scheme . '://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host . ':' . $port;
    $query = [];
    // Symfony Mailer 5.4 menghapus opsi DSN `encryption` (pakai skema smtps).
    // Skema smtp biasa otomatis STARTTLS saat server menawarkannya.
    $query[] = 'verify_peer=0'; // mirror perilaku MailTransport non-TLS (sertifikat internal)
    if ($query) $dsn .= '?' . implode('&', $query);

    $transport = Symfony\Component\Mailer\Transport::fromDsn($dsn);
    // Batasi waktu koneksi/IO SMTP (default ikut default_socket_timeout / bisa ~60 s).
    if (method_exists($transport, 'getStream')) {
        try {
            $transport->getStream()->setTimeout(10);
        } catch (Throwable $e) {
            // biarkan default bila API stream berbeda
        }
    }
    $mailer = new Symfony\Component\Mailer\Mailer($transport);
    // From = ALIAS (noreply-orangehrm@...), Reply-To tetap alamat manusia
    // supaya balasan email tidak masuk ke mailbox noreply.
    $alias = KPI_MAIL_FROM_ALIAS !== '' ? KPI_MAIL_FROM_ALIAS : $sentAs;
    $email = (new Symfony\Component\Mime\Email())
        ->from(new Symfony\Component\Mime\Address($alias, KPI_MAIL_FROM_NAME))
        ->to($toEmail)
        ->subject($subject)
        ->html($htmlBody);
    if (KPI_MAIL_REPLY_TO !== '') {
        $email->replyTo(KPI_MAIL_REPLY_TO);
    }
    $mailer->send($email);
    kpi_mail_log('OK  to=' . $toEmail . ' from=' . $alias . ' subject=' . $subject);
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
    if (KPI_EMAIL_NOTIFY_ENABLED !== true) {
        return false; // email dimatikan — notifikasi lewat lonceng in-app
    }

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

    // Penerima: user role BOD (permintaan Pak 2026-11-24 — mis. andrew.colin),
    // fallback Admin bila tidak ada BOD ber-email. Lihat kpi_mail_recipients().
    $recipients = kpi_mail_recipients($pdo);

    $fmt = static function ($v): string {
        return ($v === null || $v === '') ? '-' : (string)$v;
    };
    $link    = kpi_approval_url();
    $subject = '[KPI] Pengajuan Edit Indikator menunggu persetujuan';

    $sent = false;
    foreach ($recipients as $to) {
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
        try {
            if (kpi_send_approval_mail($pdo, $to, $subject, $body)) {
                $sent = true;
            } else {
                kpi_mail_log('SKIP to=' . $to . ' (SMTP belum dikonfigurasi / bukan smtp)');
            }
        } catch (Throwable $e) {
            kpi_mail_log('FAIL to=' . $to . ' err=' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * Notifikasi email saat ADMIN mengubah target/bobot/schedule langsung dari ikon
 * pensil di Input Realisasi KPI (jalur save_setup_kpi.php — tanpa approval).
 *
 * Penerima: role BOD (lihat kpi_mail_recipients()). Dipanggil SETELAH response
 * JSON dikirim + di-flush, supaya UI tidak menunggu koneksi SMTP.
 *
 * @param array $oldSetup baris ohrm_kpi_setup SEBELUM diubah (id, indikator,
 *                        divisi, target, bobot, schedule)
 * @return bool true jika minimal 1 email terkirim.
 */
function kpi_notify_setup_change(PDO $pdo, int $empNumber, array $oldSetup, $newTarget, $newBobot, $newSchedule): bool
{
    if (KPI_EMAIL_NOTIFY_ENABLED !== true) {
        return false;
    }
    $setupId   = (int)($oldSetup['id'] ?? 0);
    $indikator = (string)($oldSetup['indikator'] ?? '');
    $divisi    = (string)($oldSetup['divisi'] ?? '');

    // Nama + role pengubah (untuk konteks "diubah oleh siapa").
    $editor = '';
    if ($empNumber > 0) {
        $st = $pdo->prepare(
            "SELECT TRIM(CONCAT(COALESCE(e.emp_firstname,''), ' ', COALESCE(e.emp_lastname,''))) AS nama,
                    r.name AS role_name
               FROM ohrm_user u
               LEFT JOIN hs_hr_employee e ON e.emp_number = u.emp_number
               LEFT JOIN ohrm_user_role r ON r.id = u.user_role_id
              WHERE u.emp_number = ? AND u.deleted = 0
              LIMIT 1"
        );
        $st->execute([$empNumber]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $editor = trim((string)$row['nama']);
            $roleName = trim((string)($row['role_name'] ?? ''));
            if ($roleName !== '') {
                $editor = ($editor !== '' ? $editor . ' ' : '') . '(' . $roleName . ')';
            }
        }
    }

    $recipients = kpi_mail_recipients($pdo);
    if (!$recipients) {
        kpi_mail_log('SKIP perubahan setup ' . $setupId . ' (tidak ada penerima ber-email)');
        return false;
    }

    $fmt = static function ($v): string {
        return ($v === null || $v === '') ? '-' : (string)$v;
    };
    $link    = kpi_app_url('performance/inputRealisasiKpi');
    $subject = '[KPI] Perubahan Target/Bobot Indikator KPI';

    $sent = false;
    foreach ($recipients as $to) {
        $body =
            '<p>Halo,</p><p>Ada <b>perubahan definisi indikator KPI</b> yang baru saja disimpan:</p>'
            . '<ul>'
            . '<li>Diubah oleh: <b>' . htmlspecialchars($editor !== '' ? $editor : '-') . '</b></li>'
            . '<li>Indikator: <b>' . htmlspecialchars($indikator) . '</b></li>'
            . '<li>Divisi: <b>' . htmlspecialchars($divisi) . '</b></li>'
            . '<li>Target: ' . htmlspecialchars($fmt($oldSetup['target'] ?? null)) . ' &rarr; <b>' . htmlspecialchars($fmt($newTarget)) . '</b></li>'
            . '<li>Bobot: ' . htmlspecialchars($fmt($oldSetup['bobot'] ?? null)) . '% &rarr; <b>' . htmlspecialchars($fmt($newBobot)) . '%</b></li>'
            . '<li>Schedule: ' . htmlspecialchars($fmt($oldSetup['schedule'] ?? null)) . ' &rarr; <b>' . htmlspecialchars($fmt($newSchedule)) . '</b></li>'
            . '</ul>'
            . '<p>Perubahan ini langsung berlaku (tanpa approval). Bulan sebelum bulan berjalan '
            . 'tetap memakai target/bobot lama.</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">Buka Input Realisasi KPI</a></p>'
            . '<p>' . htmlspecialchars($link) . '</p>';
        try {
            if (kpi_send_approval_mail($pdo, $to, $subject, $body)) {
                $sent = true;
            } else {
                kpi_mail_log('SKIP to=' . $to . ' (SMTP belum dikonfigurasi / bukan smtp)');
            }
        } catch (Throwable $e) {
            kpi_mail_log('FAIL to=' . $to . ' err=' . $e->getMessage());
        }
    }
    return $sent;
}