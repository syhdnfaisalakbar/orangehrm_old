-- Menyembunyikan akses ESS (self service) untuk role tertentu.
-- User yang role utamanya punya exclude_ess = 1 tidak lagi otomatis mendapat
-- role ESS, sehingga menu Leave/Time/My Info/Performance/Dashboard/Directory/
-- Claim/Buzz hilang kecuali diberikan lewat matriks hak akses role.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ohrm_user_role'
               AND COLUMN_NAME = 'exclude_ess');
SET @q := IF(@col = 0,
    'ALTER TABLE ohrm_user_role ADD COLUMN exclude_ess TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Supervisor (role 3) tanpa akses ESS
UPDATE ohrm_user_role SET exclude_ess = 1 WHERE name = 'Supervisor';
