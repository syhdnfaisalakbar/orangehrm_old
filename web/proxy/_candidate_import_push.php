<?php
/**
 * _candidate_import_push.php — push kandidat hasil import Excel ke Acumatica.
 *
 * Dipakai save_candidate_import.php saat opsi "Push ke Acumatica" dicentang.
 *
 * Strategi (disepakati Pak, 2026-11-12):
 *  - Description Case ditulis SEKALI per Case, bukan sekali per kandidat, karena
 *    isinya selalu seluruh aplikasi pada Case itu. Caranya: panggil endpoint
 *    sync_application_to_acumatica.php sekali untuk kandidat pertama di Case
 *    tersebut (endpoint itu sudah menangani Description + blok kuota +
 *    auto-close vacancy/MPR + Task + Note + simpan status Task ke DB).
 *  - Kandidat berikutnya di Case yang sama: buat/cari Task (Summary = nama
 *    kandidat, CV dilampirkan) lalu kirim Note status — fungsi yang sama yang
 *    dipakai sync_application_to_acumatica.php bagian 7c/7e.
 *  - Semua best-effort: kegagalan push TIDAK membatalkan kandidat yang sudah
 *    masuk, hanya dicatat per baris.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';

/** URL endpoint sync_application_to_acumatica.php di server yang sama. */
function candidate_import_push_endpoint(): string
{
    $override = getenv('CANDIDATE_IMPORT_PUSH_URL');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        $scheme = 'https';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/orangehrm/web/proxy/save_candidate_import.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . $dir . '/sync_application_to_acumatica.php';
}

/** Panggil endpoint sync_application_to_acumatica.php (internal HTTP). */
function candidate_import_push_call(string $url, int $candidateId, int $vacancyId): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'candidate_id' => $candidateId,
            'vacancy_id' => $vacancyId,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 'error', 'message' => 'Gagal memanggil endpoint push: ' . $err];
    }
    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        return ['status' => 'error', 'message' => 'Respons push tidak valid (HTTP ' . $http . ')'];
    }
    if (($json['status'] ?? '') === 'error') {
        return ['status' => 'error', 'message' => (string) ($json['message'] ?? 'Push gagal')];
    }
    if ($http < 200 || $http >= 300) {
        return ['status' => 'error', 'message' => 'Push gagal (HTTP ' . $http . ')'];
    }

    return ['status' => 'ok', 'message' => (string) ($json['message'] ?? 'Push berhasil'), 'raw' => $json];
}

/** CV terbaru kandidat untuk dilampirkan ke Task. */
function candidate_import_latest_attachment(PDO $pdo, int $candidateId): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT file_name, file_content FROM ohrm_job_candidate_attachment
              WHERE candidate_id = :cid AND file_content IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':cid' => $candidateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['file_content'])) {
            return ['name' => (string) $row['file_name'], 'content' => $row['file_content']];
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/** Simpan status Task ke tabel lokal (sumber kolom Acumatica Task Status). */
function candidate_import_upsert_task(PDO $pdo, int $candidateId, string $caseId, array $task): void
{
    if (empty($task['task_id'])) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO ohrm_candidate_acumatica_task
            (candidate_id, case_id, task_id, task_summary, task_status, synced_at)
         VALUES (:cid, :case_id, :task_id, :summary, :status, NOW())
         ON DUPLICATE KEY UPDATE
            case_id = VALUES(case_id),
            task_id = VALUES(task_id),
            task_summary = VALUES(task_summary),
            task_status = VALUES(task_status),
            synced_at = NOW()'
    );
    $stmt->execute([
        ':cid' => $candidateId,
        ':case_id' => $caseId,
        ':task_id' => (string) $task['task_id'],
        ':summary' => (string) ($task['task_summary'] ?? ''),
        ':status' => (string) ($task['task_status'] ?? ''),
    ]);
}

/**
 * Push kandidat hasil import ke Acumatica.
 *
 * @param array<int, int> $candidateIds
 * @return array<int, array{candidate_id:int, push_status:string, push_message:string}>
 */
