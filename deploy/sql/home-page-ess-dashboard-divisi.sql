-- Fork ASG — halaman pertama setelah login untuk role ESS
-- Diarahkan ke menu Dashboard > Dashboard Divisi (permintaan Pak, 2026-11-12).
--
-- Cara kerja: OrangeHRM menentukan halaman pertama dari tabel ohrm_home_page
-- (satu baris per role, dipilih menurut priority). LoginController /
-- ValidateController memanggil HomePageService::getHomePagePath() lalu redirect
-- ke nilai kolom `action`.
--
-- Rollback ke perilaku standar (Dashboard biasa):
--   UPDATE ohrm_home_page SET action = 'dashboard/index' WHERE user_role_id = 2;

UPDATE ohrm_home_page
   SET action = 'dashboard/dashboardDivisi'
 WHERE user_role_id = (SELECT id FROM ohrm_user_role WHERE name = 'ESS');

-- Verifikasi:
-- SELECT hp.user_role_id, r.name, hp.action
--   FROM ohrm_home_page hp JOIN ohrm_user_role r ON r.id = hp.user_role_id;
