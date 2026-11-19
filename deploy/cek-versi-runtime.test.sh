#!/usr/bin/env bash
# Uji logika deploy/cek-versi-runtime.sh dengan versi tiruan (CR-154) — tanpa menurunkan runtime. Dijalankan di CI (job static).
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$DIR/cek-versi-runtime.sh"
gagal=0

harap() {
    local kode="$1" runtime="$2" versi="$3" rc
    bash "$GUARD" "$runtime" "$versi" >/dev/null 2>&1 && rc=0 || rc=$?
    if [ "$rc" -ne "$kode" ]; then
        echo "GAGAL: $runtime '$versi' keluar $rc, harap $kode" >&2
        gagal=1
    else
        echo "ok   : $runtime '$versi' -> $rc"
    fi
}

# PHP aman
harap 0 php 8.4.27
harap 0 php 8.4.30
harap 0 php 8.3.36
harap 0 php 8.3.40
harap 0 php 8.4.27-1ubuntu1
# PHP rentan / seri di luar daftar
harap 1 php 8.4.2
harap 1 php 8.4.26
harap 1 php 8.3.35
harap 1 php 8.2.35
harap 1 php 8.5.12
harap 1 php 7.4.33
harap 1 php 9.0.0
harap 1 php 8.4
harap 1 php abc
# Node aman
harap 0 node v24.22.1
harap 0 node 24.30.0
harap 0 node v22.23.4
harap 0 node 22.24.0
# Node rentan / seri di luar daftar
harap 1 node v24.22.0
harap 1 node v24.10.0
harap 1 node v22.23.3
harap 1 node v20.19.0
harap 1 node v25.0.0
harap 1 node v26.12.1
harap 1 node v23.11.0
# Argumen salah
harap 2 ruby 3.3.0

# Tanpa VERSI: membaca biner lewat PHP_BIN / NODE_BIN (biner tiruan).
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
printf '#!/usr/bin/env bash\necho -n 8.4.2\n' > "$tmp/php-lama"
printf '#!/usr/bin/env bash\necho -n 8.4.27\n' > "$tmp/php-baru"
printf '#!/usr/bin/env bash\necho v24.22.1\n' > "$tmp/node-baru"
chmod +x "$tmp"/*
for kasus in "1 php-lama php PHP_BIN" "0 php-baru php PHP_BIN" "0 node-baru node NODE_BIN" "1 tidak-ada php PHP_BIN"; do
    read -r kode bin runtime var <<<"$kasus"
    env "$var=$tmp/$bin" bash "$GUARD" "$runtime" >/dev/null 2>&1 && rc=0 || rc=$?
    if [ "$rc" -ne "$kode" ]; then echo "GAGAL: $var=$bin keluar $rc, harap $kode" >&2; gagal=1; else echo "ok   : $var=$bin -> $rc"; fi
done

if [ "$gagal" -ne 0 ]; then
    echo "!! uji guard versi runtime GAGAL" >&2
    exit 1
fi
echo "==> uji guard versi runtime lolos"
