-- ============================================
-- Status Task Acumatica per kandidat recruitment
-- Fitur: baris "Acumatica Task Status" di kartu Application Stage
--        (Recruitment -> Candidates -> profil kandidat),
--        kolom + filter "Acumatica Task Status" di daftar kandidat,
--        dan auto-Reject kandidat saat Task Acumatica berstatus Canceled.
--
-- Kenapa tabel: status Task di Acumatica TIDAK boleh dibaca saat halaman
-- dibuka (Acumatica membatasi login bersamaan + login bisa puluhan detik).
-- Nilai disimpan saat "Sync Application to Acumatica", tombol Refresh, atau
-- tombol "Sync Acumatica" (batch).
--
-- Jalankan SEKALI di DB server sebelum deploy kode baru (urutan: skema dulu).
-- Aman diulang: CREATE TABLE IF NOT EXISTS + pengecekan kolom di bawah.
-- ============================================
CREATE TABLE IF NOT EXISTS `ohrm_candidate_acumatica_task` (
  `candidate_id` int(11) NOT NULL,
  `case_id` varchar(20) DEFAULT NULL,
  `task_id` varchar(64) DEFAULT NULL,
  `task_summary` varchar(255) DEFAULT NULL,
  `task_status` varchar(32) DEFAULT NULL,
  `prev_cv_status` varchar(100) DEFAULT NULL,
  `auto_rejected` tinyint(1) NOT NULL DEFAULT 0,
  `auto_rejected_at` datetime DEFAULT NULL,
  `last_status_note` varchar(100) DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`candidate_id`),
  KEY `idx_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- ------------------------------------------------------------
-- Migrasi untuk instalasi yang tabelnya sudah ada (idempotent).
-- Jalankan ketiga ALTER ini kalau tabel dibuat versi lama.
-- ------------------------------------------------------------
-- ALTER TABLE `ohrm_candidate_acumatica_task`
--   ADD COLUMN `prev_cv_status` varchar(100) DEFAULT NULL AFTER `task_status`,
--   ADD COLUMN `auto_rejected` tinyint(1) NOT NULL DEFAULT 0 AFTER `prev_cv_status`,
--   ADD COLUMN `auto_rejected_at` datetime DEFAULT NULL AFTER `auto_rejected`,
--   ADD COLUMN `last_status_note` varchar(100) DEFAULT NULL AFTER `auto_rejected_at`;

-- Verifikasi:
-- SHOW COLUMNS FROM ohrm_candidate_acumatica_task;
