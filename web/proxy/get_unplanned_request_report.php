<?php
require_once __DIR__ . '/_config.php';
/**
 * Laporan Total Unplanned Request Disetujui per Departemen
 *
 * GET /proxy/get_unplanned_request_report.php           → semua tahun
 * GET /proxy/get_unplanned_request_report.php?year=2026 → filter tahun
 *
 * Definisi "disetujui" = ada record ohrm_manpower_approval dengan status
 * 'Completed' untuk case_id request tsb. JOIN ke derived table (GROUP BY
 * case_id) supaya aman kalau 1 case punya banyak approval — baris request
 * tidak akan dobel.
 *
 * Atribusi tahun pakai YEAR(target_date), fallback created_at
 * (handle zero-date 0000-00-00 seperti modul lain).
 *
 * Response:
 * {
 *   "status": "success",
 *   "year": 0,                          // 0 = semua tahun
 *   "summary": [                        // agregasi per departemen
 *     { "department": "...", "total_request": N, "open": N, "closed": N,
 *       "approved": N, "total_person": N }
 *   ],
 *   "totals": { "total_request": N, "open": N, "closed": N, "approved": N, "total_person": N },
 *   "detail": [                         // baris per request
 *     { case_id, case_class, department, position, subject, req_type,
 *       req_status, tot_person, target_date, created_at, owner, approval_status }
 *   ]
 * }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $year = isset($_GET['year']) ? intval($_GET['year']) : 0;

    $sql = "
        SELECT
            r.case_id,
            r.case_class,
            r.department,
            r.position,
            r.subject,
            r.req_type,
            r.status               AS req_status,
            r.tot_person,
            r.target_date,
            r.created_at,
            r.owner,
            a.status               AS approval_status
        FROM ohrm_manpower_request r
        LEFT JOIN (
            SELECT case_id,
                   MAX(status)     AS status,
                   MAX(priority)   AS priority,
                   MAX(updated_at) AS updated_at
            FROM ohrm_manpower_approval
            GROUP BY case_id
        ) a ON a.case_id = r.case_id
        WHERE UPPER(r.req_type) = 'UNPLANNED'
          AND a.status = 'Completed'
    ";

    if ($year > 0) {
        $sql .= "
            AND YEAR(COALESCE(NULLIF(r.target_date, '0000-00-00'), r.created_at)) = :year
        ";
    }
    $sql .= " ORDER BY r.department ASC, r.created_at DESC";

    $stmt = $pdo->prepare($sql);
    if ($year > 0) {
        $stmt->bindValue(':year', $year, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalisasi baris → detail
    $detail = [];
    foreach ($rows as $row) {
        $detail[] = [
            'case_id'         => $row['case_id'],
            'case_class'      => $row['case_class'] ?? '',
            'department'      => trim((string) ($row['department'] ?? '')),
            'position'        => $row['position'] ?? '',
            'subject'         => $row['subject'] ?? '',
            'req_type'        => $row['req_type'] ?? 'Unplanned',
            'req_status'      => $row['req_status'] ?? '',
            'tot_person'      => (int) ($row['tot_person'] ?? 0),
            'target_date'     => $row['target_date'] ?? '',
            'created_at'      => $row['created_at'] ?? '',
            'owner'           => $row['owner'] ?? '',
            'approval_status' => $row['approval_status'] ?? '',
        ];
    }

    // Agregasi per departemen
    $summaryMap = [];
    foreach ($detail as $d) {
        $dept = $d['department'] !== '' ? $d['department'] : '(tanpa departemen)';
        if (!isset($summaryMap[$dept])) {
            $summaryMap[$dept] = [
                'department'    => $dept,
                'total_request' => 0,
                'open'          => 0,
                'closed'        => 0,
                'approved'      => 0,
                'total_person'  => 0,
            ];
        }
        $summaryMap[$dept]['total_request'] += 1;
        $summaryMap[$dept]['total_person'] += $d['tot_person'];
        if (strtoupper($d['req_status']) === 'OPEN') {
            $summaryMap[$dept]['open'] += 1;
        } elseif (strtoupper($d['req_status']) === 'CLOSED') {
            $summaryMap[$dept]['closed'] += 1;
        }
        if (strtoupper($d['approval_status']) === 'COMPLETED') {
            $summaryMap[$dept]['approved'] += 1;
        }
    }

    // Sort summary: total_request DESC, lalu department ASC
    $summary = array_values($summaryMap);
    usort($summary, function ($a, $b) {
        if ($b['total_request'] !== $a['total_request']) {
            return $b['total_request'] - $a['total_request'];
        }
        return strcmp($a['department'], $b['department']);
    });

    $totals = [
        'total_request' => 0,
        'open'          => 0,
        'closed'        => 0,
        'approved'      => 0,
        'total_person'  => 0,
    ];
    foreach ($summary as $s) {
        $totals['total_request'] += $s['total_request'];
        $totals['open']          += $s['open'];
        $totals['closed']        += $s['closed'];
        $totals['approved']      += $s['approved'];
        $totals['total_person']  += $s['total_person'];
    }

    echo json_encode([
        'status'  => 'success',
        'year'    => $year,
        'summary' => $summary,
        'totals'  => $totals,
        'detail'  => $detail,
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
