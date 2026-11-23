# CHECKLIST RILIS — release-2026-11-23

Audit pra-deploy untuk fork `orangehrm_old` (branch `development`, repo `syhdnfaisalakbar/orangehrm_old`).
Dasar: tag terakhir **`release-2026-11-04`**.

Status working tree saat checklist ini dibuat (dicek dengan `git status` / `git diff`):

- **3 commit** belum ter-tag (`5d5f948`, `ab662df`, `bfcc0b8`), **0 commit** menunggu push.
- **27 file belum di-commit** (semua perubahan sesi 2026-11-17 s/d 2026-11-23).
- **3 file SQL baru** tidak akan ikut git karena `.gitignore` berisi `*.sql` → wajib `git add -f`.
- 1 file untracked: `deploy/CHECKLIST-RILIS-2026-11-17.md` (dokumen, bukan kode).

Artinya: **server live belum menerima satu pun perubahan di bawah ini** sampai di-commit + di-push + di-pull.

---

## 1. Perbaikan & penambahan sesi 2026-11-23 (7 item)

| # | Menu | Perbaikan | File |
|---|---|---|---|
| 1 | Recruitment → Vacancies (role MPP) | Klik ikon pensil tidak lagi "Credential Required" (403). MPP kini punya izin screen `addJobVacancy` + data group vacancy R/C/U. | `deploy/sql/mpp-vacancy-edit.sql` (baru) |
| 2 | Recruitment → Vacancies | Kolom **Hiring Manager** dihapus dari tabel. | `ViewJobVacancy.vue` |
| 3 | Sync FPTK → Job Vacancy | Hiring Manager **tidak lagi diisi karyawan acak**; kalau nama Owner Acumatica tidak cocok/gumpang, `hiring_manager_id` = **NULL** dan namanya dilaporkan di pesan sync. Plus guard di form edit supaya tidak crash saat NULL. | `web/proxy/save_job_vacancy.php`, `EditJobVacancy.vue` |
| 4 | Recruitment → Candidates | Label tombol **"Upload Excel" → "Upload"**. | `UploadCandidatesExcel.vue` |
| 5 | History Quota Department | Label tombol **"Upload Excel" → "Upload"**. | `AllQuotaDepartment.vue` |
| 6 | Sidebar role MPP | Urutan menu: Dashboard → Manpower Request (FPTK) → Recruitment → Resource Fulfillments → Quota Request → History Quota Department → Unplanned Request Report. Role lain tidak berubah. | `MenuService.php` (`ROLE_SIDE_PANEL_MENU`) |
| 7 | Login role MPP | Setelah login langsung ke **Dashboard** (sebelumnya mendarat di Quota Request). | `deploy/sql/mpp-home-page.sql` (baru) |
| 8 | Dashboard role MPP | Widget **Quick Launch** disembunyikan untuk MPP; role lain tetap melihat. | `ViewDashboard.vue`, `DashboardController.php`, `deploy/sql/mpp-hide-quick-launch.sql` (baru) |

Bukti uji (23 Nov, DB lokal):

- Login uji role MPP (user sementara) → 302 ke `/dashboard/index`; props izin dashboard hanya
  `dashboard_leave_widget`, `dashboard_resource_fulfillment_widget`, `dashboard_time_widget`
  (tanpa `dashboard_quick_launch_widget` → widget tidak dirender).
- Login uji role Admin → `dashboard_quick_launch_widget: canRead=true` (widget tetap tampil).
- User uji & log login uji dihapus setelah tes (sisa 0).

---

## 2. Perbaikan FPTK / Acumatica / Quota (sesi 2026-11-23 sebelumnya, ikut ter-deploy)

