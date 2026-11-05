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
use OrangeHRM\Core\Service\MenuService;
use OrangeHRM\Core\Traits\ORM\EntityManagerTrait;
use OrangeHRM\Entity\UserRole;

/**
 * Matriks hak akses per role (Admin -> User Role -> Hak Akses).
 *
 * GET  /api/v2/admin/user-role-screens?roleId=2      daftar screen + flag hak akses role
 * PUT  /api/v2/admin/user-role-screens               simpan flag hak akses (body: roleId + screens[])
 * POST /api/v2/admin/user-role-screens/copy          salin seluruh hak akses dari role lain
 *                                                   (body: roleId + sourceRoleId)
 *
 * Catatan:
 * - Role Admin (id 1) tidak boleh diubah lewat endpoint ini supaya tidak terkunci.
 * - Setiap penyimpanan menaikkan versi cache menu, jadi perubahan langsung terlihat
 *   oleh user yang sedang login tanpa perlu logout.
 */
class UserRolePermissionAPI extends Endpoint implements CrudEndpoint
{
    use EntityManagerTrait;

    public const PARAMETER_SCREENS = 'screens';
    public const PARAMETER_ROLE_ID = 'roleId';
    public const PARAMETER_SCREEN_ID = 'screenId';
    public const PARAMETER_CAN_READ = 'canRead';
    public const PARAMETER_CAN_CREATE = 'canCreate';
    public const PARAMETER_CAN_UPDATE = 'canUpdate';
    public const PARAMETER_CAN_DELETE = 'canDelete';
    public const PARAMETER_SOURCE_ROLE_ID = 'sourceRoleId';

    private const PROTECTED_ROLE_ID = 1;

    /**
     * Matriks screen (dikelompokkan per menu) beserta hak akses role.
     */
    public function getAll(): EndpointCollectionResult
    {
        $role = $this->getUserRoleOrFail($this->getRoleIdFromQuery());

        $sql = 'SELECT mo.id AS module_id, mo.name AS module_name,
                       m.id AS menu_id, m.menu_title, m.level AS menu_level, m.order_hint,
                       s.id AS screen_id, s.name AS screen_name, s.action_url,
                       COALESCE(rs.can_read, 0) AS can_read,
                       COALESCE(rs.can_create, 0) AS can_create,
                       COALESCE(rs.can_update, 0) AS can_update,
                       COALESCE(rs.can_delete, 0) AS can_delete
                FROM ohrm_menu_item m
                INNER JOIN ohrm_screen s ON s.id = m.screen_id
                INNER JOIN ohrm_module mo ON mo.id = s.module_id
                LEFT JOIN ohrm_user_role_screen rs
                       ON rs.screen_id = s.id AND rs.user_role_id = :roleId
                WHERE m.status = 1 AND mo.status = 1
                ORDER BY mo.name ASC, m.level ASC, m.order_hint ASC, m.id ASC, s.name ASC';

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['roleId' => $role->getId()]
        );

        $menus = [];
        foreach ($rows as $row) {
            $menuId = (int) $row['menu_id'];
            if (!isset($menus[$menuId])) {
                $menus[$menuId] = [
                    'menuId' => $menuId,
                    'menuTitle' => $row['menu_title'],
                    'menuLevel' => (int) $row['menu_level'],
                    'moduleName' => $row['module_name'],
                    'screens' => [],
                ];
            }
            $menus[$menuId]['screens'][] = [
                'screenId' => (int) $row['screen_id'],
                'screenName' => $row['screen_name'],
                'actionUrl' => $row['action_url'],
                'canRead' => (bool) $row['can_read'],
                'canCreate' => (bool) $row['can_create'],
                'canUpdate' => (bool) $row['can_update'],
                'canDelete' => (bool) $row['can_delete'],
            ];
        }

        $data = array_values($menus);

