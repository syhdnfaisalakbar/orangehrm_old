<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 */

namespace OrangeHRM\Admin\Api;

use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\CrudEndpoint;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointCollectionResult;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\Exception\InvalidParamException;
use OrangeHRM\Core\Api\V2\Exception\RecordNotFoundException;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\ParameterBag;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Core\Traits\ORM\EntityManagerTrait;
use OrangeHRM\Entity\UserRole;

/**
 * CRUD untuk tabel ohrm_user_role (Admin -> User Role).
 *
 * Catatan penting:
 * - `is_predefined = 1` adalah role bawaan sistem; hanya boleh diubah nama tampilannya,
 *   tidak boleh dihapus.
 * - Role yang masih dipakai user tidak boleh dihapus (dicek dari ohrm_user).
 * - Menambah baris di ohrm_user_role belum memberi akses apa pun. Hak akses diatur di
 *   ohrm_user_role_screen (menu) dan ohrm_user_role_data_group (baca/tulis data).
 */
class UserRoleAPI extends Endpoint implements CrudEndpoint
{
    use EntityManagerTrait;

    public const PARAMETER_NAME = 'name';
    public const PARAMETER_DISPLAY_NAME = 'displayName';
    public const PARAMETER_IS_ASSIGNABLE = 'isAssignable';

    public const PARAM_RULE_NAME_MAX_LENGTH = 255;

