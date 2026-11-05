# Checklist rilis — Hak Akses (User Role) + perbaikan Recruitment

Repo: `C:\Users\LENOVO\Project\Backup\orangehrm_old` (branch `development`)
Build frontend: `1793764137884` — `web/dist/js/app.js` md5 `35d618a41ab7a94505808aa69acb22ab`
Gate bundle: OK (syntax app.js + chunk-vendors, modul `mitt` CommonJS, marker `User Role` x4)

## 1. Isi perubahan

### A. Fitur baru: Admin -> User Role (matriks hak akses)
Backend (baru)
- `src/plugins/orangehrmAdminPlugin/Api/UserRoleAPI.php`
- `src/plugins/orangehrmAdminPlugin/Api/UserRolePermissionAPI.php`
- `src/plugins/orangehrmAdminPlugin/Controller/UserRoleController.php`
Backend (ubah)
- `src/plugins/orangehrmAdminPlugin/config/routes.yaml`
- `src/plugins/orangehrmCorePlugin/entity/UserRole.php` (kolom `exclude_ess`)
- `src/plugins/orangehrmCorePlugin/Authorization/Manager/BasicUserRoleManager.php`
  (skip ESS bila `exclude_ess=1`, alias kelas role berbasis id)
- `src/plugins/orangehrmCorePlugin/Service/MenuService.php` (invalidate cache menu)
Frontend (baru)
- `src/client/src/orangehrmAdminPlugin/pages/userRole/` (UserRole.vue, UserRolePermissionDialog.vue,
  user-role.scss, user-role-permission.scss)
Frontend (ubah)
- `src/client/src/orangehrmAdminPlugin/index.ts`
- `src/client/src/orangehrmAdminPlugin/pages/systemUser/{SaveSystemUser,EditSystemUser}.vue`

### B. Fix fatal `redirect(NULL)`
- `src/plugins/orangehrmAuthenticationPlugin/Controller/ValidateController.php`
- `src/plugins/orangehrm*Plugin/Controller/*ModuleController.php` (Admin, PIM, Leave, Time,
  Performance, Recruitment) — fallback halaman default + guard

### C. Recruitment / Acumatica Task
- `src/plugins/orangehrmRecruitmentPlugin/Api/{CandidateAPI,AbstractCandidateActionAPI}.php`
- `src/client/src/orangehrmRecruitmentPlugin/pages/{ViewCandidatesList,ViewJobVacancy}.vue`
- `src/client/src/orangehrmRecruitmentPlugin/components/RecruitmentStatus.vue`
- baru: `src/client/src/orangehrmRecruitmentPlugin/components/Acumatica{T SyncResultDialog,TaskStatusCell,TaskStatusDropdown}.vue`
- baru: `web/proxy/{_acumatica_activity,_acumatica_task_cancel,_candidate_status_sync,
  get_candidate_task_status,get_candidates_task_status,sync_candidates_task_status}.php`
- ubah: `web/proxy/{_config,close_case_acumatica,get_manpower_approval_history,
  get_manpower_requests,reject_vacancy,save_job_vacancy,sync_application_to_acumatica}.php`

### D. Manpower Request / Quota
- `src/client/src/orangehrmAdminPlugin/pages/manpowerRequest/ViewManpowerRequest.vue`

### E. Build & konfigurasi
- `web/dist/*` (build id, css, js, map)
- `src/client/vue.config.js` (alias `mitt$`)
- `.gitignore` (tambah `web/proxy/_cache/`)

## 2. Skema DB — JALANKAN SEBELUM kode baru

File di `deploy/sql/` (perhatikan: `*.sql` kena `.gitignore`, commit pakai `git add -f`).

| File | Isi | Wajib? |
|---|---|---|
| `user-role-screen.sql` | screen #196 + menu Admin + data group + api permission | ya |
| `user-role-no-ess.sql` | `ALTER TABLE ohrm_user_role ADD COLUMN exclude_ess` | ya |
| `user-role-supervisor-home.sql` | baris `ohrm_home_page` untuk role non-ESS | ya (sesuaikan role id) |
| `supervisor-recruitment-default.sql` | `ohrm_module_default_page` (7, role) + screen Candidate List | ya (role id) |
| `supervisor-recruitment-api.sql` | data group read Recruitment untuk role Supervisor | opsional |
| `supervisor-vacancy-api.sql` | data group read Vacancy + Job Title + Hiring Manager | opsional |
| `candidate-acumatica-task.sql` | tabel `ohrm_candidate_acumatica_task` | cek: mungkin sudah ada |
| `quota-manual-upload.sql` | kolom kuota upload Excel | cek: mungkin sudah ada |

