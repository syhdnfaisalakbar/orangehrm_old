-- Role MPP: izin WORKFLOW recruitment (approve/aksi kandidat).
--
-- Tombol aksi (Shortlist, Schedule Interview, Mark Passed/Failed, Offer, Hire,
-- Reject) hanya muncul kalau ada baris di ohrm_workflow_state_machine untuk
-- role user tersebut. Data bawaan hanya punya ADMIN, HIRING MANAGER, INTERVIEWER,
-- sehingga role MPP tidak pernah mendapat tombol aksi walau izin data group
-- sudah penuh.
--
-- Baris di bawah menyalin alur HIRING MANAGER (shortlist -> interview -> offer
-- -> hire/reject) ke role MPP. Idempotent: aman dijalankan berulang.

SET @role_name := COALESCE(
    (SELECT name FROM ohrm_user_role WHERE name = 'MPP' LIMIT 1),
    (SELECT name FROM ohrm_user_role WHERE name = 'Supervisor' LIMIT 1)
);

INSERT INTO ohrm_workflow_state_machine
    (workflow, state, role, action, resulting_state, priority)
SELECT w.workflow, w.state, @role_name, w.action, w.resulting_state, w.priority
FROM ohrm_workflow_state_machine w
WHERE w.workflow = 2            -- FLOW_RECRUITMENT
  AND w.role = 'HIRING MANAGER'
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM ohrm_workflow_state_machine) x
        WHERE x.workflow = w.workflow
          AND x.state = w.state
          AND x.role = @role_name
          AND x.action = w.action
  );

-- Cek hasil
-- SELECT state, role, action, resulting_state
--   FROM ohrm_workflow_state_machine WHERE workflow = 2 AND role = 'MPP' ORDER BY id;