    /**
     * @inheritDoc
     */
    public function getAll(): EndpointCollectionResult
    {
        // Pagination server-side: halaman User Role memakai usePaginate, jadi
        // limit/offset harus benar-benar memotong hasil query. Kalau tidak, semua
        // baris dikirim sekaligus dan kontrol pagination tidak pernah muncul.
        $sortField = $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_QUERY,
            CommonParams::PARAMETER_SORT_FIELD,
            'id'
        );
        $sortOrder = strtoupper(
            $this->getRequestParams()->getString(
                RequestParams::PARAM_TYPE_QUERY,
                CommonParams::PARAMETER_SORT_ORDER,
                'ASC'
            )
        ) === 'DESC' ? 'DESC' : 'ASC';
        if (!in_array($sortField, ['id', 'name', 'displayName'], true)) {
            $sortField = 'id';
        }
        $limit = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_QUERY,
            CommonParams::PARAMETER_LIMIT,
            50
        );
        $offset = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_QUERY,
            CommonParams::PARAMETER_OFFSET,
            0
        );

        $repository = $this->getEntityManager()->getRepository(UserRole::class);
        $total = (int) $repository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $queryBuilder = $repository->createQueryBuilder('r')
            ->orderBy('r.' . $sortField, $sortOrder)
            ->setFirstResult(max(0, $offset));
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }
        $roles = $queryBuilder->getQuery()->getResult();

        $userCounts = $this->getUserCounts();
        $data = [];
        foreach ($roles as $role) {
            $data[] = $this->normalizeRole($role, $userCounts[$role->getId()] ?? 0);
        }

        return new EndpointCollectionResult(
            ArrayModel::class,
            $data,
            new ParameterBag([CommonParams::PARAMETER_TOTAL => $total])
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetAll(): ParamRuleCollection
    {
        // Halaman User Role memakai usePaginate -> mengirim limit/offset/sortField/sortOrder.
        // Tanpa rule ini OrangeHRM menolak dengan 422 Invalid Parameter.
        return new ParamRuleCollection(
            ...$this->getSortingAndPaginationParamsRules(['id', 'name', 'displayName'])
        );
    }

    /**
     * @inheritDoc
     */
    public function getOne(): EndpointResourceResult
    {
        $id = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_ATTRIBUTE,
            CommonParams::PARAMETER_ID
        );
        $role = $this->getUserRoleById($id);
        $this->throwRecordNotFoundExceptionIfNotExist($role, UserRole::class);

        $userCounts = $this->getUserCounts();
        return new EndpointResourceResult(
            ArrayModel::class,
            $this->normalizeRole($role, $userCounts[$role->getId()] ?? 0)
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(CommonParams::PARAMETER_ID, new Rule(Rules::POSITIVE))
        );
    }

    /**
     * Pastikan role punya halaman default supaya login tidak berakhir dengan
     * redirect(null) ketika role tidak lagi mendapat akses ESS.
     */
    private function ensureHomePageForRole(UserRole $role): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $exists = $conn->fetchOne(
            'SELECT COUNT(*) FROM ohrm_home_page WHERE user_role_id = ?',
            [$role->getId()]
        );
        if ((int) $exists === 0) {
            $conn->insert('ohrm_home_page', [
                'user_role_id' => $role->getId(),
                'action' => 'dashboard/index',
                'priority' => 5,
            ]);
        }
    }

    /**
     * @inheritDoc
     */
    public function create(): EndpointResourceResult
    {
        $role = new UserRole();
        $role->setName($this->getName());
        $role->setDisplayName($this->getDisplayName());
        $role->setIsAssignable(
            $this->getRequestParams()->getBoolean(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_IS_ASSIGNABLE,
                true
            )
        );
        $role->setIsPredefined(false);

        $this->assertNameIsUnique($role->getName(), null);
        $this->assertDisplayNameIsUnique($role->getDisplayName(), null);

        $this->getEntityManager()->persist($role);
        $this->getEntityManager()->flush();

        // Role baru tanpa baris di ohrm_home_page membuat login gagal (redirect null).
        // Beri halaman default yang aman; admin bisa menyesuaikan sendiri nanti.
        $this->ensureHomePageForRole($role);

        return new EndpointResourceResult(ArrayModel::class, $this->normalizeRole($role, 0));
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForCreate(): ParamRuleCollection
    {
        // isAssignable opsional: kalau dikirim harus boolean, kalau tidak dikirim
        // dianggap true. Pola NOT_REQUIRED + ALL_OF dibungkus ONE_OF (composite
        // default ALL_OF membuat nilai apa pun ditolak).
        $isAssignableRule = new ParamRule(
            self::PARAMETER_IS_ASSIGNABLE,
            new Rule(Rules::BOOL_TYPE)
        );
        $isAssignableRule->setRules(
            [
                new Rule(Rules::NOT_REQUIRED),
                new Rule(Rules::ALL_OF, [new Rule(Rules::BOOL_TYPE)]),
            ]
        );
        $isAssignableRule->setCompositeClass(Rules::ONE_OF);

        return new ParamRuleCollection(
            new ParamRule(
                self::PARAMETER_NAME,
                new Rule(Rules::STRING_TYPE),
                new Rule(Rules::LENGTH, [null, self::PARAM_RULE_NAME_MAX_LENGTH])
            ),
            new ParamRule(
                self::PARAMETER_DISPLAY_NAME,
                new Rule(Rules::STRING_TYPE),
                new Rule(Rules::LENGTH, [null, self::PARAM_RULE_NAME_MAX_LENGTH])
            ),
            $isAssignableRule
        );
    }

    /**
     * @inheritDoc
     */
    public function update(): EndpointResourceResult
    {
        $id = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_ATTRIBUTE,
            CommonParams::PARAMETER_ID
        );
        $role = $this->getUserRoleById($id);
        $this->throwRecordNotFoundExceptionIfNotExist($role, UserRole::class);

        $name = $this->getName();
        $displayName = $this->getDisplayName();

        // Nama role bawaan dipakai untuk memetakan kelas hak akses (Supervisor,
        // ESS, dst). Mengubahnya membuat role kehilangan hak akses khususnya,
        // jadi hanya display name yang boleh diubah.
        if ($role->isPredefined() && $name !== $role->getName()) {
            $msg = 'Nama role bawaan tidak dapat diubah. Ubah Display Name saja.';
            throw new InvalidParamException(['name' => $msg], $msg);
        }

        $this->assertNameIsUnique($name, $role->getId());
        $this->assertDisplayNameIsUnique($displayName, $role->getId());

        $role->setName($name);
        $role->setDisplayName($displayName);
        $role->setIsAssignable(
            $this->getRequestParams()->getBoolean(
                RequestParams::PARAM_TYPE_BODY,
                self::PARAMETER_IS_ASSIGNABLE,
                $role->isAssignable()
            )
        );

        $this->getEntityManager()->flush();

        $userCounts = $this->getUserCounts();
        return new EndpointResourceResult(
            ArrayModel::class,
            $this->normalizeRole($role, $userCounts[$role->getId()] ?? 0)
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(CommonParams::PARAMETER_ID, new Rule(Rules::POSITIVE)),
            new ParamRule(
                self::PARAMETER_NAME,
                new Rule(Rules::STRING_TYPE),
                new Rule(Rules::LENGTH, [null, self::PARAM_RULE_NAME_MAX_LENGTH])
            ),
            new ParamRule(
                self::PARAMETER_DISPLAY_NAME,
                new Rule(Rules::STRING_TYPE),
                new Rule(Rules::LENGTH, [null, self::PARAM_RULE_NAME_MAX_LENGTH])
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(self::PARAMETER_IS_ASSIGNABLE, new Rule(Rules::BOOL_TYPE))
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResourceResult
    {
        $ids = $this->getRequestParams()->getArray(RequestParams::PARAM_TYPE_BODY, CommonParams::PARAMETER_IDS);
        $userCounts = $this->getUserCounts();

        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $role = $this->getUserRoleById((int) $id);
            if ($role === null) {
                continue;
            }
            if ($role->isPredefined()) {
                $skipped[] = ['id' => $role->getId(), 'name' => $role->getName(), 'reason' => 'Role bawaan sistem'];
                continue;
            }
            if (($userCounts[$role->getId()] ?? 0) > 0) {
                $skipped[] = [
                    'id' => $role->getId(),
                    'name' => $role->getName(),
                    'reason' => 'Masih dipakai ' . $userCounts[$role->getId()] . ' user',
                ];
                continue;
            }
            $deleted[] = $role->getId();
            $this->getEntityManager()->remove($role);
        }
        $this->getEntityManager()->flush();

        return new EndpointResourceResult(
            ArrayModel::class,
            ['deleted' => $deleted, 'skipped' => $skipped]
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(CommonParams::PARAMETER_IDS, new Rule(Rules::ARRAY_TYPE))
        );
    }

    private function getUserRoleById(int $id): ?UserRole
    {
        return $this->getEntityManager()->find(UserRole::class, $id);
    }

    /**
     * @return array<int, int> jumlah user aktif per role
     */
    private function getUserCounts(): array
    {
        try {
            $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
                // Hanya user aktif: user yang dihapus tetap ada sebagai baris dengan deleted = 1
                'SELECT user_role_id, COUNT(*) AS total FROM ohrm_user WHERE deleted = 0 GROUP BY user_role_id'
            );
        } catch (\Throwable $e) {
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['user_role_id']] = (int) $row['total'];
        }
        return $counts;
    }

    private function normalizeRole(UserRole $role, int $userCount): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'displayName' => $role->getDisplayName(),
            'isAssignable' => $role->isAssignable(),
            'isPredefined' => $role->isPredefined(),
            'userCount' => $userCount,
        ];
    }

    private function getName(): string
    {
        return trim((string) $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_NAME
        ));
    }

    private function getDisplayName(): string
    {
        $displayName = trim((string) $this->getRequestParams()->getString(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_DISPLAY_NAME
        ));
        return $displayName !== '' ? $displayName : $this->getName();
    }

    private function assertNameIsUnique(string $name, ?int $ignoreId): void
    {
        $all = $this->getEntityManager()->getRepository(UserRole::class)->findAll();
        foreach ($all as $role) {
            if ($ignoreId !== null && $role->getId() === $ignoreId) {
                continue;
            }
            if (strcasecmp(trim($role->getName()), $name) === 0) {
                throw new InvalidParamException(
                    'Role dengan nama "' . $name . '" sudah ada.'
                );
            }
        }
    }

    private function assertDisplayNameIsUnique(string $displayName, ?int $ignoreId): void
    {
        $all = $this->getEntityManager()->getRepository(UserRole::class)->findAll();
        foreach ($all as $role) {
            if ($ignoreId !== null && $role->getId() === $ignoreId) {
                continue;
            }
            if (strcasecmp(trim($role->getDisplayName()), $displayName) === 0) {
                throw new InvalidParamException(
                    'Nama tampilan "' . $displayName . '" sudah dipakai role lain.'
                );
            }
        }
    }
}
