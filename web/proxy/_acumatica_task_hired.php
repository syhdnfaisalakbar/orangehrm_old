<?php
/**
 * _acumatica_task_hired.php
 *
 * Membuat activity bertipe NOTE berjudul "Hired" pada Case Acumatica saat
 * kandidat di-HIRE di OrangeHRM.
 *
 * Revisi 2026-11-12 (permintaan Pak): activity Hired harus bertipe NOTE (bukan
 * Task), dan Parent Activity-nya adalah task kandidat yang bersangkutan —
 * task berjudul nama kandidat, mis. "Syahdan Faisal Akbar".
 *
 * Implementasi memakai entity `Activity` (Acumatica membedakan tipe lewat field
 * `Type`: 'T' Task, 'E' Event, 'N' Note) dan field `Task` sebagai Parent
 * Activity (berisi NoteID Task induk).
 *
 * Catatan:
 *  - Note tidak punya Status/CompletedAt (bukan Task), jadi tidak diisi.
 *  - Dedupe: Summary "Hired" + Parent Task kandidat + Case yang sama. Dengan
 *    begitu satu kandidat tidak mungkin punya dua note "Hired", sementara
 *    kandidat berbeda di Case yang sama tetap dapat note masing-masing.
 *  - Semua kegagalan best-effort: hire di OrangeHRM tetap sukses walau
 *    Acumatica bermasalah.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';

if (!function_exists('acumaticaCreateHiredActivity')) {
    /**
     * @param int $candidateId ID kandidat OrangeHRM
     * @return array{status:string,message:string,case_id?:string,note_id?:string,parent_task?:string,summary?:string}
     */
    function acumaticaCreateHiredActivity(int $candidateId): array
    {
        // 1. DB
        try {
            $pdo = new PDO(
                'mysql:host=' . PROXY_DB_HOST . ';port=' . PROXY_DB_PORT . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
                PROXY_DB_USER,
                PROXY_DB_PASS
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'DB tidak tersambung: ' . $e->getMessage()];
        }

        // 2. Data kandidat + task induk + case
        $caseId = '';
        $candidateName = '';
        $vacancyName = '';
        $parentTaskId = '';
        try {
            $stmt = $pdo->prepare(
                "SELECT TRIM(CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name)) AS full_name,
                        v.name AS vacancy_name
                   FROM ohrm_job_candidate c
                   LEFT JOIN ohrm_job_candidate_vacancy cv ON cv.candidate_id = c.id
                   LEFT JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
                  WHERE c.id = :cid
                  ORDER BY (UPPER(cv.status) = 'HIRED') DESC, cv.id DESC
                  LIMIT 1"
            );
            $stmt->execute([':cid' => $candidateId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $candidateName = trim(preg_replace('/\s+/u', ' ', (string) ($row['full_name'] ?? '')));
            $vacancyName = trim((string) ($row['vacancy_name'] ?? ''));

            $stmt = $pdo->prepare(
                'SELECT task_id, case_id FROM ohrm_candidate_acumatica_task WHERE candidate_id = :cid'
            );
            $stmt->execute([':cid' => $candidateId]);
            $map = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $parentTaskId = trim((string) ($map['task_id'] ?? ''));
            $caseId = trim((string) ($map['case_id'] ?? ''));
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Gagal baca data kandidat: ' . $e->getMessage()];
        }

        if ($caseId === '') {
            return ['status' => 'skipped', 'message' => 'Kandidat belum punya Case Acumatica'];
        }
        if ($candidateName === '') {
            return ['status' => 'skipped', 'message' => 'Nama kandidat kosong', 'case_id' => $caseId];
        }
        if ($parentTaskId === '') {
            return [
                'status' => 'skipped',
                'message' => 'Task kandidat belum ada di Acumatica, Parent Activity tidak bisa ditentukan',
                'case_id' => $caseId,
            ];
        }

        $base = rtrim(ACUMATICA_BASE_URL, '/');
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'http://' . $base;
        }
        $activityEntity = $base . '/entity/Default/25.200.001/Activity';

        // 3. Sesi bersama (kuota concurrent login kecil — jangan login tiap klik)
        $sess = acumaticaActivitySession($base);
        if (empty($sess['ok'])) {
            return ['status' => 'error', 'message' => 'Gagal login Acumatica: ' . ($sess['message'] ?? ''), 'case_id' => $caseId];
        }
        $cookie = (string) $sess['cookie'];

        // 4. NoteID Case (RelatedEntityNoteID wajib guid)
        $caseRes = acumaticaCurlJson(
            $base . '/entity/Default/25.200.001/Case?' . ('$filter=' . rawurlencode("CaseID eq '$caseId'")),
            'GET',
            null,
            $cookie
        );
        if ($caseRes['http'] < 200 || $caseRes['http'] >= 300) {
            return ['status' => 'error', 'message' => 'Gagal ambil Case (HTTP ' . $caseRes['http'] . ')', 'case_id' => $caseId];
        }
        $caseData = json_decode((string) $caseRes['body'], true);
        $caseNoteId = $caseData[0]['NoteID']['value'] ?? ($caseData[0]['id'] ?? null);
        if (!$caseNoteId) {
            return ['status' => 'error', 'message' => 'NoteID Case tidak ditemukan', 'case_id' => $caseId];
        }

        // 5. Dedupe: note "Hired" dengan Parent Task kandidat ini sudah ada?
        $escCase = str_replace("'", "''", (string) $caseNoteId);
        $escParent = str_replace("'", "''", $parentTaskId);
        // Catatan: filter `Summary eq ... and Task eq guid'...'` TIDAK bekerja pada
        // entity Activity (Acumatica balas HTTP 500 untuk `Task`, dan filter
        // Summary tidak menyaring apa pun). Yang reliable hanya
        // `RelatedEntityNoteID eq guid'<case>'`; sisa pencocokan dilakukan di PHP.
        $findNote = function (string $findCookie) use ($activityEntity, $escCase, $parentTaskId): ?array {
            $f = "RelatedEntityNoteID eq guid'{$escCase}'";
            $res = acumaticaCurlJson($activityEntity . '?$filter=' . rawurlencode($f) . '&$top=200', 'GET', null, $findCookie);
            if (!($res['http'] >= 200 && $res['http'] < 300)) {
                return null;
            }
            $rows = json_decode((string) $res['body'], true);
            if (!is_array($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (strcasecmp(trim((string) ($row['Summary']['value'] ?? '')), 'Hired') !== 0) {
                    continue;
                }
                $taskRef = $row['Task']['value'] ?? ($row['Task'] ?? '');
                if (is_array($taskRef)) {
                    $taskRef = $taskRef['value'] ?? ($taskRef['id'] ?? '');
                }
                if ((string) $taskRef === $parentTaskId) {
                    return $row;
                }
            }
            return null;
        };

        $existing = $findNote($cookie);
        if ($existing !== null) {
            return [
                'status' => 'skipped',
                'message' => 'Note Hired untuk kandidat ini sudah ada',
                'case_id' => $caseId,
                'note_id' => (string) ($existing['NoteID']['value'] ?? ($existing['id'] ?? '')),
                'parent_task' => $parentTaskId,
                'summary' => 'Hired',
            ];
        }

        // 6. Body note
        $body = "Kandidat: {$candidateName}\n"
            . ($vacancyName !== '' ? "Vacancy: {$vacancyName}\n" : '')
            . "Status: HIRED\n"
            . 'Sumber: OrangeHRM (otomatis ' . date('d-m-Y H:i') . ')';

        $payload = [
            'Type' => ['value' => 'N'],
            'Summary' => ['value' => 'Hired'],
            'Body' => ['value' => $body],
            'RelatedEntityType' => ['value' => 'PX.Objects.CR.CRCase'],
            'RelatedEntityNoteID' => ['value' => (string) $caseNoteId],
            'Date' => ['value' => date('c')],
            // Parent Activity = Task kandidat (mis. "Syahdan Faisal Akbar")
            'Task' => ['value' => $parentTaskId],
        ];

        $create = acumaticaCurlJson($activityEntity, 'PUT', $payload, $cookie);

        if (!($create['http'] >= 200 && $create['http'] < 300)) {
            // HTTP 0 / koneksi putus bisa terjadi SETELAH Acumatica menyimpan
            // data. Cek dulu sebelum retry supaya note tidak dobel.
            $maybe = $findNote($cookie);
            if ($maybe !== null) {
                return [
                    'status' => 'ok',
                    'message' => 'Note Hired sudah dibuat (koneksi terputus setelah simpan)',
                    'case_id' => $caseId,
                    'note_id' => (string) ($maybe['NoteID']['value'] ?? ($maybe['id'] ?? '')),
                    'parent_task' => $parentTaskId,
                    'summary' => 'Hired',
                ];
            }

            // Sesi bersama bisa sudah tidak valid walau GET tadi lolos.
            $fresh = acumaticaActivityLogin($base);
            if (!empty($fresh['ok'])) {
                $freshCookie = (string) $fresh['cookie'];
                $create = acumaticaCurlJson($activityEntity, 'PUT', $payload, $freshCookie);
                acumaticaActivityLogout($base, $freshCookie);
            }
        }

        if ($create['http'] >= 200 && $create['http'] < 300) {
            $created = json_decode((string) $create['body'], true);
            return [
                'status' => 'ok',
                'message' => 'Note "Hired" dibuat untuk ' . $candidateName,
                'case_id' => $caseId,
                'note_id' => (string) ($created['NoteID']['value'] ?? ($created['id'] ?? '')),
                'parent_task' => $parentTaskId,
                'summary' => 'Hired',
                'body' => $body,
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Gagal buat note Hired (HTTP ' . $create['http'] . ')',
            'case_id' => $caseId,
            'parent_task' => $parentTaskId,
            'body' => substr((string) $create['body'], 0, 300),
        ];
    }
}
