<?php
require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';
require_once __DIR__ . '/_candidate_status_sync.php';
// ============================================
// sync_candidates_task_status.php
//
// Tarik status Task Acumatica untuk BANYAK kandidat sekaligus dengan SATU login
// Acumatica (dipakai tombol toolbar "Sync Acumatica" di Recruitment -> Candidates).
// Satu login untuk semua kandidat penting karena Acumatica membatasi login
// bersamaan (SM201010): tombol per-baris akan memakan 1 login per baris.
//
// GET /proxy/sync_candidates_task_status.php[?candidate_ids=1,2,3]
// Response:
//   {status:'ok', total, updated:[{candidate_id,task_status,summary,task_id}],
//    failed:[{candidate_id,message}], login:'ok'}
// ============================================
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

function batchRespond(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function batchUpsert(PDO $pdo, int $candidateId, ?string $caseId, array $data): void
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
        ':task_id' => (string)($data['task_id'] ?? ''),
        ':summary' => (string)($data['summary'] ?? ''),
        ':status'  => (string)($data['task_status'] ?? ''),
    ]);
}

// ---------- 1. Daftar kandidat + case ----------
$ids = [];
if (isset($_GET['candidate_ids']) && $_GET['candidate_ids'] !== '') {
    foreach (explode(',', (string)$_GET['candidate_ids']) as $v) {
        $v = (int)trim($v);
        if ($v > 0) {
            $ids[] = $v;
        }
    }
    $ids = array_values(array_unique($ids));
}

try {
    $pdo = new PDO(
        'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
        PROXY_DB_USER,
        PROXY_DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    batchRespond(['status' => 'error', 'message' => 'Koneksi database gagal.']);
}

$sql = "SELECT c.id AS candidate_id,
               TRIM(CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name)) AS full_name,
               (SELECT v.case_id
                  FROM ohrm_job_candidate_vacancy cv
                  JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
                 WHERE cv.candidate_id = c.id
                 ORDER BY cv.applied_date DESC, cv.id DESC
                 LIMIT 1) AS case_id
          FROM ohrm_job_candidate c";
$params = [];
if (count($ids) > 0) {
    $sql .= " WHERE c.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
    $params = $ids;
}
$sql .= " ORDER BY c.id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    batchRespond(['status' => 'error', 'message' => 'Gagal membaca data kandidat.']);
}

if (!$candidates) {
    batchRespond(['status' => 'ok', 'total' => 0, 'updated' => [], 'failed' => [], 'login' => 'skipped']);
}

// task_id tersimpan (kalau ada) -> tidak perlu query Case
$storedTasks = [];
foreach ($pdo->query("SELECT candidate_id, task_id FROM ohrm_candidate_acumatica_task") as $r) {
    if (!empty($r['task_id'])) {
        $storedTasks[(int)$r['candidate_id']] = (string)$r['task_id'];
    }
}

// ---------- 1b. Mode OTOMATIS (dipicu saat halaman dibuka/di-refresh) ----------
// Throttle: maksimal 1x per 60 detik, dan tidak menumpuk dengan proses lain.
// Tombol manual (tanpa ?auto=1) selalu dijalankan.
$isAuto = (($_GET['auto'] ?? '') === '1');
if ($isAuto) {
    $cacheDir = __DIR__ . '/_cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $marker = $cacheDir . '/auto_sync_last.txt';
    $lockFile = $cacheDir . '/auto_sync.lock';
    $throttleSeconds = 30;

    $age = is_file($marker) ? (time() - (int) @filemtime($marker)) : PHP_INT_MAX;
    if ($age < $throttleSeconds) {
        batchRespond([
            'status'                => 'skipped',
            'reason'                => 'throttled',
            'last_sync_seconds_ago' => $age,
        ]);
    }

    $lockHandle = @fopen($lockFile, 'c');
    if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        batchRespond(['status' => 'skipped', 'reason' => 'busy']);
    }
    // Tandai SEBELUM bekerja supaya request berikutnya tidak ikut login.
    @touch($marker);
}

// ---------- 2. Sesi bersama (pakai ulang kalau masih valid) ----------
$base = ACUMATICA_BASE_URL;
$login = acumaticaActivitySession($base);
if (empty($login['ok'])) {
    $msg = (string)($login['message'] ?? 'Gagal login Acumatica.');
    if (strpos($msg, 'HTTP 500') !== false) {
        $msg = 'Acumatica menolak login (batas login bersamaan / server sibuk). Coba lagi beberapa menit.';
    }
    batchRespond(['status' => 'error', 'message' => $msg]);
}
$cookie = $login['cookie'];

$updated = [];
$failed = [];
$caseNoteCache = [];

