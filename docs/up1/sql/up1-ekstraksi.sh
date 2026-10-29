#!/usr/bin/env bash
# UP-1 (MAKE-055) — ekstraksi BACA-SAJA dari DB server dev SIMPEG untuk uji paritas Tukin.
#
# Semua parameter koneksi diisi DBA lewat variabel lingkungan saat jalan; JANGAN tulis host/akun/password di berkas ini.
#   UP1_HOST UP1_PORT UP1_USER UP1_DB   koneksi akun baca-saja (password ditanya interaktif oleh -p)
#   UP1_AWAL UP1_AKHIR                  rentang data, YYYY-MM-DD. UP1_AWAL = awal periode SEBELUM periode pertama yang
#                                       diuji (adapter v2 membaca dh_online sejak periode sebelumnya; legacy bulan
#                                       kalender N-1). UP1_AKHIR = tanggal 15 bulan M terakhir.
#   UP1_AWAL_CUTI (opsional)            lookback riwayat_cuti & absen_ijin; bawaan UP1_AWAL - 6 bulan
#   UP1_OUT                             folder kerja terbatas, di luar repo & di luar folder sinkron cloud
#
# Hanya mysqldump --single-transaction (tanpa kunci, tanpa tulis). Tanpa trigger/routine/event. Tidak mengambil
# pengguna/token/tabel kredensial. Nama kolom = [K] legacy; cocokkan dengan DDL sumber sebelum dijalankan.
set -euo pipefail

: "${UP1_HOST:?}" "${UP1_PORT:?}" "${UP1_USER:?}" "${UP1_DB:?}" "${UP1_AWAL:?}" "${UP1_AKHIR:?}" "${UP1_OUT:?}"
[[ "$UP1_AWAL" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ && "$UP1_AKHIR" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] \
  || { echo "UP1_AWAL/UP1_AKHIR harus YYYY-MM-DD" >&2; exit 2; }

mkdir -p "$UP1_OUT"
read -r -s -p "Password akun baca-saja: " UP1_PASS; echo
CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT; chmod 600 "$CNF"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$UP1_HOST" "$UP1_PORT" "$UP1_USER" "$UP1_PASS" > "$CNF"
unset UP1_PASS

UP1_AWAL_CUTI="${UP1_AWAL_CUTI:-$(date -d "$UP1_AWAL -6 months" +%F)}"
[[ "$UP1_AWAL_CUTI" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "UP1_AWAL_CUTI harus YYYY-MM-DD" >&2; exit 2; }
echo "Rentang $UP1_AWAL..$UP1_AKHIR, lookback cuti/izin sejak $UP1_AWAL_CUTI"
A="'$UP1_AWAL'"; Z="'$UP1_AKHIR'"; AC="'$UP1_AWAL_CUTI'"
TAHUN_AWAL="${UP1_AWAL:0:4}"; TAHUN_AKHIR="${UP1_AKHIR:0:4}"

dump() { # dump <berkas> <argumen mysqldump...>
  local berkas="$1"; shift
  mysqldump --defaults-extra-file="$CNF" --single-transaction --skip-lock-tables --no-tablespaces \
    --skip-triggers --default-character-set=utf8mb4 --set-gtid-purged=OFF "$UP1_DB" "$@" > "$UP1_OUT/$berkas"
  echo "ok $berkas"
}

# 1. Penuh: master/rujukan, pegawai & jabatan (populasi rekap butuh status semua pegawai).
dump 01_penuh.sql \
  pegawai pegawai_mutasi_jabatan riwayat_mutasi_jabatan pegawai_plt pegawai_plh pegawai_kp riwayat_kp \
  riwayat_keluarga detail_anak \
  unit satker jabatan group_jabatan sub_group_jabatan rumpun_jabatan struktur_jabatan periode_struktur_jabatan jabatan_koordinasi \
  jenis_pegawai gol_pppk lokasi_presensi jenis_cuti jenis_libur

# 2. Rentang: transaksi presensi, izin, cuti, TB, kalender, SKP/BKN.
dump 10_dh_online.sql           dh_online           --where="tgl BETWEEN $A AND $Z"
dump 11_absen_ijin.sql          absen_ijin          --where="date_start <= $Z AND date_end >= $AC"
dump 12_riwayat_cuti.sql        riwayat_cuti        --where="tgl_mulai <= $Z AND tgl_akhir >= $AC"
dump 13_pegawai_sisa_cuti.sql   pegawai_sisa_cuti   --where="tahun BETWEEN $TAHUN_AWAL AND $TAHUN_AKHIR"
dump 14_riwayat_tb.sql          riwayat_tb          --where="tgl_mulai <= $Z AND (tgl_akhir IS NULL OR tgl_akhir >= $A)"
dump 15_riwayat_tb_perpan.sql   riwayat_tb_perpan   --where="tgl_mulai_perpan <= $Z AND (tgl_akhir_perpan IS NULL OR tgl_akhir_perpan >= $A)"
dump 16_hari_libur.sql          hari_libur          --where="tgl_mulai <= $Z AND tgl_akhir >= $A"
dump 17_bkn_periode_ekinper.sql bkn_periode_ekinper --where="periode_awal <= $Z AND periode_akhir >= $A"
dump 18_riwayat_skp_periodik.sql riwayat_skp_periodik --where="periode_awal_skp <= $Z AND periode_akhir_skp >= $A"

# 3. Konfigurasi: hanya key tarif & jam kerja Tukin.
dump 20_web_config.sql web_config --where="config_name IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK',
  'jam_masuk_normal','jam_pulang_normal','jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa')"

echo "Selesai. Berkas di $UP1_OUT (data pribadi nyata — jangan dibagikan)."
