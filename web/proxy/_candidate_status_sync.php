<?php
/**
 * _candidate_status_sync.php
 *
 * Menyelaraskan status kandidat OrangeHRM dengan status Task Acumatica:
 *   - Task 'Canceled'          -> kandidat di-Reject (status cv = REJECTED)
 *   - Task aktif lagi (Open/Processing/Completed) -> status cv dikembalikan
 *     ke status sebelum di-reject otomatis.
 *
 * Catatan:
 *   - Hanya menyentuh aplikasi (ohrm_job_candidate_vacancy) pada vacancy yang
 *     case_id-nya sama dengan case Task. Kandidat HIRED tidak pernah diturunkan.
 *   - Status sebelum reject disimpan di ohrm_candidate_acumatica_task
 *     (kolom prev_cv_status + auto_rejected) supaya bisa dipulihkan dengan aman:
 *     hanya status yang diubah OLEH integrasi ini yang dipulihkan, reject manual
 *     dari UI tidak disentuh.
 *   - Setiap perubahan menulis baris riwayat di ohrm_job_candidate_history
 *     (performed_by NULL = sistem), jadi terlihat di tab History kandidat.
 */

/** status cv => action id (WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_*) */
function acumaticaStatusToActionMap(): array
{
    return [
        'APPLICATION INITIATED' => 1,
        'SHORTLISTED'           => 2,
        'REJECTED'              => 3,
        'INTERVIEW SCHEDULED'   => 4,
        'INTERVIEW PASSED'      => 5,
        'INTERVIEW FAILED'      => 6,
        'JOB OFFERED'           => 7,
        'OFFER DECLINED'        => 8,
        'HIRED'                 => 9,
    ];
}

/**
 * Cari aplikasi kandidat pada vacancy yang case_id-nya cocok.
 */
function acumaticaFindCandidateVacancy(PDO $pdo, int $candidateId, ?string $caseId): ?array
{
    $sql = 'SELECT cv.id, cv.status, cv.vacancy_id, v.name AS vacancy_name, v.case_id
            FROM ohrm_job_candidate_vacancy cv
            JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
            WHERE cv.candidate_id = :cid';
    $params = [':cid' => $candidateId];

    if (!empty($caseId)) {
        $sql .= ' AND v.case_id = :caseId';
        $params[':caseId'] = $caseId;
    }
    $sql .= ' ORDER BY cv.id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function acumaticaInsertCandidateHistory(PDO $pdo, int $candidateId, array $cv, int $action, string $note): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ohrm_job_candidate_history
            (candidate_id, vacancy_id, candidate_vacancy_name, action, performed_by, performed_date, note)
         VALUES (:cid, :vid, :vname, :action, NULL, NOW(), :note)'
    );
    $stmt->execute([
        ':cid'    => $candidateId,
        ':vid'    => $cv['vacancy_id'],
        ':vname'  => $cv['vacancy_name'],
        ':action' => $action,
        ':note'   => $note,
    ]);
}

/**
 * Terapkan status Task Acumatica ke status kandidat.
 *
 * @return array{action:string, from:?string, to:?string}
 *         action: 'rejected' | 'restored' | 'none'
 */
