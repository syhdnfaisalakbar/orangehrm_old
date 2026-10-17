<?php
require_once __DIR__ . '/_config.php';
/**
 * Auto-link Vacancy ke Manpower Request berdasarkan case_id yang sama.
 *
 * Mencocokkan ohrm_job_vacancy.case_id dengan ohrm_manpower_request.case_id,
 * lalu mengisi:
 *   - ohrm_manpower_vacancy_link          (relasi many-to-many)
 *   - ohrm_job_vacancy.manpower_request_id (kolom langsung di vacancy)
 *
 * Bonus: jika SEMUA vacancy yang ter-link ke suatu MPR sudah closed,
 * status MPR di-update ke 'Closed' (konsisten dengan trigger di VacancyAPI).
 *
 * Panggil langsung untuk backfill:
 *   GET/POST /proxy/link_manpower_vacancies.php
 *
 * Atau dipanggil otomatis di akhir sync (save_manpower.php / save_job_vacancy.php)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

/**
 * @param PDO $pdo
 * @return array
 */
function linkManpowerVacancies(PDO $pdo): array
{
    // Normalisasi case_id: lowercase + buang leading zeros (handle '3655' vs '0000003655')
    $normalize = function (string $s): string {
        return ltrim(strtolower(trim($s)), '0');
    };

    // 1. Ambil semua MPR yang punya case_id
    $mprStmt = $pdo->query(
        "SELECT id, case_id, status FROM ohrm_manpower_request WHERE case_id IS NOT NULL AND case_id != ''"
    );
    $mprs = $mprStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil semua vacancy yang punya case_id
    $vacStmt = $pdo->query(
        "SELECT id, case_id, status FROM ohrm_job_vacancy WHERE case_id IS NOT NULL AND case_id != ''"
    );
    $vacancies = $vacStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index vacancy per normalized case_id
    $vacByCase = [];
    foreach ($vacancies as $v) {
        $key = $normalize($v['case_id']);
        if ($key === '') {
            continue;
        }
        $vacByCase[$key][] = $v;
    }

    // 3. Link yang sudah ada, untuk cek duplikat
    $existing = $pdo->query(
        "SELECT manpower_request_id, vacancy_id FROM ohrm_manpower_vacancy_link"
    )->fetchAll(PDO::FETCH_ASSOC);
    $exists = [];
    foreach ($existing as $e) {
        $exists[$e['manpower_request_id'] . ':' . $e['vacancy_id']] = true;
    }

    $stmtInsert = $pdo->prepare(
        "INSERT INTO ohrm_manpower_vacancy_link (manpower_request_id, vacancy_id, request_type)
         VALUES (:mpr, :vac, :rt)"
    );
    $stmtUpdateVac = $pdo->prepare(
        "UPDATE ohrm_job_vacancy SET manpower_request_id = :mpr WHERE id = :vac"
    );
    $stmtUpdateMpr = $pdo->prepare(
        "UPDATE ohrm_manpower_request SET status = 'Closed', updated_at = CURRENT_TIMESTAMP WHERE id = :id"
    );
    $rtStmt = $pdo->prepare("SELECT req_type FROM ohrm_manpower_request WHERE id = :id");
    $allClosedStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(v.status), 0) AS active_cnt
        FROM ohrm_manpower_vacancy_link l
        JOIN ohrm_job_vacancy v ON l.vacancy_id = v.id
        WHERE l.manpower_request_id = :id
          AND v.rejected = 0"
    );

    $linked = 0;
    $already = 0;
    $closedMprs = [];
    $closedCaseIds = [];
    $details = [];

    $pdo->beginTransaction();
    try {
        foreach ($mprs as $mpr) {
            $mprKey = $normalize($mpr['case_id']);
            if ($mprKey === '' || !isset($vacByCase[$mprKey])) {
                continue;
            }

            $mprId = (int)$mpr['id'];

            foreach ($vacByCase[$mprKey] as $vac) {
                $vacId = (int)$vac['id'];
                $pair = $mprId . ':' . $vacId;

                if (isset($exists[$pair])) {
                    // Link sudah ada — pastikan manpower_request_id juga terisi
                    $already++;
                    continue;
                }

                // Auto request_type dari MPR
                $rtStmt->execute([':id' => $mprId]);
                $requestType = $rtStmt->fetchColumn() ?: 'Planned';

                $stmtInsert->execute([':mpr' => $mprId, ':vac' => $vacId, ':rt' => $requestType]);
                $stmtUpdateVac->execute([':mpr' => $mprId, ':vac' => $vacId]);
                $exists[$pair] = true;
                $linked++;
                $details[] = "MPR#{$mprId} ({$mpr['case_id']}) <-> Vacancy#{$vacId}";
            }

            // Bonus: jika SEMUA vacancy NON-REJECTED MPR ini sudah closed -> MPR ikut Closed.
            // Vacancy yang di-reject (rejected=1) TIDAK dihitung — kalau semua vacancy
            // ter-link rejected, total=0 → MPR dibiarkan tetap 'Rejected'.
            $allClosedStmt->execute([':id' => $mprId]);
            $agg = $allClosedStmt->fetch(PDO::FETCH_ASSOC);
            if ((int)$agg['total'] > 0 && (int)$agg['active_cnt'] === 0 && strtoupper((string)$mpr['status']) !== 'CLOSED') {
                $stmtUpdateMpr->execute([':id' => $mprId]);
                $closedMprs[] = "MPR#{$mprId} ({$mpr['case_id']})";
                $closedCaseIds[] = (string)$mpr['case_id'];
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // ---- Push ke Acumatica: close case untuk MPR yang baru ditutup ----
    $acumaticaResults = [];
    if (!empty($closedCaseIds)) {
        require_once __DIR__ . '/close_case_acumatica.php';
        foreach (array_unique($closedCaseIds) as $caseId) {
            // Best-effort: jangan sampai gagal kalau Acumatica lagi bermasalah
            try {
                $acumaticaResults[] = closeCaseInAcumatica($caseId);
            } catch (\Throwable $e) {
                $acumaticaResults[] = [
                    'status' => 'error',
                    'case_id' => $caseId,
                    'message' => $e->getMessage(),
                ];
            }
        }
    }

    return [
        'status' => 'success',
        'linked' => $linked,
        'already' => $already,
        'closed_mprs' => $closedMprs,
        'acumatica' => $acumaticaResults,
        'message' => "Auto-link: $linked vacancy baru di-link, $already sudah ada, " . count($closedMprs) . " MPR ditutup otomatis.",
        'details' => $details,
    ];
}

// Jalankan langsung jika diakses sebagai script mandiri (backfill)
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $result = linkManpowerVacancies($pdo);
        echo json_encode($result);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
