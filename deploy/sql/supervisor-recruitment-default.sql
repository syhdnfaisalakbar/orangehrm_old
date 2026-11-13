-- Supervisor: halaman default modul Recruitment + izin lihat Candidate List.
-- Role dicari lewat nama: lokal 'MPP' (dulu Supervisor), server bisa 'Supervisor'.
-- Tanpa baris default page, klik menu Recruitment berakhir redirect(NULL) -> fatal error.
SET @role_id := COALESCE(
    (SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT id FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1),
    3
);
SET @recruitment_module_id := COALESCE(
    (SELECT id FROM ohrm_module WHERE name = 'recruitment' LIMIT 1),
    7
);

INSERT INTO ohrm_module_default_page (module_id, user_role_id, action, enable_class, priority)
SELECT @recruitment_module_id, @role_id, 'recruitment/viewCandidates', NULL, 10
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM ohrm_module_default_page) p
    WHERE p.module_id = @recruitment_module_id AND p.user_role_id = @role_id
);

-- Screen dicari lewat action_url, bukan id hardcoded: id screen berbeda antar DB
-- dan id yang salah bisa memberi akses ke layar yang tidak berhubungan.
INSERT INTO ohrm_user_role_screen (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
SELECT @role_id, s.id, 1, 0, 0, 0
FROM ohrm_screen s
WHERE s.action_url = 'viewCandidates'
  AND s.module_id = @recruitment_module_id
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM ohrm_user_role_screen) rs
      WHERE rs.user_role_id = @role_id AND rs.screen_id = s.id
  );
