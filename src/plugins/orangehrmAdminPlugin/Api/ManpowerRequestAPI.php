<?php

namespace OrangeHRM\Admin\Api;

use OrangeHRM\Core\Api\V2\CrudEndpoint;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Exception\RecordNotFoundException;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;

class ManpowerRequestAPI extends Endpoint implements CrudEndpoint
{
    use EntityManagerHelperTrait;
    use AuthUserTrait;

    /**
     * @inheritDoc
     */
    public function getAll(): EndpointResult
    {
        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT * FROM ohrm_quota_request ORDER BY created_at DESC'
        );
        return new EndpointResourceResult(ArrayModel::class, $rows);
    }

    /**
     * @inheritDoc
     */
    public function create(): EndpointResult
    {
        $params = $this->getRequestParams();
        $department = $params->getStringOrNull(RequestParams::BODY, 'department');
        $positionName = $params->getStringOrNull(RequestParams::BODY, 'position_name');
        $requestType = $params->getStringOrNull(RequestParams::BODY, 'request_type');
        $quantity = $params->getIntOrNull(RequestParams::BODY, 'quantity');
        $description = $params->getStringOrNull(RequestParams::BODY, 'description');
        $targetDate = $params->getStringOrNull(RequestParams::BODY, 'target_date');

        // Auto-generate request number: MPR-YYYY-NNN
        $prefix = 'MPR-' . date('Y') . '-';
        $conn = $this->getEntityManager()->getConnection();
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

        // Get current user
        $empNumber = $this->getAuthUser()->getEmployeeNumber();
        $requestedBy = 'System';
        if ($empNumber) {
            $empName = $conn->fetchOne(
                'SELECT CONCAT(emp_firstname, \' \', emp_lastname) FROM hs_hr_employee WHERE emp_number = ?',
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
            'description' => $description,
            'status' => 'Open',
            'requested_by' => $requestedBy,
            'requested_date' => date('Y-m-d'),
            'target_date' => $targetDate ?: null,
        ]);

        $newId = $conn->lastInsertId();

        return new EndpointResourceResult(ArrayModel::class, [
            'id' => $newId,
            'request_no' => $requestNo,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResult
    {
        $id = $this->getRequestParams()->getInt(RequestParams::ATTRIBUTE, \OrangeHRM\Core\Api\CommonParams::PARAMETER_ID);
        $conn = $this->getEntityManager()->getConnection();
        $item = $conn->fetchAssociative(
            'SELECT * FROM ohrm_quota_request WHERE id = ?',
            [$id]
        );
        if (!$item) {
            throw new RecordNotFoundException('Manpower request not found');
        }
        return new EndpointResourceResult(ArrayModel::class, $item);
    }

    /**
     * @inheritDoc
     */
    public function update(): EndpointResult
    {
        $id = $this->getRequestParams()->getInt(RequestParams::ATTRIBUTE, \OrangeHRM\Core\Api\CommonParams::PARAMETER_ID);
        $params = $this->getRequestParams();
        $conn = $this->getEntityManager()->getConnection();

        $existing = $conn->fetchAssociative(
            'SELECT * FROM ohrm_quota_request WHERE id = ?',
            [$id]
        );
        if (!$existing) {
            throw new RecordNotFoundException('Manpower request not found');
        }

        $data = [];
        foreach (['department', 'position_name', 'request_type', 'quantity', 'description', 'target_date', 'status'] as $field) {
            $val = $params->getStringOrNull(RequestParams::BODY, $field);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }

        if (!empty($data)) {
            $conn->update('ohrm_quota_request', $data, ['id' => $id]);
        }

        $updated = $conn->fetchAssociative(
            'SELECT * FROM ohrm_quota_request WHERE id = ?',
            [$id]
        );
        return new EndpointResourceResult(ArrayModel::class, $updated);
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        $id = $this->getRequestParams()->getInt(RequestParams::ATTRIBUTE, \OrangeHRM\Core\Api\CommonParams::PARAMETER_ID);
        $conn = $this->getEntityManager()->getConnection();
        $deleted = $conn->delete('ohrm_quota_request', ['id' => $id]);
        return new EndpointResourceResult(ArrayModel::class, ['deleted' => $deleted]);
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetAll(): ParamRuleCollection
    {
        return new ParamRuleCollection();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForCreate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule('department', new Rule(Rules::STRING_TYPE)),
            new ParamRule('position_name', new Rule(Rules::STRING_TYPE)),
            new ParamRule('request_type', new Rule(Rules::STRING_TYPE)),
            new ParamRule('quantity', new Rule(Rules::INT_TYPE)),
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(\OrangeHRM\Core\Api\CommonParams::PARAMETER_ID, new Rule(Rules::POSITIVE)),
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(\OrangeHRM\Core\Api\CommonParams::PARAMETER_ID, new Rule(Rules::POSITIVE)),
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(\OrangeHRM\Core\Api\CommonParams::PARAMETER_ID, new Rule(Rules::POSITIVE)),
        );
    }
}