| Perbaikan | File |
|---|---|
| **Owner Approval Task** diambil dari tabel mapping `ohrm_employee_acumatica_owner` (owner_id → emp), baru fallback nama Owner Case. Kasus `0000008726` kini benar: `JKT - Syahdan Faisal Akbar`. | `web/proxy/save_manpower.php` |
| **AttributeID toleran**: `TOTPERSON` ↔ `TOTPERSONN` (Acumatica me-rename). Urutan lookup exact → alias → prefix. Tanpa ini Total Person terbaca NULL → 0. | `web/proxy/sync_manpower_scheduled.php`, `ViewManpowerRequest.vue`, `ViewJobVacancy.vue`, `QuotaRequest.vue`, `AllQuotaDepartment.vue`, `ResourceFulfillment.vue` |
| Query kuota menerima dua nama atribut | `web/proxy/get_all_quota.php`, `_quota_approved.php`, `get_planned_cases.php` |
| Simpan kuota menormalkan nama atribut ke kanonik `TOTPERSON` (cegah dobel hitung) | `web/proxy/save_quota.php` |
| **Case Attributes** modal MPR & Quota jadi tabel menyamping (transposed), modal 1400px/96vw | `ViewManpowerRequest.vue`, `QuotaRequest.vue` |
| **Approval Tasks** modal MPR & Quota jadi tabel menyamping + pill status | `ViewManpowerRequest.vue`, `QuotaRequest.vue` |

---

## 3. Perubahan sesi sebelumnya yang BELUM pernah dirilis (ikut terbawa)

Ini bukan pekerjaan 23 Nov, tapi masih di working tree/commit yang belum ter-tag — kalau dirilis sekarang, ikut ke live:

| Area | Perubahan | File |
|---|---|---|
| KPI | Tabel Input Realisasi KPI per TAHUN (kolom Jan–Des), filter Year, kolom AVG KPI Ach, baris TOTAL, popup Link Sumber Data, kunci sel untuk bulan yang sudah diajukan | `InputRealisasiKpi.vue` (+1.394 baris), `get_kpi_realisasi.php` |
| KPI | Aturan "hanya boleh diisi tepat bulan itu" (Annual) dihapus; `reject_reason` dibersihkan saat diajukan ulang | `save_kpi_realisasi.php` |
| KPI | Tolak = buka kunci (`submitted=0`), approve tetap mengunci | `update_approval.php` |
| Dashboard Divisi | Tabel per tahun, AVG KPI Ach, gauge = rata-rata achievement, tahun terbaru yang ada realisasi, filter bulan | `DivisionDetail.vue` (+713 baris) |
| Dashboard | ESS & BOD klik menu Dashboard → diarahkan ke `dashboard/dashboardDivisi` | `DashboardController.php` |
| ESS/BOD/Menu | (commit `bfcc0b8`, `5d5f948`) hide tab & search per role, notifikasi Google Chat, upload Excel kandidat, push Acumatica otomatis | sudah di commit, belum di-tag |

Catatan: dua file KPI di atas menyumbang ~2.100 baris perubahan — bagian paling berisiko, uji ulang di server setelah deploy.

---

## 4. SQL yang WAJIB dijalankan di server

Tiga file baru (belum pernah ada di server):

1. `deploy/sql/mpp-vacancy-edit.sql` — izin MPP buka & simpan Edit Job Vacancy.
2. `deploy/sql/mpp-home-page.sql` — halaman awal login MPP = Dashboard.
3. `deploy/sql/mpp-hide-quick-launch.sql` — data group widget Quick Launch (semua role kecuali MPP).

Semua idempotent (aman diulang) dan mencari role lewat NAMA, screen lewat `action_url` + modul.

Ketiganya sudah didaftarkan di `deploy/sql/run-all-2026-11-13.sh` (file itu ikut ter-commit).

> **PENTING — jebakan git:** `.gitignore` baris 80 berisi `*.sql`, sehingga file SQL baru TIDAK akan
> ikut `git add .`. Pakai `git add -f deploy/sql/mpp-vacancy-edit.sql deploy/sql/mpp-home-page.sql deploy/sql/mpp-hide-quick-launch.sql`
> lalu pastikan muncul di `git status` sebagai `A`, dan cek di GitHub setelah push.

