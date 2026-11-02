-- UP1-04 — pangkas salinan di MySQL LOKAL 3307 (skema up1_legacy_salinan) setelah Export HeidiSQL dari DB dev.
-- Jalankan di sesi HeidiSQL ke 127.0.0.1:3307 dengan database up1_legacy_salinan terpilih: File > Load SQL file, F9.
-- Menghapus baris di luar periode uji P-05 (bulan Tukin Apr & Mei 2026) sehingga isi salinan = ekstraksi runbook:
--   data 2026-02-16..2026-05-15, cuti/izin sejak 2025-08-16, BKN 202602..202605, web_config hanya key tarif & jam.
-- Pengaman: setiap DELETE hanya berlaku bila database aktif = up1_legacy_salinan (di DB lain tidak ada yang terhapus).
-- `(kondisi simpan) IS NOT TRUE` = hapus juga baris bertanggal NULL, sama dengan --where mysqldump.

DELETE FROM dh_online WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tgl BETWEEN '2026-02-16' AND '2026-05-15') IS NOT TRUE;
DELETE FROM absen_ijin WHERE DATABASE() = 'up1_legacy_salinan'
   AND (date_start <= '2026-05-15' AND date_end >= '2025-08-16') IS NOT TRUE;
DELETE FROM riwayat_cuti WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tgl_mulai <= '2026-05-15' AND tgl_akhir >= '2025-08-16') IS NOT TRUE;
DELETE FROM pegawai_sisa_cuti WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tahun BETWEEN 2026 AND 2026) IS NOT TRUE;
-- riwayat_tb_perpan punya FK ke riwayat_tb dengan ON DELETE CASCADE: pangkas perpanjangan DULU, lalu hapus TB yang
-- rentangnya lewat DAN tidak punya perpanjangan tersisa (TB lama yang diperpanjang ke periode uji tetap ikut).
DELETE FROM riwayat_tb_perpan WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tgl_mulai_perpan <= '2026-05-15' AND (tgl_akhir_perpan IS NULL OR tgl_akhir_perpan >= '2026-02-16')) IS NOT TRUE;
DELETE FROM riwayat_tb WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tgl_mulai <= '2026-05-15' AND (tgl_akhir IS NULL OR tgl_akhir >= '2026-02-16')) IS NOT TRUE
   AND id_riwayat_tb NOT IN (SELECT id_riwayat_tb FROM riwayat_tb_perpan);
DELETE FROM hari_libur WHERE DATABASE() = 'up1_legacy_salinan'
   AND (tgl_mulai <= '2026-05-15' AND tgl_akhir >= '2026-02-16') IS NOT TRUE;
DELETE FROM bkn_periode_ekinper WHERE DATABASE() = 'up1_legacy_salinan'
   AND (CAST(tahun AS UNSIGNED) * 100 + CAST(bulan AS UNSIGNED) BETWEEN 202602 AND 202605) IS NOT TRUE;
DELETE FROM riwayat_skp_periodik WHERE DATABASE() = 'up1_legacy_salinan'
   AND (periode_awal_skp <= '2026-05-15' AND periode_akhir_skp >= '2026-02-16') IS NOT TRUE;
DELETE FROM web_config WHERE DATABASE() = 'up1_legacy_salinan'
   AND config_name NOT IN ('TL1/PSW1','TL2/PSW2','TL3/PSW3','TA','TK','jam_masuk_normal','jam_pulang_normal',
                           'jam_pulang_jumat_normal','jam_masuk_puasa','jam_pulang_puasa','jam_pulang_jumat_puasa');

-- Verifikasi: semua baris harus "OK". Setelah ini jalankan up1_baca.sql di sesi lokal yang sama dan cocokkan
-- bagian "3 jumlah baris" dengan hasil di DB dev.
SELECT cek, hasil FROM (
  SELECT 1 u, 'database aktif = up1_legacy_salinan' cek, IF(DATABASE() = 'up1_legacy_salinan', 'OK', 'SALAH — pilih DB up1_legacy_salinan lalu ulangi') hasil
  UNION ALL SELECT 2, 'tanpa trigger', IF((SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()) = 0, 'OK', 'ADA trigger — hapus')
  UNION ALL SELECT 3, 'tanpa tabel pengguna/token', IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('pengguna', 'token')) = 0, 'OK', 'ADA — hapus tabel itu')
  UNION ALL SELECT 4, 'jumlah tabel (harus 41, atau 42 bila jenjang_jf ada)', CAST((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS CHAR)
  UNION ALL SELECT 5, 'web_config tersisa (DB dev: 5)', CAST((SELECT COUNT(*) FROM web_config) AS CHAR)
) v ORDER BY u;
