-- Migration: kolom untuk upload Excel kuota (manual) pada History Quota Department
-- Tanggal: 2026-10-27
-- Sifat  : additive, aman dijalankan pada tabel yang sudah berisi data.
--
-- Kenapa kolom terpisah dan bukan menimpa total_additional:
--   save_quota.php melakukan  ON DUPLICATE KEY UPDATE total_additional = VALUES(...)
--   setiap kali sync dari Acumatica. Kalau angka manual ditulis ke total_additional,
--   upload Pak akan hilang pada sync berikutnya.
--
--   manual_total       = akumulasi angka dari upload Excel (TIDAK disentuh sync)
--   branch             = branch dari Excel, dipakai get_all_quota.php sebagai fallback
--                        ketika Acumatica belum punya attribute BRANCH untuk dept/tahun itu
--   manual_updated_at  = jejak waktu upload terakhir
--
-- Jalankan di server prod setelah git pull:
--   mysql -u orangehrm -p orangehrm < build/quota-manual-upload.sql

ALTER TABLE ohrm_ext_department_quota
    ADD COLUMN manual_total INT(11) NOT NULL DEFAULT 0 AFTER unplanned,
    ADD COLUMN branch VARCHAR(100) DEFAULT NULL AFTER manual_total,
    ADD COLUMN manual_updated_at DATETIME DEFAULT NULL AFTER branch;
