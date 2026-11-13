# Checklist rilis release-2026-11-13 — fork OrangeHRM ASG

Sumber deploy: `C:\Users\LENOVO\Project\Backup\orangehrm_old` (branch `development`).
Basis terakhir yang di-deploy: tag `release-2026-11-04`. Semua di bawah ini = perubahan SETELAH tag itu
(commit terakhir `14d09db`, sisanya belum di-commit).

Build frontend yang ikut: `web/dist/build` = `1794562069947`, `md5 app.js` = `d50d6779ec2808b207060183fa5dcd35`,
gate `check_dist_bundle.sh` = OK (syntax app.js + chunk-vendors, modul `mitt` CommonJS, marker `Add Indikator` x2).

---

## 1. Perbaikan & penambahan (apa yang berubah untuk user)

### A. Performance / KPI
1. **ESS: sidebar hanya `Dashboard` + `Performance`** (whitelist per role di `MenuService`).
2. **ESS: kotak Search di sidebar disembunyikan** (class `ohrm-role-ess` di `vue.html.twig` + `global.scss`).
3. **Tombol lipat sidebar** (lingkaran hijau + panah kiri) di atas menu — semua role; sidebar 256px → 83px.
4. **ESS klik menu Performance → langsung Input Realisasi KPI** (`PerformanceModuleController::handle()`).
5. **ESS: tab Performance dirampingkan** — `Configure`, `Manage Reviews`, `My Trackers`, `Employee Trackers`
   disembunyikan; tinggal `Input Realisasi KPI` + `Approval`.
6. **ESS: halaman pertama setelah login = Dashboard > Dashboard Divisi** (butuh SQL `ohrm_home_page`).
7. **Dashboard Divisi: filter Tahun dinamis** (dari data `periode`; gauge, chart, tabel, modal, PDF ikut).
8. **Tombol `Export PDF` dihapus** di tab Dashboard Divisi dan Division Detail (method PDF disimpan).
9. **Widget Resource Fulfillments di Dashboard hanya untuk MPP + Admin** (butuh data group baru di SQL).
10. **Input Realisasi KPI: tombol `+ Add` → `+ Add Indikator`.**
11. **Input Realisasi KPI: tabel dipotong per periode** dengan tombol geser kiri/kanan
    (label “November 2026 · 1 dari 3”); Simpan/Ajukan Verifikasi tetap memproses semua baris hasil filter.
12. **Input Realisasi KPI: tombol `Export`** menghasilkan **file CSV** (bukan .xlsx): header + baris indikator
    + baris TOTAL BOBOT, seluruh hasil filter.

### B. Recruitment
13. **Vacancy wajib diisi saat Add Candidate** (validasi frontend; UPDATE kandidat tetap opsional).
14. **Kandidat berstatus HIRED disembunyikan dari daftar secara default** (`hideHired=1`; muncul lagi kalau
    filter Status diisi Hired).
15. **Daftar Vacancy default hanya status ACTIVE.**
16. **Upload Excel kandidat** (tombol `Upload Excel` di pojok kiri header Candidates):
    pratinjau dulu → simpan; kolom wajib First/Last Name, Email, Vacancy; opsional Middle Name, Contact Number,
    Date of Application, Notes, Consent, **Resume (link URL)**; email yang sudah melamar di vacancy sama =
    **dilewati**; template Excel dibuat dinamis (sheet Kandidat / Petunjuk / Daftar Vacancy);
    opsi **Push ke Acumatica default tercentang** (gagal push tidak menggagalkan insert).
17. **Push otomatis ke Acumatica saat aksi status kandidat disimpan** (Shortlist, Schedule Interview,
    Interview Passed/Failed, Job Offer, Decline Offer) — alur sama dengan tombol Sync Application
    (Description Case, Task kandidat + CV, Note status). Reject/Hire/Cancel Hire dilewati (punya hook sendiri).
18. **Tombol `Sync Application to Acumatica` di halaman kandidat dinonaktifkan** + keterangan
    “Sync ke Acumatica otomatis saat aksi status kandidat disimpan”.
19. **Hire → activity bertipe NOTE berjudul “Hired”** pada Case Acumatica, parent = task kandidat
    (task berjudul nama kandidat); task kandidat juga ditutup.

