<?php
require_once __DIR__ . '/_config.php';
/**
 * Manpower Vacancy Link API
 * 
 * Mengambil data vacancy yang terlink ke suatu Manpower Request.
 * 
 * GET  /proxy/manpower_vacancy_link.php?action=get&manpower_request_id=1
 * POST /proxy/manpower_vacancy_link.php?action=link
 *       Body: { manpower_request_id, vacancy_id, request_type }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Koneksi Database ---
$host = PROXY_DB_HOST;
$db = PROXY_DB_NAME;
$user = PROXY_DB_USER;
$pass = PROXY_DB_PASS;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // =============================================
        // GET — Ambil vacancy yang terlink ke satu request
        // =============================================
        case 'get':
            $manpowerRequestId = intval($_GET['manpower_request_id'] ?? 0);
            if (!$manpowerRequestId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'manpower_request_id required']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT 
                    l.id AS link_id,
                    l.manpower_request_id,
                    l.vacancy_id,
                    l.request_type,
                    l.status AS link_status,
                    l.created_at AS link_created_at,
                    v.id AS vacancy_id,
                    v.name AS vacancy_name,
                    v.no_of_positions,
                    v.status AS vacancy_status,
                    v.hiring_manager_id,
                    v.published_in_feed,
                    v.defined_time,
                    v.updated_time,
                    jt.job_title,
                    mr.req_type AS manpower_req_type,
                    mr.position AS manpower_position,
                    mr.department AS manpower_department,
                    mr.tot_person AS manpower_tot_person,
                    mr.status AS manpower_status
                FROM ohrm_manpower_vacancy_link l
                JOIN ohrm_job_vacancy v ON l.vacancy_id = v.id
                LEFT JOIN ohrm_job_title jt ON v.job_title_code = jt.id
                LEFT JOIN ohrm_manpower_request mr ON l.manpower_request_id = mr.id
                WHERE l.manpower_request_id = :mpr_id
                ORDER BY l.created_at DESC
            ");
            $stmt->execute([':mpr_id' => $manpowerRequestId]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        // =============================================
        // GET-LIST — Ambil semua link (opsional, untuk report)
        // =============================================
        case 'list':
            $stmt = $pdo->query("
                SELECT 
                    l.*,
                    v.name AS vacancy_name,
                    mr.position AS manpower_position,
                    mr.department
                FROM ohrm_manpower_vacancy_link l
                JOIN ohrm_job_vacancy v ON l.vacancy_id = v.id
                JOIN ohrm_manpower_request mr ON l.manpower_request_id = mr.id
                ORDER BY l.created_at DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        // =============================================
        // POST LINK — Bikin relasi baru
        // =============================================
        case 'link':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $manpowerRequestId = intval($input['manpower_request_id'] ?? 0);
            $vacancyId = intval($input['vacancy_id'] ?? 0);
            $requestType = $input['request_type'] ?? '';

            if (!$manpowerRequestId || !$vacancyId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'manpower_request_id and vacancy_id required']);
                exit;
            }

            // Cek duplikasi
            $check = $pdo->prepare("SELECT id FROM ohrm_manpower_vacancy_link WHERE manpower_request_id = :mpr AND vacancy_id = :vac");
            $check->execute([':mpr' => $manpowerRequestId, ':vac' => $vacancyId]);
            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => 'Link already exists']);
                exit;
            }

            // Auto-dapatkan request_type dari manpower_request kalo tidak dikirim
            if (!$requestType) {
                $rtStmt = $pdo->prepare("SELECT req_type FROM ohrm_manpower_request WHERE id = :id");
                $rtStmt->execute([':id' => $manpowerRequestId]);
                $requestType = $rtStmt->fetchColumn() ?: 'Planned';
            }

            $stmt = $pdo->prepare("
                INSERT INTO ohrm_manpower_vacancy_link (manpower_request_id, vacancy_id, request_type)
                VALUES (:mpr, :vac, :rt)
            ");
            $stmt->execute([
                ':mpr' => $manpowerRequestId,
                ':vac' => $vacancyId,
                ':rt'  => $requestType,
            ]);

            // Update kolom manpower_request_id di ohrm_job_vacancy
            $upd = $pdo->prepare("UPDATE ohrm_job_vacancy SET manpower_request_id = :mpr WHERE id = :vac");
            $upd->execute([':mpr' => $manpowerRequestId, ':vac' => $vacancyId]);

            echo json_encode([
                'status' => 'success',
                'data' => ['id' => $pdo->lastInsertId()],
            ]);
            break;

        // =============================================
        // DELETE — Hapus relasi
        // =============================================
        case 'unlink':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'link id required']);
                exit;
            }

            // Dapatkan vacancy_id untuk cleanup
            $getVac = $pdo->prepare("SELECT vacancy_id FROM ohrm_manpower_vacancy_link WHERE id = :id");
            $getVac->execute([':id' => $id]);
            $vacancyId = $getVac->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM ohrm_manpower_vacancy_link WHERE id = :id");
            $stmt->execute([':id' => $id]);

            // Reset manpower_request_id di vacancy
            if ($vacancyId) {
                $upd = $pdo->prepare("UPDATE ohrm_job_vacancy SET manpower_request_id = NULL WHERE id = :id");
                $upd->execute([':id' => $vacancyId]);
            }

            echo json_encode(['status' => 'success', 'deleted' => $stmt->rowCount()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown action. Use: get, list, link, unlink']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
