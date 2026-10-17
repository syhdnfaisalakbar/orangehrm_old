<?php
require_once __DIR__ . '/_config.php';
/**
 * sync_manpower_scheduled.php
 *
 * Job scheduler: Auto-sync Manpower Request (FPTK) dari Acumatica → DB lokal,
 * meniru PERILAKU tombol "Sync Acumatica" di ViewManpowerRequest.vue — tanpa
 * browser. Dijadwalkan lewat Windows Task Scheduler (tiap 1 jam).
 *
 * Alur (identik tombol, cuma tanpa UI):
 *   1. Login Acumatica (cookie-string pattern, tanpa file cookie).
 *   2. Query max case_id lokal → filter incremental `CaseID gt <max>`.
 *   3. GET Case (REQFPTK, Open/Closed) + $expand=Attributes → mapping atribut.
 *   4. GET Task approval per case baru  → casesDetail.
 *   5. GET Task approval case LAMA     → approvalUpdates.
 *   6. saveManpowerPayload(...)        → reuse fungsi yang sama dgn endpoint web.
 *   7. Logout + log run.
 *
 * Pengaman:
 *   - LOCK FILE (flock): kalau run sebelumnya masih jalan → skip (aman interval
 *     berapa pun).
 *   - LOG RUN ke sync_manpower_log.txt.
 *
 * Bisa dipanggil sebagai CLI (task scheduler) atau HTTP (curl) — output keduanya
 * ditulis ke log; CLI juga mencetak ringkasan ke stdout.
 *
 * CREATE 2026-08-27.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// XAMPP php.ini tidak set date.timezone -> default Europe/Berlin, jadi timestamp
// di sync_manpower_log.txt meleset -5 jam dari WIB. Diset eksplisit di sini
// (2026-10-12) supaya log enak dibaca. Tidak mengubah perilaku lain.
date_default_timezone_set('Asia/Jakarta');

// ============================================================
// 0. FILE LOCK (anti-bentrok) — pasang SEBELUM apa pun
// ============================================================
$LOCK_FILE = __DIR__ . '/sync_manpower.lock';
function logRun(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents(__DIR__ . '/sync_manpower_log.txt', $line, FILE_APPEND | LOCK_EX);
}
function out(string $msg): void
{
    if (php_sapi_name() === 'cli') {
        echo $msg . PHP_EOL;
    }
}

$lockHandle = @fopen($LOCK_FILE, 'c');
if ($lockHandle === false) {
    logRun('FAIL: tidak bisa buka lock file (' . $LOCK_FILE . ')');
    out('[sync-manpower] Fatal: tidak bisa buka lock file.');
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    logRun('SKIP: run sebelumnya masih berjalan (lock aktif).');
    out('[sync-manpower] SKIP: lock aktif, run sebelumnya belum selesai.');
    exit(0);
}

// ============================================================
// 1. INCLUDE LOGIKA BERSAMA (SEBELUM output apa pun — biar header()
//    di file include tidak memicu "headers already sent")
// ============================================================
// save_manpower.php  → mendefinisikan saveManpowerPayload() + helper (guard endpoint).
// close_case_acumatica.php → mendefinisikan acumaticaLogin()/acumaticaLogout() +
//                            konstanta + acumaticaCaseRequest (S140/ASGJKT/staging).
require_once __DIR__ . '/save_manpower.php';
require_once __DIR__ . '/close_case_acumatica.php';

$started = microtime(true);
logRun('START run.');
out('[sync-manpower] Start run.');

// ============================================================
// 2. KONEKSI DB LOKAL
// ============================================================
$host = PROXY_DB_HOST;
$dbname = PROXY_DB_NAME;
$dbUser = PROXY_DB_USER;
$dbPass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    logRun('FAIL: koneksi DB gagal: ' . $e->getMessage());
    out('[sync-manpower] FAIL: koneksi DB: ' . $e->getMessage());
    exit(1);
}

// ============================================================
// HELPER PARSING ACUMATICA (mirror Vue)
// ============================================================
function mpGetAttrValue(array $attributes, string $attrId)
{
    $searchId = strtolower($attrId);
    foreach ($attributes as $a) {
        $id = $a['AttributeID']['value'] ?? null;
        if ($id !== null && strtolower($id) === $searchId) {
            return $a['ValueDescription']['value'] ?? $a['Value']['value'] ?? null;
        }
    }
    return null;
}
function mpFormatName($full): string
{
    $full = (string)$full;
    if ($full === '' || $full === '-') {
        return '-';
    }
    if (strpos($full, ' - ') !== false) {
        return trim(explode(' - ', $full, 2)[1]);
    }
    return $full;
}
/**
 * Request GET ke endpoint Acumatica dengan header Cookie.
 * Timeout 60s (query Case full + Task bisa lambat — lebih lama dari
 * 15s bawaan close_case_acumatica.php supaya tidak timeout).
 */