### C. Manpower Request (FPTK)
20. **Filter Status Case** (Open / Closed / Semua Status) dengan default **Open**.
21. **Kolom Owner = owner approval task terakhir** (task Completed terakhir → task terakhir → owner case).
22. **Setiap klik `Sync Acumatica`: Owner case REQFPTK diisi user OrangeHRM yang sedang login** — hanya untuk
    case status New/Open dan hanya kalau Owner masih kosong; kegagalan tidak menggagalkan sync data
    (butuh tabel mapping `ohrm_employee_acumatica_owner`).

### D. Rilis / operasional
23. **File SQL dibuat portabel**: role dicari lewat NAMA (bukan id), screen lewat `action_url` + modul
    (`bod-kpi-access`, `supervisor-recruitment-default`, `user-role-no-ess`).
24. **`deploy/git-deploy.sh --check`** sekarang juga menguji prasyarat SQL rilis (kolom `exclude_ess`, tabel
    `ohrm_candidate_acumatica_task`, data group + 2 izin API User Role, screen+menu User Role, home page role 3,
    role BOD) supaya gejala “403 Unauthorized” ketahuan sebelum deploy.

---

## 2. Inventaris file

| Kelompok | Ubah | Baru |
|---|---|---|
| Backend `src/plugins` | 10 | 0 |
| Frontend `src/client` | 11 | 2 |
| Build `web/dist` | 4 | 0 |
| Proxy `web/proxy` | 1 | 9 |
| SQL `deploy/sql` | 4 (3 SQL revisi + `run-all-2026-11-04.sh`) | 4 (3 SQL + `run-all-2026-11-13.sh`) |
| Deploy lain | 1 (`deploy/git-deploy.sh`) | 1 folder `deploy/templates/` (artefak) |

Backend: `MenuService.php`, `VueControllerHelper.php`, `templates/vue.html.twig`,
`DashboardController.php`, `PerformanceModuleController.php`, `AbstractCandidateActionAPI.php`,
`CandidateAPI.php`, `CandidateInterviewSchedulingAPI.php`, `CandidateDao.php`, `CandidateSearchFilterParams.php`.

Frontend: `core/styles/global.scss`, `InputRealisasiKpi.vue`, `DashboardDivisi.vue`, `DivisionDetail.vue`,
`ViewDashboard.vue`, `manpowerRequest/ViewManpowerRequest.vue`, `ViewCandidatesList.vue`, `SaveCandidate.vue`,
`ViewJobVacancy.vue`, `CandidateProfile.vue`, `VacancyDropdown.vue` + baru `UploadCandidatesExcel.vue`,
`UploadCandidatesExcel.scss`.

Proxy baru: `_candidate_import_lib.php`, `_candidate_import_push.php`, `_xlsx_writer.php`,
`preview_candidate_import.php`, `save_candidate_import.php`, `candidate_import_template.php`,
`_acumatica_task_hired.php`, `_application_push.php`, `sync_manpower_owner.php`.
Proxy ubah: `get_manpower_requests.php`.

---

## 3. SQL wajib (jalankan SEBELUM checkout kode)

