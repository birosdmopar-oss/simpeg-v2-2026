#!/usr/bin/env bash
# =============================================================================
# SIMPEG v2 — rollback / aktifkan release di server Dev (F0-18; ISSUE-014, CR-021)
#
#   ./rollback.sh                 # kembali ke release `previous`
#   ./rollback.sh <release-id>    # pindah ke release tertentu (lihat: ls releases/), termasuk mengaktifkan
#                                 # release yang DITAHAN hook karena migration tertunda — hanya setelah
#                                 # migrate manual lewat runbook selesai (README-deploy.md §3a)
#   ./rollback.sh --force [<release-id>]
#                                 # DARURAT: lewati pemeriksaan migration (README-deploy.md §4)
#
# Hanya memindahkan symlink `current`; tidak menjalankan migration. Sebelum memindah symlink, skrip ini
# menjalankan `php spark migrate:status` (hanya membaca) dari release tujuan dan MENOLAK bila release itu
# masih punya migration tertunda (kode baru tidak boleh berjalan di atas skema lama) atau bila status tidak
# bisa dibaca (fail-closed, mis. DB tidak terjangkau). Rollback ke release lama tetap lolos: semua
# migration release lama sudah tercatat di DB. `--force` melewati pemeriksaan ini.
#
# Konfigurasi sama dengan hook: deploy.env di samping skrip ini (README §1 langkah 6 memasangnya sebagai
# symlink ke <BARE_REPO>/hooks/deploy.env) memberi DEPLOY_ROOT dan PHP_BIN. Tanpa DEPLOY_ROOT di sana,
# DEPLOY_ROOT = direktori skrip ini (skrip dipasang di DEPLOY_ROOT). Nilai DEPLOY_ROOT dari environment
# diabaikan, supaya langkah `<DEPLOY_ROOT>/rollback.sh <id>` yang dicetak hook selalu mengenai root yang sama.
#
# Kalau release yang dibatalkan membawa migration yang sudah dijalankan dan harus dibatalkan, jalankan
# rollback DB dari direktori release ITU (file migration + down() hanya ada di sana; dari release lama CI4
# menolak dengan "gap in the migration sequence"):
#   cd releases/<release-yang-membawa-migration>/backend && php spark migrate:rollback -b <batch>
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
unset DEPLOY_ROOT
# shellcheck disable=SC1091
[ -f "$SCRIPT_DIR/deploy.env" ] && source "$SCRIPT_DIR/deploy.env"
DEPLOY_ROOT="${DEPLOY_ROOT:-$SCRIPT_DIR}"
PHP_BIN="${PHP_BIN:-php}"

RELEASES_DIR="$DEPLOY_ROOT/releases"
CURRENT_LINK="$DEPLOY_ROOT/current"
PREVIOUS_LINK="$DEPLOY_ROOT/previous"
HELD_MARKER=".deploy-ditahan"   # sama dengan hook: tanda release DITAHAN

# -----------------------------------------------------------------------------
# migration_tertunda — IDENTIK dengan fungsi bernama sama di deploy/post-receive (lihat komentar di sana);
# ubah keduanya bersamaan (README §5).
# -----------------------------------------------------------------------------
migration_tertunda() {
    local out rc parsed
    out="$("$PHP_BIN" spark migrate:status --no-header 2>&1)" && rc=0 || rc=$?
    parsed=""
    if [ "$rc" -eq 0 ] && parsed="$(printf '%s\n' "$out" | sed 's/\x1b\[[0-9;]*m//g' | awk -F'|' '
        function trim(s) { gsub(/^[ \t\r]+|[ \t\r]+$/, "", s); return s }
        !/^[|]/ { next }
        !hdr {
            hdr = 1; nf = NF
            for (i = 2; i < NF; i++) col[trim($i)] = i
            if (!("Namespace" in col) || !("Version" in col) || !("Filename" in col) || !("Batch" in col)) {
                bad = "header tanpa kolom Namespace/Version/Filename/Batch: " $0; exit 2
            }
            cn = col["Namespace"]; cv = col["Version"]; cf = col["Filename"]; cb = col["Batch"]
            next
        }
        NF != nf { bad = "jumlah kolom baris berbeda dari header: " $0; exit 2 }
        {
            rows++; b = trim($cb)
            if (b == "---") pending = pending sprintf("      %s  %s  %s\n", trim($cn), trim($cv), trim($cf))
            else if (b !~ /^[0-9]+$/) { bad = "nilai Batch tidak dikenal: " $0; exit 2 }
        }
        END {
            if (bad == "" && !hdr) bad = "tabel status tidak ditemukan"
            if (bad == "" && rows == 0) bad = "tabel status tanpa baris migration"
            if (bad != "") { print "format tidak dikenal (" bad ")"; exit 2 }
            printf "%s", pending
        }')"; then
        [ -n "$parsed" ] && printf '%s\n' "$parsed"
        return 0
    fi
    echo "!!  status migration tidak bisa dibaca (php spark migrate:status, exit $rc)${parsed:+: $parsed}" >&2
    printf '%s\n' "$out" >&2
    return 1
}

