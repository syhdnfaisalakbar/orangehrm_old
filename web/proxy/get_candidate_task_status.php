<?php
require_once __DIR__ . '/_config.php';
// ============================================
// get_candidate_task_status.php
//
// Status TASK (tab Activities) Acumatica untuk seorang kandidat recruitment.
// Dipakai baris "Acumatica Task Status: ..." di kartu Application Stage
// (menu Recruitment -> Candidates -> profil kandidat).
//
// DESAIN: baca dari DB (cepat, tanpa login Acumatica). Status diperbarui:
//   - otomatis setiap "Sync Application to Acumatica" (sync_application_to_acumatica.php, bagian 7d)
//   - manual lewat ?refresh=1 (tombol Refresh di profil kandidat)
// Alasan tidak query Acumatica tiap buka profil: Acumatica membatasi login
// bersamaan dan login bisa makan puluhan detik (diuji 2026-11-02: pernah 60s,
// lalu HTTP 500 "concurrent API logins"), jadi login per page-view tidak layak.
//
// GET /proxy/get_candidate_task_status.php?candidate_id=2[&refresh=1]
// Response:
//   {status:'ok', candidate_id, case_id, task_id, task_status, summary, synced_at, refreshed}
//   {status:'no_case'}  -> kandidat belum terhubung ke vacancy/case
//   {status:'no_task'}  -> belum ada Task atas nama kandidat / belum pernah sync
//   {status:'error', message}
// ============================================
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

function candTaskRespond(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

function candTaskUpsert(PDO $pdo, int $candidateId, string $caseId, array $data): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO ohrm_candidate_acumatica_task
            (candidate_id, case_id, task_id, task_summary, task_status, synced_at)
         VALUES (:cid, :case_id, :task_id, :summary, :status, NOW())
         ON DUPLICATE KEY UPDATE
            case_id = VALUES(case_id),
            task_id = VALUES(task_id),
            task_summary = VALUES(task_summary),
            task_status = VALUES(task_status),
            synced_at = NOW()"
    );
    $stmt->execute([
        ':cid'     => $candidateId,
        ':case_id' => $caseId,
        ':task_id' => (string) ($data['task_id'] ?? ''),
        ':summary' => (string) ($data['summary'] ?? ''),
        ':status'  => (string) ($data['task_status'] ?? ''),
    ]);
}

$candidateId = isset($_GET['candidate_id']) ? (int)$_GET['candidate_id'] : 0;
$forceRefresh = isset($_GET['refresh'])
    && !in_array(strtolower((string)$_GET['refresh']), ['', '0', 'false'], true);

if ($candidateId <= 0) {
    candTaskRespond(['status' => 'error', 'message' => 'candidate_id wajib diisi.']);
}

// ---------- Koneksi + data kandidat ----------
try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    candTaskRespond(['status' => 'error', 'message' => 'Koneksi database gagal.']);
}

