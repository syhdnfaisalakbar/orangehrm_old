<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */

namespace OrangeHRM\Recruitment\Api;

use Exception;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Exception\ForbiddenException;
use OrangeHRM\Core\Api\V2\Exception\RecordNotFoundException;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\ResourceEndpoint;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Core\Traits\Service\DateTimeHelperTrait;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Entity\Candidate;
use OrangeHRM\Entity\CandidateHistory;
use OrangeHRM\Entity\CandidateVacancy;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\Vacancy;
use OrangeHRM\Entity\WorkflowStateMachine;
use OrangeHRM\ORM\Exception\TransactionException;
use OrangeHRM\Pim\Traits\Service\EmployeeServiceTrait;
use OrangeHRM\Recruitment\Api\Model\CandidateHistoryDefaultModel;
use OrangeHRM\Recruitment\Service\CandidateService;
use OrangeHRM\Recruitment\Traits\Service\CandidateServiceTrait;

abstract class AbstractCandidateActionAPI extends Endpoint implements ResourceEndpoint
{
    use CandidateServiceTrait;
    use EntityManagerHelperTrait;
    use AuthUserTrait;
    use DateTimeHelperTrait;
    use UserRoleManagerTrait;
    use EmployeeServiceTrait;

    public const PARAMETER_CANDIDATE_ID = 'candidateId';
    public const PARAMETER_INTERVIEW_ID = 'interviewId';
    public const PARAMETER_NOTE = 'note';

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     * @throws TransactionException|ForbiddenException | RecordNotFoundException
     */
    public function update(): EndpointResult
    {
        $this->beginTransaction();
        try {
            $candidateId = $this->getRequestParams()->getInt(
                RequestParams::PARAM_TYPE_ATTRIBUTE,
                self::PARAMETER_CANDIDATE_ID
            );

            $candidateVacancy = $this->getCandidateService()
                ->getCandidateDao()
                ->getCandidateVacancyByCandidateId($candidateId);
            $this->throwRecordNotFoundExceptionIfNotExist($candidateVacancy, CandidateVacancy::class);

            $vacancy = $candidateVacancy->getVacancy();

            if (
                !$vacancy->getStatus()
                && $this->getResultingState() !== WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_HIRE_CANCELLED
            ) {
                throw $this->getForbiddenException();
            }

            $rolesToExclude = [];
            if (!$this->isAuthUserHiringManager($vacancy)) {
                $rolesToExclude = ['HiringManager'];
            }

            $allowedWorkflowItems = $this->getUserRoleManager()->getAllowedActions(
                WorkflowStateMachine::FLOW_RECRUITMENT,
                $candidateVacancy->getStatus(),
                $rolesToExclude
            );
            if (
                !in_array($this->getResultingState(), array_keys($allowedWorkflowItems))
            ) {
                throw $this->getForbiddenException();
            }

            $candidateVacancy->setStatus(
                $this->getResultingState() === WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_HIRE_CANCELLED
                    ? 'REJECTED'
                    : CandidateService::STATUS_MAP[$this->getResultingState()]
            );

            $this->getCandidateService()->getCandidateDao()->saveCandidateVacancy($candidateVacancy);

            if ($this->getResultingState() === WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_HIRE) {
                $employee = new Employee();
                $this->setCandidateAsEmployee($candidateVacancy, $employee);
                $this->getEmployeeService()->getEmployeeDao()->saveEmployee($employee);
            }

            if ($this->getResultingState() === WorkflowStateMachine::RECRUITMENT_APPLICATION_ACTION_HIRE_CANCELLED) {
                $this->handleHireCancellationSideEffects($candidateVacancy);
            }

            $candidateHistory = new CandidateHistory();
            $this->setCandidateHistory($candidateHistory, $candidateVacancy);
            $result = $this->getCandidateService()->getCandidateDao()->saveCandidateHistory($candidateHistory);

            $this->commitTransaction();
            return new EndpointResourceResult(CandidateHistoryDefaultModel::class, $result);
        } catch (RecordNotFoundException|ForbiddenException $e) {
            $this->rollBackTransaction();
            throw $e;
        } catch (Exception $e) {
            $this->rollBackTransaction();
            throw new TransactionException($e);
        }
    }

