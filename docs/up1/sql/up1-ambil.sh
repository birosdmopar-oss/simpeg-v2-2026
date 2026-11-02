#!/usr/bin/env bash
# UP-1 (MAKE-055, CR-088) — UP1-04 dalam SATU perintah, untuk Git Bash di Windows (mysql/mysqldump 8.x):
#   cek zona & BKN -> ekstraksi BACA-SAJA DB dev -> hitung baris -> restore ke skema salinan di MySQL lokal -> verifikasi.
#   bash docs/up1/sql/up1-ambil.sh
#
# Host/akun/password DB dev ditanya saat jalan dan TIDAK disimpan. Data nyata hanya ada di folder kerja (bawaan
# C:\UP1_kerja, di luar repo & folder sinkron cloud) dan di skema up1_legacy_salinan. Yang boleh dibagikan hanya
# ringkasan_untuk_pr.txt (jam, tanggal, nama tabel, jumlah, id unit; tanpa NIP/nama/host/akun).
# Periode bawaan = P-05 (bulan Tukin Sep & Okt 2026); ubah lewat UP1_AWAL/UP1_AKHIR/UP1_AWAL_CUTI/UP1_BULAN_UJI.
# Isi tabel & saringan sama dengan up1-ekstraksi.sh + up1-hitung-baris.sql (Runbook langkah 7–10). Kueri memakai literal,
# bukan @variabel (ERROR 1267 collation di MySQL 8); BKN disaring numerik tahun*100+bulan karena periode_* = 'MM-DD'.
set -euo pipefail

AWAL=${UP1_AWAL:-2026-07-16}; AKHIR=${UP1_AKHIR:-2026-10-15}; AWAL_CUTI=${UP1_AWAL_CUTI:-2026-01-16}   # P-05
BULAN_UJI=${UP1_BULAN_UJI:-202609 202610}            # bulan Tukin yang dibandingkan: wajib tepat satu baris BKN (T-04)
for v in "$AWAL" "$AKHIR" "$AWAL_CUTI"; do [[ "$v" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "tanggal harus YYYY-MM-DD: $v" >&2; exit 2; }; done
[[ "$BULAN_UJI" =~ ^[0-9]{6}( [0-9]{6})*$ ]] || { echo "UP1_BULAN_UJI harus YYYYMM dipisah spasi" >&2; exit 2; }
BKN_DARI="${AWAL:0:4}${AWAL:5:2}"; BKN_SAMPAI="${AKHIR:0:4}${AKHIR:5:2}"; BKN_YM="CAST(tahun AS UNSIGNED)*100 + CAST(bulan AS UNSIGNED)"
KERJA=${UP1_KERJA:-/c/UP1_kerja}; DUMP=$KERJA/dump; SALINAN=${UP1_SALINAN:-up1_legacy_salinan}
LOKAL_HOST=127.0.0.1; LOKAL_PORT=3307

export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'   # argumen SQL berisi '/' tidak boleh diubah Git Bash
PATH="$PATH:/c/laragon/bin/mysql/mysql-8.0.30-winx64/bin"
command -v mysql >/dev/null && command -v mysqldump >/dev/null || { echo "mysql/mysqldump tidak ditemukan" >&2; exit 1; }
langkah() { echo; echo "=== $* ==="; }
gagal() { echo; echo "BERHENTI: $*" >&2; exit 1; }

# --- 0. Persiapan --------------------------------------------------------------------------------------------------
case "$KERJA" in *OneDrive*|*Dropbox*|*"Google Drive"*) gagal "folder kerja ada di folder sinkron cloud";; esac
mkdir -p "$DUMP"
[ -z "$(ls -A "$DUMP")" ] || gagal "$DUMP tidak kosong (sisa ekstraksi sebelumnya). Pindahkan/hapus dulu isinya."

