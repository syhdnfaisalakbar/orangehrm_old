# CHECKLIST RILIS — release-2026-11-17

Audit pra-deploy untuk fork `orangehrm_old` (branch `development`).
Dasar: tag terakhir `release-2026-11-04`. Ada **3 commit belum ter-tag** + **9 file belum di-commit**.

---

## 1. Yang sudah di-commit tapi BELUM dirilis (release-2026-11-04..HEAD)

| commit | isi |
|---|---|
| `5d5f948` | ESS menu+tab, KPI export CSV & pagination, upload Excel kandidat, push Acumatica otomatis, owner FPTK |
| `ab662df` | SQL baru: dashboard-rf-widget-role, home-page-ess-dashboard-divisi, manpower-owner-acumatica |
| `bfcc0b8` | update KPI: MenuService (hide tab/search per role), `_google_chat.php`, notifikasi Chat |

Kelompok fitur:

- **ESS / BOD (tampilan)** — sidebar ESS hanya `Dashboard` + `Performance`; tab Performance ESS & BOD hanya
  `Input Realisasi KPI` + `Approval`; kotak Search sidebar disembunyikan; menu Performance ESS
  langsung ke `performance/inputRealisasiKpi` (`PerformanceModuleController`).
- **KPI** — export CSV (mengganti Export PDF), pagination per periode, Dashboard Divisi + Division Detail,
  Approval KPI, validasi bobot, rumus perhitungan skor, link Sumber Data, notifikasi Google Chat
  (`web/proxy/_google_chat.php`).
- **Recruitment** — upload Excel kandidat (`UploadCandidatesExcel.vue`,
  `_candidate_import_lib.php`, `_candidate_import_push.php`), Add Candidate wajib, push ke Acumatica
  otomatis (`_application_push.php`, `_acumatica_task_hired.php`), owner FPTK
  (`manpower-owner-acumatica.sql`).
- **Dashboard** — widget Resource Fulfillments dibatasi role (`dashboard-rf-widget-role.sql`).

## 2. Yang BELUM di-commit (working tree, 9 file)

| file | perubahan |
|---|---|
| `src/client/src/orangehrmPerformancePlugin/pages/InputRealisasiKpi.vue` | tabel per TAHUN (satu baris = indikator × cabang, kolom Jan–Des), kolom Status dihapus, Skor → **Average** (÷ sesuai Schedule), warna Average dihilangkan, popup Sumber Data minimalis + langsung simpan ke server, **field "Bulan Pengajuan" dihapus**, header bold, kolom Indikator sticky, `isRowDirty()` (hanya sel berubah yang dikirim) |
| `src/plugins/orangehrmDashboardPlugin/Controller/DashboardController.php` | **ESS klik menu Dashboard → `dashboard/dashboardDivisi`** (role lain tidak berubah) |
| `web/proxy/get_kpi_realisasi.php` | override periode **per nama indikator + cabang**; `?year=YYYY` mengembalikan 12 bulan penuh |
| `web/proxy/save_kpi_realisasi.php` | aturan "hanya boleh diisi tepat bulan itu" dihapus (penyebab angka hilang setelah refresh); `reject_reason` dibersihkan saat diajukan ulang |
| `web/proxy/update_approval.php` | **Tolak = buka kunci** (`submitted=0`), Approve tetap mengunci |
| `web/dist/build`, `css/app.css`, `js/app.js`, `js/app.js.map` | hasil build terakhir |

Build yang ikut: `web/dist/build` = **1794908615846**
`md5 web/dist/js/app.js` = `ebad3614435af79b8f5a76b6339ea491`
`md5 web/dist/js/chunk-vendors.js` = `bf4592cecd6eeb2452230ab672cb495f`
Gate `check_dist_bundle.sh web/dist "Ajukan Verifikasi"` = **OK** (syntax OK, modul mitt CommonJS, marker x2).

## 3. SQL yang wajib

- `deploy/sql/run-all-2026-11-13.sh` — 8 file: user-role-screen, user-role-no-ess,
  user-role-supervisor-home, supervisor-recruitment-default, supervisor-recruitment-api,
  supervisor-vacancy-api, mpp-recruitment-full, mpp-recruitment-workflow, candidate-acumatica-task,
  bod-kpi-access, home-page-ess-dashboard-divisi, dashboard-rf-widget-role, manpower-owner-acumatica.
  Semua idempotent (aman diulang).
