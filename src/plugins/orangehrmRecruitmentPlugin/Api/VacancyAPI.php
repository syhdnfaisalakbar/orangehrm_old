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

use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\CrudEndpoint;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointCollectionResult;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Exception\BadRequestException;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\ParameterBag;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Core\Traits\Service\DateTimeHelperTrait;
use OrangeHRM\Core\Traits\UserRoleManagerTrait;
use OrangeHRM\Entity\Employee;
use OrangeHRM\Entity\JobTitle;
use OrangeHRM\Entity\Vacancy;
use OrangeHRM\Recruitment\Api\Model\VacancyDetailedModel;
use OrangeHRM\Recruitment\Api\Model\VacancyModel;
use OrangeHRM\Recruitment\Api\Model\VacancySummaryModel;
use OrangeHRM\Recruitment\Dto\VacancySearchFilterParams;
use OrangeHRM\Recruitment\Traits\Service\VacancyServiceTrait;

class VacancyAPI extends Endpoint implements CrudEndpoint
{
    use VacancyServiceTrait;
    use DateTimeHelperTrait;
    use UserRoleManagerTrait;
    use EntityManagerHelperTrait;

    public const FILTER_JOB_TITLE_ID = 'jobTitleId';
    public const FILTER_VACANCY_ID = 'vacancyId';
    public const FILTER_HIRING_MANAGER_ID = 'hiringManagerId';
    public const FILTER_STATUS = 'status';
    public const FILTER_NAME = 'name';
    public const FILTER_DEPARTMENT = 'department';
    public const FILTER_REJECTED = 'rejected';
    public const FILTER_EXCLUDE_INTERVIEWERS = 'excludeInterviewers';
    public const FILTER_MODEL = 'model';

    public const PARAMETER_NAME = 'name';
    public const PARAMETER_DESCRIPTION = 'description';
    public const PARAMETER_NUM_OF_POSITIONS = 'numOfPositions';
    public const PARAMETER_STATUS = 'status';
    public const PARAMETER_IS_PUBLISHED = 'isPublished';
    public const PARAMETER_JOB_TITLE_ID = 'jobTitleId';
    public const PARAMETER_EMPLOYEE_ID = 'employeeId';
    public const PARAMETER_CASE_ID = 'caseId';
    public const PARAMETER_REQ_TYPE = 'reqType';

    public const PARAMETER_RULE_NAME_MAX_LENGTH = 100;
    public const PARAMETER_RULE_NO_OF_POSITIONS_MAX_LENGTH = 13;

    public const MODEL_DEFAULT = 'default';
    public const MODEL_SUMMARY = 'summary';
    public const MODEL_DETAILED = 'detailed';

    public const MODEL_MAP = [
        self::MODEL_DEFAULT => VacancyModel::class,
        self::MODEL_SUMMARY => VacancySummaryModel::class,
        self::MODEL_DETAILED => VacancyDetailedModel::class,
    ];

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResourceResult
    {
        $id = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_ATTRIBUTE,
            CommonParams::PARAMETER_ID
        );
        $vacancy = $this->getVacancyService()->getVacancyDao()->getVacancyById($id);
        $this->throwRecordNotFoundExceptionIfNotExist($vacancy, Vacancy::class);

        // Fallback: if reqType is null, try to get from ohrm_manpower_request via case_id
        if ($vacancy->getReqType() === null && $vacancy->getCaseId() !== null) {
            $reqType = $this->getEntityManager()->getConnection()->fetchOne(
                'SELECT req_type FROM ohrm_manpower_request WHERE case_id = :case_id LIMIT 1',
                ['case_id' => $vacancy->getCaseId()]
            );
            if ($reqType !== false) {
                $vacancy->setReqType($reqType);
            }
        }