try {
    foreach ($candidates as $cand) {
        $candidateId = (int)$cand['candidate_id'];
        $fullName = trim(preg_replace('/\s+/u', ' ', (string)$cand['full_name']));
        $caseId = trim((string)($cand['case_id'] ?? ''));

        if ($caseId === '') {
            $failed[] = ['candidate_id' => $candidateId, 'message' => 'Kandidat belum terhubung ke vacancy/case.'];
            continue;
        }

        $taskData = null;

        // Jalur 1: task_id sudah tersimpan -> GET /Task/{id} (tanpa Case)
        if (!empty($storedTasks[$candidateId])) {
            $res = acumaticaCurlJson(
                $base . '/entity/Default/25.200.001/Task/' . rawurlencode($storedTasks[$candidateId]) . '?$select=NoteID,Summary,Status',
                'GET',
                null,
                $cookie
            );
            $http = (int)($res['http'] ?? 0);
            if ($http >= 200 && $http < 300) {
                $tj = json_decode((string)($res['body'] ?? ''), true);
                if (is_array($tj)) {
                    $taskData = [
                        'task_id'     => $tj['NoteID']['value'] ?? ($tj['NoteID'] ?? $storedTasks[$candidateId]),
                        'summary'     => $tj['Summary']['value'] ?? ($tj['Summary'] ?? $fullName),
                        'task_status' => $tj['Status']['value'] ?? ($tj['Status'] ?? null),
                    ];
                }
            }
        }

        // Jalur 2: cari lewat Case (NoteID di-cache per case)
        if ($taskData === null) {
            if (!array_key_exists($caseId, $caseNoteCache)) {
                $caseRes = acumaticaCurlJson(
                    $base . '/entity/Default/25.200.001/Case?$select=NoteID,CaseID&$filter=' . rawurlencode("CaseID eq '" . $caseId . "'") . '&$top=1',
                    'GET',
                    null,
                    $cookie
                );
                $caseHttp = (int)($caseRes['http'] ?? 0);
                if ($caseHttp >= 200 && $caseHttp < 300) {
                    $cj = json_decode((string)($caseRes['body'] ?? ''), true);
                    $row = $cj[0] ?? ($cj['value'][0] ?? null);
                    $caseNoteCache[$caseId] = $row['NoteID']['value'] ?? ($row['NoteID'] ?? false);
                } else {
                    $cj = json_decode((string)($caseRes['body'] ?? ''), true);
                    $detail = $cj['exceptionMessage'] ?? ('HTTP ' . $caseHttp);
                    $detail = trim(preg_split('/\r?\n/', (string)$detail)[0]);
                    $caseNoteCache[$caseId] = 'ERR: ' . $detail;
                }
            }

            $noteId = $caseNoteCache[$caseId];
            if (is_string($noteId) && strpos($noteId, 'ERR: ') === 0) {
                $failed[] = ['candidate_id' => $candidateId, 'message' => substr($noteId, 5)];
                continue;
            }
            if (!$noteId) {
                $failed[] = ['candidate_id' => $candidateId, 'message' => 'Case ' . $caseId . ' tidak ditemukan di Acumatica.'];
                continue;
            }

            // Cocokkan nama secara ternormalisasi (Summary bisa memuat spasi ganda)
            $task = acumaticaFindCaseTask($base, $cookie, $noteId, $fullName);
            if ($task === false) {
                $failed[] = ['candidate_id' => $candidateId, 'message' => 'Gagal membaca Task dari Acumatica.'];
                continue;
            }
            if ($task === null) {
                $failed[] = ['candidate_id' => $candidateId, 'message' => 'Belum ada Task Acumatica atas nama kandidat ini.'];
                continue;
            }
            $taskData = [
                'task_id'     => $task['NoteID']['value'] ?? ($task['NoteID'] ?? null),
                'summary'     => $task['Summary']['value'] ?? ($task['Summary'] ?? $fullName),
                'task_status' => $task['Status']['value'] ?? ($task['Status'] ?? null),
            ];
        }

        if ($taskData === null || empty($taskData['task_status'])) {
            $failed[] = ['candidate_id' => $candidateId, 'message' => 'Status Task tidak terbaca.'];
            continue;
        }

        try {
            batchUpsert($pdo, $candidateId, $caseId, $taskData);
            // Selaraskan status kandidat OrangeHRM dengan status Task Acumatica:
            // Canceled -> REJECTED, aktif lagi -> dipulihkan ke status sebelumnya.
            $statusSync = ['action' => 'none', 'from' => null, 'to' => null];
            try {
                $statusSync = acumaticaApplyCandidateStatusFromTask(
                    $pdo,
                    $candidateId,
                    $caseId,
                    $taskData['task_status']
                );
            } catch (\Throwable $e) {
                // jangan gagalkan sync hanya karena update status kandidat
            }
            $updated[] = [
                'candidate_id' => $candidateId,
                'task_status'  => $taskData['task_status'],
                'summary'      => $taskData['summary'],
                'task_id'      => $taskData['task_id'],
                'cv_action'    => $statusSync['action'],
                'cv_from'      => $statusSync['from'],
                'cv_to'        => $statusSync['to'],
            ];
            // simpan untuk iterasi berikutnya (hindari GET ulang bila id sama)
            $storedTasks[$candidateId] = (string)$taskData['task_id'];
        } catch (\Throwable $e) {
            $failed[] = ['candidate_id' => $candidateId, 'message' => 'Gagal simpan ke DB.'];
        }
    }
} finally {
    // Sesi TIDAK di-logout di sini: cookie disimpan di cache (TTL 8 menit) dan
    // dipakai ulang oleh Sync/Refresh berikutnya. Login berulang + kuota login
    // Acumatica yang kecil itulah yang dulu memicu "batas login bersamaan".
}

batchRespond([
    'status'  => 'ok',
    'total'   => count($candidates),
    'updated' => $updated,
    'failed'  => $failed,
    'login'   => 'ok',
]);
