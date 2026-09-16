#!/usr/bin/env bash
# =============================================================================
# SIMPEG v2 — rollback release di server Dev (F0-18)
#
#   ./rollback.sh                 # kembali ke release `previous`
#   ./rollback.sh <release-id>    # kembali ke release tertentu (lihat: ls releases/)
#
# Hanya memindahkan symlink `current`; tidak menyentuh database.
# Kalau release yang gagal sempat menjalankan migration baru, jalankan manual:
#   cd current/backend && php spark migrate:rollback -b <batch>
# =============================================================================
set -euo pipefail

DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/simpeg-v2}"
RELEASES_DIR="$DEPLOY_ROOT/releases"
CURRENT_LINK="$DEPLOY_ROOT/current"
PREVIOUS_LINK="$DEPLOY_ROOT/previous"

target="${1:-}"

if [ -z "$target" ]; then
    if [ ! -L "$PREVIOUS_LINK" ]; then
        echo "Tidak ada symlink previous — sebutkan release-id: $(ls -1 "$RELEASES_DIR" | tr '\n' ' ')" >&2
        exit 1
    fi
    target_dir="$(readlink -f "$PREVIOUS_LINK")"
else
    target_dir="$RELEASES_DIR/$target"
fi

if [ ! -d "$target_dir" ]; then
    echo "Release tidak ditemukan: $target_dir" >&2
    exit 1
fi

cur="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"
if [ -n "$cur" ] && [ "$cur" != "$target_dir" ]; then
    ln -sfn "$cur" "$PREVIOUS_LINK"
fi
ln -sfn "$target_dir" "$CURRENT_LINK.tmp" && mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"

echo "OK  current -> $(basename "$target_dir")"