CNF_DEV="$(mktemp)"; CNF_LOKAL="$(mktemp)"; trap 'rm -f "$CNF_DEV" "$CNF_LOKAL"' EXIT
esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }

echo "Koneksi DB dev (akun BACA-SAJA). Tidak ada yang disimpan."
read -r -p "  Host      : " H; read -r -p "  Port [3306]: " P; P=${P:-3306}
read -r -p "  User      : " U; read -r -p "  Nama DB   : " D; read -r -s -p "  Password  : " PW; echo
printf '[client]\nhost="%s"\nport=%s\nuser="%s"\npassword="%s"\ndefault-character-set=utf8mb4\n' \
  "$(esc "$H")" "$P" "$(esc "$U")" "$(esc "$PW")" > "$CNF_DEV"; unset PW
CNFW_DEV="$(cygpath -w "$CNF_DEV")"; CNFW_LOKAL="$(cygpath -w "$CNF_LOKAL")"

printf '[client]\nhost=%s\nport=%s\nuser=root\ndefault-character-set=utf8mb4\n' "$LOKAL_HOST" "$LOKAL_PORT" > "$CNF_LOKAL"
if ! mysql --defaults-extra-file="$CNFW_LOKAL" -N -e "SELECT 1" >/dev/null 2>&1; then
  read -r -s -p "Password root MySQL lokal 3307: " PL; echo
  printf 'password="%s"\n' "$(esc "$PL")" >> "$CNF_LOKAL"; unset PL
  mysql --defaults-extra-file="$CNFW_LOKAL" -N -e "SELECT 1" >/dev/null || gagal "tidak bisa masuk ke MySQL lokal 3307 (sudah dinyalakan?)"
fi
dev()   { mysql --defaults-extra-file="$CNFW_DEV" "$@" "$D"; }
lokal() { mysql --defaults-extra-file="$CNFW_LOKAL" "$@"; }

dev -N -e "SELECT 1" >/dev/null || gagal "tidak bisa masuk ke DB dev"
[ -z "$(lokal -N -e "SHOW DATABASES LIKE '$SALINAN'")" ] || gagal "skema $SALINAN sudah ada di 3307. Hapus dulu (keputusan Horii) lalu ulangi."
MULAI="$(date '+%Y-%m-%d %H:%M') WIB"
RINGKAS=$KERJA/ringkasan_untuk_pr.txt
{ echo "UP1-04 — ringkasan (tanpa NIP/nama/host/akun)"; echo "Tanggal salinan: $MULAI"; echo "Rentang: $AWAL..$AKHIR, cuti/izin sejak $AWAL_CUTI, BKN $BKN_DARI..$BKN_SAMPAI"; } > "$RINGKAS"

# --- 1. Zona jam & BKN -------------------------------------------------------------------------------------------
langkah "1/6 Zona jam server dev (GAP-2)"
{ echo; echo "## 1. Zona jam"; dev -t -e "SELECT NOW(), UTC_TIMESTAMP(), TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW()) selisih_jam, @@system_time_zone, @@global.time_zone, VERSION();"; } | tee -a "$RINGKAS"

langkah "2/6 Baris BKN periode uji (T-04)"
{ echo; echo "## 2. BKN (jenis_periode NULL)"; dev -t -e "SELECT tahun, bulan, periode_awal, periode_akhir, COUNT(*) n FROM bkn_periode_ekinper WHERE jenis_periode IS NULL AND $BKN_YM BETWEEN $BKN_DARI AND $BKN_SAMPAI GROUP BY tahun, bulan, periode_awal, periode_akhir ORDER BY tahun, bulan;"; } | tee -a "$RINGKAS"
OK_BKN=$(dev -N -e "SELECT COUNT(*) FROM (SELECT tahun, bulan FROM bkn_periode_ekinper WHERE jenis_periode IS NULL AND $BKN_YM IN (${BULAN_UJI// /,}) AND periode_awal REGEXP '^[0-9]{2}-[0-9]{2}\$' AND periode_akhir REGEXP '^[0-9]{2}-[0-9]{2}\$' GROUP BY tahun, bulan HAVING COUNT(*) = 1) x;")
N_UJI=$(wc -w <<< "$BULAN_UJI" | tr -d ' ')
[ "$OK_BKN" = "$N_UJI" ] || gagal "BKN bulan uji ($BULAN_UJI) harus tepat satu baris per bulan berformat MM-DD (lolos: $OK_BKN dari $N_UJI). Lihat tabel di atas."