**PENTING:** file SQL memakai `user_role_id = 3`. Di server, verifikasi dulu:
```sql
SELECT id, name, display_name FROM ohrm_user_role ORDER BY id;
SELECT * FROM ohrm_screen WHERE id = 196;            -- User Role
SELECT * FROM ohrm_menu_item WHERE id = 126;
SHOW COLUMNS FROM ohrm_user_role LIKE 'exclude_ess';
SHOW TABLES LIKE 'ohrm_candidate_acumatica_task';
```
Kalau id role berbeda, ganti angka 3 sebelum menjalankan.

## 3. Urutan deploy

```bash
# 0. laptop
cd /mnt/c/Users/LENOVO/Project/Backup/orangehrm_old
git add -A && git add -f deploy/sql/*.sql
git commit -m "feat(admin): matriks hak akses User Role + fix redirect(NULL) + recruitment/vacancy read-only"
git tag -a release-YYYY-MM-DD -m "User Role permission matrix"
git push origin development && git push origin release-YYYY-MM-DD

# 1. server: backup DB
mysqldump -h 127.0.0.1 -u orangehrm -p --single-transaction orangehrm \
  > /var/backups/orangehrm-pre-userrole-$(date +%F_%H%M).sql

# 2. server: skema (isi deploy/sql, urutan tabel di atas)

# 3. server: kode
cd /var/www/orangehrm
./deploy/git-deploy.sh --check
./deploy/git-deploy.sh --deploy release-YYYY-MM-DD
# script: fetch tags -> checkout tag -> orm:generate-proxies -> cache:clear -> chmod
```

`orm:generate-proxies` wajib karena entity `UserRole` dapat properti baru.

## 3b. Akses BOD: Dashboard Divisi + Approval KPI

Role **BOD** kini melihat semua divisi di Dashboard Divisi / Division Detail dan boleh
approve/reject realisasi KPI serta pengajuan edit definisi.

File (semua di `web/proxy/`, sudah disalin ke htdocs + Project/orangehrm):
- baru: `_kpi_scope.php` — helper `kpiIsFullScope()`; daftar role full-scope di
  `KPI_FULL_SCOPE_ROLES = ['Admin','BOD']` (dicocokkan lewat **nama** role).
- diubah (pakai helper): `get_kpi_realisasi.php`, `get_kpi_realisasi_history.php`,
  `get_approval.php`, `update_approval.php`, `get_setup_edit_requests.php`,
  `update_setup_edit_request.php`, `get_kpi_division_members.php`,
  `delete_kpi_division_member.php`, `delete_setup_kpi.php`,
  `save_kpi_division_member.php`, `set_kpi_division_member_active.php`.

SQL: `deploy/sql/bod-kpi-access.sql` (idempotent, mencari role lewat `name='BOD'`):
- grant screen 103, 130, 190, 191, 193, 194 (read; 191 update = approve)
- `ohrm_module_default_page` mod 10 -> `dashboard/dashboardDivisi`, mod 11 -> `performance/approvalKpi`

Kalau BOD hanya boleh MELIHAT (tanpa approve), pisahkan daftar approver dari
`KPI_FULL_SCOPE_ROLES` (buat `KPI_APPROVER_ROLES` khusus untuk `update_*.php`).

## 3c. Role MPP: Recruitment penuh (Add kandidat + aksi workflow)

MPP (role id 3, dulu `Supervisor`) diberi akses penuh Recruitment untuk kandidat.

SQL (dua file, idempotent, cari role lewat `name='MPP'`):
- `deploy/sql/mpp-recruitment-full.sql` — data group kandidat & semua aksinya
  (candidates, history, allowed_actions, attachments, status, shortlist,
  schedule_interview, mark_passed/failed, offer_job, decline_offer, hire,
  cancel_hire, reject, `recruitment_candidates`) = read/create/update/delete,
  plus screen 60 (Candidate List) full.
