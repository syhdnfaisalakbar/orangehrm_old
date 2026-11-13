<?php
/**
 * _candidate_import_lib.php — logika bersama upload kandidat via Excel.
 *
 * Dipakai preview_candidate_import.php (tanpa menulis DB) dan
 * save_candidate_import.php (menulis DB). Zero-dependency: hanya butuh
 * _xlsx_reader.php + ext-zip.
 *
 * Aturan bisnis (dikonfirmasi Pak, 2026-11-12):
 *  - kolom wajib: First Name, Last Name, Email, Vacancy
 *  - kolom opsional: Middle Name, Contact Number, Date of Application, Notes,
 *    Consent to Keep Data, Resume (link URL)
 *  - email yang sudah punya lamaran di vacancy yang sama -> SKIP (tidak error)
 *  - alur UI: pratinjau dulu, baru simpan
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_xlsx_reader.php';

const CANDIDATE_IMPORT_MAX_ROWS = 2000;
const CANDIDATE_IMPORT_STATUS_APPLICATION_INITIATED = 'APPLICATION INITIATED';
const CANDIDATE_IMPORT_ACTION_ADD = 16;              // CandidateService::RECRUITMENT_CANDIDATE_ACTION_ADD
const CANDIDATE_IMPORT_ACTION_ATTACH = 1;            // WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_ATTACH_VACANCY
const CANDIDATE_IMPORT_MODE_MANUAL = 1;              // mode_of_application = manual
const CANDIDATE_IMPORT_RESUME_MAX_BYTES = 8 * 1024 * 1024;

/** Kolom kanonik => alias header yang diterima (sudah dinormalisasi). */
function candidate_import_headers(): array
{
    return [
        'first_name' => ['firstname', 'nama depan', 'namadepan', 'first'],
        'middle_name' => ['middlename', 'nama tengah', 'namatengah', 'middle'],
        'last_name' => ['lastname', 'nama belakang', 'namabelakang', 'last'],
        'email' => ['email', 'e mail', 'emailaddress', 'alamat email', 'surel'],
        'contact_number' => ['contactnumber', 'contact', 'phone', 'phonenumber', 'no hp', 'nohp', 'nomor hp', 'nomorhp', 'telepon', 'telp', 'handphone'],
        'vacancy' => ['vacancy', 'vacancyname', 'case id', 'caseid', 'kode case', 'kodecase', 'case', 'posisi', 'lowongan'],
        'date_of_application' => ['dateofapplication', 'tanggal melamar', 'tanggalmelamar', 'tanggal aplikasi', 'tanggalaplikasi', 'date'],
        'notes' => ['notes', 'note', 'comment', 'comments', 'komentar', 'catatan', 'keterangan'],
        'consent' => ['consent to keep data', 'consenttokeepdata', 'consent', 'persetujuan', 'persetujuan simpan data'],
        'resume' => ['resume', 'resume url', 'resumeurl', 'cv', 'link cv', 'linkcv', 'link resume', 'linkresume', 'url cv'],
    ];
}

/** Normalisasi header/judul kolom: lowercase, buang karakter non-alfanumerik. */
function candidate_import_norm(string $value): string
{
    $value = str_replace(['_', '-', "\u{00A0}"], ' ', $value);
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

/**
 * Cari baris header dan petakan indeks kolom => nama kolom kanonik.
 *
 * @param array<int, array<int, string>> $rows hasil xlsx_read_rows()
 * @return array{row:int, map:array<int,string>}|null
 */
function candidate_import_detect_header(array $rows): ?array
{
    $aliases = [];
    foreach (candidate_import_headers() as $canonical => $list) {
        $aliases[$canonical] = $list;
        $aliases[$canonical][] = candidate_import_norm(str_replace('_', ' ', $canonical));
    }

    foreach ($rows as $rowNumber => $cells) {
        $map = [];
        $matched = 0;
        foreach ($cells as $colIndex => $value) {
            $norm = candidate_import_norm((string) $value);
            if ($norm === '') {
                continue;
            }
            foreach ($aliases as $canonical => $list) {
                if (in_array($norm, $list, true) || $norm === candidate_import_norm(str_replace('_', ' ', $canonical))) {
                    if (!in_array($canonical, $map, true)) {
                        $map[$colIndex] = $canonical;
                        $matched++;
                    }
                    break;
                }
            }
        }

        // Minimal 3 kolom dikenali dan harus ada first_name + email + vacancy
        if ($matched >= 3 && in_array('first_name', $map, true) && in_array('email', $map, true)
            && in_array('vacancy', $map, true)) {
            return ['row' => (int) $rowNumber, 'map' => $map];
        }
    }

    return null;
}

/** Ambil nilai sel untuk sebuah kolom kanonik. */
function candidate_import_value(array $cells, array $map, string $canonical): string
{
    foreach ($map as $colIndex => $name) {
        if ($name === $canonical) {
            return trim((string) ($cells[$colIndex] ?? ''));
        }
    }
    return '';
}

/**
 * Parse tanggal: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, atau serial Excel.
 */
function candidate_import_parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        $datePart = substr($value, 0, 10);
        $dt = DateTime::createFromFormat('Y-m-d', $datePart);
        return ($dt && $dt->format('Y-m-d') === $datePart) ? $datePart : null;
    }

    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $value, $m)) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return null;
    }

    // Serial number Excel (mis. 46000)
    if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
        $days = (int) floor((float) $value);
        if ($days > 20000 && $days < 80000) {
            $base = new DateTime('1899-12-30', new DateTimeZone('UTC'));
            $base->modify('+' . $days . ' days');
            return $base->format('Y-m-d');
        }
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Ambil semua vacancy untuk pencocokan (tabelnya kecil).
 *
 * @return array<int, array{id:int,name:string,case_id:string,status:int,rejected:int}>
 */