    /**
     * Efek samping "Cancel Hire" (kandidat yang sudah HIRED tapi batal masuk).
     * Dipanggil dalam transaction update() pada aksi RECRUITMENT_APPLICATION_ACTION_HIRE_CANCELLED:
     *   (1) Hapus record employee otomatis yang dibuat saat HIRE (identity match ketat).
     *   (2) Buka kembali vacancy jika ditutup gara-gara hire ini dan jumlah HIRED kini < posisi.
     *   (3) Buka kembali MPR ter-link jika statusnya 'Closed' dan kembali punya vacancy aktif.
     *   (4) Recompute kuota Replacement/Unplanned (idempotent) agar posisi vacancy yang
     *       dibuka tidak lagi terhitung sebagai lowongan tertutup.
     * Semua best-effort — kegagalan di sini tidak boleh menggagalkan ubah status kandidat.
     */
    private function handleHireCancellationSideEffects(CandidateVacancy $candidateVacancy): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $candidate = $candidateVacancy->getCandidate();
        $vacancy = $candidateVacancy->getVacancy();
        $vacancyId = $vacancy->getId();

        // (1) Hapus employee "hantu" yang dibuat saat aksi HIRE.
        // Identity match ketat: work_email + first/last name + job_title_code sama persis,
        // dan hanya jika pas satu baris — supaya tidak menghapus employee yang kebetulan
        // punya email sama (mis. candidate yang sudah jadi karyawan dan melamar lagi).
        try {
            $matches = $conn->fetchAllAssociative(
                'SELECT emp_number FROM hs_hr_employee
                 WHERE emp_work_email = :email
                   AND emp_firstname = :fn
                   AND emp_lastname = :ln
                   AND job_title_code = :jt',
                [
                    'email' => $candidate->getEmail(),
                    'fn' => $candidate->getFirstName(),
                    'ln' => $candidate->getLastName(),
                    'jt' => $vacancy->getJobTitle() ? $vacancy->getJobTitle()->getId() : null,
                ]
            );
            if (count($matches) === 1) {
                $conn->executeStatement(
                    'DELETE FROM hs_hr_employee WHERE emp_number = :n',
                    ['n' => $matches[0]['emp_number']]
                );
            }
        } catch (\Throwable $e) {
            // best-effort — jangan gagalkan cancel
        }

        // (2)(3)(4) Koreksi vacancy / MPR / kuota.
        try {
            // Hitung ulang HIRED untuk vacancy ini (kandidat tadi sudah jadi REJECTED,
            // sehingga secara alami tidak dihitung lagi).
            $hired = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM ohrm_job_candidate_vacancy
                 WHERE vacancy_id = :vid AND status = \'HIRED\'',
                ['vid' => $vacancyId]
            );
            $positions = (int) $conn->fetchOne(
                'SELECT COALESCE(no_of_positions, 1) FROM ohrm_job_vacancy WHERE id = :vid',
                ['vid' => $vacancyId]
            );
            $vacInfo = $conn->fetchAssociative(
                'SELECT status, rejected FROM ohrm_job_vacancy WHERE id = :vid',
                ['vid' => $vacancyId]
            );

            $reopened = false;
            if (
                $vacInfo
                && $vacInfo['rejected'] != 1
                && (int)$vacInfo['status'] === 0
                && $hired < $positions
            ) {
                // Buka kembali vacancy (status 0 -> 1 / active)
                $conn->executeStatement(
                    'UPDATE ohrm_job_vacancy SET status = 1, updated_time = NOW() WHERE id = :vid',
                    ['vid' => $vacancyId]
                );
                $reopened = true;
            }

