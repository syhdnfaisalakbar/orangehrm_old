<?php
/**
 * _acumatica_activity.php — helper untuk membuat TASK di tab Activities Case
 * Acumatica (contract-based API Default/25.200.001).
 *
 * Dipakai oleh sync_application_to_acumatica.php (tombol "Sync Application to
 * Acumatica" di profil kandidat). Dipisah ke file sendiri supaya bisa diuji
 * langsung dari CLI tanpa menjalankan seluruh alur sync.
 *
 * Fakta API (diverifikasi di staging 2026-10-30):
 *   - "Activities" di Acumatica = entity `Task` (Task = CR306020).
 *   - Task terhubung ke Case lewat:
 *       RelatedEntityType   = 'PX.Objects.CR.CRCase'
 *       RelatedEntityNoteID = NoteID Case (sama dengan field `id` Case)
 *   - Field teks utama Task = `Summary` (BUKAN Subject).
 *   - Status Task yang dipakai di data: Open / Completed.
 *   - Priority: Low / Normal / High.
 *
 * AUTH: login sendiri di sini dan pakai header `Cookie:` (BUKAN cookie file).
 * Alasan: pola cookie file (CURLOPT_COOKIEJAR/COOKIEFILE) di PHP/Windows
 * menulis jar secara tertunda saat script selesai — request berikutnya di
 * script yang sama tidak membawa cookie dan dijawab 401. Diuji di lokal
 * 2026-10-30: login 204 lalu GET langsung 401 dengan pola file.
 * Dengan header Cookie hasilnya stabil (sama seperti close_case_acumatica.php).
 */

// Bisa dipakai standalone (CLI/uji) maupun dari script lain.
if (!defined('ACUMATICA_BASE_URL')) {
    require_once __DIR__ . '/_config.php';
}

/**
 * Login Acumatica, kembalikan string cookie untuk header `Cookie:`.
 *
 * @return array{ok:bool, cookie?:string, message?:string}
 */
function acumaticaActivityLogin(string $baseUrl): array
{
    $ch = curl_init($baseUrl . '/entity/auth/login/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'name'     => ACUMATICA_LOGIN_USER,
            'password' => ACUMATICA_LOGIN_PASS,
            'company'  => ACUMATICA_COMPANY,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_HEADER         => true, // tangkap Set-Cookie
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'message' => 'Gagal login Acumatica (HTTP ' . $http . ')'];
    }

    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', (string) $resp, $m);
    $pairs = [];
    foreach ($m[1] as $c) {
        $parts = explode('=', $c, 2);
        if (count($parts) === 2) {
            $pairs[] = trim($parts[0]) . '=' . trim($parts[1]);
        }
    }
    if (empty($pairs)) {
        return ['ok' => false, 'message' => 'Login sukses tapi tidak ada Set-Cookie'];
    }
    return ['ok' => true, 'cookie' => implode('; ', $pairs)];
}

/**
 * Request JSON generik ke API Acumatica memakai header Cookie.
 *
 * @return array{http:int, body:string, error:string}
 */
function acumaticaCurlJson(string $url, string $method, ?array $payload, string $cookie): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string) curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'body' => (string) $body, 'error' => $err];
}

/**
 * Request biner (upload file) ke API Acumatica memakai header Cookie.
 * Content-Type wajib application/octet-stream; sukses = HTTP 204.
 *
 * @return array{http:int, body:string, error:string}
 */
function acumaticaCurlFile(string $url, string $bytes, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Accept: application/json',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string) curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'body' => (string) $body, 'error' => $err];
}

/**
 * Bersihkan nama file supaya aman di URL Acumatica:
 * hanya huruf, angka, spasi, titik, dash, underscore. Sisanya jadi '-'.
 */
function sanitizeAttachmentName(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'attachment';
    }
    $name = preg_replace('/[^A-Za-z0-9 ._-]+/u', '-', $name);
    $name = preg_replace('/\s+/u', ' ', (string) $name);
    return trim((string) $name) !== '' ? trim((string) $name) : 'attachment';
}

/**
 * Lampirkan file ke sebuah Task yang SUDAH ADA (idempotent: kalau file
 * dengan nama sama sudah menempel, tidak di-upload ulang).
 *
 * @param array{name:string, content:string} $attachment
 * @return array{status:string, name:string, size?:int, already?:bool, http?:int, body?:string}
 */
