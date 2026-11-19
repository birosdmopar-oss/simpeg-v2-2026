#!/usr/bin/env bash
# =============================================================================
# SIMPEG v2 — guard versi runtime (CR-154; ISSUE-053 PHP2Shell, ISSUE-054 Node2Shell)
#
# Menolak PHP/Node yang rentan. Dipakai CI (.github/workflows/quality-gate.yml) dan deploy/post-receive.
#
#   cek-versi-runtime.sh php  [VERSI]   # tanpa VERSI: "$PHP_BIN" (default php) -r 'echo PHP_VERSION;'
#   cek-versi-runtime.sh node [VERSI]   # tanpa VERSI: "$NODE_BIN" (default node) -v
#
# VERSI dipakai untuk uji logika dengan versi tiruan (deploy/cek-versi-runtime.test.sh) tanpa menurunkan runtime.
# Keluar 0 bila aman; 1 bila rentan / seri di luar daftar aman / versi tidak terbaca; 2 bila argumen salah.
#
# Daftar aman (seri lain, termasuk yang lebih baru, ditolak sampai daftar ini diperbarui lewat CR):
#   PHP  : 8.3.x >= 8.3.36, 8.4.x >= 8.4.27
#   Node : 22.x  >= 22.23.4, 24.x >= 24.22.1
# =============================================================================
set -euo pipefail

ISU_PHP='ISSUE-053 (PHP2Shell, CVE-2026-109618/CVE-2026-109619)'
ISU_NODE='ISSUE-054 (Node2Shell, CVE-2026-111497)'

# versi_ge A B: 0 bila A >= B (keduanya X.Y.Z angka).
versi_ge() {
    local a1 a2 a3 b1 b2 b3
    IFS=. read -r a1 a2 a3 <<<"$1"
    IFS=. read -r b1 b2 b3 <<<"$2"
    if [ "$a1" -ne "$b1" ]; then [ "$a1" -gt "$b1" ]; return; fi
    if [ "$a2" -ne "$b2" ]; then [ "$a2" -gt "$b2" ]; return; fi
    [ "$a3" -ge "$b3" ]
}

# normalisasi: buang awalan "v" dan akhiran non-angka (mis. "8.4.27-1ubuntu", "v24.22.1"); hasil kosong bila bukan X.Y.Z.
normalisasi() {
    local v="${1#v}"
    if [[ "$v" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]]; then
        printf '%s.%s.%s' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}"
    fi
}

tolak() {
    echo "!!  [cek-versi-runtime] DITOLAK: $1" >&2
    exit 1
}

runtime="${1:-}"
versi_mentah="${2:-}"

case "$runtime" in
    php)
        if [ -z "$versi_mentah" ]; then
            versi_mentah="$("${PHP_BIN:-php}" -r 'echo PHP_VERSION;' 2>/dev/null)" || tolak "PHP (${PHP_BIN:-php}) tidak bisa dijalankan; $ISU_PHP"
        fi
        isu="$ISU_PHP"
        aman='8.3.x >= 8.3.36 atau 8.4.x >= 8.4.27'
        ;;
    node)
        if [ -z "$versi_mentah" ]; then
            versi_mentah="$("${NODE_BIN:-node}" -v 2>/dev/null)" || tolak "Node (${NODE_BIN:-node}) tidak bisa dijalankan; $ISU_NODE"
        fi
        isu="$ISU_NODE"
        aman='22.x >= 22.23.4 atau 24.x >= 24.22.1'
        ;;
    *)
        echo "Pemakaian: $0 php|node [VERSI]" >&2
        exit 2
        ;;
esac

versi="$(normalisasi "$versi_mentah")"
[ -n "$versi" ] || tolak "versi $runtime tidak terbaca ('$versi_mentah'); aman: $aman; $isu"

seri="${versi%.*}"
case "$runtime:$seri" in
    php:8.3) minimum=8.3.36 ;;
    php:8.4) minimum=8.4.27 ;;
    node:22.*) minimum=22.23.4 ;;
    node:24.*) minimum=24.22.1 ;;
    *) minimum="" ;;
esac

if [ -z "$minimum" ]; then
    tolak "$runtime $versi di luar daftar seri aman ($aman); $isu"
fi
if ! versi_ge "$versi" "$minimum"; then
    tolak "$runtime $versi rentan, minimum $minimum ($aman); $isu"
fi

echo "==> [cek-versi-runtime] $runtime $versi aman (minimum seri: $minimum)"
