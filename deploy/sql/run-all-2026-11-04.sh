#!/usr/bin/env bash
#
# Jalankan SQL rilis release-2026-11-04 dengan urutan yang benar.
#
# Pakai di server prod (setelah backup DB, SEBELUM checkout kode baru):
#   cd /var/www/orangehrm/deploy/sql      # atau folder hasil scp
#   ./run-all-2026-11-04.sh
#
# Override koneksi bila perlu:
#   DB_HOST=127.0.0.1 DB_USER=orangehrm DB_NAME=orangehrm ./run-all-2026-11-04.sh
#
# Semua file idempotent: aman dijalankan ulang. Password tidak disimpan ke file,
# hanya ditanyakan sekali lalu dipakai lewat variabel MYSQL_PWD selama proses.

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
  candidate-acumatica-task.sql
  user-role-screen.sql
  user-role-no-ess.sql
  user-role-supervisor-home.sql
  supervisor-recruitment-default.sql
  supervisor-recruitment-api.sql
  supervisor-vacancy-api.sql
  mpp-recruitment-full.sql
  mpp-recruitment-workflow.sql
  bod-kpi-access.sql
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
  printf "   %-40s " "$f"
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
echo "   mysql -h $DB_HOST -u $DB_USER $DB_NAME -e \"SHOW TABLES LIKE 'ohrm_candidate_acumatica_task'; SHOW COLUMNS FROM ohrm_user_role LIKE 'exclude_ess'; SELECT id,name FROM ohrm_user_role ORDER BY id;\""
echo
echo "Selesai. Lanjut: ./deploy/git-deploy.sh --check lalu --deploy release-2026-11-04"
