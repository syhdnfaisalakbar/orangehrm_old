#!/usr/bin/env bash
# ============================================================
# git-deploy.sh — deploy OrangeHRM di SERVER (bukan di laptop).
# Update = checkout tag dari git, bukan copy file.
#
# PEMAKAIAN (di server, user yang punya akses /var/www/orangehrm):
#   ./deploy/git-deploy.sh --check              # audit read-only, tidak mengubah apa pun
#   ./deploy/git-deploy.sh --deploy release-2026-10-17
#   ./deploy/git-deploy.sh --rollback release-2026-10-01
#
# PRASYARAT:
#   1. Repo sudah di-clone di /var/www/orangehrm (git clone, BUKAN copy folder)
#   2. web/proxy/config.php DAN lib/confs/Conf.php sudah ada (tidak ikut git)
#   3. Deploy key read-only sudah terpasang, `git fetch` bisa jalan
#
# SELALU jalankan --check dulu. File config/kredensial tidak pernah ditimpa
# script ini karena memang tidak ada di git.
# ============================================================
set -uo pipefail

REPO_DIR="${REPO_DIR:-/var/www/orangehrm}"
MODE="${1:-}"
ARG="${2:-}"

# php CLI: utamakan 'php', fallback versi umum
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for c in php php8.2 php8.1 php8.0; do
    if command -v "$c" >/dev/null 2>&1; then PHP_BIN="$c"; break; fi
  done
fi

ok()   { printf '  [OK]   %s\n' "$1"; }
bad()  { printf '  [GAGAL] %s\n' "$1"; }
info() { printf '  [INFO] %s\n' "$1"; }

need_repo() {
  if [ ! -d "$REPO_DIR/.git" ]; then
    bad "$REPO_DIR bukan repo git. Clone dulu: git clone -b development <url> $REPO_DIR"
    exit 1
  fi
  cd "$REPO_DIR" || exit 1
}

preflight() {
  echo "== PREFLIGHT ($REPO_DIR) =="
  need_repo

  info "commit sekarang : $(git rev-parse --short HEAD) $(git log -1 --pretty=%s)"
  info "branch/tag      : $(git describe --tags --always 2>/dev/null)"
  info "php binary      : ${PHP_BIN:-TIDAK ADA}"
  [ -n "$PHP_BIN" ] && ok "$($PHP_BIN -r 'echo PHP_VERSION;')" || bad "php CLI tidak ditemukan"

  # file konfigurasi yang tidak ikut git
  for f in lib/confs/Conf.php web/proxy/config.php; do
    if [ -f "$f" ]; then ok "$f ada"; else bad "$f HILANG (aplikasi akan error / pakai placeholder)"; fi
  done

  # folder runtime harus writable oleh web server
  for d in src/cache src/log web/upload; do
    if [ -d "$d" ]; then
      info "perm $d : $(stat -c '%a %U:%G' "$d")"
    else
      info "$d belum ada (dibuat saat install)"
    fi
  done

  # koneksi DB lewat konstanta dari config.php
  if [ -f web/proxy/config.php ] && [ -n "$PHP_BIN" ]; then
    "$PHP_BIN" -r '
      require "web/proxy/_config.php";
      try {
        $pdo = new PDO("mysql:host=".PROXY_DB_HOST.";port=".PROXY_DB_PORT.";dbname=".PROXY_DB_NAME, PROXY_DB_USER, PROXY_DB_PASS);
        $v = $pdo->query("SELECT value FROM hs_hr_config WHERE name=\"instance.version\"")->fetchColumn();
        echo "  [OK]   koneksi DB sebagai ".PROXY_DB_USER.", instance.version=".$v.PHP_EOL;
      } catch (Throwable $e) {
        echo "  [GAGAL] koneksi DB: ".$e->getMessage().PHP_EOL;
      }'
  fi

  # tabel custom wajib sudah ada (tidak ada migrasinya di repo)
  if [ -f web/proxy/config.php ] && [ -n "$PHP_BIN" ]; then
    "$PHP_BIN" -r '
      require "web/proxy/_config.php";
      $need = ["ohrm_manpower_request","ohrm_manpower_approval","ohrm_manpower_approval_history",
               "ohrm_manpower_vacancy_link","ohrm_quota_requests","ohrm_ext_department_quota",
               "ohrm_kpi_setup","ohrm_kpi_realisasi","ohrm_kpi_realisasi_history","ohrm_kpi_approval_log",
               "ohrm_kpi_division_member","ohrm_kpi_setup_edit_request","ohrm_kpi_setup_period_delete",
               "ohrm_approval_history","ohrm_case_attributes"];
      try {
        $pdo = new PDO("mysql:host=".PROXY_DB_HOST.";port=".PROXY_DB_PORT.";dbname=".PROXY_DB_NAME, PROXY_DB_USER, PROXY_DB_PASS);
        $have = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $miss = array_values(array_diff($need, $have));
        if ($miss) { echo "  [GAGAL] tabel custom BELUM ADA: ".implode(", ", $miss).PHP_EOL; }
        else { echo "  [OK]   semua ".count($need)." tabel custom ada".PHP_EOL; }
      } catch (Throwable $e) { echo "  [GAGAL] cek tabel: ".$e->getMessage().PHP_EOL; }'
  fi
  echo
}

