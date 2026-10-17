<?php

namespace OrangeHRM\Admin\Controller;

use OrangeHRM\Core\Controller\AbstractController;
use OrangeHRM\Core\Controller\PublicControllerInterface;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Http\Response;

class NotificationController extends AbstractController implements PublicControllerInterface
{
    use EntityManagerHelperTrait;
    use AuthUserTrait;

    public function handle(Request $request): Response
    {
        $response = new Response();
        $response->headers->set('Content-Type', 'application/json');

        try {
            $auth = $this->getAuthUser();
            if (!$auth->isAuthenticated()) {
                $response->setStatusCode(401);
                $response->setContent(json_encode([
                    'error' => ['status' => 401, 'message' => 'Unauthorized']
                ]));
                return $response;
            }

            $conn = $this->getEntityManager()->getConnection();

            // Emp user yang login + cek admin (dipakai untuk scoping GRUP 3 KPI)
            $authEmpNumber = (int) $auth->getEmpNumber();
            $authRoleId = (int) $conn->executeQuery(
                'SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1',
                [$authEmpNumber]
            )->fetchOne();
            $isAdmin = ($authRoleId === 1);

            // Param opsional: hanya tampilkan approval dengan ID > after_id
            $afterId = (int) $request->query->get('after', 0);

            $sql = "SELECT a.id, a.case_id, a.summary, a.owner, a.priority, a.status, a.updated_at,
                           r.department, r.position, r.subject as request_subject,
                           'approval_task' AS type
                    FROM ohrm_manpower_approval a
                    LEFT JOIN ohrm_manpower_request r ON a.case_id = r.case_id
                    WHERE a.status = 'Completed'
                      -- History notifikasi otomatis hilang setelah 2 jam
                      AND a.updated_at >= (NOW() - INTERVAL 2 HOUR)";
            $params = [];

            if ($afterId > 0) {
                $sql .= " AND a.id > :after_id";
                $params['after_id'] = $afterId;
            }

            $sql .= " ORDER BY a.updated_at DESC LIMIT 50";

            $rows = $conn->fetchAllAssociative($sql, $params);

            // ============================================================
            // GROUP 2: HRPLAN Quota — Case yang sudah Completed / Approved
            // Sumber: ohrm_quota_requests + ohrm_approval_history +
            //         ohrm_case_attributes (attribute DEPARTMENT/POSITION).
            // 1 baris per case (approval diambil yang terakhir).
            // ============================================================
            $hrplanSql = "
                SELECT
                    q.case_id,
                    q.subject,
                    q.owner,
                    COALESCE(a.approval_status, q.current_status) AS status,
                    q.sync_date AS updated_at,
                    a.approval_id AS id,
                    COALESCE(d.attribute_value, '-') AS department,
                    COALESCE(p.attribute_value, '-') AS position,
                    'hrplan_completed' AS type
                FROM ohrm_quota_requests q
                LEFT JOIN (
                    SELECT ref_note_id,
                           MAX(approval_id) AS approval_id,
                           MAX(CASE WHEN approval_status IN ('Completed', 'Approved')
                                    THEN approval_status END) AS approval_status
                    FROM ohrm_approval_history
                    WHERE approval_status IN ('Completed', 'Approved')
                    GROUP BY ref_note_id
                ) a ON a.ref_note_id = q.note_id
                LEFT JOIN ohrm_case_attributes d
                    ON d.ref_note_id = q.note_id AND d.attribute_name = 'DEPARTMENT'
                LEFT JOIN ohrm_case_attributes p
                    ON p.ref_note_id = q.note_id AND p.attribute_name = 'POSITION'
                WHERE q.case_class = 'HRPLAN'
                  AND (a.approval_id IS NOT NULL
                       OR UPPER(q.current_status) IN ('COMPLETED', 'APPROVED'))
                  -- History notifikasi otomatis hilang setelah 2 jam
                  AND q.sync_date >= (NOW() - INTERVAL 2 HOUR)
                ORDER BY q.sync_date DESC, q.case_id DESC
                LIMIT 50
            ";
            $hrplanRows = $conn->fetchAllAssociative($hrplanSql);

            // ============================================================
            // GROUP 3: Approval KPI — pengajuan EDIT DEFINISI indikator yang
            // sudah diputuskan admin (Disetujui / Ditolak) di menu Approval KPI
            // (tab "Edit Indikator" → web/proxy/update_setup_edit_request.php).
            //
            // Penerima: pengaju (requested_by) + SEMUA admin.
            // Window 7 hari (keputusan KPI frekuensinya rendah, bukan 2 jam).
            // Tidak ada tabel notifikasi baru — pakai kolom approved_at /
            // request_status yang sudah ada di ohrm_kpi_setup_edit_request, jadi
            // TIDAK butuh ALTER TABLE (aman walau migrasi prod belum jalan).
            // ============================================================
            $kpiSql = "
                SELECT
                    r.id,
                    r.setup_id,
                    r.divisi,
                    r.target,
                    r.bobot,
                    r.schedule,
                    r.request_status AS status,
                    r.reject_reason AS note,
                    r.requested_by,
                    r.approved_by,
                    r.approved_at AS updated_at,
                    s.indikator,
                    s.satuan,
                    s.periode,
                    s.cabang,
                    TRIM(CONCAT(COALESCE(e.emp_firstname, ''), ' ', COALESCE(e.emp_lastname, ''))) AS requester_name,
                    'kpi_edit_request' AS type
                FROM ohrm_kpi_setup_edit_request r
                LEFT JOIN ohrm_kpi_setup s ON s.id = r.setup_id
                LEFT JOIN hs_hr_employee e ON e.emp_number = r.requested_by
                WHERE r.request_status IN ('Disetujui', 'Ditolak')
                  AND r.approved_at IS NOT NULL
                  AND r.approved_at >= (NOW() - INTERVAL 7 DAY)
            ";
            $kpiParams = [];
            if (!$isAdmin) {
                // Non-admin hanya melihat keputusan atas pengajuan MILIKNYA.
                $kpiSql .= ' AND r.requested_by = :auth_emp';
                $kpiParams['auth_emp'] = $authEmpNumber;
            }
            $kpiSql .= ' ORDER BY r.approved_at DESC, r.id DESC LIMIT 50';

            $kpiRows = $conn->fetchAllAssociative($kpiSql, $kpiParams);

            $response->setContent(json_encode([
                'data' => [
                    'total' => count($rows) + count($hrplanRows) + count($kpiRows),
                    'items' => [
                        [
                            'type' => 'approval_task',
                            'label' => 'Approval Completed',
                            'count' => count($rows),
                            'list' => $rows,
                        ],
                        [
                            'type' => 'hrplan_completed',
                            'label' => 'HRPLAN Quota Approved',
                            'count' => count($hrplanRows),
                            'list' => $hrplanRows,
                        ],
                        [
                            'type' => 'kpi_edit_request',
                            'label' => 'Approval KPI',
                            'count' => count($kpiRows),
                            'list' => $kpiRows,
                        ],
                    ],
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
