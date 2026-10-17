# Runbook: Deploy OrangeHRM ke Produksi (via GitHub)

Target: server produksi `172.16.5.206`, path aplikasi `/var/www/orangehrm`.
Repo: `https://github.com/syhdnfaisalakbar/orangehrm` (private), branch `development`, deploy per **tag rilis**.

Prinsip yang dipakai:
- Yang lewat git = KODE + asset build (`web/dist`) + dependency (`src/vendor`).
- Yang TIDAK lewat git = kredensial (`lib/confs/Conf.php`, `web/proxy/config.php`) dan data (dump SQL).
- DB produksi SUDAH BERISI DATA ASLI → **jangan pernah menjalankan installer**; deploy = ganti kode saja.

---

## 0. Di laptop: commit + tag + push (sekali per rilis)

```bash
cd /mnt/c/Users/LENOVO/Project/orangehrm
git status                       # harus bersih
git tag release-2026-10-17       # nama tag = penanda rilis (dipakai saat rollback)
git push -u origin development
git push origin release-2026-10-17
```

Push butuh PAT (classic, scope `repo`) atau SSH key — WSL ini belum punya credential helper.
Verifikasi di GitHub: `web/dist` ada, `web/proxy/config.php` TIDAK ada, tidak ada file `*.sql` dump.

---

## 1. Di server: cek prasyarat (read-only)

```bash
ssh <user>@172.16.5.206

php -v                                    # butuh PHP 7.4+ (lokal 8.2); catat versinya
php -m | grep -E 'pdo_mysql|mbstring|gd|zip|ldap|intl|xml|curl'
git --version
dpkg -l | grep -E 'apache2|nginx' | head -3
mysql -h 127.0.0.1 -u orangehrm -p -e "select value from orangehrm.hs_hr_config where name='instance.version'"
```

Kalau ada ekstensi yang kurang, aplikasi akan gagal saat boot — pasang dulu sebelum lanjut.
`instance.version` harus **5.5.0** (sama dengan kode). Kalau beda, jangan lanjut: perlu migrasi core dulu.

---

## 2. Deploy key + clone (sekali saja, saat pertama kali)

```bash
# di server: buat key khusus read-only
ssh-keygen -t ed25519 -C 'deploy@orangehrm-prod' -f ~/.ssh/id_orangehrm -N ''
cat ~/.ssh/id_orangehrm.pub
```

Tempel public key itu di GitHub → repo → **Settings → Deploy keys → Add deploy key**
(centang *Allow read access* saja, jangan write). Lalu:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-orangehrm
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_orangehrm
  IdentitiesOnly yes
EOF

sudo mkdir -p /var/www && cd /var/www
sudo git clone -b development git@github-orangehrm:syhdnfaisalakbar/orangehrm.git orangehrm
```

Kalau folder `/var/www/orangehrm` sudah ada (aplikasi lama jalan), **backup dulu**:

```bash
sudo mv /var/www/orangehrm /var/www/orangehrm.backup-$(date +%Y%m%d_%H%M)
```

---

## 3. File konfigurasi yang tidak ikut git (WAJIB dibuat manual)

### 3a. Kredensial DB aplikasi

```bash
cd /var/www/orangehrm
sudo cp lib/confs/Conf.php.example lib/confs/Conf.php
sudo nano lib/confs/Conf.php        # isi dbUser + dbPass produksi
```

### 3b. Kredensial proxy (MPR / Quota / KPI / Acumatica / SSO)

```bash
sudo cp web/proxy/config.example.php web/proxy/config.php
sudo nano web/proxy/config.php
```

Yang harus diisi untuk produksi:

| key | catatan |
|---|---|
| `db.host` | `127.0.0.1` (MySQL satu server dengan aplikasi) |
| `db.user` / `db.pass` | user MySQL produksi |
| `acumatica.base_url` | `https://asg.acumatica.com` (TANPA suffix `/entity/`) |
| `acumatica.login_user` / `login_pass` / `company` | kredensial integrasi produksi |
| `google_sso.client_id` / `client_secret` | OAuth client Google |
| `google_sso.redirect_uri` | **persis** sama dengan yang didaftarkan di Google Console |
| `google_sso.allowed_email_domain` | `aditya.co.id` |

Contoh redirect URI staging: `http://staging.aditya.group:8181/auth/google/callback`
(bentuk tanpa `/web` hanya berlaku kalau `DocumentRoot` vhost menunjuk ke folder `web`).

Kalau `config.php` lupa dibuat, aplikasi tetap jalan tapi memakai placeholder:
MPR/Quota/KPI akan gagal konek DB dan tombol SSO non-aktif. Cek `error_log` PHP dulu
sebelum menyimpulkan "DB error".