# --- 2. Ekstraksi ------------------------------------------------------------------------------------------------
langkah "3/6 Ekstraksi baca-saja (mysqldump --single-transaction)"
A="'$AWAL'"; Z="'$AKHIR'"; AC="'$AWAL_CUTI'"
dump() { local berkas="$1"; shift
  mysqldump --defaults-extra-file="$CNFW_DEV" --single-transaction --skip-lock-tables --no-tablespaces --skip-triggers \
    --default-character-set=utf8mb4 --set-gtid-purged=OFF "$D" "$@" > "$DUMP/$berkas"; echo "  ok $berkas"; }
dump 01_penuh.sql pegawai pegawai_mutasi_jabatan riwayat_mutasi_jabatan pegawai_plt pegawai_plh pegawai_kp riwayat_kp \
  riwayat_keluarga detail_anak unit satker jabatan group_jabatan sub_group_jabatan rumpun_jabatan struktur_jabatan \
  periode_struktur_jabatan jabatan_koordinasi kelas_jabatan jenis_pegawai gol_pppk lokasi_presensi jenis_cuti jenis_libur \
  agama jenis_status provinsi kabupaten_kota pangkat jenis_kp jenjang_pendidikan
if [ -n "$(dev -N -e "SHOW TABLES LIKE 'jenjang_jf'")" ]; then dump 02_jenjang_jf.sql jenjang_jf; fi
dump 10_dh_online.sql            dh_online            --where="tgl BETWEEN $A AND $Z"
dump 11_absen_ijin.sql           absen_ijin           --where="date_start <= $Z AND date_end >= $AC"
dump 12_riwayat_cuti.sql         riwayat_cuti         --where="tgl_mulai <= $Z AND tgl_akhir >= $AC"
dump 13_pegawai_sisa_cuti.sql    pegawai_sisa_cuti    --where="tahun BETWEEN ${AWAL:0:4} AND ${AKHIR:0:4}"
dump 14_riwayat_tb.sql           riwayat_tb           --where="tgl_mulai <= $Z AND (tgl_akhir IS NULL OR tgl_akhir >= $A)"
dump 15_riwayat_tb_perpan.sql    riwayat_tb_perpan    --where="tgl_mulai_perpan <= $Z AND (tgl_akhir_perpan IS NULL OR tgl_akhir_perpan >= $A)"
dump 16_hari_libur.sql           hari_libur           --where="tgl_mulai <= $Z AND tgl_akhir >= $A"
dump 17_bkn_periode_ekinper.sql  bkn_periode_ekinper  --where="$BKN_YM BETWEEN $BKN_DARI AND $BKN_SAMPAI"
dump 18_riwayat_skp_periodik.sql riwayat_skp_periodik --where="periode_awal_skp <= $Z AND periode_akhir_skp >= $A"
dump 20_web_config.sql web_config --where="config_name IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK','jam_masuk_normal','jam_pulang_normal','jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa')"