force=0
target=""
for arg in "$@"; do
    case "$arg" in
        --force) force=1 ;;
        -*) echo "Opsi tidak dikenal: $arg (pakai: rollback.sh [--force] [<release-id>])" >&2; exit 2 ;;
        *)
            if [ -n "$target" ]; then
                echo "Terlalu banyak argumen (pakai: rollback.sh [--force] [<release-id>])" >&2
                exit 2
            fi
            target="$arg"
            ;;
    esac
done

if [ ! -d "$RELEASES_DIR" ]; then
    echo "DEPLOY_ROOT tidak valid: $RELEASES_DIR tidak ada (pasang rollback.sh di DEPLOY_ROOT atau isi DEPLOY_ROOT di deploy.env; README-deploy.md §1)" >&2
    exit 1
fi

if [ -z "$target" ]; then
    if [ ! -L "$PREVIOUS_LINK" ]; then
        echo "Tidak ada symlink previous — sebutkan release-id: $(ls -1 "$RELEASES_DIR" | tr '\n' ' ')" >&2
        exit 1
    fi
    target_dir="$(readlink -f "$PREVIOUS_LINK")"
else
    case "$target" in
        */* | . | ..) echo "release-id tidak valid: $target (nama folder di $RELEASES_DIR)" >&2; exit 1 ;;
    esac
    target_dir="$RELEASES_DIR/$target"
fi

if [ ! -d "$target_dir" ]; then
    echo "Release tidak ditemukan: $target_dir" >&2
    exit 1
fi

target_dir="$(readlink -f "$target_dir")"
target_id="$(basename "$target_dir")"

if [ "$force" = "1" ]; then
    echo "!!  --force: pemeriksaan migration dilewati. Pastikan skema database cocok dengan release $target_id (README-deploy.md §4)." >&2
else
    if ! pending="$(cd "$target_dir/backend" && migration_tertunda)"; then
        echo "DIBATALKAN: status migration release $target_id tidak bisa dibaca — current tidak diubah." >&2
        echo "    Periksa pesan di atas (koneksi database di shared/backend.env, atau format migrate:status yang tidak dikenal)." >&2
        echo "    Darurat saja: rollback.sh --force $target_id (README-deploy.md §4)." >&2
        exit 1
    fi
    if [ -n "$pending" ]; then
        echo "DIBATALKAN: release $target_id masih punya migration tertunda — current tidak diubah:" >&2
        printf '%s\n' "$pending" >&2
        echo "    Jalankan dulu migrate manual dari release itu lewat runbook setelah approval DBV (README-deploy.md §3a langkah 1-3)," >&2
        echo "    lalu ulangi perintah ini. Kode baru tidak boleh berjalan di atas skema lama (ISSUE-014)." >&2
        exit 1
    fi
fi

# `current` belum ada (mis. release pertama yang ditahan hook): jangan buat `previous` yang menunjuk ke `current`.
cur=""
if [ -L "$CURRENT_LINK" ]; then
    cur="$(readlink -f "$CURRENT_LINK")"
fi
if [ -n "$cur" ] && [ "$cur" != "$target_dir" ]; then
    ln -sfn "$cur" "$PREVIOUS_LINK"
fi
ln -sfn "$target_dir" "$CURRENT_LINK.tmp" && mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
# Release sudah aktif: bukan lagi release DITAHAN (hook tidak akan menghapusnya sebagai release DITAHAN lama).
rm -f "$target_dir/$HELD_MARKER"

echo "OK  current -> $target_id"