function attachFileToTask(string $baseUrl, string $cookie, string $taskId, array $attachment): array
{
    $fileName = sanitizeAttachmentName((string) ($attachment['name'] ?? 'attachment'));
    $content  = (string) ($attachment['content'] ?? '');

    // Cek file yang sudah menempel di Task
    $taskRes = acumaticaCurlJson(
        $baseUrl . '/entity/Default/25.200.001/Task/' . $taskId . '?$select=NoteID,files&$expand=files',
        'GET',
        null,
        $cookie
    );
    if ($taskRes['http'] >= 200 && $taskRes['http'] < 300) {
        $taskData = json_decode($taskRes['body'], true);
        foreach (($taskData['files'] ?? []) as $f) {
            // Acumatica menyimpan nama sebagai "Task (NoteID)\<namafile>"
            $raw = (string) ($f['filename'] ?? '');
            $base = strpos($raw, '\\') !== false ? substr($raw, strrpos($raw, '\\') + 1) : $raw;
            if (strcasecmp(trim($base), $fileName) === 0) {
                return ['status' => 'ok', 'already' => true, 'name' => $fileName];
            }
        }
    }

    // URL upload diambil dari pola _links.files:put Task
    $filesUrl = $baseUrl . '/entity/Default/25.200.001/files/PX.Objects.CR.CRTaskMaint/Tasks/'
        . $taskId . '/' . rawurlencode($fileName);
    $up = acumaticaCurlFile($filesUrl, $content, $cookie);

    if ($up['http'] >= 200 && $up['http'] < 300) {
        return ['status' => 'ok', 'name' => $fileName, 'size' => strlen($content)];
    }
    return [
        'status' => 'error',
        'name'   => $fileName,
        'http'   => $up['http'],
        'body'   => substr($up['body'], 0, 200),
    ];
}

/**
 * Buat Task di tab Activities sebuah Case Acumatica, Summary = $summary
 * (dipakai untuk nama kandidat). Idempotent: kalau Task dengan Summary sama
 * sudah ada di Case itu, tidak dibuat ulang — TAPI file tetap dipastikan
 * menempel (Task lama bisa dibuat sebelum fitur lampiran ada).
 *
 * @param array{name:string, content:string}|null $attachment
 *        File yang ikut dilampirkan ke Task (mis. CV kandidat).
 * @return array{status:string, message:string, task_id?:string, body?:string, file?:array}
 */