---

## 5. Identitas build yang ikut dirilis

- `web/dist/build` = **1795424102250** (23 Nov 15:55)
- `md5 web/dist/js/app.js` = `10d0e9e267c5abd1db0caa962d292d68` (1.831.507 byte)
- `md5 web/dist/js/chunk-vendors.js` = `bf4592cecd6eeb2452230ab672cb495f`
- `md5 web/dist/css/app.css` = `a6147f9b0e8e1aea745c7fece6507c38` (213.623 byte)
- Gate `check_dist_bundle.sh web/dist "dashboard_quick_launch_widget"`:
  syntax app.js OK, syntax chunk-vendors OK, modul mitt CommonJS OK, marker x1 → **OK**

`web/dist` di-commit, jadi server **tidak perlu** `npm`/`composer` saat deploy.

---

## 6. Urutan deploy

1. Laptop: `git add -A` + `git add -f` tiga SQL → commit (`release 2026-11-23`) → tag `release-2026-11-23` → push.
2. Server: `git pull` (branch `development`) di `/var/www/orangehrm`.
3. Server: jalankan 3 SQL baru (via `mysql -h 127.0.0.1 -u orangehrm -p orangehrm < file.sql`), atau `bash deploy/sql/run-all-2026-11-13.sh` sekalian.
4. Server: `php bin/console cache:clear` (jangan hapus folder `src/cache` manual) lalu
   `chmod -R 2775 src/cache src/log web/upload`.
5. Semua user: logout → login (menu, izin, dan halaman awal dibaca dari session).
6. Browser: hard refresh `Ctrl+Shift+R` sekali untuk memastikan `web/dist` baru terpakai.

---

## 7. Cek cepat setelah deploy (per role)

- **MPP**: login → mendarat di Dashboard; sidebar 7 menu urutan baru; Quick Launch tidak ada;
  Recruitment → Vacancies → pensil membuka form edit; tombol Upload (bukan "Upload Excel").
- **Admin**: Quick Launch masih ada; urutan sidebar Admin tidak berubah; Recruitment → Vacancies tidak
  ada kolom Hiring Manager.
- **ESS / BOD**: klik menu Dashboard → Dashboard Divisi.
- **Sync Acumatica** (MPP): Total Person terbaca (bukan 0/`-`); pesan sync menyebut nama yang gagal
  match hiring manager (kalau ada) tanpa mengisi orang acak.

---

## 8. Risiko / hal yang perlu keputusan Pak

1. **Mapping owner hanya 2 baris** (`233` NOVA, `316044` Syahdan). Case lain dengan owner berbeda akan
   fallback ke nama Owner Case — kalau Pak ingin deterministik, perlu lanjutan mapping lewat `owner_id`
   Acumatica (rekomendasi sudah disampaikan, belum dikerjakan).
2. **HRPLAN** (Quota) belum pernah ketemu case-nya di staging; hardening `TOTPERSONN` bersifat defensif.
3. **8 vacancy lama** hiring manager-nya tidak diubah (DAVID UTOMO 4, NOVA 3, Faried 1). Kalau ada yang
   salah orang, harus dikosongkan manual.
4. Perubahan KPI (bagian 3) besar dan belum pernah diuji di data produksi — pertimbangkan rilis terpisah
   kalau Pak ingin memisahkan risiko.
5. Jangan pernah jalankan installer di server; DB produksi sudah berisi data asli.

---

## 9. Kebersihan data uji (sudah diverifikasi)

- `ohrm_user` dengan nama `zz_uji%`: 0 baris; log `ohrm_login` uji: dibersihkan.
- `ohrm_job_vacancy` bernama `ZZTEST%`: 0 baris; `ohrm_job_title` `ZZTEST-JT`: 0 baris.
- Total vacancy tetap 8 (data asli).
- File sementara `web/proxy/_tmp_*.php`: sudah dihapus semua.
