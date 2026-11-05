-- Home page untuk role yang dikecualikan dari ESS.
-- Tanpa baris di ohrm_home_page, login berakhir dengan redirect(NULL) -> fatal error.
-- Role dicari lewat nama: lokal 'MPP' (dulu Supervisor), server bisa 'Supervisor'.
SET @role_id := COALESCE(
    (SELECT id FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT id FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1),
    3
);

INSERT INTO ohrm_home_page (user_role_id, action, enable_class, priority)
SELECT @role_id, 'admin/viewSystemUsers?page=quota-request', NULL, 5
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM ohrm_home_page) hp
    WHERE hp.user_role_id = @role_id AND hp.action = 'admin/viewSystemUsers?page=quota-request'
);