        return new EndpointResourceResult(VacancyDetailedModel::class, $vacancy);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(
                CommonParams::PARAMETER_ID,
                new Rule(Rules::IN_ACCESSIBLE_ENTITY_ID, [Vacancy::class])
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function getAll(): EndpointCollectionResult
    {
        $vacancyParamHolder = new VacancySearchFilterParams();
        $this->setSortingAndPaginationParams($vacancyParamHolder);

        $vacancyId = $this->getRequestParams()->getIntOrNull(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_VACANCY_ID
        );

        $excludeInterviewers = $this->getRequestParams()->getBoolean(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_EXCLUDE_INTERVIEWERS,
        );

        if (!is_null($vacancyId)) {
            $vacancyParamHolder->setVacancyIds([$vacancyId]);
        } else {
            $rolesToExclude = [];
            if ($excludeInterviewers) {
                $rolesToExclude = ['Interviewer'];
            }
            $accessibleVacancyIds = $this->getUserRoleManager()
                ->getAccessibleEntityIds(Vacancy::class, null, null, $rolesToExclude);
            $vacancyParamHolder->setVacancyIds($accessibleVacancyIds);
        }

        $vacancyParamHolder->setJobTitleId(
            $this->getRequestParams()->getIntOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_JOB_TITLE_ID
            )
        );
        $vacancyParamHolder->setEmpNumber(
            $this->getRequestParams()->getIntOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_HIRING_MANAGER_ID
            )
        );
        $vacancyParamHolder->setStatus(
            $this->getRequestParams()->getBooleanOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_STATUS
            )
        );
        $vacancyParamHolder->setName(
            $this->getRequestParams()->getStringOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_NAME
            )
        );
        $vacancyParamHolder->setDepartment(
            $this->getRequestParams()->getStringOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_DEPARTMENT
            )
        );
        $vacancyParamHolder->setRejected(
            $this->getRequestParams()->getBooleanOrNull(
                RequestParams::PARAM_TYPE_QUERY,
                self::FILTER_REJECTED
            )
        );
        $vacancies = $this->getVacancyService()->getVacancyDao()->getVacancies($vacancyParamHolder);
        $count = $this->getVacancyService()->getVacancyDao()->getVacanciesCount($vacancyParamHolder);
        return new EndpointCollectionResult(
            $this->getModelClass(),
            $vacancies,
            new ParameterBag([CommonParams::PARAMETER_TOTAL => $count])
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetAll(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_VACANCY_ID,
                    new Rule(Rules::POSITIVE),
                    new Rule(Rules::IN_ACCESSIBLE_ENTITY_ID, [Vacancy::class])
                )
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_HIRING_MANAGER_ID,
                    new Rule(Rules::POSITIVE)
                )
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_JOB_TITLE_ID,
                    new Rule(Rules::POSITIVE)
                )
            ),
            new ParamRule(
                self::FILTER_STATUS,
                new Rule(Rules::BOOL_VAL)
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_NAME,
                    new Rule(Rules::STRING_TYPE),
                    new Rule(Rules::LENGTH, [0, self::PARAMETER_RULE_NAME_MAX_LENGTH])
                )
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::FILTER_DEPARTMENT,
                    new Rule(Rules::STRING_TYPE),
                    new Rule(Rules::LENGTH, [0, self::PARAMETER_RULE_NAME_MAX_LENGTH])
                )
            ),
            new ParamRule(
                self::FILTER_REJECTED,
                new Rule(Rules::BOOL_VAL)
            ),
            new ParamRule(
                self::FILTER_EXCLUDE_INTERVIEWERS,
                new Rule(Rules::BOOL_VAL),
            ),
            $this->getModelClassParamRule(),
            ...$this->getSortingAndPaginationParamsRules(VacancySearchFilterParams::ALLOWED_SORT_FIELDS)
        );
    }

    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        $model = $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_QUERY,
            self::FILTER_MODEL,
            self::MODEL_DEFAULT,
        );
        return self::MODEL_MAP[$model];
    }

    /**
     * @return ParamRule
     */
    protected function getModelClassParamRule(): ParamRule
    {
        return $this->getValidationDecorator()->notRequiredParamRule(
            new ParamRule(
                self::FILTER_MODEL,
                new Rule(Rules::IN, [array_keys(self::MODEL_MAP)])
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function create(): EndpointResult
    {
        $vacancy = new Vacancy();
        $vacancy->setDefinedTime($this->getDateTimeHelper()->getNow());
        $this->setVacancy($vacancy);
        $vacancy = $this->getVacancyService()->getVacancyDao()->saveJobVacancy($vacancy);

        return new EndpointResourceResult(VacancyDetailedModel::class, $vacancy);
    }

    /**
     * @param Vacancy $vacancy
     * @throws BadRequestException
     */
    private function setVacancy(Vacancy $vacancy): void
    {
        $jobTitleId = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_JOB_TITLE_ID
        );
        if (!$this->getVacancyService()->getVacancyDao()->isActiveJobTitle($jobTitleId)) {
            throw $this->getBadRequestException('Please Select An Active Job Title');
        }

        $hiringManagerId = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_EMPLOYEE_ID
        );
        if (!$this->getVacancyService()->getVacancyDao()->isActiveHiringManger($hiringManagerId)) {
            throw $this->getBadRequestException('Employee No Longer Exists');
        }

        $vacancy->getDecorator()->setJobTitleById($jobTitleId);
        $vacancy->getDecorator()->setEmployeeById($hiringManagerId);

        $vacancy->getDecorator()->setEmployeeById(
            $this->getRequestParams()->getInt(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_EMPLOYEE_ID
            )
        );
        $vacancy->setName(
            $this->getRequestParams()->getString(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_NAME
            )
        );
        $vacancy->setDescription(
            $this->getRequestParams()->getString(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_DESCRIPTION
            )
        );
        $vacancy->setNumOfPositions(
            $this->getRequestParams()->getIntOrNull(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_NUM_OF_POSITIONS
            )
        );
        $vacancy->setIsPublished(
            $this->getRequestParams()->getBoolean(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_IS_PUBLISHED,
                true
            )
        );
        $vacancy->setUpdatedTime($this->getDateTimeHelper()->getNow());
        $vacancy->setStatus(
            $this->getRequestParams()->getBoolean(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_STATUS,
                true
            )
        );
        $vacancy->setCaseId(
            $this->getRequestParams()->getStringOrNull(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_CASE_ID
            )
        );
        $vacancy->setReqType(
            $this->getRequestParams()->getStringOrNull(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_REQ_TYPE
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForCreate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            ...$this->getCommonBodyValidationRules(),
        );
    }

    /**
     * @return ParamRule[]
     */
    protected function getCommonBodyValidationRules(): array
    {
        return [
            new ParamRule(
                self::PARAMETER_NAME,
                new Rule(Rules::STRING_TYPE),
                new Rule(Rules::LENGTH, [null, self::PARAMETER_RULE_NAME_MAX_LENGTH])
            ),
            new ParamRule(
                self::PARAMETER_STATUS,
                new Rule(Rules::BOOL_TYPE),
            ),
            new ParamRule(
                self::PARAMETER_JOB_TITLE_ID,
                new Rule(Rules::POSITIVE),
                new Rule(Rules::ENTITY_ID_EXISTS, [JobTitle::class]),
            ),
            new ParamRule(
                self::PARAMETER_IS_PUBLISHED,
                new Rule(Rules::BOOL_TYPE),
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::PARAMETER_DESCRIPTION,
                    new Rule(Rules::STRING_TYPE),
                ),
                true
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::PARAMETER_CASE_ID,
                    new Rule(Rules::STRING_TYPE),
                    new Rule(Rules::LENGTH, [null, 50]),
                ),
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::PARAMETER_REQ_TYPE,
                    new Rule(Rules::STRING_TYPE),
                    new Rule(Rules::LENGTH, [null, 50]),
                ),
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(
                    self::PARAMETER_NUM_OF_POSITIONS,
                    new Rule(Rules::INT_TYPE),
                    new Rule(Rules::LENGTH, [null, self::PARAMETER_RULE_NO_OF_POSITIONS_MAX_LENGTH])
                )
            ),
            new ParamRule(
                self::PARAMETER_EMPLOYEE_ID,
                new Rule(Rules::POSITIVE),
                new Rule(Rules::ENTITY_ID_EXISTS, [Employee::class])
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function update(): EndpointResult
    {
        $id = $this->getRequestParams()->getInt(RequestParams::PARAM_TYPE_ATTRIBUTE, CommonParams::PARAMETER_ID);
        $vacancy = $this->getVacancyService()->getVacancyDao()->getVacancyById($id);
        $this->throwRecordNotFoundExceptionIfNotExist($vacancy, Vacancy::class);

        // Catat status lama sebelum di-overwrite
        $oldStatus = $vacancy->getStatus();

        $this->setVacancy($vacancy);
        $this->getVacancyService()->getVacancyDao()->saveJobVacancy($vacancy);

        // Trigger: jika vacancy berubah dari Active (true) ke Closed (false),
        // cek dan tutup manpower request yang ter-link jika semua vacancy-nya sudah closed
        $newStatus = $vacancy->getStatus();
        if ($oldStatus === true && $newStatus === false) {
            $this->closeManpowerRequestIfAllVacanciesClosed($id);
            // Request Type Replacement/Unplanned: tambahkan jumlah posisi ke kolom kuota departemen
            $this->updateClosedVacancyQuota($id);
        }

        return new EndpointResourceResult(VacancyDetailedModel::class, $vacancy);
    }

    /**
     * Untuk vacancy dengan Request Type = Replacement atau Unplanned:
     * saat vacancy di-close, tambahkan no_of_positions ke kolom kuota terkait
     * di ohrm_ext_department_quota (menu All Quota Department):
     *   - Replacement → kolom replacement
     *   - Unplanned   → kolom unplanned
     * Dicocokkan lewat departemen manpower request yang ter-link.
     */
    private function updateClosedVacancyQuota(int $vacancyId): void
    {
        $conn = $this->getEntityManager()->getConnection();

        // RECOMPUTE idempotent (2026-09-01): kolom replacement & unplanned di
        // ohrm_ext_department_quota dihitung ulang dari SEMUA vacancy yang
        // closed (status=0, rejected!=1) — BUKAN tambah (+), jadi aman dipanggil
        // berulang dan menyembuhkan double-count lama (vacancy yang ditutup
        // lewat dua jalur tidak akan ditambah 2x). Logika sama dengan
        // recomputeClosedVacancyQuota() di sync_application_to_acumatica.php.
        // UPSERT (2026-09-09): selain UPDATE, INSERT baris dept/tahun yang belum
        // ada di ohrm_ext_department_quota — replacement/unplanned vacancy closed
        // tetap ke-record meski belum ada baris kuota thn itu (mis. IT 2026).
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
        try {
            $conn->executeStatement($sql);
        } catch (\Throwable $e) {
            // Best-effort: error recompute quota tidak boleh menggagalkan close vacancy
        }
    }

    /**
     * Cari manpower request yang terhubung ke vacancy ini.
     * Jika SEMUA vacancy yang ter-link sudah closed, update status MPR ke 'Closed'.
     */
    private function closeManpowerRequestIfAllVacanciesClosed(int $vacancyId): void
    {
        $conn = $this->getEntityManager()->getConnection();

        // Cari manpower_request_id — coba dari kolom langsung dulu, fallback ke link table
        $mprId = $conn->fetchOne(
            'SELECT manpower_request_id FROM ohrm_job_vacancy WHERE id = :id AND manpower_request_id IS NOT NULL',
            ['id' => $vacancyId]
        );

        if (!$mprId) {
            $mprId = $conn->fetchOne(
                'SELECT manpower_request_id FROM ohrm_manpower_vacancy_link WHERE vacancy_id = :id',
                ['id' => $vacancyId]
            );
        }

        if (!$mprId) {
            return; // Vacancy ini tidak terhubung ke manpower request manapun
        }

        // Cek apakah masih ada vacancy aktif yang ter-link ke MPR ini
        // (cek dari kolom manpower_request_id ATAU link table, biar aman).
        // Vacancy yang di-reject (rejected=1) TIDAK dihitung — kalau semua
        // vacancy ter-link rejected, MPR dibiarkan tetap 'Rejected'.
        $linkClause = '(v.manpower_request_id = :mpr_id
            OR v.id IN (SELECT vacancy_id FROM ohrm_manpower_vacancy_link WHERE manpower_request_id = :mpr_id))';

        $totalNonRejected = $conn->fetchOne(
            'SELECT COUNT(DISTINCT v.id)
             FROM ohrm_job_vacancy v
             WHERE v.rejected = 0 AND ' . $linkClause,
            ['mpr_id' => $mprId]
        );

        if ((int)$totalNonRejected === 0) {
            return; // Semua vacancy ter-link di-reject — jangan timpa MPR jadi Closed
        }

        $activeCount = $conn->fetchOne(
            'SELECT COUNT(DISTINCT v.id)
             FROM ohrm_job_vacancy v
             WHERE v.status = 1 AND v.rejected = 0 AND ' . $linkClause,
            ['mpr_id' => $mprId]
        );

        if ((int)$activeCount > 0) {
            return; // Masih ada vacancy aktif — jangan close MPR
        }

        // Semua vacancy sudah closed — update status MPR
        $conn->executeStatement(
            "UPDATE ohrm_manpower_request SET status = 'Closed', updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['id' => $mprId]
        );

        // Push ke Acumatica: close case-nya juga, biar sinkron saat sync berikutnya.
        // Best-effort — kegagalan push tidak menggagalkan update vacancy.
        $caseId = $conn->fetchOne(
            'SELECT case_id FROM ohrm_manpower_request WHERE id = :id',
            ['id' => $mprId]
        );
        if ($caseId) {
            $proxyFile = dirname(__DIR__, 4) . '/web/proxy/close_case_acumatica.php';
            if (file_exists($proxyFile)) {
                require_once $proxyFile;
                try {
                    closeCaseInAcumatica((string)$caseId);
                } catch (\Throwable $e) {
                    // abaikan — push bersifat best-effort
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(
                CommonParams::PARAMETER_ID,
                new Rule(Rules::POSITIVE)
            ),
            ...$this->getCommonBodyValidationRules(),
        );
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        $ids = $this->getRequestParams()->getArray(RequestParams::PARAM_TYPE_BODY, CommonParams::PARAMETER_IDS);
        $this->getVacancyService()->getVacancyDao()->deleteVacancies($ids);
        return new EndpointResourceResult(ArrayModel::class, $ids);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(
                CommonParams::PARAMETER_IDS,
                new Rule(Rules::ARRAY_TYPE)
            ),
        );
    }
}
