-- ============================================================================
-- Role MPP: halaman awal setelah login = Dashboard.
--
-- Tanpa file ini, role MPP mendarat di
--   admin/viewSystemUsers?page=quota-request   (Quota Request)
-- karena baris ohrm_home_page milik role MPP masih menunjuk ke sana.
--
-- Sumber kebenaran: LoginController::handle() → HomePageService::getHomePagePath()
--   → BasicUserRoleManager::getHomePage() membaca ohrm_home_page per user_role_id,
--   urut priority DESC lalu id DESC (baris dengan priority sama & id lebih besar menang).
--
-- Idempotent: INSERT hanya kalau role MPP belum punya baris, lalu UPDATE menormalkan
-- action-nya. Role dicari lewat NAMA, bukan id hard-coded.
-- ============================================================================

SET @mpp_role_id := (
  SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1
);

INSERT INTO ohrm_home_page (user_role_id, action, enable_class, priority)
SELECT @mpp_role_id, 'dashboard/index', NULL, 15
FROM DUAL
WHERE @mpp_role_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ohrm_home_page hp WHERE hp.user_role_id = @mpp_role_id
  );

UPDATE ohrm_home_page
SET action = 'dashboard/index'
WHERE user_role_id = @mpp_role_id;

-- Verifikasi:
-- SELECT hp.id, r.name, hp.action, hp.priority
--   FROM ohrm_home_page hp JOIN ohrm_user_role r ON r.id = hp.user_role_id
--  ORDER BY r.id;
