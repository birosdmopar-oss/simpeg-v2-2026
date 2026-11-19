-- =====================================================================================================================
-- DBV-021 (ISSUE-045) — Audit yatim FK G-02 per tabel anak: 10 kueri ringkasan + contoh nilai — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-021-audit-yatim-fk-g02-per-tabel.md. Runbook: DBV-011 2.10 (langkah 1 "Audit = 0").
-- Lanjutan keputusan review DB Validator PR #26 (15-10-2026): "perluasan menjadi 10 kueri ringkasan per tabel anak = unit
-- lanjutan sebelum gladi impor". Berkas ini MELENGKAPI `DBV-019-audit-yatim-fk-g02.sql` (tidak menggantikannya):
-- Bagian 1 berkas DBV-019 (pola per FK) kini tertulis lengkap untuk ke-55 FK di sini; Bagian 3–4 DBV-019 (FK masuk
-- `pengguna`/`faq_rate`, master status 10) tetap dijalankan dari berkas DBV-019.
--
-- Sumber FK: konstanta `FOREIGN_KEYS` migration `2026-10-07-100000_AddFkG02Pegawai` (15 FK) dan
-- `2026-10-07-100100_AddFkG02Riwayat` (40 FK) = DBV-019 Bagian 2.3 [K D1]. Kecocokan nama FK, kolom anak, dan induk
-- dikunci `tests/database/Kepegawaian/AuditYatimFkG02Test.php`.
--
-- Klasifikasi (arti awalan = DBV-011 2.3):
--   Bagian A = WAJIB_0. Satu kueri per tabel anak (satu pemindaian; master di-LEFT JOIN lewat PK, jadi baris tidak
--              berlipat). Kolom `baris` = jumlah baris tabel anak (INFO); setiap kolom ber-nama FK harus 0. NULL = "tidak
--              dirujuk" (semua kolom anak nullable [K]) dan tidak dihitung yatim; nilai `0`/'' dihitung yatim.
--   Bagian B = INFO, dibaca bila Bagian A > 0. Per FK: jumlah nilai yatim berbeda dan maksimal 10 contoh NILAI FK
--              (id master yang tidak ada). Tidak menampilkan PK/NIP baris anak atau kolom pribadi lain.
--
-- Tabel anak (10; urutan = urutan pemasangan FK):
--   A01/B01 pegawai_mutasi_jabatan   13 FK
--   A02/B02 pegawai_ak                1 FK
--   A03/B03 pegawai_ak_siasn          1 FK
--   A04/B04 riwayat_mutasi_jabatan   13 FK
--   A05/B05 pegawai_plt              10 FK
--   A06/B06 pegawai_plh              10 FK
--   A07/B07 riwayat_lckh              2 FK
--   A08/B08 riwayat_ak                1 FK
--   A09/B09 riwayat_ak_siasn          1 FK
--   A10/B10 konv_ak                   3 FK
--
-- Cara pakai (akun baca saja; tanpa --force agar galat langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2_tujuan> \
--         < backend/docs/db-review/sql/DBV-021-audit-yatim-fk-g02-per-tabel.sql > hasil_audit_dbv021.txt 2>&1
--   Perkiraan awal di salinan legacy: nama tabel/kolom anak dan induk = D1 (sama); jalankan di skema salinan/gladi.
--   Galat di tengah berkas (tanpa `--force`, mis. "Table doesn't exist" di salinan legacy yang tidak memuat salah satu tabel
--   anak) menghentikan kueri berikutnya: catat galat itu di lembar hasil, buang blok A/B tabel tersebut di salinan kerja
--   berkas (bukan di repo), lalu jalankan ulang. DB ber-`DBPrefix`: sesuaikan nama tabel di salinan kerja berkas (pola
--   runbook DBV-028), bukan di repo.
--   Cakupan hanya 55 FK DBV-019; 28 FK G-02 lain di luar berkas ini (dokumen DBV-021 Bagian 1, batas cakupan).
--
-- LARANGAN: jangan jalankan di DB produksi legacy yang sedang melayani aplikasi. Jalankan hanya di DB v2 tujuan (setelah
-- impor) atau di salinan dump final / salinan gladi (DBV-011 2.4 langkah 1). Perbaikan nilai yatim dikerjakan di salinan
-- kerja atau transformasi impor menurut aturan normalisasi DBV-011 2.10, tidak di skrip ini.
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian A — WAJIB_0. Ringkasan per tabel anak.
-- ---------------------------------------------------------------------------------------------------------------------
-- A01. `pegawai_mutasi_jabatan` — 13 FK
SELECT 'pegawai_mutasi_jabatan' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_group_jabatan     IS NOT NULL AND p01.id_group_jabatan       IS NULL), 0) AS `fk_id_group_jabatan_pmj_to_gj`,
  COALESCE(SUM(c.id_sub_group_jabatan IS NOT NULL AND p02.id_sub_group_jabatan   IS NULL), 0) AS `fk_id_sub_group_jabatan_pmj_to_sgj`,
  COALESCE(SUM(c.id_unit              IS NOT NULL AND p03.id_unit                IS NULL), 0) AS `fk_id_unit_pmj_to_unit`,
  COALESCE(SUM(c.id_satker            IS NOT NULL AND p04.id_satker              IS NULL), 0) AS `fk_id_satker_pmj_to_satker`,
  COALESCE(SUM(c.id_jabatan           IS NOT NULL AND p05.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_pmj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_1       IS NOT NULL AND p06.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_1_pmj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_2       IS NOT NULL AND p07.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_2_pmj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_3       IS NOT NULL AND p08.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_3_pmj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_4       IS NOT NULL AND p09.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_4_pmj_to_jabatan`,
  COALESCE(SUM(c.id_jabatan_koord     IS NOT NULL AND p10.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_jabatan_koord_pmj_to_jabkoor`,
  COALESCE(SUM(c.id_atasan_es_3_koord IS NOT NULL AND p11.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_atasan_es_3_koord_pmj_to_jabkoor`,
  COALESCE(SUM(c.id_atasan_es_4_koord IS NOT NULL AND p12.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_atasan_es_4_koord_pmj_to_jabkoor`,
  COALESCE(SUM(c.id_rumpun_jabatan    IS NOT NULL AND p13.id_rumpun_jabatan      IS NULL), 0) AS `pegawai_mutasi_jabatan_ibfk_02`
FROM pegawai_mutasi_jabatan c
LEFT JOIN group_jabatan      p01 ON p01.id_group_jabatan = c.id_group_jabatan
LEFT JOIN sub_group_jabatan  p02 ON p02.id_sub_group_jabatan = c.id_sub_group_jabatan
LEFT JOIN unit               p03 ON p03.id_unit = c.id_unit
LEFT JOIN satker             p04 ON p04.id_satker = c.id_satker
LEFT JOIN jabatan            p05 ON p05.id_jabatan = c.id_jabatan
LEFT JOIN jabatan            p06 ON p06.id_jabatan = c.id_atasan_es_1
LEFT JOIN jabatan            p07 ON p07.id_jabatan = c.id_atasan_es_2
LEFT JOIN jabatan            p08 ON p08.id_jabatan = c.id_atasan_es_3
LEFT JOIN jabatan            p09 ON p09.id_jabatan = c.id_atasan_es_4
LEFT JOIN jabatan_koordinasi p10 ON p10.id_jabatan_koordinasi = c.id_jabatan_koord
LEFT JOIN jabatan_koordinasi p11 ON p11.id_jabatan_koordinasi = c.id_atasan_es_3_koord
LEFT JOIN jabatan_koordinasi p12 ON p12.id_jabatan_koordinasi = c.id_atasan_es_4_koord
LEFT JOIN rumpun_jabatan     p13 ON p13.id_rumpun_jabatan = c.id_rumpun_jabatan;

-- A02. `pegawai_ak` — 1 FK
SELECT 'pegawai_ak' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_cak_to_jab`
FROM pegawai_ak c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan;

-- A03. `pegawai_ak_siasn` — 1 FK
SELECT 'pegawai_ak_siasn' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_peg_ak_siasn_03`
FROM pegawai_ak_siasn c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan;

-- A04. `riwayat_mutasi_jabatan` — 13 FK
SELECT 'riwayat_mutasi_jabatan' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_group_jabatan     IS NOT NULL AND p01.id_group_jabatan       IS NULL), 0) AS `fk_id_group_jabatan_rwymj_to_gj`,
  COALESCE(SUM(c.id_sub_group_jabatan IS NOT NULL AND p02.id_sub_group_jabatan   IS NULL), 0) AS `fk_id_sub_group_jabatan_rwymj_to_sgj`,
  COALESCE(SUM(c.id_unit              IS NOT NULL AND p03.id_unit                IS NULL), 0) AS `fk_id_unit_rwymj_to_unit`,
  COALESCE(SUM(c.id_satker            IS NOT NULL AND p04.id_satker              IS NULL), 0) AS `fk_id_satker_rwymj_to_satker`,
  COALESCE(SUM(c.id_jabatan           IS NOT NULL AND p05.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_rwymj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_1       IS NOT NULL AND p06.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_1_rwymj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_2       IS NOT NULL AND p07.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_2_rwymj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_3       IS NOT NULL AND p08.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_3_rwymj_to_jabatan`,
  COALESCE(SUM(c.id_atasan_es_4       IS NOT NULL AND p09.id_jabatan             IS NULL), 0) AS `fk_id_atasan_es_4_rwymj_to_jabatan`,
  COALESCE(SUM(c.id_jabatan_koord     IS NOT NULL AND p10.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_jabatan_koord_rmj_to_jabkoor`,
  COALESCE(SUM(c.id_atasan_es_3_koord IS NOT NULL AND p11.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_atasan_es_3_koord_rmj_to_jabkoor`,
  COALESCE(SUM(c.id_atasan_es_4_koord IS NOT NULL AND p12.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_atasan_es_4_koord_rmj_to_jabkoor`,
  COALESCE(SUM(c.id_rumpun_jabatan    IS NOT NULL AND p13.id_rumpun_jabatan      IS NULL), 0) AS `riwayat_mutasi_jabatan_ibfk_02`
FROM riwayat_mutasi_jabatan c
LEFT JOIN group_jabatan      p01 ON p01.id_group_jabatan = c.id_group_jabatan
LEFT JOIN sub_group_jabatan  p02 ON p02.id_sub_group_jabatan = c.id_sub_group_jabatan
LEFT JOIN unit               p03 ON p03.id_unit = c.id_unit
LEFT JOIN satker             p04 ON p04.id_satker = c.id_satker
LEFT JOIN jabatan            p05 ON p05.id_jabatan = c.id_jabatan
LEFT JOIN jabatan            p06 ON p06.id_jabatan = c.id_atasan_es_1
LEFT JOIN jabatan            p07 ON p07.id_jabatan = c.id_atasan_es_2
LEFT JOIN jabatan            p08 ON p08.id_jabatan = c.id_atasan_es_3
LEFT JOIN jabatan            p09 ON p09.id_jabatan = c.id_atasan_es_4
LEFT JOIN jabatan_koordinasi p10 ON p10.id_jabatan_koordinasi = c.id_jabatan_koord
LEFT JOIN jabatan_koordinasi p11 ON p11.id_jabatan_koordinasi = c.id_atasan_es_3_koord
LEFT JOIN jabatan_koordinasi p12 ON p12.id_jabatan_koordinasi = c.id_atasan_es_4_koord
LEFT JOIN rumpun_jabatan     p13 ON p13.id_rumpun_jabatan = c.id_rumpun_jabatan;

-- A05. `pegawai_plt` — 10 FK
SELECT 'pegawai_plt' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_group_jabatan         IS NOT NULL AND p01.id_group_jabatan       IS NULL), 0) AS `fk_id_group_jabatan_plt_to_gj`,
  COALESCE(SUM(c.id_group_jabatan_plt     IS NOT NULL AND p02.id_group_jabatan       IS NULL), 0) AS `fk_id_group_jabatan_plt_plt_to_gj`,
  COALESCE(SUM(c.id_sub_group_jabatan     IS NOT NULL AND p03.id_sub_group_jabatan   IS NULL), 0) AS `fk_id_sub_group_jabatan_plt_to_sgj`,
  COALESCE(SUM(c.id_sub_group_jabatan_plt IS NOT NULL AND p04.id_sub_group_jabatan   IS NULL), 0) AS `fk_id_sub_group_jabatan_plt_plt_to_sgj`,
  COALESCE(SUM(c.id_unit                  IS NOT NULL AND p05.id_unit                IS NULL), 0) AS `fk_id_unit_plt_to_unit`,
  COALESCE(SUM(c.id_unit_plt              IS NOT NULL AND p06.id_unit                IS NULL), 0) AS `fk_id_unit_plt_plt_to_unit`,
  COALESCE(SUM(c.id_satker                IS NOT NULL AND p07.id_satker              IS NULL), 0) AS `fk_id_satker_plt_to_satker`,
  COALESCE(SUM(c.id_satker_plt            IS NOT NULL AND p08.id_satker              IS NULL), 0) AS `fk_id_satker_plt_plt_to_satker`,
  COALESCE(SUM(c.id_jabatan               IS NOT NULL AND p09.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_plt_to_jabatan`,
  COALESCE(SUM(c.id_jabatan_koord         IS NOT NULL AND p10.id_jabatan_koordinasi  IS NULL), 0) AS `fk_id_jabatan_koord_plt_to_jabkoord`
FROM pegawai_plt c
LEFT JOIN group_jabatan      p01 ON p01.id_group_jabatan = c.id_group_jabatan
LEFT JOIN group_jabatan      p02 ON p02.id_group_jabatan = c.id_group_jabatan_plt
LEFT JOIN sub_group_jabatan  p03 ON p03.id_sub_group_jabatan = c.id_sub_group_jabatan
LEFT JOIN sub_group_jabatan  p04 ON p04.id_sub_group_jabatan = c.id_sub_group_jabatan_plt
LEFT JOIN unit               p05 ON p05.id_unit = c.id_unit
LEFT JOIN unit               p06 ON p06.id_unit = c.id_unit_plt
LEFT JOIN satker             p07 ON p07.id_satker = c.id_satker
LEFT JOIN satker             p08 ON p08.id_satker = c.id_satker_plt
LEFT JOIN jabatan            p09 ON p09.id_jabatan = c.id_jabatan
LEFT JOIN jabatan_koordinasi p10 ON p10.id_jabatan_koordinasi = c.id_jabatan_koord;

-- A06. `pegawai_plh` — 10 FK
SELECT 'pegawai_plh' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan               IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_pegawai_plh_ibfk_02`,
  COALESCE(SUM(c.id_jabatan_koord         IS NOT NULL AND p02.id_jabatan_koordinasi  IS NULL), 0) AS `fk_pegawai_plh_ibfk_03`,
  COALESCE(SUM(c.id_group_jabatan         IS NOT NULL AND p03.id_group_jabatan       IS NULL), 0) AS `fk_pegawai_plh_ibfk_04`,
  COALESCE(SUM(c.id_sub_group_jabatan     IS NOT NULL AND p04.id_sub_group_jabatan   IS NULL), 0) AS `fk_pegawai_plh_ibfk_05`,
  COALESCE(SUM(c.id_unit                  IS NOT NULL AND p05.id_unit                IS NULL), 0) AS `fk_pegawai_plh_ibfk_06`,
  COALESCE(SUM(c.id_satker                IS NOT NULL AND p06.id_satker              IS NULL), 0) AS `fk_pegawai_plh_ibfk_07`,
  COALESCE(SUM(c.id_group_jabatan_plh     IS NOT NULL AND p07.id_group_jabatan       IS NULL), 0) AS `fk_pegawai_plh_ibfk_08`,
  COALESCE(SUM(c.id_sub_group_jabatan_plh IS NOT NULL AND p08.id_sub_group_jabatan   IS NULL), 0) AS `fk_pegawai_plh_ibfk_09`,
  COALESCE(SUM(c.id_unit_plh              IS NOT NULL AND p09.id_unit                IS NULL), 0) AS `fk_pegawai_plh_ibfk_10`,
  COALESCE(SUM(c.id_satker_plh            IS NOT NULL AND p10.id_satker              IS NULL), 0) AS `fk_pegawai_plh_ibfk_11`
FROM pegawai_plh c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan
LEFT JOIN jabatan_koordinasi p02 ON p02.id_jabatan_koordinasi = c.id_jabatan_koord
LEFT JOIN group_jabatan      p03 ON p03.id_group_jabatan = c.id_group_jabatan
LEFT JOIN sub_group_jabatan  p04 ON p04.id_sub_group_jabatan = c.id_sub_group_jabatan
LEFT JOIN unit               p05 ON p05.id_unit = c.id_unit
LEFT JOIN satker             p06 ON p06.id_satker = c.id_satker
LEFT JOIN group_jabatan      p07 ON p07.id_group_jabatan = c.id_group_jabatan_plh
LEFT JOIN sub_group_jabatan  p08 ON p08.id_sub_group_jabatan = c.id_sub_group_jabatan_plh
LEFT JOIN unit               p09 ON p09.id_unit = c.id_unit_plh
LEFT JOIN satker             p10 ON p10.id_satker = c.id_satker_plh;

-- A07. `riwayat_lckh` — 2 FK
SELECT 'riwayat_lckh' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_unit   IS NOT NULL AND p01.id_unit                IS NULL), 0) AS `fk_riwayat_lckh_ibfk_03`,
  COALESCE(SUM(c.id_satker IS NOT NULL AND p02.id_satker              IS NULL), 0) AS `fk_riwayat_lckh_ibfk_04`
FROM riwayat_lckh c
LEFT JOIN unit               p01 ON p01.id_unit = c.id_unit
LEFT JOIN satker             p02 ON p02.id_satker = c.id_satker;

-- A08. `riwayat_ak` — 1 FK
SELECT 'riwayat_ak' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_rak_to_jab`
FROM riwayat_ak c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan;

-- A09. `riwayat_ak_siasn` — 1 FK
SELECT 'riwayat_ak_siasn' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_rw_ak_siasn_02`
FROM riwayat_ak_siasn c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan;

-- A10. `konv_ak` — 3 FK
SELECT 'konv_ak' AS tabel_anak, COUNT(*) AS baris,
  COALESCE(SUM(c.id_jabatan IS NOT NULL AND p01.id_jabatan             IS NULL), 0) AS `fk_id_jabatan_konvak_to_jabatan`,
  COALESCE(SUM(c.id_unit    IS NOT NULL AND p02.id_unit                IS NULL), 0) AS `fk_id_unit_konvak_to_unit`,
  COALESCE(SUM(c.id_satker  IS NOT NULL AND p03.id_satker              IS NULL), 0) AS `fk_id_satker_konvak_to_satker`
FROM konv_ak c
LEFT JOIN jabatan            p01 ON p01.id_jabatan = c.id_jabatan
LEFT JOIN unit               p02 ON p02.id_unit = c.id_unit
LEFT JOIN satker             p03 ON p03.id_satker = c.id_satker;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian B — INFO. Contoh nilai yatim per FK (dibaca bila Bagian A > 0). Hanya nilai FK (id master), maksimal 10.
-- ---------------------------------------------------------------------------------------------------------------------
-- B01. `pegawai_mutasi_jabatan`
SELECT 'fk_id_group_jabatan_pmj_to_gj' AS fk, 'pegawai_mutasi_jabatan.id_group_jabatan' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan ORDER BY c.id_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan
WHERE c.id_group_jabatan IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_sub_group_jabatan_pmj_to_sgj' AS fk, 'pegawai_mutasi_jabatan.id_sub_group_jabatan' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan ORDER BY c.id_sub_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan
WHERE c.id_sub_group_jabatan IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_unit_pmj_to_unit' AS fk, 'pegawai_mutasi_jabatan.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_id_satker_pmj_to_satker' AS fk, 'pegawai_mutasi_jabatan.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL
UNION ALL
SELECT 'fk_id_jabatan_pmj_to_jabatan' AS fk, 'pegawai_mutasi_jabatan.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_1_pmj_to_jabatan' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_1' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_1) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_1 ORDER BY c.id_atasan_es_1 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_1
WHERE c.id_atasan_es_1 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_2_pmj_to_jabatan' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_2' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_2) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_2 ORDER BY c.id_atasan_es_2 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_2
WHERE c.id_atasan_es_2 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_3_pmj_to_jabatan' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_3' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_3) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_3 ORDER BY c.id_atasan_es_3 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_3
WHERE c.id_atasan_es_3 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_4_pmj_to_jabatan' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_4' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_4) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_4 ORDER BY c.id_atasan_es_4 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_4
WHERE c.id_atasan_es_4 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_jabatan_koord_pmj_to_jabkoor' AS fk, 'pegawai_mutasi_jabatan.id_jabatan_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_jabatan_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan_koord ORDER BY c.id_jabatan_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_jabatan_koord
WHERE c.id_jabatan_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_3_koord_pmj_to_jabkoor' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_3_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_atasan_es_3_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_3_koord ORDER BY c.id_atasan_es_3_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_atasan_es_3_koord
WHERE c.id_atasan_es_3_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_4_koord_pmj_to_jabkoor' AS fk, 'pegawai_mutasi_jabatan.id_atasan_es_4_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_atasan_es_4_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_4_koord ORDER BY c.id_atasan_es_4_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_atasan_es_4_koord
WHERE c.id_atasan_es_4_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'pegawai_mutasi_jabatan_ibfk_02' AS fk, 'pegawai_mutasi_jabatan.id_rumpun_jabatan' AS kolom, 'rumpun_jabatan' AS induk, COUNT(DISTINCT c.id_rumpun_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_rumpun_jabatan ORDER BY c.id_rumpun_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_mutasi_jabatan c LEFT JOIN rumpun_jabatan x ON x.id_rumpun_jabatan = c.id_rumpun_jabatan
WHERE c.id_rumpun_jabatan IS NOT NULL AND x.id_rumpun_jabatan IS NULL;

-- B02. `pegawai_ak`
SELECT 'fk_id_jabatan_cak_to_jab' AS fk, 'pegawai_ak.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_ak c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL;

-- B03. `pegawai_ak_siasn`
SELECT 'fk_id_jabatan_peg_ak_siasn_03' AS fk, 'pegawai_ak_siasn.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_ak_siasn c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL;

-- B04. `riwayat_mutasi_jabatan`
SELECT 'fk_id_group_jabatan_rwymj_to_gj' AS fk, 'riwayat_mutasi_jabatan.id_group_jabatan' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan ORDER BY c.id_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan
WHERE c.id_group_jabatan IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_sub_group_jabatan_rwymj_to_sgj' AS fk, 'riwayat_mutasi_jabatan.id_sub_group_jabatan' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan ORDER BY c.id_sub_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan
WHERE c.id_sub_group_jabatan IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_unit_rwymj_to_unit' AS fk, 'riwayat_mutasi_jabatan.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_id_satker_rwymj_to_satker' AS fk, 'riwayat_mutasi_jabatan.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL
UNION ALL
SELECT 'fk_id_jabatan_rwymj_to_jabatan' AS fk, 'riwayat_mutasi_jabatan.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_1_rwymj_to_jabatan' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_1' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_1) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_1 ORDER BY c.id_atasan_es_1 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_1
WHERE c.id_atasan_es_1 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_2_rwymj_to_jabatan' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_2' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_2) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_2 ORDER BY c.id_atasan_es_2 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_2
WHERE c.id_atasan_es_2 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_3_rwymj_to_jabatan' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_3' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_3) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_3 ORDER BY c.id_atasan_es_3 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_3
WHERE c.id_atasan_es_3 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_4_rwymj_to_jabatan' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_4' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_atasan_es_4) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_4 ORDER BY c.id_atasan_es_4 SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan x ON x.id_jabatan = c.id_atasan_es_4
WHERE c.id_atasan_es_4 IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_jabatan_koord_rmj_to_jabkoor' AS fk, 'riwayat_mutasi_jabatan.id_jabatan_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_jabatan_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan_koord ORDER BY c.id_jabatan_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_jabatan_koord
WHERE c.id_jabatan_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_3_koord_rmj_to_jabkoor' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_3_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_atasan_es_3_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_3_koord ORDER BY c.id_atasan_es_3_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_atasan_es_3_koord
WHERE c.id_atasan_es_3_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'fk_id_atasan_es_4_koord_rmj_to_jabkoor' AS fk, 'riwayat_mutasi_jabatan.id_atasan_es_4_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_atasan_es_4_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_atasan_es_4_koord ORDER BY c.id_atasan_es_4_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_atasan_es_4_koord
WHERE c.id_atasan_es_4_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'riwayat_mutasi_jabatan_ibfk_02' AS fk, 'riwayat_mutasi_jabatan.id_rumpun_jabatan' AS kolom, 'rumpun_jabatan' AS induk, COUNT(DISTINCT c.id_rumpun_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_rumpun_jabatan ORDER BY c.id_rumpun_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_mutasi_jabatan c LEFT JOIN rumpun_jabatan x ON x.id_rumpun_jabatan = c.id_rumpun_jabatan
WHERE c.id_rumpun_jabatan IS NOT NULL AND x.id_rumpun_jabatan IS NULL;

-- B05. `pegawai_plt`
SELECT 'fk_id_group_jabatan_plt_to_gj' AS fk, 'pegawai_plt.id_group_jabatan' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan ORDER BY c.id_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan
WHERE c.id_group_jabatan IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_group_jabatan_plt_plt_to_gj' AS fk, 'pegawai_plt.id_group_jabatan_plt' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan_plt) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan_plt ORDER BY c.id_group_jabatan_plt SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan_plt
WHERE c.id_group_jabatan_plt IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_sub_group_jabatan_plt_to_sgj' AS fk, 'pegawai_plt.id_sub_group_jabatan' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan ORDER BY c.id_sub_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan
WHERE c.id_sub_group_jabatan IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_sub_group_jabatan_plt_plt_to_sgj' AS fk, 'pegawai_plt.id_sub_group_jabatan_plt' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan_plt) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan_plt ORDER BY c.id_sub_group_jabatan_plt SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan_plt
WHERE c.id_sub_group_jabatan_plt IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_id_unit_plt_to_unit' AS fk, 'pegawai_plt.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_id_unit_plt_plt_to_unit' AS fk, 'pegawai_plt.id_unit_plt' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit_plt) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit_plt ORDER BY c.id_unit_plt SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN unit x ON x.id_unit = c.id_unit_plt
WHERE c.id_unit_plt IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_id_satker_plt_to_satker' AS fk, 'pegawai_plt.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL
UNION ALL
SELECT 'fk_id_satker_plt_plt_to_satker' AS fk, 'pegawai_plt.id_satker_plt' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker_plt) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker_plt ORDER BY c.id_satker_plt SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN satker x ON x.id_satker = c.id_satker_plt
WHERE c.id_satker_plt IS NOT NULL AND x.id_satker IS NULL
UNION ALL
SELECT 'fk_id_jabatan_plt_to_jabatan' AS fk, 'pegawai_plt.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_jabatan_koord_plt_to_jabkoord' AS fk, 'pegawai_plt.id_jabatan_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_jabatan_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan_koord ORDER BY c.id_jabatan_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plt c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_jabatan_koord
WHERE c.id_jabatan_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL;

