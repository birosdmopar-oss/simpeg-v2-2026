-- UP1-04 — SATU skrip baca-saja untuk HeidiSQL (DB dev). Buka: File > Load SQL file, lalu F9.
-- Hasil: SATU tabel (bagian | item | nilai). Isinya hanya jam, tanggal, nama tabel, jumlah, dan id unit — tanpa NIP/nama.
-- Salin seluruh tabel hasil (Ctrl+A, Ctrl+C di grid) lalu tempel sebagai komentar di PR UP1-04.
-- Periode uji P-05 (diubah Horii 02-11, opsi a): bulan Tukin Apr & Mei 2026 -> data 2026-02-16..2026-05-15, cuti/izin sejak 2025-08-16, BKN 202602..202605.
-- Tanpa @variabel (ERROR 1267 collation di MySQL 8); BKN disaring tahun*100+bulan karena periode_awal/akhir = 'MM-DD' (CR-088).

SELECT bagian, item, nilai FROM (

  -- 0. Info
  SELECT 0 urut, 0 sub, '0 info' bagian, 'db / versi / waktu baca' item,
         CONCAT(DATABASE(), ' / ', VERSION(), ' / ', NOW()) nilai

  -- 1. Zona jam server (GAP-2): selisih +7 = WIB -> --zona-sumber=WIB; 0 = UTC
  UNION ALL SELECT 1, 0, '1 zona', 'NOW | UTC | selisih jam | system tz | global tz',
         CONCAT(NOW(), ' | ', UTC_TIMESTAMP(), ' | ', TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW()), ' | ',
                @@system_time_zone, ' | ', @@global.time_zone)

  -- 2. BKN per bulan (jenis_periode NULL) dalam rentang
  UNION ALL SELECT 2, CAST(tahun AS UNSIGNED) * 100 + CAST(bulan AS UNSIGNED), '2 BKN', CONCAT(tahun, '-', bulan),
         CONCAT(MIN(periode_awal), ' s.d. ', MAX(periode_akhir), ' (', COUNT(*), ' baris)')
    FROM bkn_periode_ekinper
   WHERE jenis_periode IS NULL AND CAST(tahun AS UNSIGNED) * 100 + CAST(bulan AS UNSIGNED) BETWEEN 202602 AND 202605
   GROUP BY tahun, bulan
  UNION ALL SELECT 2, 999999, '2 BKN', 'Apr & Mei 2026 tepat 1 baris per bulan (T-04)',
         IF((SELECT COUNT(*) FROM (SELECT tahun, bulan FROM bkn_periode_ekinper
              WHERE jenis_periode IS NULL AND CAST(tahun AS UNSIGNED) * 100 + CAST(bulan AS UNSIGNED) IN (202604, 202605)
              GROUP BY tahun, bulan HAVING COUNT(*) = 1) x) = 2, 'OK', 'TIDAK — laporkan ke reviewer')

  -- 3. Jumlah baris per tabel (batas sama dengan ekstraksi)
  UNION ALL SELECT 3, 1, '3 jumlah baris', 'pegawai', COUNT(*) FROM pegawai
  UNION ALL SELECT 3, 2, '3 jumlah baris', 'pegawai_mutasi_jabatan', COUNT(*) FROM pegawai_mutasi_jabatan
  UNION ALL SELECT 3, 3, '3 jumlah baris', 'riwayat_mutasi_jabatan', COUNT(*) FROM riwayat_mutasi_jabatan
  UNION ALL SELECT 3, 4, '3 jumlah baris', 'pegawai_plt', COUNT(*) FROM pegawai_plt
  UNION ALL SELECT 3, 5, '3 jumlah baris', 'pegawai_plh', COUNT(*) FROM pegawai_plh
  UNION ALL SELECT 3, 6, '3 jumlah baris', 'riwayat_keluarga', COUNT(*) FROM riwayat_keluarga
  UNION ALL SELECT 3, 7, '3 jumlah baris', 'detail_anak', COUNT(*) FROM detail_anak
  UNION ALL SELECT 3, 8, '3 jumlah baris', 'unit', COUNT(*) FROM unit
  UNION ALL SELECT 3, 9, '3 jumlah baris', 'satker', COUNT(*) FROM satker
  UNION ALL SELECT 3, 10, '3 jumlah baris', 'jabatan', COUNT(*) FROM jabatan
  UNION ALL SELECT 3, 11, '3 jumlah baris', 'rumpun_jabatan', COUNT(*) FROM rumpun_jabatan
  UNION ALL SELECT 3, 12, '3 jumlah baris', 'kelas_jabatan', COUNT(*) FROM kelas_jabatan
  UNION ALL SELECT 3, 13, '3 jumlah baris', 'jabatan_koordinasi', COUNT(*) FROM jabatan_koordinasi
  UNION ALL SELECT 3, 14, '3 jumlah baris', 'agama', COUNT(*) FROM agama
  UNION ALL SELECT 3, 15, '3 jumlah baris', 'jenis_status', COUNT(*) FROM jenis_status
  UNION ALL SELECT 3, 16, '3 jumlah baris', 'provinsi', COUNT(*) FROM provinsi
  UNION ALL SELECT 3, 17, '3 jumlah baris', 'kabupaten_kota', COUNT(*) FROM kabupaten_kota
  UNION ALL SELECT 3, 18, '3 jumlah baris', 'pangkat', COUNT(*) FROM pangkat
  UNION ALL SELECT 3, 19, '3 jumlah baris', 'jenis_kp', COUNT(*) FROM jenis_kp
  UNION ALL SELECT 3, 20, '3 jumlah baris', 'jenjang_pendidikan', COUNT(*) FROM jenjang_pendidikan
  UNION ALL SELECT 3, 21, '3 jumlah baris', 'pegawai_kp', COUNT(*) FROM pegawai_kp
  UNION ALL SELECT 3, 22, '3 jumlah baris', 'riwayat_kp', COUNT(*) FROM riwayat_kp
  UNION ALL SELECT 3, 23, '3 jumlah baris', 'lokasi_presensi', COUNT(*) FROM lokasi_presensi
  UNION ALL SELECT 3, 24, '3 jumlah baris', 'dh_online', COUNT(*) FROM dh_online
         WHERE tgl BETWEEN '2026-02-16' AND '2026-05-15'
  UNION ALL SELECT 3, 25, '3 jumlah baris', 'dh_online_tanpa_jam', COUNT(*) FROM dh_online
         WHERE tgl BETWEEN '2026-02-16' AND '2026-05-15' AND jam_masuk IS NULL AND jam_pulang IS NULL
  UNION ALL SELECT 3, 26, '3 jumlah baris', 'absen_ijin', COUNT(*) FROM absen_ijin
         WHERE date_start <= '2026-05-15' AND date_end >= '2025-08-16'
  UNION ALL SELECT 3, 27, '3 jumlah baris', 'riwayat_cuti', COUNT(*) FROM riwayat_cuti
         WHERE tgl_mulai <= '2026-05-15' AND tgl_akhir >= '2025-08-16'
  UNION ALL SELECT 3, 28, '3 jumlah baris', 'pegawai_sisa_cuti', COUNT(*) FROM pegawai_sisa_cuti
         WHERE tahun BETWEEN 2026 AND 2026
  UNION ALL SELECT 3, 29, '3 jumlah baris', 'riwayat_tb', COUNT(*) FROM riwayat_tb
         WHERE tgl_mulai <= '2026-05-15' AND (tgl_akhir IS NULL OR tgl_akhir >= '2026-02-16')
  UNION ALL SELECT 3, 30, '3 jumlah baris', 'riwayat_tb_perpan', COUNT(*) FROM riwayat_tb_perpan
         WHERE tgl_mulai_perpan <= '2026-05-15' AND (tgl_akhir_perpan IS NULL OR tgl_akhir_perpan >= '2026-02-16')
  UNION ALL SELECT 3, 31, '3 jumlah baris', 'hari_libur', COUNT(*) FROM hari_libur
         WHERE tgl_mulai <= '2026-05-15' AND tgl_akhir >= '2026-02-16'
  UNION ALL SELECT 3, 32, '3 jumlah baris', 'bkn_periode_ekinper', COUNT(*) FROM bkn_periode_ekinper
         WHERE CAST(tahun AS UNSIGNED) * 100 + CAST(bulan AS UNSIGNED) BETWEEN 202602 AND 202605
  UNION ALL SELECT 3, 33, '3 jumlah baris', 'riwayat_skp_periodik', COUNT(*) FROM riwayat_skp_periodik
         WHERE periode_awal_skp <= '2026-05-15' AND periode_akhir_skp >= '2026-02-16'
  UNION ALL SELECT 3, 34, '3 jumlah baris', 'web_config_tukin', COUNT(*) FROM web_config
         WHERE config_name IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK','jam_masuk_normal','jam_pulang_normal',
                               'jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa')

  -- 4. Kontrol isi: presensi per bulan, cuti per jenis & status
  UNION ALL SELECT 4, YEAR(tgl) * 100 + MONTH(tgl), '4 dh_online per bulan', DATE_FORMAT(MIN(tgl), '%Y-%m'), COUNT(*)
    FROM dh_online WHERE tgl BETWEEN '2026-02-16' AND '2026-05-15' GROUP BY YEAR(tgl) * 100 + MONTH(tgl)
  UNION ALL SELECT 5, id_jenis_cuti * 100 + status, '5 cuti per jenis', CONCAT('jenis ', id_jenis_cuti, ' status ', status), COUNT(*)
    FROM riwayat_cuti WHERE tgl_mulai <= '2026-05-15' AND tgl_akhir >= '2025-08-16' GROUP BY id_jenis_cuti, status

  -- 6. Kandidat lingkup: cuti yang melewati batas periode Tukin (15 -> 16), per unit (20 teratas)
  UNION ALL SELECT 6, urutan, '6 kandidat lingkup', CONCAT('unit ', id_unit, ' jenis cuti ', id_jenis_cuti), n
    FROM (SELECT pmj.id_unit, rc.id_jenis_cuti, COUNT(*) n,
                 ROW_NUMBER() OVER (ORDER BY COUNT(*) DESC, pmj.id_unit, rc.id_jenis_cuti) urutan
            FROM riwayat_cuti rc JOIN pegawai_mutasi_jabatan pmj ON pmj.nip = rc.nip
           WHERE rc.tgl_mulai <= '2026-05-15' AND rc.tgl_akhir >= '2026-02-16' AND rc.status = 1
             AND DATE_FORMAT(DATE_SUB(rc.tgl_mulai, INTERVAL 15 DAY), '%Y-%m')
                 <> DATE_FORMAT(DATE_SUB(rc.tgl_akhir, INTERVAL 15 DAY), '%Y-%m')
           GROUP BY pmj.id_unit, rc.id_jenis_cuti) k
   WHERE urutan <= 20
) hasil
ORDER BY urut, sub;
