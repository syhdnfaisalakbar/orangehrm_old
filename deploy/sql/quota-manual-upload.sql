-- Migration: kolom kuota hasil upload Excel pada tabel utama
-- Tanggal: 2026-10-27 (revisi: angka upload masuk ke ohrm_ext_department_quota.planned)
-- Sifat  : additive, aman dijalankan pada tabel yang sudah berisi data.
--
-- Latar belakang:
--   Hasil upload Excel harus tampil di kolom "Planned" pada menu History Quota
--   Department. Kolom planned belum pernah ada di tabel ini — nilai Planned di UI
--   selama ini DIHITUNG dari SUM(TOTPERSON) case yang approvalnya Completed.
--
--   planned = angka dari upload Excel (AKUMULATIF, ditambah tiap upload)
--   branch  = branch dari Excel, dipakai sebagai fallback tampilan kalau Acumatica
--             belum punya attribute BRANCH untuk dept/tahun tsb
--
--   AMAN dari sync Acumatica: save_quota.php melakukan
--     ON DUPLICATE KEY UPDATE total_additional=..., replacement=..., reduction=..., unplanned=...
--   Kolom planned & branch TIDAK ada di daftar itu, jadi tidak pernah ditimpa.
--
-- Catatan: tabel ohrm_ext_department_quota_manual (percobaan sebelumnya) tidak dipakai
--   lagi. Isinya kosong; boleh dihapus kapan saja:
--     DROP TABLE ohrm_ext_department_quota_manual;
--
-- Jalankan di server prod setelah git pull:
--   mysql -u orangehrm -p orangehrm < deploy/sql/quota-manual-upload.sql

ALTER TABLE ohrm_ext_department_quota
    ADD COLUMN planned INT(11) NOT NULL DEFAULT 0 AFTER total_additional,
    ADD COLUMN branch VARCHAR(100) DEFAULT NULL AFTER planned;
