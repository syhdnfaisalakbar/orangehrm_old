-- Role MPP (Supervisor): akses PENUH Recruitment untuk kandidat.
-- Sebelumnya read-only (can_create/update/delete = 0) sehingga tombol Add
-- di tab Candidates tidak muncul.
-- Idempotent: aman dijalankan berulang. Role dicari lewat name (bukan id).
--
-- Cakupan: tambah kandidat, upload CV, shortlist, jadwal interview,
-- passed/failed interview, offer job, decline offer, hire, cancel hire,
-- reject, dan hapus kandidat.

SET @role_id := COALESCE(
    (SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT id FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1)
);

-- 1. Data group (API) — baca/tulis penuh untuk kandidat & aksinya
INSERT INTO ohrm_user_role_data_group
    (user_role_id, data_group_id, can_read, can_create, can_update, can_delete, `self`)
SELECT @role_id, dg.id, 1, 1, 1, 1, 0
FROM ohrm_data_group dg
WHERE dg.name IN (
        'apiv2_recruitment_candidates',
        'apiv2_recruitment_candidates_history',
        'apiv2_recruitment_candidate_allowed_actions',
        'apiv2_recruitment_candidate_attachments',
        'apiv2_recruitment_candidate_interview_attachment',
        'apiv2_recruitment_candidate_status',
        'apiv2_recruitment_candidate_shortlist',
        'apiv2_recruitment_candidate_schedule_interview',
        'apiv2_recruitment_candidate_mark_interview_passed',
        'apiv2_recruitment_candidate_mark_interview_failed',
        'apiv2_recruitment_candidate_offer_job',
        'apiv2_recruitment_candidate_decline_offer',
        'apiv2_recruitment_candidate_hire',
        'apiv2_recruitment_candidate_cancel_hire',
        'apiv2_recruitment_candidate_reject',
        'recruitment_candidates'
      )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_user_role_data_group) x
        WHERE x.user_role_id = @role_id AND x.data_group_id = dg.id
  );

UPDATE ohrm_user_role_data_group rdg
  JOIN ohrm_data_group dg ON dg.id = rdg.data_group_id
   SET rdg.can_read = 1, rdg.can_create = 1, rdg.can_update = 1, rdg.can_delete = 1
 WHERE rdg.user_role_id = @role_id
   AND dg.name IN (
        'apiv2_recruitment_candidates',
        'apiv2_recruitment_candidates_history',
        'apiv2_recruitment_candidate_allowed_actions',
        'apiv2_recruitment_candidate_attachments',
        'apiv2_recruitment_candidate_interview_attachment',
        'apiv2_recruitment_candidate_status',
        'apiv2_recruitment_candidate_shortlist',
        'apiv2_recruitment_candidate_schedule_interview',
        'apiv2_recruitment_candidate_mark_interview_passed',
        'apiv2_recruitment_candidate_mark_interview_failed',
        'apiv2_recruitment_candidate_offer_job',
        'apiv2_recruitment_candidate_decline_offer',
        'apiv2_recruitment_candidate_hire',
        'apiv2_recruitment_candidate_cancel_hire',
        'apiv2_recruitment_candidate_reject',
        'recruitment_candidates'
      );

-- 2. Screen: tab Candidates (60) harus punya izin tulis
INSERT INTO ohrm_user_role_screen (user_role_id, screen_id, can_read, can_create, can_update, can_delete)
SELECT @role_id, 60, 1, 1, 1, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM ohrm_user_role_screen) x
    WHERE x.user_role_id = @role_id AND x.screen_id = 60
);

UPDATE ohrm_user_role_screen
   SET can_read = 1, can_create = 1, can_update = 1, can_delete = 1
 WHERE user_role_id = @role_id AND screen_id = 60;

-- 3. Cek hasil
-- SELECT dg.name, rdg.can_read, rdg.can_create, rdg.can_update, rdg.can_delete
--   FROM ohrm_user_role_data_group rdg JOIN ohrm_data_group dg ON dg.id = rdg.data_group_id
--  WHERE rdg.user_role_id = (SELECT id FROM ohrm_user_role WHERE name='MPP')
--    AND dg.name LIKE '%recruitment_candidate%';
