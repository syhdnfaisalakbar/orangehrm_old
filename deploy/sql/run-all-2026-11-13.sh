#!/usr/bin/env bash
#
# Jalankan SQL rilis release-2026-11-13 dengan urutan yang benar.
#
# Pakai di server prod (setelah backup DB, SEBELUM checkout kode baru):
#   cd /var/www/orangehrm && mkdir -p /tmp/sql && git archive <tag> deploy/sql \
#     | tar -x -C /tmp/sql --strip-components=2 && cd /tmp/sql
#   ./run-all-2026-11-13.sh
#
# Override koneksi bila perlu:
#   DB_HOST=127.0.0.1 DB_USER=orangehrm DB_NAME=orangehrm_backup ./run-all-2026-11-13.sh
#
# Semua file di daftar ini idempotent (sudah diuji 2x di DB lokal: hasil run#2
# identik dengan run#1), jadi aman dijalankan pada server yang sudah pernah
# menjalankan rilis 2026-11-04. Password tidak disimpan ke file, hanya
# ditanyakan sekali lalu dipakai lewat MYSQL_PWD selama proses.
#
# TIDAK termasuk: quota-manual-upload.sql (pakai ALTER TABLE ADD COLUMN tanpa
# IF NOT EXISTS -> ERROR 1060 kalau diulang). File itu sudah dijalankan pada
# rilis 2026-10-27; jangan diulang kecuali di server baru.

set -euo pipefail

cd "$(dirname "$0")"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-orangehrm}"
DB_NAME="${DB_NAME:-}"

# Nama DB diambil dari lib/confs/Conf.php kalau DB_NAME tidak diberikan.
# Jangan pakai default 'orangehrm' buta: di server produksi nama DB bisa lain
# (mis. orangehrm_backup) sehingga SQL masuk ke DB yang salah / tidak ada.
if [ -z "$DB_NAME" ]; then
  PHP_BIN="${PHP_BIN:-}"
  if [ -z "$PHP_BIN" ]; then
    for c in php php8.2 php8.1 php8.0; do
      command -v "$c" >/dev/null 2>&1 && { PHP_BIN="$c"; break; }
    done
  fi
  if [ -n "$PHP_BIN" ] && [ -f ../../lib/confs/Conf.php ]; then
    DB_NAME="$("$PHP_BIN" -r 'require "../../lib/confs/Conf.php"; echo (new Conf())->getDbName();' 2>/dev/null || true)"
  fi
  DB_NAME="${DB_NAME:-orangehrm}"
fi

FILES=(
  # --- dasar role & hak akses (revisi: cari role lewat NAMA, bukan id) ---
  user-role-screen.sql
  user-role-no-ess.sql
  user-role-supervisor-home.sql
  # --- recruitment: MPP/Supervisor (revisi: id modul/screen dicari lewat nama) ---
  supervisor-recruitment-default.sql
  supervisor-recruitment-api.sql
  supervisor-vacancy-api.sql
  mpp-recruitment-full.sql
  mpp-recruitment-workflow.sql
  # --- recruitment: status Task Acumatica per kandidat ---
  candidate-acumatica-task.sql
  # --- KPI: akses BOD + halaman pertama ESS ---
  bod-kpi-access.sql
  home-page-ess-dashboard-divisi.sql
  # --- Dashboard: widget Resource Fulfillments hanya MPP + Admin ---
  dashboard-rf-widget-role.sql
  # --- Manpower Request (FPTK): mapping user -> Owner Acumatica ---
  manpower-owner-acumatica.sql
)

# cek semua file ada dulu, jangan berhenti di tengah jalan
missing=0
for f in "${FILES[@]}"; do
  [ -f "$f" ] || { echo "FILE HILANG: $f"; missing=1; }
done
[ "$missing" -eq 0 ] || exit 1

if [ -z "${MYSQL_PWD:-}" ]; then
  read -rsp "Password MySQL ($DB_USER@$DB_HOST/$DB_NAME): " MYSQL_PWD
  echo
  export MYSQL_PWD
fi

echo "== Menjalankan ${#FILES[@]} file SQL ke $DB_USER@$DB_HOST/$DB_NAME"
for f in "${FILES[@]}"; do
  printf "   %-42s " "$f"
  if mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" < "$f"; then
    echo "OK"
  else
    echo "GAGAL (berhenti)"
    unset MYSQL_PWD
    exit 1
  fi
done
unset MYSQL_PWD

echo
echo "== Verifikasi prasyarat (jalankan manual, password diminta sekali):"
echo "   mysql -h $DB_HOST -u $DB_USER $DB_NAME -e \"\\"
echo "     SHOW COLUMNS FROM ohrm_user_role LIKE 'exclude_ess'; \\"
echo "     SHOW TABLES LIKE 'ohrm_candidate_acumatica_task'; \\"
echo "     SHOW TABLES LIKE 'ohrm_employee_acumatica_owner'; \\"
echo "     SELECT ur.name, hp.action FROM ohrm_home_page hp JOIN ohrm_user_role ur ON ur.id=hp.user_role_id; \\"
echo "     SELECT ur.name, dg.name, rdg.can_read FROM ohrm_user_role_data_group rdg \\"
echo "       JOIN ohrm_data_group dg ON dg.id=rdg.data_group_id \\"
echo "       JOIN ohrm_user_role ur ON ur.id=rdg.user_role_id \\"
echo "      WHERE dg.name='dashboard_resource_fulfillment_widget';\""
echo
echo "Selesai. Lanjut: ./deploy/git-deploy.sh --check lalu --deploy release-2026-11-13"