- `deploy/sql/mpp-recruitment-workflow.sql` — menyalin baris
  `ohrm_workflow_state_machine` (workflow=2 / FLOW_RECRUITMENT) dari role
  `HIRING MANAGER` ke role `MPP`.

Kenapa perlu dua-duanya:
1. Izin data group menentukan tombol **Add** (`$can.create('recruitment_candidates')`).
2. Tombol aksi (Shortlist / Schedule Interview / Offer / Hire / Reject) hanya muncul
   kalau ada baris workflow untuk NAMA role user. Bawaan hanya punya `ADMIN`,
   `HIRING MANAGER`, `INTERVIEWER` — tanpa baris `MPP`, tombol aksi tidak pernah muncul
   walau izin data group penuh (gejala: 403 Unauthorized / daftar action kosong).
3. Tombol aksi juga bergantung status vacancy: vacancy `status = 0` (tertutup) membuat
   daftar action kosong untuk SEMUA role, termasuk Admin.

Verifikasi (user role MPP, vacancy aktif):
```
POST /api/v2/recruitment/candidates                     -> 200 (Add jalan)
GET  /candidates/{id}/actions/allowed                    -> ['Shortlist','Reject']
PUT  /candidates/{id}/shortlist                          -> 200
GET  /candidates/{id}/actions/allowed (setelah shortlist)-> ['Reject','Schedule Interview']
halaman viewCandidates: dataGroupPermissions recruitment_candidates
                        = {canRead:true, canCreate:true, canUpdate:true, canDelete:true}
```

## 3d. Hire -> Task Acumatica Closed

Saat kandidat diklik **Hire** di OrangeHRM, Task kandidat di Acumatica otomatis
diubah statusnya menjadi **Closed**.

- File baru: `web/proxy/_acumatica_task_close.php` (fungsi `acumaticaCloseCandidateTask()`).
  Melewati (skip) kalau Task sudah `Closed`/`Canceled`, dan tidak menyentuh Task `Canceled`.
- Hook: `AbstractCandidateActionAPI::update()` — setelah commit transaksi, untuk aksi
  `RECRUITMENT_APPLICATION_ACTION_HIRE` memanggil `closeAcumaticaTaskBestEffort()`
  (best-effort; hire tetap sukses kalau Acumatica bermasalah). Log: `web/proxy/_cache/acumatica_hire.log`.
- Tabel lokal `ohrm_candidate_acumatica_task` di-update: `task_status = 'Closed'`,
  `last_status_note = 'HIRED'`.
- Catatan: Task status `Closed` **valid** di entity `Default/25.200.001` (dites live:
  HTTP 200, status terbaca kembali `Closed`). Jadi bukan hanya Open/Processing/Completed/Canceled.

Verifikasi live:
```
cand#8 Indra Aditya Test / case 0000008681 -> Task Closed
cand#1 Testing Aditya Akbar / case 0000008667 -> Task Closed (backfill HIRED)
cand#2 Syahruddin Test -> tetap Canceled (tidak ditimpa)
uji hire kandidat baru: log acumatica_hire.log bertambah (hook jalan)
```

## 4. Verifikasi setelah deploy

- `php bin/console cache:clear --env=prod` sukses, tidak ada error.
- Login Admin -> menu **User Role** muncul di tab Admin, halaman terbuka.
- Buka **Hak Akses** sebuah role: centang tersimpan (reload tetap tercentang).
- Login user role non-ESS: menu ESS hilang, tidak ada `Fatal error` di halaman mana pun.
- Recruitment: daftar Candidates & Vacancies terbuka; aksi tulis tetap 403 untuk role read-only.
- `grep -c "User Role" web/dist/js/app.js` > 0 dan browser Ctrl+Shift+R.

## 5. Rollback

```bash
./deploy/git-deploy.sh --rollback <tag-sebelumnya>
```
Skema: `exclude_ess` **jangan** di-DROP (kolom berisi data). Cukup restore dump bila perlu.

## 6. Risiko yang sudah diperiksa

- `web/proxy/_config.php` **loader**, bukan kredensial: membaca `config.php` (gitignored) di server.
  Perubahan lokal hanya menambah konstanta `ACUMATICA_REJECT_REASON` — aman.