-- B06. `pegawai_plh`
SELECT 'fk_pegawai_plh_ibfk_02' AS fk, 'pegawai_plh.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_03' AS fk, 'pegawai_plh.id_jabatan_koord' AS kolom, 'jabatan_koordinasi' AS induk, COUNT(DISTINCT c.id_jabatan_koord) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan_koord ORDER BY c.id_jabatan_koord SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN jabatan_koordinasi x ON x.id_jabatan_koordinasi = c.id_jabatan_koord
WHERE c.id_jabatan_koord IS NOT NULL AND x.id_jabatan_koordinasi IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_04' AS fk, 'pegawai_plh.id_group_jabatan' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan ORDER BY c.id_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan
WHERE c.id_group_jabatan IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_05' AS fk, 'pegawai_plh.id_sub_group_jabatan' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan ORDER BY c.id_sub_group_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan
WHERE c.id_sub_group_jabatan IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_06' AS fk, 'pegawai_plh.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_07' AS fk, 'pegawai_plh.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_08' AS fk, 'pegawai_plh.id_group_jabatan_plh' AS kolom, 'group_jabatan' AS induk, COUNT(DISTINCT c.id_group_jabatan_plh) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_group_jabatan_plh ORDER BY c.id_group_jabatan_plh SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN group_jabatan x ON x.id_group_jabatan = c.id_group_jabatan_plh
WHERE c.id_group_jabatan_plh IS NOT NULL AND x.id_group_jabatan IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_09' AS fk, 'pegawai_plh.id_sub_group_jabatan_plh' AS kolom, 'sub_group_jabatan' AS induk, COUNT(DISTINCT c.id_sub_group_jabatan_plh) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_sub_group_jabatan_plh ORDER BY c.id_sub_group_jabatan_plh SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN sub_group_jabatan x ON x.id_sub_group_jabatan = c.id_sub_group_jabatan_plh
WHERE c.id_sub_group_jabatan_plh IS NOT NULL AND x.id_sub_group_jabatan IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_10' AS fk, 'pegawai_plh.id_unit_plh' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit_plh) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit_plh ORDER BY c.id_unit_plh SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN unit x ON x.id_unit = c.id_unit_plh
WHERE c.id_unit_plh IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_pegawai_plh_ibfk_11' AS fk, 'pegawai_plh.id_satker_plh' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker_plh) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker_plh ORDER BY c.id_satker_plh SEPARATOR ','), ',', 10) AS contoh_nilai
FROM pegawai_plh c LEFT JOIN satker x ON x.id_satker = c.id_satker_plh
WHERE c.id_satker_plh IS NOT NULL AND x.id_satker IS NULL;

