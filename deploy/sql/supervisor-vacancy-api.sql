-- Role Supervisor/MPP: izin baca API Vacancies (read-only).
-- Role dicari lewat nama: lokal 'MPP' (dulu Supervisor), server bisa 'Supervisor'.
SET @role_id := COALESCE(
    (SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT id FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1),
    3
);

INSERT INTO ohrm_user_role_data_group (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, `self`)
SELECT @role_id, dg.id, 1, 0, 0, 0, 0
FROM ohrm_data_group dg
WHERE dg.name IN (
        'apiv2_recruitment_vacancy',
        'apiv2_recruitment_vacancy_attachment',
        'recruitment_vacancies'
      )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_user_role_data_group) x
        WHERE x.user_role_id = @role_id AND x.data_group_id = dg.id
  );

-- Filter halaman Vacancies juga memuat Job Title & Hiring Manager (read-only).
INSERT INTO ohrm_user_role_data_group (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, `self`)
SELECT @role_id, dg.id, 1, 0, 0, 0, 0
FROM ohrm_data_group dg
WHERE dg.name IN ('apiv2_admin_job_title', 'apiv2_recruitment_hiring_managers')
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_user_role_data_group) x
        WHERE x.user_role_id = @role_id AND x.data_group_id = dg.id
  );