Jalankan lewat script rilis: `deploy/sql/run-all-2026-11-13.sh` (13 file, semuanya **idempotent** — sudah diuji
2x di DB lokal, hasil run#2 identik run#1). Urutannya: role/hak akses dasar → recruitment MPP/Supervisor →
status Task Acumatica → akses BOD + home page ESS → widget Dashboard → mapping Owner Acumatica.

**3 file SQL BELUM ter-track git** (kena `*.sql` di `.gitignore`) — wajib `git add -f` sebelum commit/tag:
- `deploy/sql/dashboard-rf-widget-role.sql`
- `deploy/sql/home-page-ess-dashboard-divisi.sql`
- `deploy/sql/manpower-owner-acumatica.sql`
- plus script baru `deploy/sql/run-all-2026-11-13.sh`

`quota-manual-upload.sql` **tidak** dimasukkan ke run-all baru: isinya `ALTER TABLE ... ADD COLUMN` tanpa
`IF NOT EXISTS`, jadi gagal (ERROR 1060) kalau diulang di server yang sudah pernah menjalankannya.

**Urutan server:**

```bash
cd /var/www/orangehrm
DB_NAME=$(php -r 'require "lib/confs/Conf.php"; echo (new Conf())->getDbName();')   # prod ASG: orangehrm_backup
mysqldump -h 127.0.0.1 -u orangehrm -p --single-transaction "$DB_NAME" \
  > /var/backups/orangehrm-pre-release-2026-11-13-$(date +%F_%H%M).sql

git fetch --tags
mkdir -p /tmp/sql-2026-11-13
git archive release-2026-11-13 deploy/sql | tar -x -C /tmp/sql-2026-11-13 --strip-components=2
cd /tmp/sql-2026-11-13 && ./run-all-2026-11-13.sh      # password ditanya sekali

cd /var/www/orangehrm
./deploy/git-deploy.sh --check
./deploy/git-deploy.sh --deploy release-2026-11-13
```

`git-deploy.sh` sudah menjalankan `orm:generate-proxies` + `cache:clear` (wajib karena ada properti/kolom baru
di `CandidateSearchFilterParams`). `web/dist` ikut tag, tidak perlu `npm run build` di server.

---

## 4. Verifikasi setelah deploy

1. `grep -c "Add Indikator" web/dist/js/app.js` > 0 dan `web/dist/build` = `1794562069947`.
2. Login ESS: dashboard mendarat di `dashboard/dashboardDivisi`, sidebar hanya Dashboard + Performance,
   klik Performance → `performance/inputRealisasiKpi`, tab hanya Input Realisasi KPI + Approval,
   tombol `Export` menghasilkan `.csv`.
3. Login Admin: sidebar & tab lengkap (tidak boleh ikut terpotong), Dashboard masih menampilkan widget
   Resource Fulfillments, filter Tahun berjalan.
4. Recruitment: Add Candidate tanpa Vacancy → ditolak; kandidat Hired tidak tampil default; Upload Excel
   (pratinjau → simpan) jalan; aksi Shortlist → cek Case Acumatica dapat Note status; Hire → activity
   “Hired” tipe NOTE.
5. Manpower Request: default filter Status = Open; kolom Owner berisi owner approval task; klik Sync
   Acumatica lalu cek Owner case REQFPTK = user yang login (hanya kalau sebelumnya kosong).
6. Kalau muncul `Unauthorized` (403) di satu halaman: SQL rilis belum jalan → ulangi langkah 3,
   `php bin/console cache:clear`, `rm -f src/cache/orangehrm/.menu_version`, lalu logout-login.

---

## 5. Catatan / risiko

- **`deploy/templates/template_upload_kandidat.xlsx`** = artefak contoh. Template asli dibuat dinamis oleh
  `web/proxy/candidate_import_template.php`, jadi file ini tidak perlu ikut tag (boleh dihapus).
- **`web/proxy/config.php` tidak ikut git** (kredensial). Di server harus tetap menunjuk Acumatica produksi
  `https://asg.acumatica.com`; jangan pernah menyalin config dari laptop.
- **Task Scheduler `\SyncAcumaticaMPR`** sering di-disable saat testing — cek statusnya di server dan
  konfirmasi ke Pak sebelum di-enable.
- Push ke Acumatica berjalan best-effort: kegagalan di sisi Acumatica tidak menggagalkan simpan data
  OrangeHRM, tapi juga tidak ada retry otomatis — pantau `web/proxy/*_log.txt` setelah rilis.
- **Salinan lokal tidak seragam** (bukan bagian rilis): `C:\xampp\htdocs\orangehrm\web\proxy\get_manpower_requests.php`
  masih versi lama (Owner = owner case), dan `C:\Users\LENOVO\Project\orangehrm` belum punya 9 file proxy baru.
  Hanya `Backup\orangehrm_old` yang lengkap.

---

## 6. Rollback

```bash
cd /var/www/orangehrm
./deploy/git-deploy.sh --rollback release-2026-11-04
```

Kalau perlu mundur skema: restore dump dari langkah backup. Jangan `DROP COLUMN` / `DROP TABLE` — tabel
`ohrm_employee_acumatica_owner` dan kolom `planned`/`branch` sudah berisi data user.
