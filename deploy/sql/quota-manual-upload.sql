-- Migration: tabel kuota manual hasil upload Excel (History Quota Department)
-- Tanggal: 2026-10-27
-- Sifat  : additive. Aman dijalankan pada DB yang sudah berisi data.
--
-- Kenapa tabel terpisah dari ohrm_ext_department_quota:
--   save_quota.php melakukan  ON DUPLICATE KEY UPDATE total_additional = VALUES(...)
--   setiap sync dari Acumatica, jadi angka manual yang ditulis ke tabel utama akan
--   tertimpa. Angka manual disimpan di tabel sendiri supaya tidak pernah tersentuh sync.
--
-- Kenapa TANPA unique key (ledger, satu baris per upload):
--   - upload bersifat AKUMULATIF -> total per department+tahun = SUM(total_person)
--   - riwayat upload tetap utuh: siapa, kapan, file apa, berapa orang
--   - kalau perlu membatalkan satu upload, cukup hapus baris dengan id tersebut
--
-- Jalankan di server prod setelah git pull:
--   mysql -u orangehrm -p orangehrm < deploy/sql/quota-manual-upload.sql

CREATE TABLE IF NOT EXISTS ohrm_ext_department_quota_manual (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    dept_name    VARCHAR(100) NOT NULL,
    year         INT(4) NOT NULL,
    branch       VARCHAR(100) DEFAULT NULL,
    total_person INT(11) NOT NULL DEFAULT 0,
    source_file  VARCHAR(255) DEFAULT NULL,
    uploaded_by  VARCHAR(100) DEFAULT NULL,
    uploaded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dept_year (dept_name, year),
    KEY idx_uploaded_at (uploaded_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;
