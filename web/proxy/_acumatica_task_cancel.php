<?php
/**
 * Batalkan Task kandidat di Acumatica saat kandidat di-Reject di OrangeHRM.
 *
 * Arah sebaliknya (Task Canceled -> kandidat REJECTED) ditangani
 * _candidate_status_sync.php. File ini untuk arah OrangeHRM -> Acumatica.
 *
 * Dipanggil dari AbstractCandidateActionAPI::update() setelah commit transaksi
 * pada aksi RECRUITMENT_APPLICATION_ACTION_REJECT. Semua kegagalan bersifat
 * best-effort: reject di OrangeHRM tetap sukses walau Acumatica bermasalah.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';

/**
 * @return array{status:string,message:string,task_id?:string,case_id?:string,from?:string,to?:string}
 */
function acumaticaCancelCandidateTask(int $candidateId): array
{
    // 1. Koneksi DB (helper ini dipanggil dari luar folder proxy, jadi buat sendiri).
    try {
        $pdo = new PDO(
            'mysql:host=' . PROXY_DB_HOST . ';dbname=' . PROXY_DB_NAME . ';charset=utf8',
            PROXY_DB_USER,
            PROXY_DB_PASS
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        return ['status' => 'error', 'message' => 'DB tidak tersambung: ' . $e->getMessage()];
    }

    // 2. Task kandidat yang tersimpan (diisi oleh fitur Sync Application / Sync kandidat).
    $taskId = '';
    $caseId = '';
    $taskStatus = '';
    try {
        $stmt = $pdo->prepare(
            'SELECT task_id, case_id, task_status
               FROM ohrm_candidate_acumatica_task
              WHERE candidate_id = :cid'
        );
        $stmt->execute([':cid' => $candidateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $taskId = trim((string) $row['task_id']);
            $caseId = trim((string) $row['case_id']);
            $taskStatus = trim((string) $row['task_status']);
        }
    } catch (\Throwable $e) {
        return ['status' => 'error', 'message' => 'Tabel status Acumatica tidak terbaca'];
    }

    if ($taskId === '') {
        return [
            'status'  => 'skipped',
            'message' => 'Kandidat ini belum punya Task di Acumatica',
            'case_id' => $caseId,
        ];
    }

    if (strcasecmp($taskStatus, 'Canceled') === 0) {
        return [
            'status'  => 'skipped',
            'message' => 'Task Acumatica sudah Canceled',
            'task_id' => $taskId,
            'case_id' => $caseId,
        ];
    }

    // 3. Sesi bersama (jangan login/logout per request: kuota Acumatica ~1).
    $base = ACUMATICA_BASE_URL;
    $sess = acumaticaActivitySession($base);
    if (empty($sess['ok'])) {
        return ['status' => 'error', 'message' => 'Gagal login Acumatica: ' . ($sess['message'] ?? '')];
    }

    // 4. Set Status Task = Canceled. Baca dulu supaya nilai lama bisa dilaporkan.
    $cur = acumaticaCurlJson(
        $base . '/entity/Default/25.200.001/Task/' . $taskId . '?$select=NoteID,Summary,Status',
        'GET',
        null,
        $sess['cookie']
    );
    $curData = json_decode((string) $cur['body'], true);
    $fromStatus = (string) ($curData['Status']['value'] ?? $taskStatus);
    $summary = (string) ($curData['Summary']['value'] ?? '');

    $put = acumaticaCurlJson(
        $base . '/entity/Default/25.200.001/Task',
        'PUT',
        [
            'NoteID' => ['value' => $taskId],
            'Status' => ['value' => 'Canceled'],
        ],
        $sess['cookie']
    );

    if (!($put['http'] >= 200 && $put['http'] < 300)) {
        return [
            'status'  => 'error',
            'message' => 'Acumatica menolak ubah Task (HTTP ' . $put['http'] . ')',
            'task_id' => $taskId,
        ];
    }

    // 5. Sinkronkan tabel lokal.
    try {
        $pdo->prepare(
            'UPDATE ohrm_candidate_acumatica_task
                SET task_status = :st, synced_at = NOW()
              WHERE candidate_id = :cid'
        )->execute([':st' => 'Canceled', ':cid' => $candidateId]);
    } catch (\Throwable $e) {
        // best-effort
    }

    return [
        'status'  => 'ok',
        'message' => 'Task Acumatica "' . $summary . '" diubah ' . $fromStatus . ' -> Canceled',
        'task_id' => $taskId,
        'case_id' => $caseId,
        'from'    => $fromStatus,
        'to'      => 'Canceled',
        'summary' => $summary,
    ];
}