function syncRequest(string $url, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Cookie: ' . $cookie],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => (int)$http, 'body' => (string)$body, 'error' => (string)$err];
}
function mpFetchTasks(string $base, string $cookie, string $noteId): array
{
    $url = $base . 'Task?$filter=' . urlencode("RelatedEntityNoteID eq guid'{$noteId}'");
    $r = syncRequest($url, $cookie);
    if ($r['http'] < 200 || $r['http'] >= 300) {
        return [];
    }
    $d = json_decode($r['body'], true);
    if (is_array($d) && isset($d['value'])) {
        $d = $d['value'];
    }
    return is_array($d) ? $d : [];
}

// ============================================================
// 3. PERHITUNGAN INCREMENTAL (max case_id lokal)
// ============================================================
$max = 0;
foreach ($pdo->query("SELECT case_id FROM ohrm_manpower_request WHERE case_id <> ''")->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $n = (int)preg_replace('/\D/', '', (string)$cid);
    if ($n > $max) {
        $max = $n;
    }
}
$gtFilter = $max > 0 ? " and CaseID gt '" . str_pad((string)$max, 10, '0', STR_PAD_LEFT) . "'" : '';
logRun("max case_id lokal = {$max}" . ($gtFilter !== '' ? " (filter: {$gtFilter})" : ' (tidak ada filter — ambil semua)'));
out('[sync-manpower] max case_id lokal = ' . $max);