# --- 3. Hitung baris (sumber) ------------------------------------------------------------------------------------
HITUNG="SELECT 'pegawai' tabel, COUNT(*) n FROM pegawai
UNION ALL SELECT 'pegawai_mutasi_jabatan', COUNT(*) FROM pegawai_mutasi_jabatan
UNION ALL SELECT 'riwayat_mutasi_jabatan', COUNT(*) FROM riwayat_mutasi_jabatan
UNION ALL SELECT 'pegawai_plt', COUNT(*) FROM pegawai_plt
UNION ALL SELECT 'pegawai_plh', COUNT(*) FROM pegawai_plh
UNION ALL SELECT 'riwayat_keluarga', COUNT(*) FROM riwayat_keluarga
UNION ALL SELECT 'detail_anak', COUNT(*) FROM detail_anak
UNION ALL SELECT 'unit', COUNT(*) FROM unit
UNION ALL SELECT 'satker', COUNT(*) FROM satker
UNION ALL SELECT 'jabatan', COUNT(*) FROM jabatan
UNION ALL SELECT 'rumpun_jabatan', COUNT(*) FROM rumpun_jabatan
UNION ALL SELECT 'kelas_jabatan', COUNT(*) FROM kelas_jabatan
UNION ALL SELECT 'jabatan_koordinasi', COUNT(*) FROM jabatan_koordinasi
UNION ALL SELECT 'agama', COUNT(*) FROM agama
UNION ALL SELECT 'jenis_status', COUNT(*) FROM jenis_status
UNION ALL SELECT 'provinsi', COUNT(*) FROM provinsi
UNION ALL SELECT 'kabupaten_kota', COUNT(*) FROM kabupaten_kota
UNION ALL SELECT 'pangkat', COUNT(*) FROM pangkat
UNION ALL SELECT 'jenis_kp', COUNT(*) FROM jenis_kp
UNION ALL SELECT 'jenjang_pendidikan', COUNT(*) FROM jenjang_pendidikan
UNION ALL SELECT 'pegawai_kp', COUNT(*) FROM pegawai_kp
UNION ALL SELECT 'riwayat_kp', COUNT(*) FROM riwayat_kp
UNION ALL SELECT 'lokasi_presensi', COUNT(*) FROM lokasi_presensi
UNION ALL SELECT 'dh_online', COUNT(*) FROM dh_online WHERE tgl BETWEEN $A AND $Z
UNION ALL SELECT 'dh_online_tanpa_jam', COUNT(*) FROM dh_online WHERE tgl BETWEEN $A AND $Z AND jam_masuk IS NULL AND jam_pulang IS NULL
UNION ALL SELECT 'absen_ijin', COUNT(*) FROM absen_ijin WHERE date_start <= $Z AND date_end >= $AC
UNION ALL SELECT 'riwayat_cuti', COUNT(*) FROM riwayat_cuti WHERE tgl_mulai <= $Z AND tgl_akhir >= $AC
UNION ALL SELECT 'pegawai_sisa_cuti', COUNT(*) FROM pegawai_sisa_cuti WHERE tahun BETWEEN ${AWAL:0:4} AND ${AKHIR:0:4}
UNION ALL SELECT 'riwayat_tb', COUNT(*) FROM riwayat_tb WHERE tgl_mulai <= $Z AND (tgl_akhir IS NULL OR tgl_akhir >= $A)
UNION ALL SELECT 'riwayat_tb_perpan', COUNT(*) FROM riwayat_tb_perpan WHERE tgl_mulai_perpan <= $Z AND (tgl_akhir_perpan IS NULL OR tgl_akhir_perpan >= $A)
UNION ALL SELECT 'hari_libur', COUNT(*) FROM hari_libur WHERE tgl_mulai <= $Z AND tgl_akhir >= $A
UNION ALL SELECT 'bkn_periode_ekinper', COUNT(*) FROM bkn_periode_ekinper WHERE $BKN_YM BETWEEN $BKN_DARI AND $BKN_SAMPAI
UNION ALL SELECT 'riwayat_skp_periodik', COUNT(*) FROM riwayat_skp_periodik WHERE periode_awal_skp <= $Z AND periode_akhir_skp >= $A
UNION ALL SELECT 'web_config_tukin', COUNT(*) FROM web_config WHERE config_name IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK','jam_masuk_normal','jam_pulang_normal','jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa');"
KONTROL="SELECT DATE_FORMAT(tgl, '%Y-%m') bulan, COUNT(*) n FROM dh_online WHERE tgl BETWEEN $A AND $Z GROUP BY bulan ORDER BY bulan;
SELECT id_jenis_cuti, status, COUNT(*) n FROM riwayat_cuti WHERE tgl_mulai <= $Z AND tgl_akhir >= $AC GROUP BY id_jenis_cuti, status ORDER BY id_jenis_cuti, status;"
LINGKUP="SELECT pmj.id_unit, rc.id_jenis_cuti, COUNT(*) n FROM riwayat_cuti rc JOIN pegawai_mutasi_jabatan pmj ON pmj.nip = rc.nip
 WHERE rc.tgl_mulai <= $Z AND rc.tgl_akhir >= $A AND rc.status = 1
   AND DATE_FORMAT(DATE_SUB(rc.tgl_mulai, INTERVAL 15 DAY), '%Y-%m') <> DATE_FORMAT(DATE_SUB(rc.tgl_akhir, INTERVAL 15 DAY), '%Y-%m')
 GROUP BY pmj.id_unit, rc.id_jenis_cuti ORDER BY n DESC LIMIT 20;"

