-- UP1-04 — cek cakupan data presensi di DB dev (BACA-SAJA, tanpa NIP/nama). HeidiSQL: File > Load SQL file, lalu F9.
-- Tujuan: dh_online periode Sep-Okt 2026 ternyata 0 baris -> cari sampai bulan berapa presensi terisi dan kapan
-- salinan DB dev kira-kira diambil (stempel created_at terakhir yang tidak di masa depan).

SELECT bagian, item, nilai FROM (
  SELECT 1 urut, YEAR(tgl) * 100 + MONTH(tgl) sub, '1 dh_online per bulan' bagian, DATE_FORMAT(MIN(tgl), '%Y-%m') item,
         CONCAT(COUNT(*), ' baris | ', SUM(jam_masuk IS NOT NULL OR jam_pulang IS NOT NULL), ' berjam | stempel terakhir ',
                IFNULL(MAX(created_at), '-')) nilai
    FROM dh_online WHERE tgl >= '2025-07-01'
   GROUP BY YEAR(tgl) * 100 + MONTH(tgl)
  UNION ALL SELECT 2, 1, '2 stempel terakhir', 'dh_online.created_at', MAX(created_at) FROM dh_online WHERE created_at <= NOW()
  UNION ALL SELECT 2, 2, '2 stempel terakhir', 'absen_ijin.date_created', MAX(date_created) FROM absen_ijin WHERE date_created <= NOW()
  UNION ALL SELECT 2, 3, '2 stempel terakhir', 'hari_libur.created_at', MAX(created_at) FROM hari_libur WHERE created_at <= NOW()
) hasil
ORDER BY urut, sub;