- `deploy/sql/bod-kpi-revoke.sql` — **BELUM ter-track** (kena `*.sql` di `.gitignore`) → wajib
  `git add -f`. Sifatnya kebijakan (mencabut screen Performance yang tidak dipakai BOD), **tidak**
  masuk run-all: jalankan manual setelah run-all, opsional.

Tidak ada perubahan skema baru dari pekerjaan hari ini (KPI + redirect Dashboard) — semuanya kode.

## 4. Langkah rilis

```bash
# 1) laptop — commit + tag (dari Windows, git.exe + GCM)
cd C:\Users\LENOVO\Project\Backup\orangehrm_old
git add src/client/src/orangehrmPerformancePlugin/pages/InputRealisasiKpi.vue ^
        src/plugins/orangehrmDashboardPlugin/Controller/DashboardController.php ^
        web/proxy/get_kpi_realisasi.php web/proxy/save_kpi_realisasi.php web/proxy/update_approval.php ^
        web/dist
git add -f deploy/sql/bod-kpi-revoke.sql
git status --porcelain          # harus kosong
git commit -m "KPI: tabel per tahun, average per schedule, buka kunci reject, ESS dashboard divisi"
git tag -a release-2026-11-17 -m "Rilis KPI per tahun + ESS Dashboard Divisi + upload kandidat"
git push origin development
git push origin release-2026-11-17
```

```bash
# 2) server — backup DB
cd /var/www/orangehrm
DB_NAME=$(php -r 'require "lib/confs/Conf.php"; echo (new Conf())->getDbName();')
mysqldump -h 127.0.0.1 -u orangehrm -p --single-transaction "$DB_NAME" \
  > /var/backups/orangehrm-pre-kpi-tahunan-$(date +%F_%H%M).sql

# 3) server — SQL dulu (sebelum checkout kode)
git fetch --tags
mkdir -p /tmp/sql-2026-11-17
git archive release-2026-11-17 deploy/sql | tar -x -C /tmp/sql-2026-11-17 --strip-components=2
cd /tmp/sql-2026-11-17 && DB_NAME="$DB_NAME" ./run-all-2026-11-13.sh
# opsional hardening: mysql -h 127.0.0.1 -u orangehrm -p "$DB_NAME" < bod-kpi-revoke.sql

# 4) server — kode
cd /var/www/orangehrm
./deploy/git-deploy.sh --check
./deploy/git-deploy.sh --deploy release-2026-11-17
```

## 5. Verifikasi setelah deploy

```bash
cd /var/www/orangehrm
grep -c "Ajukan Verifikasi" web/dist/js/app.js        # > 0
grep -c "Bulan Pengajuan" web/dist/js/app.js         # harus 0
cat web/dist/build                                    # 1794908615846
curl -s http://127.0.0.1/proxy/get_kpi_realisasi.php?year=2026 | head -c 200
```

Manual (browser, Ctrl+Shift+R):
1. Login ESS → menu Dashboard mendarat di **Dashboard Divisi**.
2. Input Realisasi KPI → tabel 12 kolom bulan, tidak ada dropdown Bulan Pengajuan, tombol
   `Simpan` + `Ajukan Verifikasi & Approve`.
3. Isi sel bulan yang boleh (Semester: Juni/Desember) → Simpan → refresh → nilai tetap ada.
4. Approver: Tolak satu baris → sel kembali editable. Approve → tetap terkunci.
5. BOD: tab Performance hanya Input Realisasi KPI + Approval.

## 6. Rollback

```bash
./deploy/git-deploy.sh --rollback release-2026-11-04
```
Jika `bod-kpi-revoke.sql` sudah dijalankan dan ingin dibatalkan, jalankan ulang
`bod-kpi-access.sql` (memberi kembali akses BOD).

## 7. Catatan / risiko

- Data yang saya ubah hari ini **hanya di DB lokal** (buka 27 baris Sep/Okt, kosongkan beberapa
  nilai, hapus 3 baris `ohrm_kpi_setup_period_delete` Januari 2026). Prod tidak tersentuh.
- `git commit -a` tidak memasukkan file baru — pakai `git add` eksplisit (termasuk `-f` untuk SQL).
- Jangan lupa `cache:clear` (sudah dilakukan `git-deploy.sh`) lalu logout-login agar menu/tab baru terbaca.
- `web/proxy/config.php` (kredensial + webhook Google Chat) tidak lewat git: pastikan sudah ada di server.
