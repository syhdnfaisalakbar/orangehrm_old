-- ============================================================
-- Fork ASG: mapping user OrangeHRM -> Owner ID Acumatica
--
-- Dipakai oleh web/proxy/sync_manpower_owner.php untuk mengubah field
-- Owner pada Case Acumatica menjadi user OrangeHRM yang menekan tombol
-- Sync Acumatica di menu Manpower Request (FPTK).
--
-- Kenapa perlu tabel: entity Employee di contract 25.200.001 hanya
-- mengekspos EmployeeID (kode, mis. "S140"), EmployeeName, dan Status —
-- TIDAK ada ContactID/email, sedangkan field Owner Case berisi angka
-- internal (mis. 316044). Angka itu hanya bisa diturunkan dari Case yang
-- sudah punya Owner, atau diisi manual.
--
-- Jalankan SEKALI di database aplikasi (dev & prod) SEBELUM memakai fitur.
-- ============================================================

CREATE TABLE IF NOT EXISTS `ohrm_employee_acumatica_owner` (
  `emp_number` INT(11) NOT NULL,
  `owner_id` VARCHAR(20) NOT NULL COMMENT 'nilai field Owner di Acumatica (angka, disimpan as text apa adanya)',
  `owner_name` VARCHAR(150) DEFAULT NULL COMMENT 'OwnerEmployeeName di Acumatica saat mapping dibuat',
  `source` VARCHAR(20) NOT NULL DEFAULT 'auto' COMMENT 'auto = hasil cocok nama dari daftar Case; manual = diisi admin',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`emp_number`),
  KEY `idx_owner_id` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