function createCaseActivityTask(string $baseUrl, string $caseId, string $summary, ?array $attachment = null): array
{
    $summary = trim($summary);
    if ($summary === '') {
        return ['status' => 'skipped', 'message' => 'Nama kandidat kosong — Task tidak dibuat'];
    }

    $login = acumaticaActivityLogin($baseUrl);
    if (!$login['ok']) {
        return ['status' => 'error', 'message' => $login['message'] ?? 'Login gagal'];
    }
    $cookie = $login['cookie'];

    try {
        // 1. NoteID Case (dipakai sebagai RelatedEntityNoteID)
        $caseUrl = $baseUrl . '/entity/Default/25.200.001/Case?$filter=' . urlencode("CaseID eq '$caseId'");
        $caseRes = acumaticaCurlJson($caseUrl, 'GET', null, $cookie);
        if ($caseRes['http'] < 200 || $caseRes['http'] >= 300) {
            return ['status' => 'error', 'message' => 'Gagal ambil NoteID Case (HTTP ' . $caseRes['http'] . ')'];
        }
        $caseData = json_decode($caseRes['body'], true);
        $noteId = $caseData[0]['NoteID']['value'] ?? ($caseData[0]['id'] ?? null);
        if (!$noteId) {
            return ['status' => 'error', 'message' => 'NoteID Case tidak ditemukan'];
        }

        // 2. Dedupe: Task dengan Summary sama di Case yang sama sudah ada?
        //    CATATAN: RelatedEntityNoteID bertipe Guid, jadi di filter OData
        //    wajib pakai literal guid'...' — kalau dikirim sebagai string
        //    ('...') Acumatica balas HTTP 500 "operand types 'Edm.Guid' and
        //    'Edm.String'".
        $escSummary = str_replace("'", "''", $summary);
        $escNote    = str_replace("'", "''", (string) $noteId);
        $findUrl = $baseUrl . '/entity/Default/25.200.001/Task?$filter=' .
            urlencode("RelatedEntityNoteID eq guid'{$escNote}' and Summary eq '{$escSummary}'") . '&$top=1';
        $findRes = acumaticaCurlJson($findUrl, 'GET', null, $cookie);
        if ($findRes['http'] >= 200 && $findRes['http'] < 300) {
            $existing = json_decode($findRes['body'], true);
            if (is_array($existing) && count($existing) > 0) {
                $existingId = (string) ($existing[0]['NoteID']['value'] ?? ($existing[0]['id'] ?? ''));
                $result = [
                    'status'  => 'skipped',
                    'message' => 'Task sudah ada (tidak dibuat ulang)',
                    'task_id' => $existingId,
                    // Status task saat ini (Open/Processing/Completed/Canceled) —
                    // dipakai untuk menyimpan status ke DB OrangeHRM.
                    'task_status' => $existing[0]['Status']['value'] ?? ($existing[0]['Status'] ?? null),
                    'task_summary' => $existing[0]['Summary']['value'] ?? ($existing[0]['Summary'] ?? $summary),
                ];
                // Task lama bisa dibuat sebelum fitur lampiran ada → file
                // tetap dipastikan menempel (idempotent, cek nama file dulu).
                if ($attachment !== null && !empty($attachment['content']) && $existingId !== '') {
                    $fileInfo = attachFileToTask($baseUrl, $cookie, $existingId, $attachment);
                    $result['file'] = $fileInfo;
                    if (($fileInfo['status'] ?? '') === 'ok') {
                        $result['message'] .= !empty($fileInfo['already'])
                            ? ' | file sudah ada: ' . $fileInfo['name']
                            : ' | file dilampirkan: ' . $fileInfo['name'];
                    } else {
                        $result['message'] .= ' | file gagal dilampirkan (HTTP ' . ($fileInfo['http'] ?? '?') . ')';
                    }
                }
                return $result;
            }
        }

        // 3. Buat Task — Acumatica memakai PUT untuk CREATE (upsert pattern).
        //    POST ke entity endpoint dijawab HTTP 406 Not Acceptable (diuji
        //    2026-10-30: POST /Task -> 406 body kosong).
        $payload = [
            'Summary'             => ['value' => $summary],
            'RelatedEntityType'   => ['value' => 'PX.Objects.CR.CRCase'],
            'RelatedEntityNoteID' => ['value' => (string) $noteId],
            'Status'              => ['value' => 'Open'],
            'Priority'            => ['value' => 'Normal'],
            'StartDate'           => ['value' => date('c')],
        ];
        $create = acumaticaCurlJson($baseUrl . '/entity/Default/25.200.001/Task', 'PUT', $payload, $cookie);

        if ($create['http'] >= 200 && $create['http'] < 300) {
            $created = json_decode($create['body'], true);
            $taskId = $created['NoteID']['value'] ?? ($created['id'] ?? '');
            $result = [
                'status' => 'ok',
                'message' => 'Task Activities dibuat',
                'task_id' => (string) $taskId,
                // Task baru selalu dibuat dengan Status Open (lihat payload).
                'task_status' => $created['Status']['value'] ?? ($created['Status'] ?? 'Open'),
                'task_summary' => $summary,
            ];

            // 4. Lampirkan file ke Task (mis. CV kandidat).
            //    URL dari _links.files:put: files/PX.Objects.CR.CRTaskMaint/Tasks/{NoteID}/{filename}
            //    Content-Type: application/octet-stream, sukses = HTTP 204.
            if ($attachment !== null && !empty($attachment['content']) && $taskId !== '') {
                $fileInfo = attachFileToTask($baseUrl, $cookie, (string) $taskId, $attachment);
                $result['file'] = $fileInfo;
                if (($fileInfo['status'] ?? '') === 'ok') {
                    $result['message'] .= ' + file ' . $fileInfo['name'];
                } else {
                    $result['message'] .= ' (file gagal di-upload: HTTP ' . ($fileInfo['http'] ?? '?') . ')';
                }
            }
            return $result;
        }
        return [
            'status'  => 'error',
            'message' => 'Gagal buat Task (HTTP ' . $create['http'] . ')',
            'body'    => substr($create['body'], 0, 300),
        ];
    } finally {
        // logout best-effort — HARUS sukses, kalau tidak sesi API menumpuk dan
        // user kena "number of concurrent API logins ... reached" (SM201010).
        // Pakai CURLOPT_POST + body kosong; kalau dikirim lewat helper JSON
        // biasa (payload null) Acumatica menolak dan sesi tidak pernah tutup.
        acumaticaActivityLogout($baseUrl, $cookie);
    }
}

