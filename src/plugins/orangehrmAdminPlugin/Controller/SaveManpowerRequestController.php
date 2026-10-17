<?php

namespace OrangeHRM\Admin\Controller;

use OrangeHRM\Core\Controller\AbstractController;
use OrangeHRM\Core\Controller\PublicControllerInterface;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Http\Response;

class SaveManpowerRequestController extends AbstractController implements PublicControllerInterface
{
    use EntityManagerHelperTrait;
    use AuthUserTrait;

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');

        if ($request->getMethod() === 'GET') {
            return $this->handleGet($request, $response);
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $response);
        }

        $response->setStatusCode(405);
        $response->setContent(json_encode([
            'error' => ['status' => 405, 'message' => 'Method not allowed']
        ]));
        return $response;
    }

    /**
     * GET: Search quota requests for case_id field
     */
    private function handleGet(Request $request, Response $response): Response
    {
        try {
            $auth = $this->getAuthUser();
            if (!$auth->isAuthenticated()) {
                $response->setStatusCode(401);
                $response->setContent(json_encode([
                    'error' => ['status' => 401, 'message' => 'Unauthorized']
                ]));
                return $response;
            }

            $search = trim($request->query->get('search', ''));
            $conn = $this->getEntityManager()->getConnection();

            $sql = "SELECT qr.id, qr.request_no, qr.department, qr.position_name 
                    FROM ohrm_quota_request qr 
                    WHERE 1=1";
            $params = [];

            if ($search) {
                $sql .= " AND (qr.request_no LIKE :search OR qr.department LIKE :search OR qr.position_name LIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            $sql .= " ORDER BY qr.id DESC LIMIT 20";

            $rows = $conn->fetchAllAssociative($sql, $params);

            $response->setContent(json_encode(['data' => $rows]));

        } catch (\Exception $e) {
            $response->setStatusCode(500);
            $response->setContent(json_encode([
                'error' => ['status' => 500, 'message' => $e->getMessage()]
            ]));
        }

        return $response;
    }

    /**
     * POST: Save new manpower request
     */
    private function handlePost(Request $request, Response $response): Response
    {
        try {
            $auth = $this->getAuthUser();
            if (!$auth->isAuthenticated()) {
                $response->setStatusCode(401);
                $response->setContent(json_encode([
                    'error' => ['status' => 401, 'message' => 'Unauthorized']
                ]));
                return $response;
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                $response->setStatusCode(400);
                $response->setContent(json_encode([
                    'error' => ['status' => 400, 'message' => 'Invalid JSON']
                ]));
                return $response;
            }

            $department = trim($data['department'] ?? '');
            $positionName = trim($data['position_name'] ?? '');
            $requestType = trim($data['request_type'] ?? 'Planned');
            $quantity = intval($data['quantity'] ?? 1);
            $caseId = $data['case_id'] ? intval($data['case_id']) : null;
            $gender = trim($data['gender'] ?? '');
            $employmentStatus = trim($data['employment_status'] ?? '');
            $skill = trim($data['skill'] ?? '');
            $jobDescription = trim($data['job_description'] ?? '');
            $description = trim($data['description'] ?? '');
            $targetDate = trim($data['target_date'] ?? '');

            if (empty($department) || empty($positionName)) {
                $response->setStatusCode(422);
                $response->setContent(json_encode([
                    'error' => ['status' => 422, 'message' => 'Department and Position are required']
                ]));
                return $response;
            }

            $conn = $this->getEntityManager()->getConnection();

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

            // Get requested by
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

            $conn->insert('ohrm_quota_request', [
                'request_no' => $requestNo,
                'request_type' => $requestType ?: 'Planned',
                'position_name' => $positionName,
                'department' => $department,
                'quantity' => $quantity ?: 1,
                'case_id' => $caseId,
                'gender' => $gender ?: null,
                'employment_status' => $employmentStatus ?: null,
                'skill' => $skill ?: null,
                'job_description' => $jobDescription ?: null,
                'description' => $description,
                'status' => 'Open',
                'requested_by' => $requestedBy,
                'requested_date' => date('Y-m-d'),
                'target_date' => $targetDate ?: null,
            ]);

            $newId = $conn->lastInsertId();

            $response->setContent(json_encode([
                'data' => [
                    'id' => $newId,
                    'request_no' => $requestNo,
                ]
            ]));

        } catch (\Exception $e) {
            $response->setStatusCode(500);
            $response->setContent(json_encode([
                'error' => ['status' => 500, 'message' => $e->getMessage()]
            ]));
        }

        return $response;
    }
}
