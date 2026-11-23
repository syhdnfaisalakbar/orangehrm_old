-- ============================================================================
-- MPP: izin buka & simpan halaman Edit Job Vacancy (screen `addJobVacancy`).
--
-- Gejala tanpa file ini:
--   Login sebagai role MPP (mis. nova.susetyoharsi) → Recruitment → tab
--   Vacancies → klik ikon pensil → halaman "Credential Required".
--   Penyebabnya HTTP 403 dari ScreenAuthorizationSubscriber
--   (src/plugins/orangehrmCorePlugin/Subscriber/ScreenAuthorizationSubscriber.php):
--   role MPP punya izin untuk screen `viewJobVacancy` (61) tapi TIDAK punya baris
--   untuk screen `addJobVacancy` (94), sehingga rute edit ditolak sebelum render.
--   Ini bukan masalah koneksi/kredensial Acumatica.
--
-- Isi file:
--   1. Izin screen addJobVacancy untuk role MPP (read/create/update).
--   2. Izin data group API yang dipakai form edit vacancy, supaya form bisa
--      mengambil data dan tombol Save tidak 403:
--        recruitment_vacancies            (grup klasik)
--        apiv2_recruitment_vacancy        (API v2 vacancy)
--        apiv2_recruitment_vacancy_attachment
--   3. can_delete sengaja dibiarkan 0 (MPP boleh ubah, tidak boleh hapus vacancy).
--
-- Idempotent: aman dijalankan berulang (INSERT hanya bila belum ada, lalu UPDATE
-- menormalkan nilainya). Role & screen dicari lewat NAMA / action_url, bukan id
-- hard-coded, karena id bisa berbeda antar instalasi.
-- ============================================================================

SET @mpp_role_id := (
  SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1
);

SET @screen_id := (
  SELECT s.id
  FROM ohrm_screen s
  JOIN ohrm_module m ON m.id = s.module_id
  WHERE m.name = 'recruitment' AND s.action_url = 'addJobVacancy'
  LIMIT 1
);

-- 1. Izin layar Edit/Add Job Vacancy untuk MPP -------------------------------
INSERT INTO ohrm_user_role_screen
  (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
SELECT @mpp_role_id, @screen_id, 1, 1, 1, 0
FROM DUAL
WHERE @mpp_role_id IS NOT NULL
  AND @screen_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM ohrm_user_role_screen rs
    WHERE rs.user_role_id = @mpp_role_id AND rs.screen_id = @screen_id
  );

UPDATE ohrm_user_role_screen
SET can_read = 1, can_create = 1, can_update = 1
WHERE user_role_id = @mpp_role_id AND screen_id = @screen_id;

-- 2. Izin data group yang dipakai form vacancy -------------------------------
INSERT INTO ohrm_user_role_data_group
  (user_role_id, data_group_id, can_read, can_create, can_update, can_delete)
SELECT @mpp_role_id, dg.id, 1, 1, 1, 0
FROM ohrm_data_group dg
WHERE @mpp_role_id IS NOT NULL
  AND dg.name IN (
    'recruitment_vacancies',
    'apiv2_recruitment_vacancy',
    'apiv2_recruitment_vacancy_attachment'
  )
  AND NOT EXISTS (
    SELECT 1 FROM ohrm_user_role_data_group rdg
    WHERE rdg.user_role_id = @mpp_role_id AND rdg.data_group_id = dg.id
  );

UPDATE ohrm_user_role_data_group rdg
JOIN ohrm_data_group dg ON dg.id = rdg.data_group_id
SET rdg.can_read = 1, rdg.can_create = 1, rdg.can_update = 1
WHERE rdg.user_role_id = @mpp_role_id
  AND dg.name IN (
    'recruitment_vacancies',
    'apiv2_recruitment_vacancy',
    'apiv2_recruitment_vacancy_attachment'
  );

-- 3. Verifikasi (jalankan manual bila perlu) ---------------------------------
-- SELECT r.name AS role, s.action_url, rs.can_read, rs.can_create, rs.can_update, rs.can_delete
--   FROM ohrm_user_role_screen rs
--   JOIN ohrm_user_role r ON r.id = rs.user_role_id
--   JOIN ohrm_screen s ON s.id = rs.screen_id
--  WHERE s.action_url = 'addJobVacancy';
-- SELECT r.name AS role, dg.name, rdg.can_read, rdg.can_create, rdg.can_update, rdg.can_delete
--   FROM ohrm_user_role_data_group rdg
--   JOIN ohrm_user_role r ON r.id = rdg.user_role_id
--   JOIN ohrm_data_group dg ON dg.id = rdg.data_group_id
--  WHERE r.name = 'MPP'
--    AND dg.name IN ('recruitment_vacancies','apiv2_recruitment_vacancy','apiv2_recruitment_vacancy_attachment');
