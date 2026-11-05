-- Supervisor: izin baca API Recruitment (data group), bukan hanya screen/menu.
-- Matriks hak akses di halaman User Role hanya mengatur screen (menu).
-- Akses API diatur lewat ohrm_user_role_data_group, jadi tanpa baris di sini
-- halaman Candidate List akan 403 Forbidden.
-- Role dicari lewat nama: lokal = 'MPP' (dulu Supervisor), server bisa masih 'Supervisor'.
SET @role_id := COALESCE(
    (SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT id FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1),
    3
);

INSERT INTO ohrm_user_role_data_group (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, `self`)
SELECT @role_id, dg.id, 1, 0, 0, 0, 0
FROM ohrm_data_group dg
WHERE dg.name IN (
        'apiv2_recruitment_candidates',
        'apiv2_recruitment_candidate_allowed_actions',
        'apiv2_recruitment_candidate_status',
        'apiv2_recruitment_candidate_attachments',
        'apiv2_recruitment_candidates_history'
      )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_user_role_data_group) x
        WHERE x.user_role_id = @role_id AND x.data_group_id = dg.id
  );
