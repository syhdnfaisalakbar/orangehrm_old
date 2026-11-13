-- ============================================================
-- Widget Resource Fulfillments di Dashboard: HANYA role MPP + Admin.
-- Idempotent: aman dijalankan berulang.
--
-- Cara kerja: DashboardController::preRender() mengirim daftar data group ini
-- sebagai `permissions` ke Vue; ViewDashboard.vue memakai
-- v-if="$can.read('dashboard_resource_fulfillment_widget')".
-- Jadi tanpa baris di sini widget tampil untuk SEMUA role.
-- ============================================================

-- 1. Data group baru (entity-independent, tidak perlu ohrm_api_permission
--    karena tidak ada kelas API yang memakainya).
INSERT INTO `ohrm_data_group` (`name`, `description`, `can_read`, `can_create`, `can_update`, `can_delete`)
SELECT 'dashboard_resource_fulfillment_widget', 'Dashboard - widget Resource Fulfillments', 1, 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `ohrm_data_group` WHERE `name` = 'dashboard_resource_fulfillment_widget');

-- 2. Hak baca untuk Admin (id 1) dan role MPP (namanya bisa MPP / Supervisor).
INSERT INTO `ohrm_user_role_data_group`
    (`user_role_id`, `data_group_id`, `can_read`, `can_create`, `can_update`, `can_delete`, `self`)
SELECT ur.`id`, dg.`id`, 1, 0, 0, 0, 0
FROM `ohrm_user_role` ur
JOIN `ohrm_data_group` dg ON dg.`name` = 'dashboard_resource_fulfillment_widget'
WHERE (ur.`id` = 1 OR ur.`name` IN ('MPP', 'Supervisor'))
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM `ohrm_user_role_data_group`) x
      WHERE x.`user_role_id` = ur.`id` AND x.`data_group_id` = dg.`id`
  );

-- 3. MPP boleh membuka halaman Dashboard (screen 'index' modul dashboard).
--    Tanpa izin screen ini, menu Dashboard tidak muncul dan widget tidak
--    akan pernah terlihat walau data group-nya sudah diberikan.
--    Screen dicari lewat action_url + modul, bukan id hardcoded.
INSERT INTO `ohrm_user_role_screen` (`user_role_id`, `screen_id`, `can_read`, `can_create`, `can_update`, `can_delete`)
SELECT ur.`id`, s.`id`, 1, 0, 0, 0
FROM `ohrm_user_role` ur
JOIN `ohrm_screen` s
  ON s.`action_url` = 'index'
 AND s.`module_id` = (SELECT `id` FROM `ohrm_module` WHERE `name` = 'dashboard' LIMIT 1)
WHERE ur.`name` IN ('MPP', 'Supervisor')
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM `ohrm_user_role_screen`) rs
      WHERE rs.`user_role_id` = ur.`id` AND rs.`screen_id` = s.`id`
  );

-- 4. Cek hasil (jalankan manual kalau perlu):
-- SELECT dg.name, ur.name AS role_name, rdg.can_read
--   FROM ohrm_user_role_data_group rdg
--   JOIN ohrm_data_group dg ON dg.id = rdg.data_group_id
--   JOIN ohrm_user_role ur ON ur.id = rdg.user_role_id
--  WHERE dg.name = 'dashboard_resource_fulfillment_widget';
-- SELECT ur.name AS role_name, s.action_url, rs.can_read
--   FROM ohrm_user_role_screen rs
--   JOIN ohrm_screen s ON s.id = rs.screen_id
--   JOIN ohrm_user_role ur ON ur.id = rs.user_role_id
--  WHERE s.action_url = 'index' AND ur.name IN ('MPP','Admin');