- `web/proxy/_cache/` (cookie sesi Acumatica + lock) sudah masuk `.gitignore`, tidak ikut ter-commit.
- `web/dist` ikut di-commit (memang aturan repo), jadi server tidak perlu `npm run build`.
- `src/plugins/orangehrmCorePlugin/entity/UserRole.php` huruf kecil `entity` — sama seperti aslinya,
  jangan "dirapikan" jadi `Entity` (autoload Linux case-sensitive).

## 6. Inventaris file rilis (audit git)

Branch `development`, commit terakhir `feee26c`, build `web/dist/build` = **1793781000941**
(`app.js` md5 `550c9f51…`). Total: **46 modified + 12 untracked** (setelah file sementara dibersihkan).

### A. Backend PHP — `src/plugins/` (14 berubah + 4 baru)

Berubah:
- `orangehrmAdminPlugin/Controller/AdminModuleController.php` — guard `redirect(null)`
- `orangehrmAdminPlugin/config/routes.yaml` — route User Role
- `orangehrmAuthenticationPlugin/Controller/ValidateController.php` — fallback home page
- `orangehrmCorePlugin/Authorization/Manager/BasicUserRoleManager.php` — skip ESS, alias kelas role per predefined id
- `orangehrmCorePlugin/Authorization/UserRole/SupervisorUserRole.php` — Candidate & Vacancy full-ID (read-only lama)
- `orangehrmCorePlugin/Service/MenuService.php` — `.menu_version` (invalidate cache menu)
- `orangehrmCorePlugin/entity/UserRole.php` — properti `exclude_ess`
- `orangehrmLeavePlugin/Controller/LeaveModuleController.php` — guard `redirect(null)`
- `orangehrmPerformancePlugin/Controller/PerformanceModuleController.php` — guard `redirect(null)`
- `orangehrmPimPlugin/Controller/PimModuleController.php` — guard `redirect(null)`
- `orangehrmTimePlugin/Controller/TimeModuleController.php` — guard `redirect(null)`
- `orangehrmRecruitmentPlugin/Controller/RecruitmentModuleController.php` — guard `redirect(null)`
- `orangehrmRecruitmentPlugin/Api/CandidateAPI.php` — filter `acumaticaTaskStatus`
- `orangehrmRecruitmentPlugin/Api/AbstractCandidateActionAPI.php` — hook reject→Canceled + **hire→Closed**

Baru:
- `orangehrmAdminPlugin/Api/UserRoleAPI.php` (CRUD role, pagination server-side, `isAssignable` opsional, guard rename role predefined)
- `orangehrmAdminPlugin/Api/UserRolePermissionAPI.php` (matriks + copy hak akses)
- `orangehrmAdminPlugin/Controller/UserRoleController.php`
- `orangehrmRecruitmentPlugin/…` tidak ada file baru; helper proxy lihat bagian D

### B. Frontend — `src/client/` (9 berubah + 7 baru)

Berubah:
- `src/orangehrmAdminPlugin/index.ts`
- `src/orangehrmAdminPlugin/pages/systemUser/SystemUser.vue` — kolom Role sebenarnya + filter role dinamis + pagination 5/10/25/50
- `src/orangehrmAdminPlugin/pages/systemUser/EditSystemUser.vue` — fix race dropdown role
- `src/orangehrmAdminPlugin/pages/systemUser/SaveSystemUser.vue` — dropdown role dinamis
- `src/orangehrmAdminPlugin/pages/manpowerRequest/ViewManpowerRequest.vue`
- `src/orangehrmRecruitmentPlugin/pages/ViewCandidatesList.vue` — kolom + auto-sync + tombol Sync
- `src/orangehrmRecruitmentPlugin/pages/ViewJobVacancy.vue`
- `src/orangehrmRecruitmentPlugin/components/RecruitmentStatus.vue` — baris Acumatica Task Status
- `vue.config.js` — alias `mitt$` (cegah layar putih)

Baru:
- `src/orangehrmAdminPlugin/pages/userRole/` (UserRole.vue, UserRolePermissionDialog.vue, user-role.scss, user-role-permission.scss, + file pendukung folder)
- `src/orangehrmAdminPlugin/pages/systemUser/system-user.scss` — style footer pagination Users
- `src/orangehrmRecruitmentPlugin/components/AcumaticaSyncResultDialog.vue`
- `src/orangehrmRecruitmentPlugin/components/AcumaticaTaskStatusCell.vue`
- `src/orangehrmRecruitmentPlugin/components/AcumaticaTaskStatusDropdown.vue`