// ============================================================
// 4. LOGIN + QUERY CASE
// ============================================================
try {
    $login = acumaticaLogin();
    if (!$login['ok']) {
        throw new RuntimeException($login['message'] ?? 'Login Acumatica gagal');
    }
    $cookie = $login['cookie'];
    logRun('Login Acumatica OK (cookie len=' . strlen($cookie) . ').');

    $base = ACUMATICA_BASE_URL . '/entity/Default/25.200.001/';
    $filter = "(Status eq 'New' or Status eq 'Open' or Status eq 'Closed' or Status eq 'Canceled') and ClassID eq 'REQFPTK'" . $gtFilter;
    $caseUrl = $base . 'Case?$filter=' . urlencode($filter) . '&$expand=' . urlencode('Attributes');

    $getCase = syncRequest($caseUrl, $cookie);
    if ($getCase['http'] < 200 || $getCase['http'] >= 300) {
        throw new RuntimeException('Gagal GET Case (HTTP ' . $getCase['http'] . '): ' . substr($getCase['body'] ?? '', 0, 300));
    }
    $rawData = json_decode($getCase['body'], true);
    if (is_array($rawData) && isset($rawData['value'])) {
        $rawData = $rawData['value'];
    }
    $rawData = is_array($rawData) ? $rawData : [];
    logRun('GET Case: ' . count($rawData) . ' case ditarik.');
    out('[sync-manpower] GET Case: ' . count($rawData) . ' case.');

    // ============================================================
    // 5. MAPPING CASE → casesDetail (mirror Vue) + tarik Task per case
    // ============================================================
    $mappedItems = [];
    foreach ($rawData as $i => $item) {
        $attributes = (isset($item['Attributes']) && is_array($item['Attributes'])) ? $item['Attributes'] : [];

        $department = mpGetAttrValue($attributes, 'department') ?: ($item['Department']['value'] ?? '-');
        $branch     = mpGetAttrValue($attributes, 'branch') ?: '-';
        $position   = mpGetAttrValue($attributes, 'position') ?: '-';
        $reqType    = mpGetAttrValue($attributes, 'reqtype') ?: '-';
        $totPersonRaw = mpGetAttrValue($attributes, 'totperson');
        if ($totPersonRaw === null) {
            $totPersonRaw = $item['TotPerson']['value'] ?? 0;
        }
        $totPerson = (int)$totPersonRaw;
        $gender     = mpGetAttrValue($attributes, 'gender') ?: '-';
        $emplStatus = mpGetAttrValue($attributes, 'empstatus') ?: '-';
        $skill      = mpGetAttrValue($attributes, 'skill') ?: '-';
        $targetDate = mpGetAttrValue($attributes, 'targetdate') ?: '-';
        $jobDesc    = mpGetAttrValue($attributes, 'jobdesc') ?: '-';
        $reason     = mpGetAttrValue($attributes, 'reason') ?: ($item['Reason']['value'] ?? '-');
        $noteId     = $item['NoteID']['value'] ?? $item['id'] ?? '-';

        $mappedItems[] = [
            'id'        => $item['id'] ?? $i + 1,
            'caseId'    => $item['CaseID']['value'] ?? '-',
            'caseClass' => $item['ClassID']['value'] ?? '-',
            'status'    => $item['Status']['value'] ?? '-',
            'subject'   => $item['Subject']['value'] ?? '-',
            'owner'     => mpFormatName($item['OwnerEmployeeName']['value'] ?? $item['Owner']['value'] ?? '-'),
            'noteId'    => $noteId,
            'department'=> $department,
            'branch'    => $branch,
            'position'  => $position,
            'reqType'   => $reqType,
            'totPerson' => is_nan($totPerson) ? 0 : $totPerson,
            'gender'    => $gender,
            'emplStatus'=> $emplStatus,
            'skill'     => $skill,
            'targetDate'=> $targetDate,
            'jobDesc'   => $jobDesc,
            'reason'    => $reason,
            'attributes'=> $attributes,
        ];
    }

    // Tarik Task approval per case + kumpulkan caseId yang ditarik
    $drawnCaseIds = [];
    foreach ($mappedItems as $i => $caseItem) {
        $mappedItems[$i]['approvals'] = mpFetchTasks($base, $cookie, $caseItem['noteId']);
        $drawnCaseIds[] = $caseItem['caseId'];
    }
    $drawnSet = array_flip($drawnCaseIds);

    // ============================================================
    // 6. APPROVAL UPDATES CASE LAMA
    //    - Fetch STATUS & OWNER case dari Acumatica (update perubahan
    //      status seperti New→Open, perubahan owner).
    //    - Fetch Task approval per case (update status task).
    // ============================================================
    $approvalUpdates = [];
    $statusUpdatedCount = 0;
    $ownerUpdatedCount = 0;
    foreach ($pdo->query("SELECT case_id, note_id, status AS local_status, owner AS local_owner FROM ohrm_manpower_request WHERE case_id <> '' AND note_id <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($drawnSet[$row['case_id']])) {
            continue;
        }
        $auEntry = [
            'caseId'    => $row['case_id'],
            'approvals' => mpFetchTasks($base, $cookie, $row['note_id']),
            // Nama owner case lokal — fallback kalau owner task masih numerik
            // dan tidak bisa di-resolve (lihat resolveTaskOwnerName).
            'caseOwner' => $row['local_owner'] ?? null,
        ];

        // Fetch status & owner case terbaru dari Acumatica
        $caseFilter = "CaseID eq '" . $row['case_id'] . "'";
        $caseUrl = $base . 'Case?$filter=' . urlencode($caseFilter);
        $caseResp = syncRequest($caseUrl, $cookie);
        if ($caseResp['http'] >= 200 && $caseResp['http'] < 300) {
            $caseRaw = json_decode($caseResp['body'], true);
            if (is_array($caseRaw) && isset($caseRaw['value'])) {
                $caseRaw = $caseRaw['value'];
            }
            if (is_array($caseRaw) && !empty($caseRaw[0])) {
                $caseData = $caseRaw[0];
                $newStatus = $caseData['Status']['value'] ?? null;
                $newOwner  = mpFormatName($caseData['OwnerEmployeeName']['value'] ?? $caseData['Owner']['value'] ?? null);

                if ($newStatus !== null && strtoupper($newStatus) !== strtoupper($row['local_status'])) {
                    $auEntry['status'] = $newStatus;
                    $statusUpdatedCount++;
                    logRun("  [STATUS] Case {$row['case_id']}: {$row['local_status']} → {$newStatus}");
                }
                if ($newOwner !== null && $newOwner !== '' && $newOwner !== $row['local_owner']) {
                    $auEntry['owner'] = $newOwner;
                    $ownerUpdatedCount++;
                    logRun("  [OWNER] Case {$row['case_id']}: '{$row['local_owner']}' → '{$newOwner}'");
                }

                // Simpan juga nama owner case terbaru (walau tidak berubah) sebagai
                // bahan fallback owner task di saveManpowerPayload.
                if ($newOwner !== null && $newOwner !== '' && $newOwner !== '-') {
                    $auEntry['caseOwner'] = $newOwner;
                }
            }
        }

        $approvalUpdates[] = $auEntry;
    }
    logRun('Approval update case lama: ' . count($approvalUpdates) . ' case dicek | status berubah: ' . $statusUpdatedCount . ' | owner berubah: ' . $ownerUpdatedCount);
    out('[sync-manpower] Approval update case lama: ' . count($approvalUpdates) . ' | status berubah: ' . $statusUpdatedCount . ' | owner berubah: ' . $ownerUpdatedCount);

    // ============================================================
    // 6b. RESOLUSI NAMA OWNER TASK (2026-09-14) — samakan dengan tombol web.
    //     Acumatica Task tidak kirim nama owner (hanya ID numerik). Resolusi via
    //     Case?$filter=Owner eq '<id>' → baca OwnerEmployeeName, lalu tulis ke
    //     task.OwnerEmployeeName supaya DB menyimpan nama asli.
    // ============================================================
    $ownerIdMap = []; // id => nama (atau null kalau gagal resolve)
    $collectOwnerIds = function (array $arr) use (&$ownerIdMap) {
        foreach ($arr as $caseItem) {
            $approvals = $caseItem['approvals'] ?? null;
            if (!is_array($approvals)) {
                continue;
            }
            foreach ($approvals as $t) {
                $raw = $t['OwnerEmployeeName']['value'] ?? $t['Owner']['value'] ?? '';
                if ($raw !== '' && preg_match('/^\d+$/', (string)$raw)) {
                    $ownerIdMap[(string)$raw] = null;
                }
            }
        }
    };
    $collectOwnerIds($mappedItems);
    $collectOwnerIds($approvalUpdates);

    $resolvedOwnerCount = 0;
    foreach (array_keys($ownerIdMap) as $oid) {
        $resUrl = $base . 'Case?$filter=' . urlencode("Owner eq '{$oid}'");
        $res = syncRequest($resUrl, $cookie);
        if ($res['http'] < 200 || $res['http'] >= 300) {
            $ownerIdMap[$oid] = null;
            continue;
        }
        $list = json_decode($res['body'], true);
        if (is_array($list) && isset($list['value'])) {
            $list = $list['value'];
        }
        $list = is_array($list) ? $list : [];
        $found = null;
        foreach ($list as $c) {
            if ((string)($c['Owner']['value'] ?? '') === $oid) {
                $found = $c;
                break;
            }
        }
        if ($found === null) {
            $found = $list[0] ?? null;
        }
        $nm = ($found['OwnerEmployeeName']['value'] ?? '');
        $ownerIdMap[$oid] = ($nm !== '' && $nm !== '-') ? mpFormatName($nm) : null;
        if ($ownerIdMap[$oid] !== null) {
            $resolvedOwnerCount++;
        }
    }

    $injectOwners = function (array &$arr) use ($ownerIdMap) {
        foreach ($arr as &$caseItem) {
            if (isset($caseItem['approvals']) && is_array($caseItem['approvals'])) {
                foreach ($caseItem['approvals'] as &$t) {
                    $raw = $t['OwnerEmployeeName']['value'] ?? $t['Owner']['value'] ?? '';
                    if ($raw !== '' && preg_match('/^\d+$/', (string)$raw)) {
                        $id = (string)$raw;
                        if (!empty($ownerIdMap[$id])) {
                            $t['OwnerEmployeeName'] = ['value' => $ownerIdMap[$id]];
                        }
                    }
                }
                unset($t);
            }
        }
        unset($caseItem);
    };
    $injectOwners($mappedItems);
    $injectOwners($approvalUpdates);
    logRun('Resolusi owner task: ' . count($ownerIdMap) . ' ID unik, ' . $resolvedOwnerCount . ' ter-resolve.');
    out('[sync-manpower] Resolusi owner task: ' . count($ownerIdMap) . ' ID, ' . $resolvedOwnerCount . ' ter-resolve.');

    // ============================================================
    // 7. SIMPAN KE DB (fungsi REUSABLE — sama persis tombol web)
    // ============================================================
    $result = saveManpowerPayload($pdo, [
        'casesDetail'      => $mappedItems,
        'approvalUpdates'  => $approvalUpdates,
    ]);

    logRun('SIMPAN: status=' . ($result['status'] ?? '?') . ' | ' . ($result['message'] ?? ''));
    out('[sync-manpower] SIMPAN: ' . ($result['message'] ?? ''));

    // ============================================================
    // 8. LOGOUT
    // ============================================================
    try {
        acumaticaLogout($cookie);
        logRun('Logout Acumatica OK.');
    } catch (\Throwable $e) {
        logRun('Logout gagal (best-effort): ' . $e->getMessage());
    }
} catch (\Throwable $e) {
    logRun('FAIL: ' . $e->getMessage());
    out('[sync-manpower] FAIL: ' . $e->getMessage());
    // coba logout kalau sudah login
    if (isset($cookie) && !empty($cookie)) {
        try { acumaticaLogout($cookie); logRun('Logout (dari error handler).'); } catch (\Throwable $x) {}
    }
    exit(1);
}

$elapsed = round(microtime(true) - $started, 2);
logRun("END run (durasi {$elapsed}s).");
out("[sync-manpower] Selesai ({$elapsed}s).");

// release lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);