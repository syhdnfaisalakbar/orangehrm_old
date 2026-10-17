#!/usr/bin/env bash
# ============================================================
# deploy.sh — deploy OrangeHRM ke produksi via rsync+ssh.
#
# USAGE:
#   ./deploy/deploy.sh --dry-run     # AUDIT: cuma tunjukkan daftar, TIDAK sentuh server
#   ./deploy/deploy.sh --sync        # EKSEKUSI: benar2 kirim file ke produksi
#
# SYARAT:
#   1. EDIT deploy/deploy.conf dulu (isikan DEPLOY_USER).
#   2. SSH key sudah terpasang ke server (password login sudah beres).
#   3. SELALU jalankan --dry-run dulu, audit hasilnya, baru --sync.
# ============================================================
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/deploy.conf"

MODE="${1:---dry-run}"

# --- validasi ---
if [[ -z "$DEPLOY_USER" ]]; then
  echo "ERROR: DEPLOY_USER masih kosong. Isi dulu di $DIR/deploy.conf" >&2
  exit 1
fi
if [[ ! -f "$DIR/rsync-exclude.txt" ]]; then
  echo "ERROR: $DIR/rsync-exclude.txt tidak ditemukan" >&2
  exit 1
fi
if ! command -v rsync >/dev/null 2>&1; then
  echo "ERROR: rsync belum terpasang. 'sudo apt install rsync'" >&2
  exit 1
fi

# --- mode ---
EXTRA=()
case "$MODE" in
  --dry-run)
    echo ">>> MODE AUDIT (--dry-run): hanya menampilkan daftar, TIDAK mengubah server"
    EXTRA=(--dry-run --itemize-changes)
    ;;
  --sync)
    echo ">>> MODE EKSEKUSI (--sync): mengirim file ke produksi"
    EXTRA=(-v)
    ;;
  *)
    echo "Penggunaan: $0 [--dry-run|--sync]" >&2
    exit 1
    ;;
esac

DEST_STR="${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_REMOTE_DIR}"
# rsync melewati ssh; sambung port dari deploy.conf
SSH_CMD="ssh -p ${DEPLOY_PORT} -o StrictHostKeyChecking=accept-new"

# Trailing slash di source penting: isi folder, bukan folder-nya jadi subfolder
SOURCE_DIR="${DEPLOY_SOURCE}"

echo ">>> Source   : ${SOURCE_DIR}"
echo ">>> Destinasi: ${DEST_STR}"
echo ">>> Exclude  : ${DIR}/rsync-exclude.txt"
echo ""

rsync "${EXTRA[@]}" \
  -az \
  --delete \
  --exclude-from="$DIR/rsync-exclude.txt" \
  -e "$SSH_CMD" \
  "$SOURCE_DIR" \
  "${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_REMOTE_DIR}/"

echo ""
if [[ "$MODE" == "--dry-run" ]]; then
  echo ">>> AUDIT SELESAI. Periksa daftar di atas."
  echo "    - baris '+++++++' = file baru akan disalin"
  echo "    - baris 'c' atau '.' = isi berubah / tidak berubah"
  echo "    - pastikan TIDAK ada dump sql / .bak / build_log / Conf.php ikut."
  echo "    - kalo sudah yakin jalankan:  ./deploy/deploy.sh --sync"
else
  echo ">>> FILES TERKIRIM. Langkah berikutnya (JALANKAN DI SERVER via SSH):"
  echo "    1) Setup kredensial prod : tulis Conf.php & config proxy dgn creds DB prod"
  echo "       (file di-exclude, jadi belum ada di server)"
  echo "    2) Migrasi DB            : php installer/cli_install.php"
  echo "       (JANGAN restore orangehrm_dump.sql langsung)"
  echo "    3) Regenerate            : php bin/console orm:generate-proxies"
  echo "       lalu                   : php bin/console cache:clear"
  echo "    4) Permissions runtime   : chmod 2775 src/cache src/log  (www-data)"
  echo "    5) Scheduler MPR         : daftarkan sync_manpower_scheduled.php ~1 jam"
  echo "    6) Smoke test end-to-end : login, quota, manpower, sync, SSO, export"
fi