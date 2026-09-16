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
#
# Prasyarat: MySQL berjalan dan database group `tests` (backend/.env: database.tests.*) sudah dibuat,
# karena PHPUnit memakai DatabaseTestTrait (migrate:refresh di database test).
# =============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="${1:-all}"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }

check_backend() {
    log "[backend] composer check (PHPStan -> PHP-CS-Fixer -> PHPUnit)"
    cd "$ROOT_DIR/backend"
    if [ ! -d vendor ]; then
        log "[backend] vendor/ belum ada -> composer install"
        composer install --no-interaction --prefer-dist
    fi
    composer check
    ok "backend lolos"
}

check_frontend() {
    log "[frontend] npm run check (ESLint -> vue-tsc -> Vitest -> build)"
    cd "$ROOT_DIR/frontend"
    if [ ! -d node_modules ]; then
        log "[frontend] node_modules/ belum ada -> npm ci"
        npm ci --no-audit --no-fund
    fi
    npm run check
    ok "frontend lolos"
}

case "$TARGET" in
    all)
        check_backend
        check_frontend
        ;;
    backend)  check_backend ;;
    frontend) check_frontend ;;
    *)
        echo "Pemakaian: ./check.sh [all|backend|frontend]" >&2
        exit 2
        ;;
esac

log "SEMUA CHECK LOLOS ($TARGET)"