try {
    $stmt = $pdo->prepare(
        "SELECT c.id,
                c.first_name,
                COALESCE(c.middle_name, '') AS middle_name,
                c.last_name,
                v.id   AS vacancy_id,
                v.name AS vacancy_name,
                v.case_id
         FROM ohrm_job_candidate c
         LEFT JOIN ohrm_job_candidate_vacancy cv ON cv.candidate_id = c.id
         LEFT JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
         WHERE c.id = :id
         ORDER BY cv.applied_date DESC, cv.id DESC
         LIMIT 1"
    );
    $stmt->execute([':id' => $candidateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    candTaskRespond(['status' => 'error', 'message' => 'Gagal membaca data kandidat.']);
}

if (!$row) {
    candTaskRespond(['status' => 'error', 'message' => 'Kandidat tidak ditemukan.']);
}

$fullName = trim(preg_replace('/\s+/u', ' ', $row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']));
$caseId = trim((string)($row['case_id'] ?? ''));

// ---------- Tanggal cache DB ----------
function candTaskRows(PDO $pdo, int $candidateId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT candidate_id, case_id, task_id, task_summary AS summary, task_status, synced_at,
                TIMESTAMPDIFF(SECOND, synced_at, NOW()) AS age_sec
         FROM ohrm_candidate_acumatica_task WHERE candidate_id = :cid LIMIT 1"
    );
    $stmt->execute([':cid' => $candidateId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

$stored = candTaskRows($pdo, $candidateId);

// Tanpa refresh: cukup kembalikan yang tersimpan (instan).
if (!$forceRefresh) {
    if ($caseId === '') {
        candTaskRespond([
            'status'       => 'no_case',
            'candidate_id' => $candidateId,
            'message'      => 'Kandidat belum terhubung ke vacancy/case Acumatica.',
        ]);
    }
    if (!$stored || ($stored['task_status'] ?? '') === '') {
        candTaskRespond([
            'status'       => 'no_task',
            'candidate_id' => $candidateId,
            'case_id'      => $caseId,
            'message'      => 'Belum ada status Task tersimpan (klik Refresh atau Sync Application).',
        ]);
    }
    candTaskRespond([
        'status'       => 'ok',
        'candidate_id' => $candidateId,
        'case_id'      => (string)($stored['case_id'] ?? $caseId),
        'task_id'      => $stored['task_id'],
        'summary'      => $stored['summary'],
        'task_status'  => $stored['task_status'],
        'synced_at'    => $stored['synced_at'],
        'age_seconds'  => isset($stored['age_sec']) ? (int)$stored['age_sec'] : null,
        'stale'        => isset($stored['age_sec']) ? ((int)$stored['age_sec'] > 300) : true,
        'refreshed'    => false,
    ]);
}

// ---------- Refresh: ambil ulang dari Acumatica ----------
if ($caseId === '') {
    candTaskRespond([
        'status'       => 'no_case',
        'candidate_id' => $candidateId,
        'message'      => 'Kandidat belum terhubung ke vacancy/case Acumatica.',
    ]);
}

require_once __DIR__ . '/_acumatica_activity.php';
require_once __DIR__ . '/_candidate_status_sync.php';

$base = ACUMATICA_BASE_URL;
$login = acumaticaActivitySession($base);
if (empty($login['ok'])) {
    $msg = (string)($login['message'] ?? 'Gagal login Acumatica.');
    // Acumatica membatasi login bersamaan (SM201010): balasannya HTTP 500.
    if (strpos($msg, 'HTTP 500') !== false) {
        $msg = 'Acumatica menolak login (batas login bersamaan / server sibuk). Coba Refresh lagi beberapa menit.';
    }
    candTaskRespond([
        'status'       => 'error',
        'candidate_id' => $candidateId,
        'message'      => $msg,
    ]);
}
$cookie = $login['cookie'];

// ---------- Jalur 1: task_id sudah tersimpan -> baca Task langsung ----------
// Tidak perlu query entity Case sama sekali. Ini juga menyelamatkan fitur saat
// entity Case sedang bermasalah di server Acumatica (pernah: HTTP 500
// "Invalid column name [CRCase].[UsrApprovalStatus]" karena customization rusak).
$data = null;
$storedTaskId = trim((string)($stored['task_id'] ?? ''));
if ($storedTaskId !== '') {
    $taskDirect = acumaticaCurlJson(
        $base . '/entity/Default/25.200.001/Task/' . rawurlencode($storedTaskId) . '?$select=NoteID,Summary,Status',
        'GET',
        null,
        $cookie
    );
    $directHttp = (int)($taskDirect['http'] ?? 0);
    if ($directHttp >= 200 && $directHttp < 300) {
        $tj = json_decode((string)($taskDirect['body'] ?? ''), true);
        if (is_array($tj)) {
            $data = [
                'task_id'     => $tj['NoteID']['value'] ?? ($tj['NoteID'] ?? $storedTaskId),
                'summary'     => $tj['Summary']['value'] ?? ($tj['Summary'] ?? ($stored['summary'] ?? $fullName)),
                'task_status' => $tj['Status']['value'] ?? ($tj['Status'] ?? null),
            ];
        }
    }
}

// Jalur 1 berhasil -> logout (WAJIB; kalau tidak, sesi API menggantung dan
// memicu "The number of concurrent API logins ... has been reached" SM201010).
if ($data !== null) {
    // sesi dipakai ulang dari cache (lihat acumaticaActivitySession)
}

// ---------- Jalur 2: belum ada task_id -> cari lewat Case ----------
if ($data === null) {
    $caseRes = acumaticaCurlJson(
        $base . '/entity/Default/25.200.001/Case?$select=NoteID,CaseID&$filter=' . rawurlencode("CaseID eq '" . $caseId . "'") . '&$top=1',
        'GET',
        null,
        $cookie
    );
    $caseHttp = (int)($caseRes['http'] ?? 0);
    $caseJson = json_decode((string)($caseRes['body'] ?? ''), true);

    // Error server (bukan "case tidak ada") harus dilaporkan apa adanya.
    if ($caseHttp < 200 || $caseHttp >= 300) {
        // sesi dipakai ulang dari cache (lihat acumaticaActivitySession)
        $detail = $caseJson['exceptionMessage'] ?? ($caseJson['message'] ?? 'HTTP ' . $caseHttp);
        $detail = trim(preg_split('/\r?\n/', (string)$detail)[0]);
        candTaskRespond([
            'status'       => 'error',
            'candidate_id' => $candidateId,
            'case_id'      => $caseId,
            'message'      => 'Acumatica gagal membaca Case ' . $caseId . ': ' . $detail,
        ]);
    }

    $caseRow = $caseJson[0] ?? ($caseJson['value'][0] ?? null);
    $caseNoteId = $caseRow['NoteID']['value'] ?? ($caseRow['NoteID'] ?? null);

    if (!$caseNoteId) {
        // sesi dipakai ulang dari cache (lihat acumaticaActivitySession)
        candTaskRespond([
            'status'       => 'no_case',
            'candidate_id' => $candidateId,
            'case_id'      => $caseId,
            'message'      => 'Case ' . $caseId . ' tidak ditemukan di Acumatica.',
        ]);
    }

    // Task milik case tersebut, dicocokkan dengan nama kandidat yang dinormalisasi
    // (jangan filter `Summary eq`: Summary bisa memuat spasi ganda).
    $foundTask = acumaticaFindCaseTask($base, $cookie, $caseNoteId, $fullName);

    // logout wajib (Acumatica membatasi login bersamaan)
    // sesi dipakai ulang dari cache (lihat acumaticaActivitySession)

    if ($foundTask === false) {
        candTaskRespond(['status' => 'error', 'message' => 'Respons Task Acumatica tidak valid.']);
    }
    if ($foundTask === null) {
        candTaskRespond([
            'status'       => 'no_task',
            'candidate_id' => $candidateId,
            'case_id'      => $caseId,
            'full_name'    => $fullName,
            'message'      => 'Belum ada Task Acumatica atas nama kandidat ini.',
        ]);
    }

    $task = $foundTask;
    $data = [
        'task_id'     => $task['NoteID']['value'] ?? ($task['NoteID'] ?? null),
        'summary'     => $task['Summary']['value'] ?? ($task['Summary'] ?? $fullName),
        'task_status' => $task['Status']['value'] ?? ($task['Status'] ?? null),
    ];
}

try {
    candTaskUpsert($pdo, $candidateId, $caseId, $data);
} catch (\Throwable $e) {
    // best-effort: tetap kembalikan hasil fetch walau gagal simpan
}

// Selaraskan status kandidat dengan status Task: Canceled -> REJECTED,
// aktif kembali -> dipulihkan ke status sebelumnya.
$statusSync = ['action' => 'none', 'from' => null, 'to' => null];
try {
    $statusSync = acumaticaApplyCandidateStatusFromTask($pdo, $candidateId, $caseId, $data['task_status'] ?? null);
} catch (\Throwable $e) {
    // jangan gagalkan refresh hanya karena update status kandidat
}

// Baca ulang dari DB supaya synced_at konsisten dengan zona waktu MySQL
// (jangan pakai date() PHP: bisa beda jam dengan NOW() MySQL).
$afterSave = candTaskRows($pdo, $candidateId);

candTaskRespond([
    'status'       => 'ok',
    'candidate_id' => $candidateId,
    'case_id'      => $caseId,
    'task_id'      => $data['task_id'],
    'summary'      => $data['summary'],
    'task_status'  => $data['task_status'],
    'synced_at'    => $afterSave['synced_at'] ?? null,
    'age_seconds'  => 0,
    'stale'        => false,
    'refreshed'    => true,
    'cv_action'    => $statusSync['action'],
    'cv_from'      => $statusSync['from'],
    'cv_to'        => $statusSync['to'],
]);
