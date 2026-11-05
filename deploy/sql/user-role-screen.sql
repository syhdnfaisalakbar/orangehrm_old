-- ============================================================
-- Admin -> User Role (menu + hak akses)
-- Idempotent: aman dijalankan berulang.
-- ============================================================

-- 1. Screen
INSERT INTO `ohrm_screen` (`name`, `module_id`, `action_url`, `menu_configurator`)
SELECT 'User Role', 2, 'userRole', NULL
WHERE NOT EXISTS (SELECT 1 FROM `ohrm_screen` WHERE `action_url` = 'userRole');

-- 2. Menu item di bawah Admin, paling atas (order_hint 50 < 100 User Management)
INSERT INTO `ohrm_menu_item` (`menu_title`, `screen_id`, `parent_id`, `level`, `order_hint`, `status`, `additional_params`)
SELECT 'User Role', s.`id`, 1, 2, 50, 1, NULL
FROM `ohrm_screen` s
WHERE s.`action_url` = 'userRole'
  AND NOT EXISTS (SELECT 1 FROM `ohrm_menu_item` m WHERE m.`screen_id` = s.`id`);

-- 3. Hak akses layar untuk role Admin (id 1)
INSERT INTO `ohrm_user_role_screen` (`user_role_id`, `screen_id`, `can_read`, `can_create`, `can_update`, `can_delete`)
SELECT 1, s.`id`, 1, 1, 1, 1
FROM `ohrm_screen` s
WHERE s.`action_url` = 'userRole'
  AND NOT EXISTS (
      SELECT 1 FROM `ohrm_user_role_screen` rs WHERE rs.`user_role_id` = 1 AND rs.`screen_id` = s.`id`
  );

-- 4. Data group untuk API v2
INSERT INTO `ohrm_data_group` (`name`, `description`, `can_read`, `can_create`, `can_update`, `can_delete`)
SELECT 'apiv2_admin_user_roles', 'API-v2 Admin - User Roles', 1, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `ohrm_data_group` WHERE `name` = 'apiv2_admin_user_roles');

-- 5. Hak data group untuk Admin
INSERT INTO `ohrm_user_role_data_group` (`user_role_id`, `data_group_id`, `can_read`, `can_create`, `can_update`, `can_delete`, `self`)
SELECT 1, dg.`id`, 1, 1, 1, 1, 0
FROM `ohrm_data_group` dg
WHERE dg.`name` = 'apiv2_admin_user_roles'
  AND NOT EXISTS (
      SELECT 1 FROM `ohrm_user_role_data_group` rdg WHERE rdg.`user_role_id` = 1 AND rdg.`data_group_id` = dg.`id`
  );

-- 6. Izin API v2
INSERT INTO `ohrm_api_permission` (`module_id`, `data_group_id`, `api_name`)
SELECT 2, dg.`id`, 'OrangeHRM\\Admin\\Api\\UserRoleAPI'
FROM `ohrm_data_group` dg
WHERE dg.`name` = 'apiv2_admin_user_roles'
  AND NOT EXISTS (
      SELECT 1 FROM `ohrm_api_permission` ap WHERE ap.`api_name` = 'OrangeHRM\\Admin\\Api\\UserRoleAPI'
  );

-- 7. Admin harus lebih dulu dari menu custom modul admin (Quota Request order 99)
--    supaya tab Admin (level-2) benar-benar dirender sebagai top menu.
UPDATE `ohrm_menu_item` SET `order_hint` = 98 WHERE `id` = 1 AND `order_hint` > 98;

-- 8. Izin API untuk endpoint matriks hak akses role
INSERT INTO `ohrm_api_permission` (`module_id`, `data_group_id`, `api_name`)
SELECT 2, dg.`id`, 'OrangeHRM\\Admin\\Api\\UserRolePermissionAPI'
FROM `ohrm_data_group` dg
WHERE dg.`name` = 'apiv2_admin_user_roles'
  AND NOT EXISTS (
      SELECT 1 FROM `ohrm_api_permission` ap WHERE ap.`api_name` = 'OrangeHRM\\Admin\\Api\\UserRolePermissionAPI'
  );