### C. Build — `web/dist/` (wajib ikut, jangan partial)

`build`, `css/app.css`, `js/app.js`, `js/app.js.map`, `js/chunk-vendors.js`, `js/chunk-vendors.js.map`
(rebuild setelah perubahan Vue: `vue-cli-service build --dest ../../web/dist`, lalu gate `check_dist_bundle.sh`).

### D. Proxy PHP — `web/proxy/` (18 berubah + 10 baru)

Berubah: `_config.php` (LOADER saja — tidak memuat kredensial), `close_case_acumatica.php`,
`reject_vacancy.php`, `save_job_vacancy.php`, `sync_application_to_acumatica.php`,
`get_manpower_requests.php`, `get_manpower_approval_history.php`,
`get_kpi_realisasi.php`, `get_kpi_realisasi_history.php`, `get_approval.php`, `update_approval.php`,
`get_setup_edit_requests.php`, `update_setup_edit_request.php`, `get_kpi_division_members.php`,
`delete_kpi_division_member.php`, `delete_setup_kpi.php`, `save_kpi_division_member.php`,
`set_kpi_division_member_active.php`.

Baru: `_acumatica_activity.php`, `_acumatica_task_cancel.php`, `_acumatica_task_close.php` (hire→Closed),
`_candidate_status_sync.php`, `_kpi_scope.php` (BOD full-scope), `get_candidate_task_status.php`,
`get_candidates_task_status.php`, `sync_candidates_task_status.php`.

**Wajib juga disalin ke `C:\xampp\htdocs\orangehrm\web\proxy\` dan `...\Project\orangehrm\web\proxy\`**
(selain repo Backup) — sudah dicek identik (md5) untuk semua file yang berubah/baru.
`config.php` JANGAN pernah disalin (kredensial + beda environment).

### E. SQL — `deploy/sql/` (11 file, 10 di antaranya BARU & kena `*.sql` di `.gitignore`)

Urutan aman (semua idempotent, role dicari lewat NAMA, bukan id):
1. `candidate-acumatica-task.sql` — tabel status Task
2. `user-role-screen.sql` — screen + menu User Role
3. `user-role-no-ess.sql` — `exclude_ess` untuk role Supervisor/MPP
4. `user-role-supervisor-home.sql` — home page role MPP/Supervisor
5. `supervisor-recruitment-default.sql` — module default page Recruitment + screen 60
6. `supervisor-recruitment-api.sql` — data group read kandidat
7. `supervisor-vacancy-api.sql` — data group read vacancy/job title/hiring manager
8. `mpp-recruitment-full.sql` — kandidat full CRUD (Add + aksi)
9. `mpp-recruitment-workflow.sql` — baris workflow MPP (tombol Shortlist/Interview/Offer/Hire/Reject)
10. `bod-kpi-access.sql` — BOD: Dashboard Divisi/Detail + Approval KPI
11. `quota-manual-upload.sql` — (sudah ada sebelumnya; kolom `planned`, ulang = `1060` aman)

Commit wajib: `git add -f deploy/sql/*.sql` (kecuali `quota-manual-upload.sql` sudah tracked).

### F. Dokumentasi

- `.gitignore` (ditambah `web/proxy/_cache/`)
- `deploy/CHECKLIST-RILIS-user-role.md` (file ini)

### G. Prasyarat + hal khusus deploy

- `orm:generate-proxies` wajib (entity `UserRole` dapat properti baru).
- Cek di server sebelum SQL: `SELECT id,name FROM ohrm_user_role;` — role MPP/Supervisor dicari lewat nama,
  jadi id tidak harus 3.
- Vakum file runtime: `web/proxy/_cache/` (cookie sesi Acumatica) TIDAK ikut commit (gitignored).
- Dua file sementara dev (`_tmp_wf3.php`, `web/proxy/_tmp_test_closed.php`) sudah dihapus dari working tree.
- Salinan `Project/orangehrm` sempat kurang 3 proxy (`get_candidate_task_status.php`,
  `get_candidates_task_status.php`, `sync_candidates_task_status.php`) — sudah disalin.