// ============================================================
// Pencarian Task milik sebuah Case berdasarkan nama kandidat.
//
// PENTING: jangan pakai filter OData `Summary eq '<nama>'` — Summary di
// Acumatica bisa menyimpan spasi ganda (mis. "Test Faisal  Test" karena
// middle_name kandidat berakhiran spasi), sehingga pencocokan persis gagal
// dan Task dianggap tidak ada. Ambil semua Task pada Case tersebut lalu
// bandingkan nama yang sudah dinormalisasi (spasi dirapikan, case-insensitive).
// ============================================================
function acumaticaNormalizeName(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s));
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return $s;
}

/**
 * @return array|false|null  array = task ditemukan, null = tidak ada yang cocok,
 *                           false = request ke Acumatica gagal
 */
function acumaticaFindCaseTask(string $base, string $cookie, string $caseNoteId, string $fullName)
{
    $res = acumaticaCurlJson(
        $base . '/entity/Default/25.200.001/Task?$select=NoteID,Summary,Status&$filter=' . rawurlencode("RelatedEntityNoteID eq guid'" . $caseNoteId . "'") . '&$top=200',
        'GET',
        null,
        $cookie
    );
    $http = (int)($res['http'] ?? 0);
    if ($http < 200 || $http >= 300) {
        return false;
    }
    $json = json_decode((string)($res['body'] ?? ''), true);
    if (!is_array($json)) {
        return false;
    }
    $tasks = $json['value'] ?? $json;
    if (!is_array($tasks)) {
        return null;
    }

    $target = acumaticaNormalizeName($fullName);
    $canceledMatch = null;
    foreach ($tasks as $task) {
        $summary = (string)($task['Summary']['value'] ?? ($task['Summary'] ?? ''));
        $status = (string)($task['Status']['value'] ?? ($task['Status'] ?? ''));
        if (acumaticaNormalizeName($summary) !== $target) {
            continue;
        }
        if (stripos($status, 'cancel') === false) {
            return $task; // utamakan Task yang tidak dibatalkan
        }
        $canceledMatch = $canceledMatch ?: $task;
    }

    return $canceledMatch ?: null;
}