function acumaticaApplyCandidateStatusFromTask(PDO $pdo, int $candidateId, ?string $caseId, ?string $taskStatus): array
{
    $result = ['action' => 'none', 'from' => null, 'to' => null];
    $taskStatus = trim((string) $taskStatus);
    if ($taskStatus === '') {
        return $result;
    }

    $cv = acumaticaFindCandidateVacancy($pdo, $candidateId, $caseId);
    if (!$cv) {
        return $result;
    }

    $stmt = $pdo->prepare(
        'SELECT prev_cv_status, auto_rejected FROM ohrm_candidate_acumatica_task WHERE candidate_id = :cid'
    );
    $stmt->execute([':cid' => $candidateId]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['prev_cv_status' => null, 'auto_rejected' => 0];

    $isCanceled = strcasecmp($taskStatus, 'Canceled') === 0;

    if ($isCanceled) {
        // Sudah rejected / sudah hired: jangan disentuh (tidak menimpa aksi manual / hire).
        if ($cv['status'] === 'REJECTED' || $cv['status'] === 'HIRED') {
            return $result;
        }

        $pdo->prepare(
            'UPDATE ohrm_candidate_acumatica_task
                SET prev_cv_status = :prev, auto_rejected = 1, auto_rejected_at = NOW()
              WHERE candidate_id = :cid'
        )->execute([':prev' => $cv['status'], ':cid' => $candidateId]);

        $pdo->prepare('UPDATE ohrm_job_candidate_vacancy SET status = :st WHERE id = :id')
            ->execute([':st' => 'REJECTED', ':id' => $cv['id']]);

        acumaticaInsertCandidateHistory(
            $pdo,
            $candidateId,
            $cv,
            3,
            'Rejected otomatis: Task Acumatica berstatus Canceled.'
        );

        return ['action' => 'rejected', 'from' => $cv['status'], 'to' => 'REJECTED'];
    }

    // Task aktif kembali: pulihkan HANYA kalau reject-nya dilakukan oleh integrasi ini.
    if (
        (int) $state['auto_rejected'] === 1
        && $cv['status'] === 'REJECTED'
        && !empty($state['prev_cv_status'])
    ) {
        $prev = (string) $state['prev_cv_status'];

        $pdo->prepare('UPDATE ohrm_job_candidate_vacancy SET status = :st WHERE id = :id')
            ->execute([':st' => $prev, ':id' => $cv['id']]);

        $map = acumaticaStatusToActionMap();
        if (isset($map[$prev])) {
            acumaticaInsertCandidateHistory(
                $pdo,
                $candidateId,
                $cv,
                $map[$prev],
                'Dipulihkan otomatis: Task Acumatica kembali berstatus ' . $taskStatus . '.'
            );
        }

        $pdo->prepare(
            'UPDATE ohrm_candidate_acumatica_task
                SET auto_rejected = 0, prev_cv_status = NULL
              WHERE candidate_id = :cid'
        )->execute([':cid' => $candidateId]);

        return ['action' => 'restored', 'from' => 'REJECTED', 'to' => $prev];
    }

    return $result;
}

// ============================================================
// KIRIM STATUS KANDIDAT SEBAGAI NOTE DI TAB ACTIVITIES
//
// Aturan (disepakati): Summary Note = nama status kandidat dalam Title Case
// (mis. "Interview Scheduled"), dibuat untuk SEMUA status, dan hanya dibuat
// kalau statusnya BERUBAH sejak pengiriman terakhir (tidak dobel saat tombol
// diklik berulang). Status terakhir disimpan di
// ohrm_candidate_acumatica_task.last_status_note.
// ============================================================
function acumaticaStatusNoteLabel(string $status): string
{
    $map = [
        'APPLICATION INITIATED' => 'Application Initiated',
        'SHORTLISTED'           => 'Shortlisted',
        'REJECTED'              => 'Rejected',
        'INTERVIEW SCHEDULED'   => 'Interview Scheduled',
        'INTERVIEW PASSED'      => 'Interview Passed',
        'INTERVIEW FAILED'      => 'Interview Failed',
        'JOB OFFERED'           => 'Job Offered',
        'OFFER DECLINED'        => 'Offer Declined',
        'HIRED'                 => 'Hired',
    ];
    $key = strtoupper(trim($status));
    return $map[$key] ?? ucwords(strtolower($key));
}

function acumaticaPushCandidateStatusNote(PDO $pdo, int $candidateId, string $caseId, string $baseUrl, string $cookie, ?string $taskNoteId = null): array
{
    // 1. Status aplikasi kandidat (HIRED diutamakan, sama seperti pemilihan vacancy).
    $stmt = $pdo->prepare(
        "SELECT cv.status, cv.vacancy_id
           FROM ohrm_job_candidate_vacancy cv
           JOIN ohrm_job_vacancy v ON v.id = cv.vacancy_id
          WHERE cv.candidate_id = :cid AND v.case_id = :caseId
          ORDER BY (UPPER(cv.status) = 'HIRED') DESC, cv.id DESC
          LIMIT 1"
    );
    $stmt->execute([':cid' => $candidateId, ':caseId' => $caseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || trim((string) $row['status']) === '') {
        return ['status' => 'skipped', 'message' => 'Status aplikasi kandidat tidak ditemukan'];
    }
    $status = strtoupper(trim((string) $row['status']));
    $label = acumaticaStatusNoteLabel($status);

    // 2. Sudah pernah dikirim? (hanya kirim kalau status berubah)
    $last = null;
    try {
        $q = $pdo->prepare('SELECT last_status_note FROM ohrm_candidate_acumatica_task WHERE candidate_id = :cid');
        $q->execute([':cid' => $candidateId]);
        $last = $q->fetchColumn();
    } catch (\Throwable $e) {
        $last = null;
    }
    if ($last !== false && (string) $last === $status) {
        return ['status' => 'skipped', 'message' => 'Status ' . $label . ' sudah dikirim sebelumnya', 'summary' => $label];
    }

    // 3. NoteID Case
    $caseRes = acumaticaCurlJson(
        $baseUrl . '/entity/Default/25.200.001/Case?$select=NoteID&$filter=' . urlencode("CaseID eq '{$caseId}'") . '&$top=1',
        'GET',
        null,
        $cookie
    );
    $caseNote = '';
    if ($caseRes['http'] >= 200 && $caseRes['http'] < 300) {
        $caseData = json_decode((string) $caseRes['body'], true);
        $caseNote = (string) ($caseData[0]['NoteID']['value'] ?? ($caseData[0]['id'] ?? ''));
    }
    if ($caseNote === '') {
        return ['status' => 'error', 'message' => 'NoteID Case ' . $caseId . ' tidak terbaca'];
    }

    // 3b. Parent Activity = Task kandidat (Summary Task = nama kandidat).
    //     Kalau tidak diberikan pemanggil, ambil dari DB (kolom task_id).
    if ($taskNoteId === null || $taskNoteId === '') {
        try {
            $qt = $pdo->prepare('SELECT task_id FROM ohrm_candidate_acumatica_task WHERE candidate_id = :cid');
            $qt->execute([':cid' => $candidateId]);
            $taskNoteId = (string) ($qt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            $taskNoteId = '';
        }
    }
    $taskNoteId = trim((string) $taskNoteId);

    // 4. Buat Note (Activity Type = N), Parent Activity = Task kandidat
    $note = createCaseActivityNote(
        $baseUrl,
        $cookie,
        $caseNote,
        $label,
        'Status kandidat di OrangeHRM: ' . $label,
        $taskNoteId !== '' ? $taskNoteId : null
    );

    // 5. Simpan status terakhir kalau berhasil
    if (($note['status'] ?? '') === 'ok' || ($note['status'] ?? '') === 'skipped') {
        try {
            $pdo->prepare(
                'INSERT INTO ohrm_candidate_acumatica_task (candidate_id, case_id, last_status_note)
                 VALUES (:cid, :caseId, :status)
                 ON DUPLICATE KEY UPDATE last_status_note = VALUES(last_status_note)'
            )->execute([':cid' => $candidateId, ':caseId' => $caseId, ':status' => $status]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    $note['status_kandidat'] = $label;
    return $note;
}
