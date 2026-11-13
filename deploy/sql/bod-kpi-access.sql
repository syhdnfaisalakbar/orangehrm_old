-- BOD: akses Dashboard Divisi/Detail + Approval KPI (approve/reject).
-- Role dicari lewat NAME (bukan id) supaya tahan rename / id berbeda di server.
--
-- 1. Izin screen (menu) untuk role BOD.
-- Screen dicari lewat action_url + modul, BUKAN id hardcoded: nomor id screen
-- berbeda antar environment, dan action_url bisa dipakai dua modul sekaligus
-- (mis. 'dashboardDivisi' ada di modul dashboard DAN performance).
INSERT INTO ohrm_user_role_screen (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
SELECT r.id, s.id, 1, 0, 1, 0
FROM ohrm_user_role r
JOIN ohrm_screen s ON (
        (s.action_url IN ('index', 'dashboardDivisi', 'divisionDetail')
         AND s.module_id = (SELECT id FROM ohrm_module WHERE name = 'dashboard' LIMIT 1))
     OR (s.action_url IN ('viewPerformanceModule', 'inputRealisasiKpi', 'approvalKpi')
         AND s.module_id = (SELECT id FROM ohrm_module WHERE name = 'performance' LIMIT 1))
     )
WHERE r.name = 'BOD'
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_user_role_screen) x
        WHERE x.user_role_id = r.id AND x.screen_id = s.id
  );

-- 2. Halaman default modul supaya klik menu tidak redirect(NULL).
INSERT INTO ohrm_module_default_page (module_id, user_role_id, action, enable_class, priority)
SELECT (SELECT id FROM ohrm_module WHERE name = 'dashboard' LIMIT 1), r.id, 'dashboard/dashboardDivisi', NULL, 10
FROM ohrm_user_role r
WHERE r.name = 'BOD'
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_module_default_page) x
        WHERE x.module_id = (SELECT id FROM ohrm_module WHERE name = 'dashboard' LIMIT 1)
          AND x.user_role_id = r.id
  );

INSERT INTO ohrm_module_default_page (module_id, user_role_id, action, enable_class, priority)
SELECT (SELECT id FROM ohrm_module WHERE name = 'performance' LIMIT 1), r.id, 'performance/approvalKpi', NULL, 10
FROM ohrm_user_role r
WHERE r.name = 'BOD'
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_module_default_page) x
        WHERE x.module_id = (SELECT id FROM ohrm_module WHERE name = 'performance' LIMIT 1)
          AND x.user_role_id = r.id
  );

-- 3. Cek hasil
-- SELECT s.id, s.name, urs.can_read, urs.can_update
--   FROM ohrm_user_role_screen urs JOIN ohrm_screen s ON s.id = urs.screen_id
--  WHERE urs.user_role_id = (SELECT id FROM ohrm_user_role WHERE name = 'BOD');