function candidate_import_push(PDO $pdo, array $candidateIds): array
{
    $candidateIds = array_values(array_unique(array_filter(array_map('intval', $candidateIds))));
    if (empty($candidateIds)) {
        return [];
    }

    require_once __DIR__ . '/_candidate_status_sync.php';

    $in = implode(',', array_fill(0, count($candidateIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT c.id AS candidate_id,
                TRIM(CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name)) AS full_name,
                (SELECT cv.vacancy_id
                   FROM ohrm_job_candidate_vacancy cv
                  WHERE cv.candidate_id = c.id
                  ORDER BY (UPPER(cv.status) = 'HIRED') DESC, cv.id DESC
                  LIMIT 1) AS vacancy_id,
                (SELECT v.case_id
                   FROM ohrm_job_candidate_vacancy cv
                   JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
                  WHERE cv.candidate_id = c.id
                  ORDER BY (UPPER(cv.status) = 'HIRED') DESC, cv.id DESC
                  LIMIT 1) AS case_id
           FROM ohrm_job_candidate c
          WHERE c.id IN ($in)
          ORDER BY c.id"
    );
    $stmt->execute($candidateIds);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    $groups = [];
    foreach ($candidates as $row) {
        $cid = (int) $row['candidate_id'];
        $caseId = trim((string) ($row['case_id'] ?? ''));
        $vacancyId = (int) ($row['vacancy_id'] ?? 0);
        if ($caseId === '') {
            $results[$cid] = [
                'candidate_id' => $cid,
                'push_status' => 'skipped',
                'push_message' => 'Vacancy kandidat belum punya Case ID — push dilewati',
            ];
            continue;
        }
        $groups[$caseId][] = [
            'candidate_id' => $cid,
            'full_name' => trim(preg_replace('/\s+/u', ' ', (string) $row['full_name'])),
            'vacancy_id' => $vacancyId,
        ];
    }

    $endpoint = candidate_import_push_endpoint();
    $base = rtrim(ACUMATICA_BASE_URL, '/');
    if (!preg_match('#^https?://#i', $base)) {
        $base = 'http://' . $base;
    }

    foreach ($groups as $caseId => $members) {
        // 1. Panggil endpoint sync sekali per Case (Description + kuota + auto-close
        //    + Task + Note kandidat pertama).
        $anchor = $members[0];
        $call = candidate_import_push_call($endpoint, (int) $anchor['candidate_id'], (int) $anchor['vacancy_id']);

        // Kalau gagal karena Acumatica (mis. kuota login), jangan ulangi untuk
        // semua anggota — cukup tandai satu Case gagal.
        $results[(int) $anchor['candidate_id']] = [
            'candidate_id' => (int) $anchor['candidate_id'],
            'push_status' => $call['status'],
            'push_message' => $call['message'],
        ];

        // 2. Anggota lain di Case yang sama: Task + Note saja.
        $rest = array_slice($members, 1);
        if (!empty($rest)) {
            $cookie = '';
            if ($call['status'] === 'ok') {
                $sess = acumaticaActivitySession($base);
                $cookie = !empty($sess['ok']) ? (string) $sess['cookie'] : '';
            }

            foreach ($rest as $member) {
                $cid = (int) $member['candidate_id'];
                if ($call['status'] !== 'ok') {
                    $results[$cid] = [
                        'candidate_id' => $cid,
                        'push_status' => 'error',
                        'push_message' => 'Dilewati karena push Case ' . $caseId . ' gagal: ' . $call['message'],
                    ];
                    continue;
                }

                $parts = [];
                $failed = false;

                try {
                    $attachment = candidate_import_latest_attachment($pdo, $cid);
                    $task = createCaseActivityTask($base, (string) $caseId, (string) $member['full_name'], $attachment);
                    if (in_array($task['status'] ?? '', ['ok', 'skipped'], true)) {
                        candidate_import_upsert_task($pdo, $cid, (string) $caseId, $task);
                        $parts[] = (string) ($task['message'] ?? 'Task Activities siap');
                    } else {
                        $failed = true;
                        $parts[] = (string) ($task['message'] ?? 'Task gagal dibuat');
                    }

                    if ($cookie !== '') {
                        $note = acumaticaPushCandidateStatusNote(
                            $pdo,
                            $cid,
                            (string) $caseId,
                            $base,
                            $cookie,
                            (string) ($task['task_id'] ?? '')
                        );
                        $parts[] = 'Note: ' . (string) ($note['message'] ?? '-');
                        if (($note['status'] ?? '') === 'error') {
                            $failed = true;
                        }
                    }
                } catch (Throwable $e) {
                    $failed = true;
                    $parts[] = 'Gagal: ' . $e->getMessage();
                }

                $results[$cid] = [
                    'candidate_id' => $cid,
                    'push_status' => $failed ? 'error' : 'ok',
                    'push_message' => implode(' | ', array_filter($parts)),
                ];
            }
        }
    }

    return $results;
}