            if ($reopened) {
                // Temukan MPR ter-link (kolom langsung dulu, fallback ke link table)
                $mprId = $conn->fetchOne(
                    'SELECT manpower_request_id FROM ohrm_job_vacancy
                     WHERE id = :vid AND manpower_request_id IS NOT NULL',
                    ['vid' => $vacancyId]
                );
                if (!$mprId) {
                    $mprId = $conn->fetchOne(
                        'SELECT manpower_request_id FROM ohrm_manpower_vacancy_link WHERE vacancy_id = :vid',
                        ['vid' => $vacancyId]
                    );
                }
                if ($mprId) {
                    $openCount = (int) $conn->fetchOne(
                        'SELECT COUNT(DISTINCT v.id) FROM ohrm_job_vacancy v
                         WHERE v.status = 1 AND v.rejected = 0
                           AND (v.manpower_request_id = :m
                                OR v.id IN (SELECT vacancy_id FROM ohrm_manpower_vacancy_link WHERE manpower_request_id = :m2))',
                        ['m' => $mprId, 'm2' => $mprId]
                    );
                    if ($openCount > 0) {
                        $conn->executeStatement(
                            "UPDATE ohrm_manpower_request SET status = 'Open', updated_at = CURRENT_TIMESTAMP
                             WHERE id = :id AND status = 'Closed'",
                            ['id' => $mprId]
                        );

                        // Push Status -> Open ke Acumatica supaya case ikut terbuka
                        // (kebalikan dari close-case saat vacancy/MPR di-close).
                        $caseId = $conn->fetchOne(
                            'SELECT case_id FROM ohrm_manpower_request WHERE id = :id',
                            ['id' => $mprId]
                        );
                        if ($caseId) {
                            $reopenProxy = dirname(__DIR__, 4) . '/web/proxy/reopen_case_acumatica.php';
                            if (file_exists($reopenProxy)) {
                                require_once $reopenProxy;
                                try {
                                    reopenCaseInAcumatica((string)$caseId);
                                } catch (\Throwable $e) {
                                    // best-effort — gagal push tidak menggagalkan cancel hire
                                }
                            }
                        }
                    }
                }

                // (4) Recomputed Replacement/Unplanned quota dari SEMUA vacancy closed (idempotent).
                try {
                    $this->recomputeClosedVacancyQuota($conn);
                } catch (\Throwable $e) {
                    // best-effort
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Recomputed idempotent kolom replacement/unplanned di ohrm_ext_department_quota
     * dari semua vacancy closed (status=0, rejected!=1). Parameter $reopenedVacancyId
     * tidak dipakai di SQL — recompute selalu dari kondisi DB saat ini (dan vacancy
     * yang dibuka sudah status=1 sehingga otomatis tidak terhitung).
     */
    private function recomputeClosedVacancyQuota($conn): void
    {
        $sql = "
            INSERT INTO ohrm_ext_department_quota
                (dept_name, year, total_additional, replacement, reduction, unplanned)
            SELECT
                vm.dept_name,
                vm.yr,
                0,
                COALESCE(vm.repl, 0),
                0,
                COALESCE(vm.unpl, 0)
            FROM (
                SELECT TRIM(mr.department) AS dept_name,
                       YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at)) AS yr,
                       SUM(CASE WHEN UPPER(COALESCE(v.req_type, '')) = 'REPLACEMENT'
                                THEN COALESCE(v.no_of_positions, 1) ELSE 0 END) AS repl,
                       SUM(CASE WHEN UPPER(COALESCE(v.req_type, '')) = 'UNPLANNED'
                                THEN COALESCE(v.no_of_positions, 1) ELSE 0 END) AS unpl
                FROM ohrm_job_vacancy v
                JOIN ohrm_manpower_request mr
                     ON (COALESCE(v.manpower_request_id,
                         (SELECT MAX(l.manpower_request_id)
                          FROM ohrm_manpower_vacancy_link l
                          WHERE l.vacancy_id = v.id)) = mr.id
                         OR (v.manpower_request_id IS NULL
                             AND NOT EXISTS(
                                 SELECT 1 FROM ohrm_manpower_vacancy_link x
                                 WHERE x.vacancy_id = v.id)
                             AND mr.case_id IS NOT NULL
                             AND v.case_id IS NOT NULL
                             AND (mr.case_id = v.case_id
                                  OR CAST(mr.case_id AS UNSIGNED) = CAST(v.case_id AS UNSIGNED))))
                WHERE v.status = 0
                  AND v.rejected != 1
                  AND UPPER(COALESCE(v.req_type, '')) IN ('REPLACEMENT', 'UNPLANNED')
                GROUP BY TRIM(mr.department),
                         YEAR(COALESCE(NULLIF(mr.target_date, '0000-00-00'), mr.created_at))
            ) vm
            ON DUPLICATE KEY UPDATE
                replacement = VALUES(replacement),
                unplanned   = VALUES(unplanned)
        ";
        $conn->executeStatement($sql);
    }