langkah "4/6 Hitung baris di DB dev"
dev -B -e "$HITUNG" > "$KERJA/hitung_sumber.txt"
{ echo; echo "## 3. Jumlah baris sumber (hitung_sumber.txt)"; dev -t -e "$HITUNG"; echo; echo "## 4. Kontrol isi"; dev -t -e "$KONTROL";
  echo; echo "## 5. Kandidat lingkup: cuti melewati batas periode, per id_unit"; dev -t -e "$LINGKUP"; } | tee -a "$RINGKAS"

# --- 4. Restore ke 3307 -------------------------------------------------------------------------------------------
langkah "5/6 Restore ke $SALINAN (MySQL lokal $LOKAL_PORT)"
lokal -e "CREATE DATABASE \`$SALINAN\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for f in "$DUMP"/*.sql; do lokal "$SALINAN" < "$f" || gagal "restore $(basename "$f")"; echo "  ok $(basename "$f")"; done

# --- 5. Verifikasi ------------------------------------------------------------------------------------------------
langkah "6/6 Verifikasi salinan"
lokal -B -e "$HITUNG" "$SALINAN" > "$KERJA/hitung_salinan.txt"
TRIGGER=$(lokal -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$SALINAN'")
PENGGUNA=$(lokal -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SALINAN' AND TABLE_NAME IN ('pengguna','token')")
{ echo; echo "## 6. Verifikasi salinan"; echo "trigger: $TRIGGER (harus 0); tabel pengguna/token: $PENGGUNA (harus 0)"
  if diff -q "$KERJA/hitung_sumber.txt" "$KERJA/hitung_salinan.txt" >/dev/null; then echo "jumlah baris salinan = sumber: SAMA"
  else echo "jumlah baris salinan = sumber: BERBEDA"; diff "$KERJA/hitung_sumber.txt" "$KERJA/hitung_salinan.txt" || true; fi; } | tee -a "$RINGKAS"

echo
echo "SELESAI. Tempel isi berkas ini sebagai komentar di PR UP1-04 (tanpa NIP/nama):"
echo "  $(cygpath -w "$RINGKAS")"
echo "Dump berisi data pribadi ada di $(cygpath -w "$DUMP"). Salinan sudah di $SALINAN, jadi dump boleh dihapus."
read -r -p "Hapus folder dump sekarang? (y/N) " HAPUS
if [ "${HAPUS:-N}" = "y" ] || [ "${HAPUS:-N}" = "Y" ]; then rm -rf "$DUMP"; echo "dump dihapus (cek juga Recycle Bin bila ada)."; fi
