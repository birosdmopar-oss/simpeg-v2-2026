#!/usr/bin/env bash
# =============================================================================
# SIMPEG v2 — check.sh gabungan (F0-12, ADR-017)
#
# Satu command quality gate lokal sebelum commit/push:
#   backend  : composer check  -> PHPStan level 5 -> PHP-CS-Fixer PSR-12 dry-run -> PHPUnit   (F0-10)
#   frontend : npm run check   -> ESLint -> vue-tsc -> Vitest -> vite build                   (F0-11)
#
# Fail-fast: `set -e` menghentikan di langkah pertama yang gagal; exit code 0 hanya kalau semua lolos.
# Urutan sengaja dari yang tercepat gagal (lint) ke yang paling lambat (build).
#
# Pemakaian:
#   ./check.sh            # backend lalu frontend
#   ./check.sh backend    # hanya backend
#   ./check.sh frontend   # hanya frontend
#   ./check.sh smoke      # cepat (MAKE-038): PHPStan + CS-Fixer + PHPUnit tests/unit & tests/feature,
#                         # lalu ESLint + vue-tsc + Vitest; tanpa suite PHPUnit modul dan tanpa vite build.
#                         # Untuk iterasi; tetap jalankan ./check.sh penuh sekali sebelum push.
#
# Prasyarat: MySQL berjalan dan database group `tests` (backend/.env: database.tests.*) sudah dibuat,
# karena PHPUnit memakai DatabaseTestTrait (migrate:refresh di database test).
# =============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-all}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }

# CR-087: folder vendor/ atau node_modules/ yang ada tetapi berkasnya terhapus sebagian (mis. pembersihan Temp Windows)
# membuat runner (vendor/bin/phpunit, node_modules/.bin/vitest, ...) gagal memuat berkasnya tanpa menghentikan gate.
# Karena itu yang diperiksa adalah berkas nyata, bukan keberadaan foldernya.
BACKEND_BERKAS_KUNCI=(
    vendor/autoload.php
    vendor/phpstan/phpstan/phpstan
    vendor/friendsofphp/php-cs-fixer/php-cs-fixer
    vendor/phpunit/phpunit/phpunit
)
FRONTEND_BERKAS_KUNCI=(
    node_modules/.bin/eslint
    node_modules/.bin/vue-tsc
    node_modules/.bin/vitest
)

# Mencetak berkas (satu per baris) yang tidak ada; keluaran kosong berarti lengkap.
berkas_hilang() {
    local berkas
    for berkas in "$@"; do
        [ -f "$berkas" ] || printf '%s\n' "$berkas"
    done
}

# Di direktori backend/: pasang ulang bila ada berkas kunci hilang; gagal keras bila masih hilang.
pastikan_backend_vendor() {
    if [ -n "$(berkas_hilang "${BACKEND_BERKAS_KUNCI[@]}")" ]; then
        log "[backend] berkas vendor/ hilang -> composer install"
        composer install --no-interaction --prefer-dist
    fi
    local hilang
    hilang="$(berkas_hilang "${BACKEND_BERKAS_KUNCI[@]}")"
    if [ -n "$hilang" ]; then
        printf 'GAGAL: berkas vendor backend masih hilang setelah composer install:\n%s\n' "$hilang" >&2
        exit 1
    fi
}

# Di direktori frontend/: pasang ulang bila ada berkas kunci hilang; gagal keras bila masih hilang.
pastikan_frontend_deps() {
    if [ -n "$(berkas_hilang "${FRONTEND_BERKAS_KUNCI[@]}")" ]; then
        log "[frontend] berkas node_modules/ hilang -> npm ci"
        npm ci --no-audit --no-fund
    fi
    local hilang
    hilang="$(berkas_hilang "${FRONTEND_BERKAS_KUNCI[@]}")"
    if [ -n "$hilang" ]; then
        printf 'GAGAL: berkas node_modules frontend masih hilang setelah npm ci:\n%s\n' "$hilang" >&2
        exit 1
    fi
}

# Menjalankan PHPUnit lalu memastikan keluarannya memuat ringkasan hasil ("OK (…)" / "Tests: …").
# Tanpa ringkasan, runner dianggap tidak berjalan (mis. include gagal) dan gate ikut gagal.
# Keluaran tetap tampil; exit code PHPUnit dipertahankan (tidak lewat pipa yang menelannya).
jalankan_phpunit() {
    local log_file status=0
    log_file="$(mktemp)"
    vendor/bin/phpunit --no-coverage "$@" 2>&1 | tee "$log_file" || status=$?
    if [ "$status" -ne 0 ]; then
        rm -f "$log_file"
        exit "$status"
    fi
    if ! grep -Eq 'OK \(|OK, but |Tests: [0-9]+' "$log_file"; then
        rm -f "$log_file"
        echo 'GAGAL: keluaran PHPUnit tidak memuat ringkasan hasil; test dianggap tidak jalan.' >&2
        exit 1
    fi
    rm -f "$log_file"
}

check_backend() {
    log "[backend] composer check (PHPStan -> PHP-CS-Fixer -> PHPUnit)"
    cd "$ROOT_DIR/backend"
    pastikan_backend_vendor
    composer analyse
    composer cs-check
    jalankan_phpunit
    ok "backend lolos"
}

check_frontend() {
    log "[frontend] npm run check (ESLint -> vue-tsc -> Vitest -> build)"
    cd "$ROOT_DIR/frontend"
    pastikan_frontend_deps
    npm run check
    ok "frontend lolos"
}

check_smoke() {
    log "[smoke] backend: PHPStan -> PHP-CS-Fixer -> PHPUnit tests/unit + tests/feature"
    cd "$ROOT_DIR/backend"
    pastikan_backend_vendor
    composer analyse
    composer cs-check
    jalankan_phpunit tests/unit tests/feature
    ok "smoke backend lolos"

    log "[smoke] frontend: ESLint -> vue-tsc -> Vitest (tanpa build)"
    cd "$ROOT_DIR/frontend"
    pastikan_frontend_deps
    npm run lint
    npm run type-check
    npm run test
    ok "smoke frontend lolos"
}

case "$TARGET" in
    all)
        check_backend
        check_frontend
        ;;
    backend)  check_backend ;;
    frontend) check_frontend ;;
    smoke)    check_smoke ;;
    *)
        echo "Pemakaian: ./check.sh [all|backend|frontend|smoke]" >&2
        exit 2
        ;;
esac

log "SEMUA CHECK LOLOS ($TARGET)"