-- B07. `riwayat_lckh`
SELECT 'fk_riwayat_lckh_ibfk_03' AS fk, 'riwayat_lckh.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_lckh c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_riwayat_lckh_ibfk_04' AS fk, 'riwayat_lckh.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_lckh c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL;

-- B08. `riwayat_ak`
SELECT 'fk_id_jabatan_rak_to_jab' AS fk, 'riwayat_ak.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_ak c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL;

-- B09. `riwayat_ak_siasn`
SELECT 'fk_id_jabatan_rw_ak_siasn_02' AS fk, 'riwayat_ak_siasn.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM riwayat_ak_siasn c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL;

-- B10. `konv_ak`
SELECT 'fk_id_jabatan_konvak_to_jabatan' AS fk, 'konv_ak.id_jabatan' AS kolom, 'jabatan' AS induk, COUNT(DISTINCT c.id_jabatan) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_jabatan ORDER BY c.id_jabatan SEPARATOR ','), ',', 10) AS contoh_nilai
FROM konv_ak c LEFT JOIN jabatan x ON x.id_jabatan = c.id_jabatan
WHERE c.id_jabatan IS NOT NULL AND x.id_jabatan IS NULL
UNION ALL
SELECT 'fk_id_unit_konvak_to_unit' AS fk, 'konv_ak.id_unit' AS kolom, 'unit' AS induk, COUNT(DISTINCT c.id_unit) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_unit ORDER BY c.id_unit SEPARATOR ','), ',', 10) AS contoh_nilai
FROM konv_ak c LEFT JOIN unit x ON x.id_unit = c.id_unit
WHERE c.id_unit IS NOT NULL AND x.id_unit IS NULL
UNION ALL
SELECT 'fk_id_satker_konvak_to_satker' AS fk, 'konv_ak.id_satker' AS kolom, 'satker' AS induk, COUNT(DISTINCT c.id_satker) AS nilai_yatim_berbeda,
       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT c.id_satker ORDER BY c.id_satker SEPARATOR ','), ',', 10) AS contoh_nilai
FROM konv_ak c LEFT JOIN satker x ON x.id_satker = c.id_satker
WHERE c.id_satker IS NOT NULL AND x.id_satker IS NULL;