        return new EndpointCollectionResult(
            ArrayModel::class,
            $data,
            new ParameterBag([
                CommonParams::PARAMETER_TOTAL => count($data),
                'role' => [
                    'id' => $role->getId(),
                    'name' => $role->getName(),
                    'displayName' => $role->getDisplayName(),
                ],
                'editable' => $role->getId() !== self::PROTECTED_ROLE_ID,
            ])
        );
    }

    public function getValidationRuleForGetAll(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_ROLE_ID, new Rule(Rules::POSITIVE))
        );
    }

    /**
     * Info ringkas role (tidak dipakai oleh route saat ini, wajib ada karena CrudEndpoint).
     */
    public function getOne(): EndpointResourceResult
    {
        $role = $this->getUserRoleOrFail($this->getRoleIdFromQuery());

        return new EndpointResourceResult(
            ArrayModel::class,
            [
                'id' => $role->getId(),
                'name' => $role->getName(),
                'displayName' => $role->getDisplayName(),
                'editable' => $role->getId() !== self::PROTECTED_ROLE_ID,
            ]
        );
    }

    public function getValidationRuleForGetOne(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_ROLE_ID, new Rule(Rules::POSITIVE))
        );
    }

    /**
     * Simpan matriks hak akses (hanya baris yang dikirim yang diubah).
     */
    public function update(): EndpointResourceResult
    {
        $role = $this->getEditableRoleOrFail($this->getRoleIdFromBody());
        $screens = $this->getRequestParams()->getArray(RequestParams::PARAM_TYPE_BODY, self::PARAMETER_SCREENS);

        $conn = $this->getEntityManager()->getConnection();
        $saved = 0;
        $cleared = 0;

        // Gabungkan per screen_id dengan logika OR.
        // Di fork ini beberapa menu memakai screen yang sama (contoh: "Users" dan
        // "History Quota Department" sama-sama screen 1 "User List"), sehingga satu
        // screen bisa terkirim beberapa kali dengan nilai berbeda. Kalau diproses
        // berurutan, kiriman terakhir yang kosong akan menghapus izin yang barusan
        // dicentang user.
        $byScreen = [];
        foreach ($screens as $screen) {
            $screenId = (int) ($screen[self::PARAMETER_SCREEN_ID] ?? 0);
            if ($screenId <= 0) {
                continue;
            }
            $flags = [
                !empty($screen[self::PARAMETER_CAN_READ]) ? 1 : 0,
                !empty($screen[self::PARAMETER_CAN_CREATE]) ? 1 : 0,
                !empty($screen[self::PARAMETER_CAN_UPDATE]) ? 1 : 0,
                !empty($screen[self::PARAMETER_CAN_DELETE]) ? 1 : 0,
            ];
            if (!isset($byScreen[$screenId])) {
                $byScreen[$screenId] = $flags;
            } else {
                foreach ($flags as $i => $flag) {
                    $byScreen[$screenId][$i] = $byScreen[$screenId][$i] | $flag;
                }
            }
        }

        foreach ($byScreen as $screenId => $flags) {
            [$canRead, $canCreate, $canUpdate, $canDelete] = $flags;

            $existingId = $conn->fetchOne(
                'SELECT id FROM ohrm_user_role_screen WHERE user_role_id = ? AND screen_id = ?',
                [$role->getId(), $screenId]
            );

            if ($canRead + $canCreate + $canUpdate + $canDelete === 0) {
                if ($existingId !== false && $existingId !== null) {
                    $conn->executeStatement('DELETE FROM ohrm_user_role_screen WHERE id = ?', [$existingId]);
                    $cleared++;
                }
                continue;
            }

            if ($existingId !== false && $existingId !== null) {
                $conn->executeStatement(
                    'UPDATE ohrm_user_role_screen
                     SET can_read = ?, can_create = ?, can_update = ?, can_delete = ?
                     WHERE id = ?',
                    [$canRead, $canCreate, $canUpdate, $canDelete, $existingId]
                );
            } else {
                $conn->executeStatement(
                    'INSERT INTO ohrm_user_role_screen
                        (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$role->getId(), $screenId, $canRead, $canCreate, $canUpdate, $canDelete]
                );
            }
            $saved++;
        }

        MenuService::bumpMenuCacheVersion();

        return new EndpointResourceResult(
            ArrayModel::class,
            ['roleId' => $role->getId(), 'saved' => $saved, 'cleared' => $cleared]
        );
    }

    public function getValidationRuleForUpdate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_ROLE_ID, new Rule(Rules::POSITIVE)),
            new ParamRule(self::PARAMETER_SCREENS, new Rule(Rules::ARRAY_TYPE))
        );
    }

    /**
     * Salin seluruh hak akses (screen + data group) dari role lain.
     */
    public function create(): EndpointResourceResult
    {
        $role = $this->getEditableRoleOrFail($this->getRoleIdFromBody());
        $sourceId = $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_SOURCE_ROLE_ID
        );
        $source = $this->getUserRoleOrFail($sourceId);

        if ($source->getId() === $role->getId()) {
            $msg = 'Role sumber dan role tujuan tidak boleh sama.';
            throw new InvalidParamException(['sourceRoleId' => $msg], $msg);
        }

        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement('DELETE FROM ohrm_user_role_screen WHERE user_role_id = ?', [$role->getId()]);
        $conn->executeStatement(
            'INSERT INTO ohrm_user_role_screen (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
             SELECT ?, screen_id, can_read, can_create, can_update, can_delete
             FROM ohrm_user_role_screen WHERE user_role_id = ?',
            [$role->getId(), $source->getId()]
        );

        $conn->executeStatement('DELETE FROM ohrm_user_role_data_group WHERE user_role_id = ?', [$role->getId()]);
        $conn->executeStatement(
            'INSERT INTO ohrm_user_role_data_group
                (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, `self`)
             SELECT ?, data_group_id, can_read, can_create, can_update, can_delete, `self`
             FROM ohrm_user_role_data_group WHERE user_role_id = ?',
            [$role->getId(), $source->getId()]
        );

        MenuService::bumpMenuCacheVersion();

        return new EndpointResourceResult(
            ArrayModel::class,
            [
                'roleId' => $role->getId(),
                'sourceRoleId' => $source->getId(),
                'screens' => (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM ohrm_user_role_screen WHERE user_role_id = ?',
                    [$role->getId()]
                ),
                'dataGroups' => (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM ohrm_user_role_data_group WHERE user_role_id = ?',
                    [$role->getId()]
                ),
            ]
        );
    }

    public function getValidationRuleForCreate(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_ROLE_ID, new Rule(Rules::POSITIVE)),
            new ParamRule(self::PARAMETER_SOURCE_ROLE_ID, new Rule(Rules::POSITIVE))
        );
    }

    public function delete(): EndpointResourceResult
    {
        $msg = 'Operasi hapus tidak tersedia untuk hak akses role.';
        throw new InvalidParamException(['roleId' => $msg], $msg);
    }

    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_ROLE_ID, new Rule(Rules::POSITIVE))
        );
    }

    private function getRoleIdFromQuery(): int
    {
        return $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_QUERY,
            self::PARAMETER_ROLE_ID
        );
    }

    private function getRoleIdFromBody(): int
    {
        return $this->getRequestParams()->getInt(
            RequestParams::PARAM_TYPE_BODY,
            self::PARAMETER_ROLE_ID
        );
    }

    private function getUserRoleOrFail(int $id): UserRole
    {
        $role = $this->getEntityManager()->find(UserRole::class, $id);
        $this->throwRecordNotFoundExceptionIfNotExist($role, UserRole::class);

        return $role;
    }

    private function getEditableRoleOrFail(int $id): UserRole
    {
        $role = $this->getUserRoleOrFail($id);
        if ($role->getId() === self::PROTECTED_ROLE_ID) {
            $msg = 'Hak akses role Admin tidak dapat diubah dari halaman ini (mencegah terkunci).';
            throw new InvalidParamException(['roleId' => $msg], $msg);
        }

        return $role;
    }
}