function candidate_import_fetch_vacancies(PDO $pdo): array
{
    $rows = [];
    $stmt = $pdo->query(
        'SELECT id, name, COALESCE(case_id, \'\') AS case_id, status, rejected
           FROM ohrm_job_vacancy
          ORDER BY id DESC'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'case_id' => (string) $row['case_id'],
            'status' => (int) $row['status'],
            'rejected' => (int) $row['rejected'],
        ];
    }
    return $rows;
}

/**
 * Cocokkan input kolom Vacancy dengan data vacancy.
 * Urutan: Case ID (abaikan nol di depan) -> ID vacancy -> nama persis ->
 * nama tanpa suffix/prefix kurung -> potongan sebelum " - " sebagai Case ID.
 *
 * @param array<int, array{id:int,name:string,case_id:string,status:int,rejected:int}> $vacancies
 */
function candidate_import_match_vacancy(array $vacancies, string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $needle = candidate_import_norm($raw);

    // 1. Case ID
    if (preg_match('/^\d+$/', $raw)) {
        $padded = str_pad(ltrim($raw, '0'), 10, '0', STR_PAD_LEFT);
        foreach ($vacancies as $v) {
            if ($v['case_id'] !== '') {
                if ($v['case_id'] === $raw
                    || str_pad(ltrim($v['case_id'], '0'), 10, '0', STR_PAD_LEFT) === $padded) {
                    return $v;
                }
            }
        }
        // 2. ID vacancy internal
        foreach ($vacancies as $v) {
            if ($v['id'] === (int) $raw) {
                return $v;
            }
        }
    }

    // 3. Nama persis (case-insensitive, spasi dirapikan)
    foreach ($vacancies as $v) {
        if (candidate_import_norm($v['name']) === $needle) {
            return $v;
        }
    }

    // 4. Nama tanpa "(Closed)"/"(Active)" dsb
    foreach ($vacancies as $v) {
        $stripped = preg_replace('/\s*\([^)]*\)\s*$/', '', $v['name']);
        if (candidate_import_norm((string) $stripped) === $needle) {
            return $v;
        }
    }

    // 5. Potongan sebelum " - " dianggap Case ID
    if (strpos($raw, ' - ') !== false) {
        $head = trim(explode(' - ', $raw)[0]);
        if (preg_match('/^\d+$/', $head)) {
            return candidate_import_match_vacancy($vacancies, $head);
        }
    }

    return null;
}