---

## 4. Gate skema: bandingkan DB produksi dengan yang diharapkan kode

Tabel custom proyek ini **tidak punya migrasi** di folder `installer/`, jadi keberadaannya
di DB produksi harus dipastikan manual.

```bash
cd /var/www/orangehrm
php deploy/schema_snapshot.php --db=orangehrm --user=orangehrm --pass='***' > /tmp/schema_prod.txt
wc -l /tmp/schema_prod.txt
# bandingkan dengan snapshot lokal (deploy/schema_local.txt, dibuat di laptop):
#   diff <(cut -f1,2 /tmp/schema_prod.txt) <(cut -f1,2 schema_local.txt)
```

Alternatif cepat: `./deploy/git-deploy.sh --check` sudah mencetak status 15 tabel custom wajib.

Kalau ada tabel/kolom yang kurang: **backup DB dulu**, lalu buat manual (`CREATE TABLE` / `ALTER TABLE`).
Jangan memakai installer untuk menambal — installer bekerja pada skenario instalasi, bukan tambal live DB.

---

## 5. Web server + PHP

Apache, `DocumentRoot` LANGSUNG ke folder `web` (supaya URL tanpa `/web`):

```apache
<VirtualHost *:80>
    ServerName orangehrm.aditya.local
    DocumentRoot /var/www/orangehrm/web

    <Directory /var/www/orangehrm/web>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  /var/log/apache2/orangehrm-error.log
    CustomLog /var/log/apache2/orangehrm-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite && sudo systemctl reload apache2
```

Kalau `DocumentRoot` menunjuk folder project (`/var/www/orangehrm`), semua URL jadi
`http://host/web/...` — termasuk redirect URI SSO. Pilih satu, konsisten.

`php.ini` yang perlu disesuaikan: `memory_limit=512M`, `upload_max_filesize=20M`,
`post_max_size=20M`, `max_execution_time=120`, `date.timezone=Asia/Jakarta`.

Permission runtime:

```bash
sudo chown -R www-data:www-data /var/www/orangehrm
sudo chmod -R 2775 src/cache src/log web/upload
```

---

## 6. Deploy kode (setiap rilis)

```bash
cd /var/www/orangehrm
./deploy/git-deploy.sh --check                    # audit: commit, config, DB, tabel custom
./deploy/git-deploy.sh --deploy release-2026-10-17
```

Script melakukan: `git fetch --tags` → `git checkout <tag>` → `orm:generate-proxies` →
`cache:clear` → `chmod 2775 src/cache src/log web/upload`.

Catatan: **jangan** menghapus folder `src/cache` manual (bikin white screen) — cukup `cache:clear`.
Jangan partial-sync: frontend (`web/dist`) dan backend (`src/plugins`) harus dari tag yang sama.

Rollback:

```bash
./deploy/git-deploy.sh --rollback <tag-sebelumnya>
# kalau ada perubahan skema: restore dump DB yang dibuat sebelum deploy
```

---

## 7. Cron / scheduler

- Sync MPR: dikelola lewat *Task Scheduler* di aplikasi (task `\SyncAcumaticaMPR`, ~1 jam).
  Cek statusnya di menu — sering ter-*disable* setelah testing. Jangan bikin cron dobel.
- Kalau perlu cron OS untuk sync terjadwal:

```cron
0 * * * * cd /var/www/orangehrm && /usr/bin/php web/proxy/sync_manpower_scheduled.php >> /var/log/orangehrm-sync.log 2>&1
```

- Backup DB harian:

```cron
30 1 * * * mysqldump -h 127.0.0.1 -u orangehrm -p'***' --single-transaction orangehrm | gzip > /var/backups/orangehrm-$(date +\%F).sql.gz
```

---

## 8. Smoke test (wajib sebelum dinyatakan selesai)

1. Login admin (dan login Google SSO).
2. Quota Request: list tampil, create, edit, approval.
3. Manpower Request: list, create, edit, approval.
4. Sync Acumatica: jalankan, pastikan request berstatus `New` ikut tersinkron.
5. Notifikasi bell: hanya item unread yang muncul.
6. Export Excel/PDF: file langsung ter-download (bukan dialog print).
7. Dashboard divisi & KPI: angka tampil, tidak ada 500 di log.

Kalau ada yang gagal: cek `/var/log/apache2/orangehrm-error.log`, `src/log/`, dan
`web/proxy/acumatica_push_log.txt`, lalu rollback kalau perlu.