// ============================================================
// Logout Acumatica yang BENAR.
//
// POST tanpa body ke /entity/auth/logout/ dibalas HTTP 411 (Length Required)
// oleh Acumatica → sesi TIDAK tertutup → slot "concurrent API logins"
// (SM201010) tetap terpakai sampai timeout. Wajib kirim Content-Length: 0.
// Balasan normal: HTTP 204.
// ============================================================
function acumaticaActivityLogout(string $baseUrl, string $cookie): int
{
    $ch = curl_init($baseUrl . '/entity/auth/logout/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: 0',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http;
}

// ============================================================
// Sesi Acumatica bersama (session reuse + login serialized).
//
// Masalah nyata di staging: kuota "concurrent API logins" sangat kecil
// (praktis 1). Bila dua request login hampir bersamaan, yang kedua dibalas
// HTTP 500 dan SETIAP percobaan berikutnya ikut gagal sampai sesi sebelumnya
// timeout — gejalanya persis "batas login bersamaan".
//
// Solusi: simpan cookie sesi di berkas cache (TTL di bawah), pakai flock agar
// hanya satu proses yang boleh login pada satu waktu, dan verifikasi ringan
// sebelum dipakai ulang. Semua endpoint memakai fungsi ini.
// ============================================================
function acumaticaActivitySessionCacheFile(): string
{
    $dir = __DIR__ . '/_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/acumatica_session.json';
}

/**
 * Ambil sesi Acumatica: pakai ulang cookie yang masih valid, kalau tidak ada
 * baru login (dengan retry). Aman dipanggil bersamaan (flock).
 *
 * @return array{ok:bool, cookie?:string, message?:string, reused?:bool, attempts?:int}
 */
function acumaticaActivitySession(string $baseUrl): array
{
    $cacheFile = acumaticaActivitySessionCacheFile();
    $lockFile = $cacheFile . '.lock';

    $fp = @fopen($lockFile, 'c');
    if ($fp) {
        @flock($fp, LOCK_EX);
    }

    try {
        // 1) Coba pakai sesi yang tersimpan (selalu diverifikasi ringan; TTL tidak
        // dipakai sebagai penentu utama supaya kita tidak pernah membuat sesi kedua
        // selagi sesi lama masih hidup — kuota login Acumatica bisa hanya 1).
        $cached = null;
        if (is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
        }
        if (is_array($cached) && !empty($cached['cookie'])) {
            $probe = acumaticaCurlJson(
                $baseUrl . '/entity/Default/25.200.001/Task?$select=NoteID&$top=1',
                'GET',
                null,
                (string) $cached['cookie']
            );
            $ph = (int) ($probe['http'] ?? 0);
            if ($ph >= 200 && $ph < 300) {
                @file_put_contents($cacheFile, json_encode(['cookie' => $cached['cookie'], 'ts' => time()]));
                return ['ok' => true, 'cookie' => (string) $cached['cookie'], 'reused' => true];
            }
            // Sesi mati/ditolak: tutup kalau masih bisa, lalu buang.
            acumaticaActivityLogout($baseUrl, (string) $cached['cookie']);
            @unlink($cacheFile);
        }

        // 2) Login baru, beberapa percobaan (absorbs tabrakan sesaat).
        $attempts = 0;
        $lastMessage = 'Gagal login Acumatica.';
        for ($i = 1; $i <= 4; $i++) {
            $attempts++;
            $login = acumaticaActivityLogin($baseUrl);
            if (!empty($login['ok'])) {
                @file_put_contents(
                    $cacheFile,
                    json_encode(['cookie' => $login['cookie'], 'ts' => time()])
                );
                return ['ok' => true, 'cookie' => $login['cookie'], 'reused' => false, 'attempts' => $attempts];
            }
            $lastMessage = (string) ($login['message'] ?? $lastMessage);
            if ($i < 4) {
                sleep(2);
            }
        }

        return ['ok' => false, 'message' => $lastMessage, 'attempts' => $attempts];
    } finally {
        if ($fp) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}

// ============================================================
// NOTE (Activity Type = N) pada Case
//
// Di Acumatica tidak ada entity `Note` (404 "Entity Note not found").
// Note adalah entity `Activity` dengan field `Type` = 'N' (Task = 'T',
// Event = 'E'). Pembuatan memakai PUT (POST ke entity dijawab 406).
// Supaya muncul di tab Activities milik Case, wajib mengisi
// RelatedEntityType = 'PX.Objects.CR.CRCase' + RelatedEntityNoteID = NoteID Case.
// ============================================================
function createCaseActivityNote(string $baseUrl, string $cookie, string $caseNoteId, string $summary, string $body = '', ?string $parentTaskNoteId = null): array
{
    $summary = trim($summary);
    if ($summary === '' || $caseNoteId === '') {
        return ['status' => 'skipped', 'message' => 'Summary/NoteID Case kosong'];
    }

    $escSummary = str_replace("'", "''", $summary);
    $escNote = str_replace("'", "''", $caseNoteId);

    // Dedupe: Note dengan Summary sama pada Case yang sama tidak dibuat ulang.
    $find = acumaticaCurlJson(
        $baseUrl . '/entity/Default/25.200.001/Activity?$select=NoteID,Summary,Type,Task&$filter=' .
            urlencode("RelatedEntityNoteID eq guid'{$escNote}' and Summary eq '{$escSummary}'") . '&$top=1',
        'GET',
        null,
        $cookie
    );
    if ($find['http'] >= 200 && $find['http'] < 300) {
        $found = json_decode((string) $find['body'], true);
        if (is_array($found) && count($found) > 0) {
            $existingId = (string) ($found[0]['NoteID']['value'] ?? ($found[0]['id'] ?? ''));
            $message = 'Note "' . $summary . '" sudah ada';

            // Rapikan kalau Parent Activity (field `Task`) belum menempel.
            if ($parentTaskNoteId !== null && $parentTaskNoteId !== '' && $existingId !== '') {
                $currentParent = $found[0]['Task']['value'] ?? ($found[0]['Task'] ?? '');
                if ((string) $currentParent !== $parentTaskNoteId) {
                    $upd = acumaticaCurlJson(
                        $baseUrl . '/entity/Default/25.200.001/Activity',
                        'PUT',
                        [
                            'NoteID' => ['value' => $existingId],
                            'Task'   => ['value' => $parentTaskNoteId],
                        ],
                        $cookie
                    );
                    $message .= ($upd['http'] >= 200 && $upd['http'] < 300)
                        ? ' | Parent Activity dipasang'
                        : ' | Parent Activity gagal (HTTP ' . $upd['http'] . ')';
                } else {
                    $message .= ' | Parent Activity sudah sesuai';
                }
            }

            return [
                'status'  => 'skipped',
                'message' => $message,
                'note_id' => $existingId,
                'summary' => $summary,
            ];
        }
    }

    $payload = [
        'Type'                => ['value' => 'N'],
        'Summary'             => ['value' => $summary],
        'RelatedEntityType'   => ['value' => 'PX.Objects.CR.CRCase'],
        'RelatedEntityNoteID' => ['value' => $caseNoteId],
        'Date'                => ['value' => date('c')],
    ];
    if ($body !== '') {
        $payload['Body'] = ['value' => $body];
    }
    // Parent Activity: entity Activity menyimpannya di field `Task`
    // (berisi NoteID Task induk, mis. Task "Syahruddin Test").
    if ($parentTaskNoteId !== null && $parentTaskNoteId !== '') {
        $payload['Task'] = ['value' => $parentTaskNoteId];
    }

    $create = acumaticaCurlJson($baseUrl . '/entity/Default/25.200.001/Activity', 'PUT', $payload, $cookie);
    if ($create['http'] >= 200 && $create['http'] < 300) {
        $created = json_decode((string) $create['body'], true);
        return [
            'status'  => 'ok',
            'message' => 'Note "' . $summary . '" dibuat'
                . (($parentTaskNoteId !== null && $parentTaskNoteId !== '') ? ' (Parent Activity: ' . $summary . ' task)' : ''),
            'note_id' => (string) ($created['NoteID']['value'] ?? ($created['id'] ?? '')),
            'summary' => $summary,
            'parent_task' => $parentTaskNoteId,
        ];
    }

    return [
        'status'  => 'error',
        'message' => 'Gagal buat Note (HTTP ' . $create['http'] . ')',
        'body'    => substr((string) $create['body'], 0, 300),
    ];
}

/**
 * Ubah cookie jar (format Netscape) hasil CURLOPT_COOKIEJAR menjadi header
 * `Cookie:` — dipakai skrip yang login sendiri (bukan lewat sesi bersama).
 */
function acumaticaCookieFromJar(string $jarFile): string
{
    if (!is_file($jarFile)) {
        return '';
    }
    $pairs = [];
    foreach (file($jarFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = preg_replace('/^#HttpOnly_/', '', $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) >= 7) {
            $pairs[] = $parts[5] . '=' . $parts[6];
        }
    }
    return implode('; ', $pairs);
}

/**
 * Tutup sesi bersama (cache) supaya skrip yang login sendiri (mis.
 * sync_application_to_acumatica.php dengan cookie jar) tidak bertabrakan dengan
 * kuota "concurrent API logins" Acumatica yang praktis hanya 1.
 */
function acumaticaReleaseSharedSession(): void
{
    $file = acumaticaActivitySessionCacheFile();
    if (!is_file($file)) {
        return;
    }
    $cached = json_decode((string) @file_get_contents($file), true);
    if (is_array($cached) && !empty($cached['cookie'])) {
        acumaticaActivityLogout(ACUMATICA_BASE_URL, (string) $cached['cookie']);
    }
    @unlink($file);
}

/**
 * Tulis cookie hasil sesi bersama ke file cookie jar (format Netscape) supaya
 * kode lama yang masih memakai CURLOPT_COOKIEFILE tetap jalan tanpa login baru.
 */
function acumaticaWriteJarFromCookieHeader(string $jarFile, string $baseUrl, string $cookieHeader): bool
{
    $host = parse_url($baseUrl, PHP_URL_HOST);
    if (!$host) {
        // config menyimpan base URL tanpa skema (mis. "staging.aditya.group/acumportal"),
        // parse_url hanya menganggapnya path sehingga host NULL.
        $normalized = strpos($baseUrl, '://') === false ? 'http://' . $baseUrl : $baseUrl;
        $host = parse_url($normalized, PHP_URL_HOST);
    }
    if (!$host || trim($cookieHeader) === '') {
        return false;
    }
    $lines = ['# Netscape HTTP Cookie File'];
    foreach (explode(';', $cookieHeader) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || strpos($pair, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $lines[] = $host . "\tFALSE\t/\tFALSE\t0\t" . $name . "\t" . trim($value);
    }
    if (count($lines) < 2) {
        return false;
    }
    return @file_put_contents($jarFile, implode("\n", $lines) . "\n") !== false;
}