deploy_tag() {
  local tag="$1"
  if [ -z "$tag" ]; then bad "tag kosong. Contoh: $0 --deploy release-2026-10-17"; exit 1; fi
  need_repo

  echo "== DEPLOY: $tag =="
  if [ -n "$(git status --porcelain)" ]; then
    bad "working tree TIDAK bersih di server. Ada file diubah manual:"
    git status --porcelain
    echo "     Periksa dulu. Deploy dibatalkan supaya perubahan itu tidak hilang."
    exit 1
  fi

  git fetch --tags || { bad "git fetch gagal (deploy key / akses repo?)"; exit 1; }
  git checkout "$tag" || { bad "tag $tag tidak ditemukan"; exit 1; }
  ok "checkout $tag ($(git rev-parse --short HEAD))"

  if [ -n "$PHP_BIN" ]; then
    echo "  regenerate proxy entity..."
    "$PHP_BIN" bin/console orm:generate-proxies >/dev/null 2>&1 && ok "orm:generate-proxies" || bad "orm:generate-proxies gagal"
    echo "  bersihkan cache aplikasi..."
    "$PHP_BIN" bin/console cache:clear >/dev/null 2>&1 && ok "cache:clear" || bad "cache:clear gagal"
  fi

  # permission runtime (web server harus bisa tulis)
  chmod 2775 src/cache src/log web/upload 2>/dev/null && ok "chmod 2775 src/cache src/log web/upload"

  echo
  echo "== SELESAI. LANJUT SMOKE TEST =="
  echo "  1. login admin"
  echo "  2. Quota Request: list + create + approval"
  echo "  3. Manpower Request: list + create + approval"
  echo "  4. Sync Acumatica (task \\SyncAcumaticaMPR) — pastikan status sync 'New' ikut"
  echo "  5. Notifikasi bell (hanya unread)"
  echo "  6. Export Excel/PDF auto-download"
  echo "  7. Login Google SSO"
}

case "$MODE" in
  --check)    preflight ;;
  --deploy)   preflight; deploy_tag "$ARG" ;;
  --rollback) preflight; deploy_tag "$ARG" ;;
  *)
    echo "Penggunaan: $0 [--check|--deploy <tag>|--rollback <tag>]"
    echo "  --check              audit read-only (aman dijalankan kapan saja)"
    echo "  --deploy <tag>       checkout tag lalu regenerate proxy + clear cache"
    echo "  --rollback <tag>     sama seperti --deploy, dipakai untuk kembali ke tag lama"
    exit 1
    ;;
esac
