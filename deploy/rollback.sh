#!/usr/bin/env bash
# =============================================================================
# SIMPEG v2 — rollback / aktifkan release di server Dev (F0-18)
#
#   ./rollback.sh                 # kembali ke release `previous`
#   ./rollback.sh <release-id>    # pindah ke release tertentu (lihat: ls releases/), termasuk mengaktifkan
#                                 # release yang DITAHAN hook karena migration tertunda — hanya setelah
#                                 # migrate manual lewat runbook selesai (README-deploy.md §3a, ISSUE-014)
#
# Hanya memindahkan symlink `current`; tidak menyentuh database.
# Kalau release yang dibatalkan membawa migration yang sudah dijalankan dan harus dibatalkan, jalankan
# rollback DB dari direktori release ITU (file migration + down() hanya ada di sana; dari release lama CI4
# menolak dengan "gap in the migration sequence"):
#   cd releases/<release-yang-membawa-migration>/backend && php spark migrate:rollback -b <batch>
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

target_dir="$(readlink -f "$target_dir")"

# `current` belum ada (mis. release pertama yang ditahan hook): jangan buat `previous` yang menunjuk ke `current`.
cur=""
if [ -L "$CURRENT_LINK" ]; then
    cur="$(readlink -f "$CURRENT_LINK")"
fi
if [ -n "$cur" ] && [ "$cur" != "$target_dir" ]; then
    ln -sfn "$cur" "$PREVIOUS_LINK"
fi
ln -sfn "$target_dir" "$CURRENT_LINK.tmp" && mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"

echo "OK  current -> $(basename "$target_dir")"