    /**
     * @param CandidateHistory $candidateHistory
     * @param CandidateVacancy $candidateVacancy
     */
    protected function setCandidateHistory(CandidateHistory $candidateHistory, CandidateVacancy $candidateVacancy): void
    {
        $candidateHistory->getDecorator()->setCandidateById($candidateVacancy->getCandidate()->getId());
        $candidateHistory->getDecorator()->setVacancyById($candidateVacancy->getVacancy()->getId());
        $candidateHistory->setCandidateVacancyName($candidateVacancy->getVacancy()->getName());
        $candidateHistory->setAction($this->getResultingState());
        $candidateHistory->getDecorator()->setPerformedBy($this->getAuthUser()->getEmpNumber());
        $candidateHistory->setPerformedDate($this->getDateTimeHelper()->getNow());

        $candidateHistory->setNote(
            $this->getRequestParams()->getStringOrNull(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_NOTE
            )
        );
        if (!is_null($this->getInterviewId())) {
            $candidateHistory->getDecorator()->setInterviewByInterviewId($this->getInterviewId());
        }
    }

    /**
     * @param Vacancy $vacancy
     * @return bool
     */
    private function isAuthUserHiringManager(Vacancy $vacancy): bool
    {
        $hiringMangerEmpNumber = $vacancy->getHiringManager()->getEmpNumber();
        return $hiringMangerEmpNumber === $this->getAuthUser()->getEmpNumber();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(
                self::PARAMETER_CANDIDATE_ID,
                new Rule(Rules::IN_ACCESSIBLE_ENTITY_ID, [Candidate::class])
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::PARAMETER_NOTE,
                    new Rule(Rules::STRING_TYPE)
                ),
                true
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @return int|null
     */
    protected function getInterviewId(): ?int
    {
        return $this->getRequestParams()->getIntOrNull(
            RequestParams::PARAM_TYPE_ATTRIBUTE,
            self::PARAMETER_INTERVIEW_ID
        );
    }


    /**
     * @param CandidateVacancy $candidateVacancy
     * @param Employee $employee
     */
    protected function setCandidateAsEmployee(CandidateVacancy $candidateVacancy, Employee $employee)
    {
        $employee->setFirstName($candidateVacancy->getCandidate()->getFirstName());
        $employee->setMiddleName($candidateVacancy->getCandidate()->getMiddleName() ?? '');
        $employee->setLastName($candidateVacancy->getCandidate()->getLastName());
        $employee->setOtherEmail($candidateVacancy->getCandidate()->getEmail());
        $employee->getDecorator()->setJobTitleById(
            $candidateVacancy->getVacancy()->getJobTitle()->getId()
        );
    }

    /**
     * @return int
     */
    abstract public function getResultingState(): int;
}
