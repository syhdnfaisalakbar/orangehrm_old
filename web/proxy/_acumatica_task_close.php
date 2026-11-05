<?php
/**
 * Tutup Task kandidat di Acumatica saat kandidat di-HIRE di OrangeHRM.
 *
 * Status Task yang dipakai: 'Closed' (diterima entity Task 25.200.001).
 * Arah sebaliknya (Task Closed/Canceled -> status kandidat) TIDAK diubah di sini;
 * _candidate_status_sync.php hanya bereaksi pada 'Canceled'.
 *
 * Dipanggil dari AbstractCandidateActionAPI::update() setelah commit transaksi
 * pada aksi RECRUITMENT_APPLICATION_ACTION_HIRE. Semua kegagalan best-effort:
 * hire di OrangeHRM tetap sukses walau Acumatica bermasalah.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_acumatica_activity.php';

if (!function_exists('acumaticaCloseCandidateTask')) {
    /**
     * Set Status Task kandidat menjadi Closed.
     *
     * @param int    $candidateId ID kandidat OrangeHRM
     * @param string $targetStatus status tujuan (default 'Closed')
     * @return array{status:string,message:string,task_id?:string,case_id?:string,from?:string,to?:string,summary?:string}
     */
    function acumaticaCloseCandidateTask(int $candidateId, string $targetStatus = 'Closed'): array
    {
        // 1. DB
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

        // 2. Task yang sudah dipetakan untuk kandidat ini
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

        if (strcasecmp($taskStatus, $targetStatus) === 0) {
            return [
                'status'  => 'skipped',
                'message' => 'Task Acumatica sudah ' . $targetStatus,
                'task_id' => $taskId,
                'case_id' => $caseId,
            ];
        }

        // Task Canceled tidak perlu diubah (sudah final).
        if (strcasecmp($taskStatus, 'Canceled') === 0) {
            return [
                'status'  => 'skipped',
                'message' => 'Task Acumatica sudah Canceled',
                'task_id' => $taskId,
                'case_id' => $caseId,
            ];
        }

        // 3. Sesi bersama Acumatica
        $base = ACUMATICA_BASE_URL;
        $sess = acumaticaActivitySession($base);
        if (empty($sess['ok'])) {
            return ['status' => 'error', 'message' => 'Gagal login Acumatica: ' . ($sess['message'] ?? '')];
        }

        // 4. Baca status terkini lalu set
        $cur = acumaticaCurlJson(
            $base . '/entity/Default/25.200.001/Task/' . $taskId . '?$select=NoteID,Summary,Status',
            'GET',
            null,
            $sess['cookie']
        );
        $curData = json_decode((string) $cur['body'], true);
        $fromStatus = (string) ($curData['Status']['value'] ?? $taskStatus);
        $summary = (string) ($curData['Summary']['value'] ?? '');

        if (strcasecmp($fromStatus, 'Canceled') === 0) {
            return [
                'status'  => 'skipped',
                'message' => 'Task Acumatica sudah Canceled',
                'task_id' => $taskId,
                'case_id' => $caseId,
            ];
        }

        $put = acumaticaCurlJson(
            $base . '/entity/Default/25.200.001/Task',
            'PUT',
            [
                'NoteID' => ['value' => $taskId],
                'Status' => ['value' => $targetStatus],
            ],
            $sess['cookie']
        );

        if (!($put['http'] >= 200 && $put['http'] < 300)) {
            return [
                'status'  => 'error',
                'message' => 'Acumatica menolak tutup Task (HTTP ' . $put['http'] . '): ' . substr((string) $put['body'], 0, 200),
                'task_id' => $taskId,
                'case_id' => $caseId,
            ];
        }

        // 5. Sinkronkan tabel lokal
        try {
            $pdo->prepare(
                'UPDATE ohrm_candidate_acumatica_task
                    SET task_status = :st, last_status_note = :note, synced_at = NOW()
                  WHERE candidate_id = :cid'
            )->execute([':st' => $targetStatus, ':note' => 'HIRED', ':cid' => $candidateId]);
        } catch (\Throwable $e) {
            // best-effort
        }

        return [
            'status'  => 'ok',
            'message' => 'Task Acumatica "' . $summary . '" diubah ' . $fromStatus . ' -> ' . $targetStatus,
            'task_id' => $taskId,
            'case_id' => $caseId,
            'from'    => $fromStatus,
            'to'      => $targetStatus,
            'summary' => $summary,
        ];
    }
}