/** Apakah kandidat dengan email ini sudah melamar pada vacancy tersebut? */
function candidate_import_find_existing(PDO $pdo, string $email, int $vacancyId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT c.id
           FROM ohrm_job_candidate c
           JOIN ohrm_job_candidate_vacancy cv ON cv.candidate_id = c.id
          WHERE LOWER(c.email) = :email AND cv.vacancy_id = :vid
          LIMIT 1'
    );
    $stmt->execute([':email' => mb_strtolower($email, 'UTF-8'), ':vid' => $vacancyId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Validasi satu baris.
 *
 * @param array<int, string> $cells
 * @param array<int, string> $map
 * @param array<string, bool> $seen kunci duplikat dalam file (by reference)
 * @param array<int, array{id:int,name:string,case_id:string,status:int,rejected:int}> $vacancies
 * @return array<string, mixed>
 */
function candidate_import_validate_row(
    PDO $pdo,
    array $cells,
    array $map,
    int $rowNumber,
    array &$seen,
    array $vacancies
): array {
    $get = static fn (string $key): string => candidate_import_value($cells, $map, $key);

    $first = $get('first_name');
    $middle = $get('middle_name');
    $last = $get('last_name');
    $email = $get('email');
    $contact = $get('contact_number');
    $vacancyRaw = $get('vacancy');
    $dateRaw = $get('date_of_application');
    $notes = $get('notes');
    $consentRaw = $get('consent');
    $resumeUrl = $get('resume');

    $errors = [];
    $warnings = [];

    if ($first === '') {
        $errors[] = 'First Name wajib diisi';
    } elseif (mb_strlen($first) > 30) {
        $errors[] = 'First Name maksimal 30 karakter';
    }
    if ($last === '') {
        $errors[] = 'Last Name wajib diisi';
    } elseif (mb_strlen($last) > 30) {
        $errors[] = 'Last Name maksimal 30 karakter';
    }
    if ($middle !== '' && mb_strlen($middle) > 30) {
        $errors[] = 'Middle Name maksimal 30 karakter';
    }
    if ($email === '') {
        $errors[] = 'Email wajib diisi';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email tidak valid';
    } elseif (mb_strlen($email) > 100) {
        $errors[] = 'Email maksimal 100 karakter';
    }
    if ($contact !== '' && mb_strlen($contact) > 30) {
        $errors[] = 'Contact Number maksimal 30 karakter';
    }
    if ($vacancyRaw === '') {
        $errors[] = 'Vacancy wajib diisi (Case ID atau nama vacancy)';
    }

    $vacancy = $vacancyRaw !== '' ? candidate_import_match_vacancy($vacancies, $vacancyRaw) : null;
    if ($vacancyRaw !== '' && $vacancy === null) {
        $errors[] = 'Vacancy "' . $vacancyRaw . '" tidak ditemukan';
    }

    $date = null;
    if ($dateRaw !== '') {
        $date = candidate_import_parse_date($dateRaw);
        if ($date === null) {
            $errors[] = 'Date of Application tidak valid (pakai YYYY-MM-DD)';
        }
    }
    if ($date === null) {
        $date = date('Y-m-d');
    }

    $consent = in_array(candidate_import_norm($consentRaw), ['yes', 'y', 'ya', '1', 'true', 'benar'], true) ? 1 : 0;

    if ($resumeUrl !== '') {
        if (!preg_match('#^https?://#i', $resumeUrl)) {
            $warnings[] = 'Resume bukan link http/https, diabaikan';
            $resumeUrl = '';
        } elseif (preg_match('~drive\.google\.com/drive/(?:u/\d+/)?folders/~i', $resumeUrl)) {
            $warnings[] = 'Link FOLDER Google Drive tidak bisa diunduh — pakai link per file CV (buka file lalu Share > Copy link, bentuknya .../file/d/<ID>/view)';
            $resumeUrl = '';
        }
    }

    $status = $errors ? 'error' : 'ok';
    $message = $errors ? implode('; ', $errors) : '';

    if ($status === 'ok' && $vacancy !== null && $email !== '') {
        $key = mb_strtolower($email, 'UTF-8') . '|' . $vacancy['id'];
        if (isset($seen[$key])) {
            $status = 'error';
            $message = 'Duplikat di file (email + vacancy sama dengan baris ' . $seen[$key] . ')';
        } else {
            $seen[$key] = $rowNumber;
            $existingId = candidate_import_find_existing($pdo, $email, (int) $vacancy['id']);
            if ($existingId !== null) {
                $status = 'skip';
                $message = 'Kandidat sudah ada di vacancy ini (ID ' . $existingId . ')';
            }
        }
    }

    if ($warnings) {
        $message = trim($message . ($message !== '' ? '; ' : '') . implode('; ', $warnings));
    }

    return [
        'row' => $rowNumber,
        'first_name' => $first,
        'middle_name' => $middle,
        'last_name' => $last,
        'full_name' => trim(preg_replace('/\s+/', ' ', $first . ' ' . $middle . ' ' . $last)),
        'email' => $email,
        'contact_number' => $contact,
        'vacancy_raw' => $vacancyRaw,
        'vacancy_id' => $vacancy['id'] ?? null,
        'vacancy_name' => $vacancy['name'] ?? '',
        'case_id' => $vacancy['case_id'] ?? '',
        'vacancy_status' => $vacancy !== null ? ((int) $vacancy['status'] === 1 ? 'Active' : 'Closed') : '',
        'date_of_application' => $date,
        'notes' => $notes,
        'consent' => $consent,
        'resume_url' => $resumeUrl,
        'status' => $status,
        'message' => $message,
    ];
}

/**
 * Parse seluruh file: header + validasi semua baris.
 *
 * @return array{header_row:int, rows:array<int, array<string, mixed>>, summary:array<string,int>}
 */
function candidate_import_parse_file(PDO $pdo, string $path): array
{
    $rows = xlsx_read_rows($path, CANDIDATE_IMPORT_MAX_ROWS + 50);
    if (empty($rows)) {
        throw new RuntimeException('File tidak berisi data.');
    }

    $header = candidate_import_detect_header($rows);
    if ($header === null) {
        throw new RuntimeException(
            'Header tidak dikenali. Wajib ada kolom: First Name, Last Name, Email, Vacancy. '
            . 'Gunakan file template supaya nama kolomnya sesuai.'
        );
    }

    $vacancies = candidate_import_fetch_vacancies($pdo);
    $seen = [];
    $results = [];
    $dataFound = false;

    foreach ($rows as $rowNumber => $cells) {
        $rowNumber = (int) $rowNumber;
        if ($rowNumber <= $header['row']) {
            continue;
        }
        $dataFound = true;
        // Baris kosong total dilewati
        $joined = trim(implode('', array_map(static fn ($v) => trim((string) $v), $cells)));
        if ($joined === '') {
            continue;
        }
        if (count($results) >= CANDIDATE_IMPORT_MAX_ROWS) {
            $results[] = [
                'row' => $rowNumber,
                'status' => 'error',
                'message' => 'Melebihi batas ' . CANDIDATE_IMPORT_MAX_ROWS . ' baris per upload',
                'full_name' => '',
                'email' => '',
                'vacancy_name' => '',
            ];
            break;
        }
        $results[] = candidate_import_validate_row($pdo, $cells, $header['map'], $rowNumber, $seen, $vacancies);
    }

    if (!$dataFound) {
        throw new RuntimeException('Tidak ada baris data di bawah header.');
    }

    $summary = ['total' => count($results), 'ok' => 0, 'skip' => 0, 'error' => 0];
    foreach ($results as $r) {
        $summary[$r['status']]++;
    }

    return ['header_row' => (int) $header['row'], 'rows' => $results, 'summary' => $summary];
}

/** Koneksi DB proxy (dipakai kedua endpoint). */
function candidate_import_pdo(): PDO
{
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';port=' . PROXY_DB_PORT . ';dbname=' . PROXY_DB_NAME . ';charset=utf8mb4',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * User OrangeHRM yang sedang login (best-effort). Dipakai untuk added_person /
 * performed_by dan audit log.
 *
 * @return array{emp_number:?int, name:string, authenticated:bool}
 */
function candidate_import_current_user(): array
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $sessionName = $isHttps ? 'orangehrm' : '_orangehrm';

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name($sessionName);
        @session_start();
    }

    // OrangeHRM (Symfony session) menyimpan atribut user dengan prefix titik
    // di $_SESSION['_sf2_attributes'], bukan objek di $_SESSION['user'].
    $attrs = $_SESSION['_sf2_attributes'] ?? [];
    $authenticated = (bool) ($attrs['user.is_authenticated'] ?? false);
    $empNumber = (int) ($attrs['user.user_employee_number'] ?? 0);
    if ($empNumber <= 0) {
        $authenticated = false;
        $empNumber = null;
    }
    $name = $empNumber !== null ? 'emp #' . $empNumber : 'unknown';

    if (session_status() === PHP_SESSION_ACTIVE) {
        // lepas lock supaya request lain tidak tertahan saat proses import
        session_write_close();
    }

    return ['emp_number' => $empNumber, 'name' => $name, 'authenticated' => $authenticated];
}

/**
 * Ubah link Google Drive "share" menjadi link unduh langsung.
 */
function candidate_import_direct_url(string $url): string
{
    // Delimiter '~' (bukan '#') karena pola perlu memuat tanda '#' di kelas karakter.
    if (preg_match('~drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})~', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    if (preg_match('~drive\.google\.com/(?:open|uc)\?(?:[^#]*&)?id=([A-Za-z0-9_-]{10,})~', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    if (preg_match('~docs\.google\.com/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,})~', $url, $m)) {
        return 'https://docs.google.com/' . $m[1] . '/d/' . $m[2] . '/export?format=pdf';
    }
    return $url;
}

/**
 * Unduh file resume dari link, kembalikan isinya.
 *
 * @return array{ok:bool, content?:string, mime?:string, name?:string, error?:string}
 */
function candidate_import_download_resume(string $url): array
{
    $url = candidate_import_direct_url(trim($url));
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => 'Link resume tidak valid'];
    }
    if (preg_match('~drive\.google\.com/drive/(?:u/\d+/)?folders/~i', $url)) {
        return [
            'ok' => false,
            'error' => 'Link folder Google Drive tidak bisa diunduh. Pakai link per file CV.',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (OrangeHRM Candidate Import)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_BUFFERSIZE => 1048576,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Gagal mengunduh: ' . $err];
    }
    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => 'Link membalas HTTP ' . $http];
    }

    $body = substr((string) $raw, $hs);
    if ($body === '') {
        return ['ok' => false, 'error' => 'File kosong'];
    }
    if (strlen($body) > CANDIDATE_IMPORT_RESUME_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Ukuran file melebihi 8 MB'];
    }

    $mime = strtolower(trim(explode(';', $ctype)[0]));
    if ($mime === '' || $mime === 'application/octet-stream') {
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $detected = $fi->buffer($body);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower($detected);
            }
        }
    }
    if ($mime === 'text/html') {
        return [
            'ok' => false,
            'error' => 'Link mengembalikan halaman web, bukan file (untuk Google Drive pastikan akses "Anyone with the link")',
        ];
    }

    $extMap = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $ext = $extMap[$mime] ?? '';
    if ($ext === '') {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        $ext = preg_match('/\.([A-Za-z0-9]{2,5})$/', $base, $m) ? strtolower($m[1]) : 'bin';
    }

    $name = 'resume-' . date('Ymd-His') . '-' . substr(sha1($url), 0, 6) . '.' . $ext;

    return ['ok' => true, 'content' => $body, 'mime' => $mime !== '' ? $mime : 'application/octet-stream', 'name' => $name];
}

