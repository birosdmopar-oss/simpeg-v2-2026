-- =====================================================================================================================
-- DBV-023 — Audit data legacy `dh_online` dan `gaji_pegawai` (+ `gaji_pegawai_qr_history` bila ada) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-023-presensi-gaji-fase5a-schema.md Bagian 8 (kueri audit) dan Bagian 7 (impor).
-- Digabung ke runbook impor tunggal backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md (langkah audit
-- pra-impor) — urutan tier ikut Mapping Migrasi (`dh_online` Tier 3).
--
-- Klasifikasi (arti awalan sama dengan DBV-011 2.3):
--   WAJIB_0 = hasil harus 0 sebelum impor ke skema v2 (FK/PK/UNIQUE/CHECK v2 akan menolak baris itu); baris yang
--             melanggar diperbaiki di salinan kerja/transformasi impor, lalu dicatat di log impor.
--   STOP    = hasil > 0 menghentikan impor; DB Validator memutus pemetaan lebih dulu (PRD 5A §7.1.1, Q5A-02).
--   INFO    = dilaporkan, tidak memblokir.
--
-- Nama tabel/kolom = D1 (dump struktur produksi `simpeg01` 01-10-2026). `gaji_pegawai` di D1 masih struktur lama (kolom
-- generik `gaji_pokok` … `tunj_kinerja`); 28 kolom GPP/TK dan `uang_makan_hari`/`uang_makan_periode` legacy `771f8b4`
-- (SQL-L) belum ada di D1. Bagian B memakai kolom D1; kueri bertanda [SQL-L] hanya dijalankan bila hasil kartu DBA
-- (`SHOW CREATE TABLE gaji_pegawai`, Q5A-02) menunjukkan kolom itu ada. `gaji_pegawai_qr_history` tidak ada di D1:
-- Bagian C hanya dijalankan bila tabelnya ada.
--
-- Cara pakai (akun baca saja; tanpa --force agar error langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan> \
--         < backend/docs/db-review/sql/DBV-023-audit-presensi-gaji.sql > hasil_audit_dbv023.txt 2>&1
--
-- LARANGAN: jangan jalankan skrip ini di DB produksi legacy yang sedang melayani aplikasi. Jalankan hanya di SALINAN dump
-- final saat freeze / salinan gladi (DBV-011 2.4 langkah 1) atau di DB v2 tujuan setelah impor. Skrip ini hanya berisi
-- SELECT (dan SET variabel sesi); tidak ada perubahan data. Hasil yang memuat NIP/nominal TIDAK dicommit ke repo publik.
-- =====================================================================================================================

-- Tanggal acuan "masa depan" untuk kueri A4: tanggal dump/freeze (ganti sesuai dump yang diaudit).
SET @tgl_dump := CURDATE();


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian A — `dh_online` (D1:390-401)
-- ---------------------------------------------------------------------------------------------------------------------

-- A1 — WAJIB_0. NIP yatim terhadap `pegawai` (FK v2 `fk_nip_dh_to_pegawai` RESTRICT). D1 ber-FK CASCADE, jadi hasil
--      seharusnya 0; tetap diaudit karena impor bisa memakai FOREIGN_KEY_CHECKS = 0.
SELECT 'A1 dh_online nip yatim' AS kueri, COUNT(*) AS jumlah
FROM dh_online d
LEFT JOIN pegawai p ON p.nip = d.nip
WHERE p.nip IS NULL;

-- A2 — WAJIB_0 bila CHECK `chk_dh_online_jam` disetujui (DBV-Q5). Baris tanpa jam masuk maupun pulang.
SELECT 'A2 dh_online kedua jam NULL' AS kueri, COUNT(*) AS jumlah
FROM dh_online
WHERE jam_masuk IS NULL AND jam_pulang IS NULL;

-- A3 — INFO. Jam masuk lebih akhir dari jam pulang (presensi online legacy tidak memeriksa urutan; v2 hanya menolaknya
--      di presensi manual [V2] F5-14). Tidak memblokir.
SELECT 'A3 dh_online jam_masuk > jam_pulang (INFO)' AS kueri, COUNT(*) AS jumlah
FROM dh_online
WHERE jam_masuk IS NOT NULL AND jam_pulang IS NOT NULL AND jam_masuk > jam_pulang;

-- A4 — WAJIB_0. Tanggal setelah tanggal dump (zonasi maksimal +120 menit hanya menggeser ke hari berikutnya).
SELECT 'A4 dh_online tgl > tanggal dump + 1' AS kueri, COUNT(*) AS jumlah
FROM dh_online
WHERE tgl > DATE_ADD(@tgl_dump, INTERVAL 1 DAY);

-- A5 — INFO. Volume dan rentang (bahan perkiraan durasi impor Tier 3).
SELECT 'A5 dh_online volume (INFO)' AS kueri, COUNT(*) AS jumlah, MIN(tgl) AS tgl_min, MAX(tgl) AS tgl_maks,
       COUNT(DISTINCT nip) AS jumlah_nip
FROM dh_online;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian B — `gaji_pegawai` (D1:1242-1273; F5-2, Q5A-02)
-- ---------------------------------------------------------------------------------------------------------------------

-- B1 — INFO (menutup DoD "volume histori", AC-D01.5). Jumlah baris dan distribusi periode.
SELECT 'B1 gaji_pegawai COUNT (INFO)' AS kueri, COUNT(*) AS jumlah FROM gaji_pegawai;

SELECT 'B1 gaji_pegawai per periode (INFO)' AS kueri, tahun, bulan, COUNT(*) AS jumlah
FROM gaji_pegawai
GROUP BY tahun, bulan
ORDER BY tahun, bulan;

-- B2 — STOP. Baris yang berisi nilai di salah satu dari 15 kolom generik D1 yang DIBUANG di v2 (F5-2). Hasil > 0 →
--      impor berhenti; DB Validator memutus pemetaan kolom generik → 28 kolom GPP/TK sebelum migration M2.
SELECT 'B2 gaji_pegawai berisi kolom generik yang dibuang (STOP)' AS kueri, COUNT(*) AS jumlah
FROM gaji_pegawai
WHERE gaji_pokok <> 0 OR tunj_istri_suami <> 0 OR tunj_anak <> 0 OR tunj_umum <> 0 OR tunj_struktural <> 0
   OR tunj_fungsional <> 0 OR tunj_lainlain <> 0 OR pembulatan <> 0 OR tunj_beras <> 0 OR tunj_khusus_pajak <> 0
   OR pot_iuran_wajib <> 0 OR pot_bpjs_kesehatan <> 0 OR pot_pph <> 0 OR pot_taperum <> 0 OR tunj_kinerja <> 0;

-- B3 — WAJIB_0. Duplikat kunci (nip, bulan, tahun) (UNIQUE `uk_nip_bulan_tahun` [K] — seharusnya 0).
SELECT 'B3 gaji_pegawai kunci ganda' AS kueri, COUNT(*) AS jumlah
FROM (SELECT nip, bulan, tahun FROM gaji_pegawai GROUP BY nip, bulan, tahun HAVING COUNT(*) > 1) g;

-- B4 — WAJIB_0. Domain CHECK [V2] `bulan` 1..12, `tahun` 1900..2100.
SELECT 'B4 gaji_pegawai bulan/tahun di luar domain' AS kueri, COUNT(*) AS jumlah
FROM gaji_pegawai
WHERE bulan NOT BETWEEN 1 AND 12 OR tahun NOT BETWEEN 1900 AND 2100;

-- B5 — WAJIB_0. Nominal negatif (CHECK [V2] `chk_gaji_pegawai_nominal`). Kolom D1 yang dipertahankan: `uang_makan`.
SELECT 'B5 gaji_pegawai uang_makan negatif' AS kueri, COUNT(*) AS jumlah
FROM gaji_pegawai
WHERE uang_makan < 0;

-- B5 [SQL-L] — WAJIB_0, hanya bila 28 kolom GPP/TK ada di salinan produksi (hasil Q5A-02).
-- SELECT 'B5 gaji_pegawai nominal GPP/TK negatif' AS kueri, COUNT(*) AS jumlah
-- FROM gaji_pegawai
-- WHERE gjpokok < 0 OR tjistri < 0 OR tjanak < 0 OR tjupns < 0 OR tjstruk < 0 OR tjfungs < 0 OR tjdaerah < 0
--    OR tjpencil < 0 OR tjlain < 0 OR tjkompen < 0 OR pembul < 0 OR tjberas < 0 OR tjpph < 0 OR potpfkbul < 0
--    OR potpfk2 < 0 OR potpfk10 < 0 OR potpph < 0 OR potswrum < 0 OR potkelbtj < 0 OR potlain < 0 OR pottabrum < 0
--    OR bersih_gpp < 0 OR kotor < 0 OR potongan < 0 OR bersih_tk < 0 OR pajak < 0 OR tunj_pajak < 0 OR bersih_2 < 0;

-- B6 — WAJIB_0. `qr_token` non-NULL ganda (UNIQUE `uk_qr_token` [V2] atas hash; token yang sama menghasilkan hash sama).
SELECT 'B6 gaji_pegawai qr_token ganda' AS kueri, COUNT(*) AS jumlah
FROM (SELECT qr_token FROM gaji_pegawai WHERE qr_token IS NOT NULL GROUP BY qr_token HAVING COUNT(*) > 1) g;

-- B7 — INFO. Konsistensi status/token: token tanpa `viewed_at` atau sebaliknya (legacy menulis keduanya bersamaan,
--      `Lsl_gaji.php:177-182`, dan mengosongkan keduanya saat buka ulang, `:862-866`). Baris tidak berpasangan
--      dilaporkan ke DB Validator (perlakuan impor diputus per kasus; tidak ada constraint v2 yang menolaknya).
SELECT 'B7 gaji_pegawai viewed_at/qr_token tidak berpasangan (INFO)' AS kueri, COUNT(*) AS jumlah
FROM gaji_pegawai
WHERE (viewed_at IS NULL) <> (qr_token IS NULL);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian C — `gaji_pegawai_qr_history` (SQL-L `2026_09_02…:28-40`; tidak ada di D1 — jalankan hanya bila tabel ada)
-- ---------------------------------------------------------------------------------------------------------------------

-- C0 — INFO. Keberadaan tabel.
SELECT 'C0 qr_history ada? (INFO)' AS kueri, COUNT(*) AS jumlah
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gaji_pegawai_qr_history';

-- C1 — WAJIB_0. Token ganda (UNIQUE `uk_gpqh_qr_token` [V2]; SQL-L backfill yang dijalankan dua kali menggandakan token).
-- SELECT 'C1 qr_history token ganda' AS kueri, COUNT(*) AS jumlah
-- FROM (SELECT qr_token FROM gaji_pegawai_qr_history GROUP BY qr_token HAVING COUNT(*) > 1) g;

-- C2 — WAJIB_0. `id_gaji_pegawai` yatim (FK v2 RESTRICT).
-- SELECT 'C2 qr_history id_gaji_pegawai yatim' AS kueri, COUNT(*) AS jumlah
-- FROM gaji_pegawai_qr_history h
-- LEFT JOIN gaji_pegawai g ON g.id = h.id_gaji_pegawai
-- WHERE g.id IS NULL;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian D — collation & panjang `nip`
-- ---------------------------------------------------------------------------------------------------------------------

-- D1 — INFO. Collation kolom `nip` dan tabel (v2 = utf8mb4_unicode_ci; collation lain dikonversi saat impor).
SELECT 'D1 collation (INFO)' AS kueri, TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('dh_online', 'gaji_pegawai', 'gaji_pegawai_qr_history')
  AND COLLATION_NAME IS NOT NULL
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- D2 — WAJIB_0. NIP lebih dari 30 karakter atau berspasi di tepi (kunci v2 VARCHAR(30), dibandingkan tanpa trim).
SELECT 'D2 dh_online nip > 30 / berspasi tepi' AS kueri, COUNT(*) AS jumlah
FROM dh_online
WHERE CHAR_LENGTH(nip) > 30 OR nip <> TRIM(nip);

SELECT 'D2 gaji_pegawai nip > 30 / berspasi tepi' AS kueri, COUNT(*) AS jumlah
FROM gaji_pegawai
WHERE CHAR_LENGTH(nip) > 30 OR nip <> TRIM(nip);
