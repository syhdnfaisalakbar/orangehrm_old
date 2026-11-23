-- ============================================================================
-- Widget Quick Launch di Dashboard: DISEMBUNYIKAN untuk role MPP.
--
-- Cara kerja: DashboardController::preRender() mengirim 'dashboard_quick_launch_widget'
-- ke Vue lewat `permissions`; ViewDashboard.vue memakai
--   v-if="$can.read('dashboard_quick_launch_widget')"
-- sehingga widget hanya tampil untuk role yang punya baris can_read = 1 di sini.
-- Baris untuk MPP sengaja TIDAK dibuat (dan dihapus kalau ada).
-- Role lain (Admin, ESS, BOD, ...) tetap seperti sekarang.
--
-- Idempotent: aman dijalankan berulang.
-- ============================================================================

-- 1. Data group baru (entity-independent, tidak perlu ohrm_api_permission).
INSERT INTO `ohrm_data_group` (`name`, `description`, `can_read`, `can_create`, `can_update`, `can_delete`)
SELECT 'dashboard_quick_launch_widget', 'Dashboard - widget Quick Launch', 1, 0, 0, 0
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `name` FROM `ohrm_data_group`) x
    WHERE x.`name` = 'dashboard_quick_launch_widget'
);

SET @dg_id := (SELECT `id` FROM `ohrm_data_group` WHERE `name` = 'dashboard_quick_launch_widget' LIMIT 1);

-- 2. Beri hak baca ke SEMUA role kecuali MPP (perilaku lama dipertahankan).
INSERT INTO `ohrm_user_role_data_group`
    (`user_role_id`, `data_group_id`, `can_read`, `can_create`, `can_update`, `can_delete`, `self`)
SELECT ur.`id`, @dg_id, 1, 0, 0, 0, 0
FROM `ohrm_user_role` ur
WHERE @dg_id IS NOT NULL
  AND UPPER(ur.`name`) <> 'MPP'
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM `ohrm_user_role_data_group`) y
      WHERE y.`user_role_id` = ur.`id` AND y.`data_group_id` = @dg_id
  );

-- 3. Pastikan MPP benar-benar tidak punya hak baca.
DELETE rdg FROM `ohrm_user_role_data_group` rdg
JOIN `ohrm_user_role` ur ON ur.`id` = rdg.`user_role_id`
WHERE rdg.`data_group_id` = @dg_id
  AND UPPER(ur.`name`) = 'MPP';

-- Verifikasi:
-- SELECT ur.name, rdg.can_read
--   FROM ohrm_user_role ur
--   LEFT JOIN ohrm_user_role_data_group rdg
--     ON rdg.user_role_id = ur.id
--    AND rdg.data_group_id = (SELECT id FROM ohrm_data_group WHERE name='dashboard_quick_launch_widget')
--  ORDER BY ur.id;