/**
 * Simpan baris hasil parse ke database (dipakai save_candidate_import.php dan uji CLI).
 *
 * @param array<int, array<string, mixed>> $parsedRows hasil candidate_import_parse_file()['rows']
 * @param int|null $performedBy emp_number user yang login
 * @return array{summary:array<string,int>, rows:array<int,array<string,mixed>>}
 */
function candidate_import_apply(PDO $pdo, array $parsedRows, ?int $performedBy): array
{
    $insertCandidate = $pdo->prepare(
        'INSERT INTO ohrm_job_candidate
            (first_name, middle_name, last_name, email, contact_number, status, comment,
             mode_of_application, date_of_application, keywords, added_person, consent_to_keep_data)
         VALUES
            (:first_name, :middle_name, :last_name, :email, :contact_number, 1, :comment,
             :mode, :applied_date, NULL, :added_person, :consent)'
    );

    $insertVacancy = $pdo->prepare(
        'INSERT INTO ohrm_job_candidate_vacancy
            (candidate_id, vacancy_id, status, applied_date)
         VALUES (:candidate_id, :vacancy_id, :status, :applied_date)'
    );

    $insertHistory = $pdo->prepare(
        'INSERT INTO ohrm_job_candidate_history
            (candidate_id, vacancy_id, candidate_vacancy_name, interview_id, action,
             performed_by, performed_date, note, interviewers)
         VALUES (:candidate_id, :vacancy_id, :vacancy_name, NULL, :action, :performed_by, :performed_date, :note, NULL)'
    );

    $insertAttachment = $pdo->prepare(
        'INSERT INTO ohrm_job_candidate_attachment
            (candidate_id, file_name, file_type, file_size, file_content, attachment_type)
         VALUES (:candidate_id, :file_name, :file_type, :file_size, :file_content, NULL)'
    );

    $now = date('Y-m-d H:i:s');
    $rows = [];
    $counters = ['total' => count($parsedRows), 'inserted' => 0, 'skipped' => 0, 'failed' => 0, 'resume_attached' => 0];

    foreach ($parsedRows as $row) {
        if ($row['status'] === 'skip') {
            $counters['skipped']++;
            $rows[] = [
                'row' => $row['row'],
                'candidate_id' => null,
                'full_name' => $row['full_name'] ?? '',
                'status' => 'skip',
                'message' => $row['message'],
            ];
            continue;
        }
        if ($row['status'] === 'error') {
            $counters['failed']++;
            $rows[] = [
                'row' => $row['row'],
                'candidate_id' => null,
                'full_name' => $row['full_name'] ?? '',
                'status' => 'error',
                'message' => $row['message'],
            ];
            continue;
        }

        // Unduh resume di luar transaksi supaya transaksi DB singkat.
        $resume = null;
        $notes = [];
        if (!empty($row['resume_url'])) {
            $resume = candidate_import_download_resume($row['resume_url']);
            if (empty($resume['ok'])) {
                $notes[] = 'Resume gagal diunduh: ' . ($resume['error'] ?? 'tidak diketahui');
                $resume = null;
            }
        }

        try {
            $pdo->beginTransaction();

            $insertCandidate->execute([
                ':first_name' => $row['first_name'],
                ':middle_name' => $row['middle_name'] !== '' ? $row['middle_name'] : null,
                ':last_name' => $row['last_name'],
                ':email' => $row['email'],
                ':contact_number' => $row['contact_number'] !== '' ? $row['contact_number'] : null,
                ':comment' => $row['notes'] !== '' ? $row['notes'] : null,
                ':mode' => CANDIDATE_IMPORT_MODE_MANUAL,
                ':applied_date' => $row['date_of_application'],
                ':added_person' => $performedBy,
                ':consent' => (int) $row['consent'],
            ]);
            $candidateId = (int) $pdo->lastInsertId();

            // Riwayat: kandidat ditambahkan (action 16)
            $insertHistory->execute([
                ':candidate_id' => $candidateId,
                ':vacancy_id' => null,
                ':vacancy_name' => null,
                ':action' => CANDIDATE_IMPORT_ACTION_ADD,
                ':performed_by' => $performedBy,
                ':performed_date' => $now,
                ':note' => null,
            ]);

            // Lamaran pada vacancy
            $insertVacancy->execute([
                ':candidate_id' => $candidateId,
                ':vacancy_id' => (int) $row['vacancy_id'],
                ':status' => CANDIDATE_IMPORT_STATUS_APPLICATION_INITIATED,
                ':applied_date' => $row['date_of_application'],
            ]);

            // Riwayat: lamaran di-initiate (action 1)
            $insertHistory->execute([
                ':candidate_id' => $candidateId,
                ':vacancy_id' => (int) $row['vacancy_id'],
                ':vacancy_name' => $row['vacancy_name'],
                ':action' => CANDIDATE_IMPORT_ACTION_ATTACH,
                ':performed_by' => $performedBy,
                ':performed_date' => $now,
                ':note' => null,
            ]);

            if ($resume !== null) {
                $insertAttachment->execute([
                    ':candidate_id' => $candidateId,
                    ':file_name' => $resume['name'],
                    ':file_type' => $resume['mime'],
                    ':file_size' => strlen($resume['content']),
                    ':file_content' => $resume['content'],
                ]);
                $counters['resume_attached']++;
            }

            $pdo->commit();
            $counters['inserted']++;
            $rows[] = [
                'row' => $row['row'],
                'candidate_id' => $candidateId,
                'full_name' => $row['full_name'],
                'status' => 'inserted',
                'message' => implode('; ', $notes),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $counters['failed']++;
            $rows[] = [
                'row' => $row['row'],
                'candidate_id' => null,
                'full_name' => $row['full_name'] ?? '',
                'status' => 'error',
                'message' => 'Gagal simpan: ' . $e->getMessage(),
            ];
        }
    }

    return ['summary' => $counters, 'rows' => $rows];
}

/** Nama lengkap user OrangeHRM untuk log audit (dari hs_hr_employee). */
function candidate_import_resolve_user_name(PDO $pdo, ?int $empNumber): string
{
    if ($empNumber === null || $empNumber <= 0) {
        return 'unknown';
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT TRIM(CONCAT_WS(' ', emp_firstname, NULLIF(emp_middle_name, ''), emp_lastname))
               FROM hs_hr_employee WHERE emp_number = :emp LIMIT 1"
        );
        $stmt->execute([':emp' => $empNumber]);
        $name = trim((string) $stmt->fetchColumn());
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
        // biarkan jatuh ke fallback
    }
    return 'emp #' . $empNumber;
}
