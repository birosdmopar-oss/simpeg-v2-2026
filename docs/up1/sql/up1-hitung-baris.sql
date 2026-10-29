-- UP-1 (MAKE-055) — hitung baris BACA-SAJA (hanya SELECT, tanpa NIP/nama).
-- Jalankan di sumber (DevSimpeg, akun baca-saja) DAN di DB scratch v2 hasil impor dengan rentang yang sama:
--   SET @awal = 'YYYY-MM-DD', @akhir = 'YYYY-MM-DD', @awal_cuti = 'YYYY-MM-DD';  (nilai sama dengan up1-ekstraksi.sh)  lalu  source docs/up1/sql/up1-hitung-baris.sql
-- Bandingkan kedua keluaran baris demi baris (Runbook langkah 14). Nama kolom = [K] legacy.

SELECT CONCAT('-- db: ', DATABASE(), ', ', VERSION(), ', rentang ', @awal, '..', @akhir) AS info;
SELECT IF(@awal IS NULL OR @akhir IS NULL OR @awal_cuti IS NULL, 'BERHENTI: @awal/@akhir/@awal_cuti belum di-SET', 'ok') AS prasyarat;

-- A. Jumlah baris per tabel (batas sama dengan up1-ekstraksi.sh).
SELECT 'pegawai' tabel, COUNT(*) n FROM pegawai
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
UNION ALL SELECT 'lokasi_presensi', COUNT(*) FROM lokasi_presensi
UNION ALL SELECT 'dh_online', COUNT(*) FROM dh_online WHERE tgl BETWEEN @awal AND @akhir
UNION ALL SELECT 'dh_online_tanpa_jam', COUNT(*) FROM dh_online
          WHERE tgl BETWEEN @awal AND @akhir AND jam_masuk IS NULL AND jam_pulang IS NULL
UNION ALL SELECT 'absen_ijin', COUNT(*) FROM absen_ijin WHERE date_start <= @akhir AND date_end >= @awal_cuti
UNION ALL SELECT 'riwayat_cuti', COUNT(*) FROM riwayat_cuti WHERE tgl_mulai <= @akhir AND tgl_akhir >= @awal_cuti
UNION ALL SELECT 'pegawai_sisa_cuti', COUNT(*) FROM pegawai_sisa_cuti
          WHERE tahun BETWEEN YEAR(@awal) AND YEAR(@akhir)
UNION ALL SELECT 'riwayat_tb', COUNT(*) FROM riwayat_tb
          WHERE tgl_mulai <= @akhir AND (tgl_akhir IS NULL OR tgl_akhir >= @awal)
UNION ALL SELECT 'hari_libur', COUNT(*) FROM hari_libur WHERE tgl_mulai <= @akhir AND tgl_akhir >= @awal
UNION ALL SELECT 'bkn_periode_ekinper', COUNT(*) FROM bkn_periode_ekinper
          WHERE periode_awal <= @akhir AND periode_akhir >= @awal
UNION ALL SELECT 'riwayat_skp_periodik', COUNT(*) FROM riwayat_skp_periodik
          WHERE periode_awal_skp <= @akhir AND periode_akhir_skp >= @awal
UNION ALL SELECT 'web_config_tukin', COUNT(*) FROM web_config
          WHERE config_name IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK','jam_masuk_normal','jam_pulang_normal',
                                'jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa');

-- B. Kontrol isi tanpa data pribadi: jumlah per bulan dh_online dan per jenis cuti.
SELECT DATE_FORMAT(tgl, '%Y-%m') bulan, COUNT(*) n FROM dh_online
 WHERE tgl BETWEEN @awal AND @akhir GROUP BY bulan ORDER BY bulan;
SELECT id_jenis_cuti, status, COUNT(*) n FROM riwayat_cuti
 WHERE tgl_mulai <= @akhir AND tgl_akhir >= @awal_cuti GROUP BY id_jenis_cuti, status ORDER BY id_jenis_cuti, status;

-- C. [INFO] Kandidat lingkup (Runbook langkah 5): cuti yang melewati batas periode Tukin (15 → 16), per unit jabatan
--     saat ini (snapshot pegawai_mutasi_jabatan). Periode tanggal d = bulan dari (d − 15 hari). Hanya id & jumlah;
--     jenis cuti sakit dicocokkan dengan master jenis_cuti di sumber.
SELECT pmj.id_unit, rc.id_jenis_cuti, COUNT(*) n
  FROM riwayat_cuti rc JOIN pegawai_mutasi_jabatan pmj ON pmj.nip = rc.nip
 WHERE rc.tgl_mulai <= @akhir AND rc.tgl_akhir >= @awal AND rc.status = 1
   AND DATE_FORMAT(DATE_SUB(rc.tgl_mulai, INTERVAL 15 DAY), '%Y-%m')
       <> DATE_FORMAT(DATE_SUB(rc.tgl_akhir, INTERVAL 15 DAY), '%Y-%m')
 GROUP BY pmj.id_unit, rc.id_jenis_cuti ORDER BY n DESC LIMIT 20;
-- Baris BKN tidak standar: periode yang tidak mulai tanggal 1 atau tidak berakhir di akhir bulan.
SELECT tahun, bulan, COUNT(*) n FROM bkn_periode_ekinper
 WHERE periode_awal <= @akhir AND periode_akhir >= @awal
   AND (DAY(periode_awal) <> 1 OR periode_akhir <> LAST_DAY(periode_awal))
 GROUP BY tahun, bulan ORDER BY tahun, bulan;
