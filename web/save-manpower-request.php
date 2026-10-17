<?php
/**
 * Manpower Request Save Endpoint
 * Simple endpoint bypassing OrangeHRM API auth system
 */

use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Http\Response;

// Bootstrap OrangeHRM kernel if not already loaded
require_once __DIR__ . '/../../vendor/autoload.php';

$request = Request::createFromGlobals();

if ($request->getMethod() !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => ['status' => 405, 'message' => 'Method not allowed']]);
    exit;
}

try {
    // Get current user
    $container = \OrangeHRM\Framework\ServiceContainer::getContainer();
    $auth = $container->get(\OrangeHRM\Framework\Services::AUTH_USER);
    
    if (!$auth || !$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['error' => ['status' => 401, 'message' => 'Unauthorized']]);
        exit;
    }

    // Parse JSON body
    $data = json_decode($request->getContent(), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => ['status' => 400, 'message' => 'Invalid JSON']]);
        exit;
    }

    // Validate
    $department = trim($data['department'] ?? '');
    $positionName = trim($data['position_name'] ?? '');
    $requestType = trim($data['request_type'] ?? 'Planned');
    $quantity = intval($data['quantity'] ?? 1);
    $description = trim($data['description'] ?? '');
    $targetDate = trim($data['target_date'] ?? '');

    if (empty($department) || empty($positionName)) {
        http_response_code(422);
        echo json_encode(['error' => ['status' => 422, 'message' => 'Department and Position are required']]);
        exit;
    }

    // Get DB connection
    $entityManager = $container->get(\OrangeHRM\Framework\Services::DOCTRINE);
    $conn = $entityManager->getConnection();

    // Auto-generate request number
    $prefix = 'MPR-' . date('Y') . '-';
    $lastNo = $conn->fetchOne(
        "SELECT request_no FROM ohrm_quota_request WHERE request_no LIKE '$prefix%' ORDER BY id DESC LIMIT 1"
    );
    if ($lastNo) {
        $lastSeq = (int) substr($lastNo, strlen($prefix));
        $seq = str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $seq = '001';
    }
    $requestNo = $prefix . $seq;

    // Get requested by name
    $empNumber = $auth->getEmployeeNumber();
    $requestedBy = 'System';
    if ($empNumber) {
        $empName = $conn->fetchOne(
            'SELECT CONCAT(emp_firstname, " ", emp_lastname) FROM hs_hr_employee WHERE emp_number = ?',
            [$empNumber]
        );
        if ($empName) {
            $requestedBy = $empName;
        }
    }

    // Insert
    $conn->insert('ohrm_quota_request', [
        'request_no' => $requestNo,
        'request_type' => $requestType ?: 'Planned',
        'position_name' => $positionName,
        'department' => $department,
        'quantity' => $quantity ?: 1,
        'description' => $description,
        'status' => 'Open',
        'requested_by' => $requestedBy,
        'requested_date' => date('Y-m-d'),
        'target_date' => $targetDate ?: null,
    ]);

    $newId = $conn->lastInsertId();

    header('Content-Type: application/json');
    echo json_encode([
        'data' => [
            'id' => $newId,
            'request_no' => $requestNo,
        ],
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => ['status' => 500, 'message' => $e->getMessage()]]);
}
