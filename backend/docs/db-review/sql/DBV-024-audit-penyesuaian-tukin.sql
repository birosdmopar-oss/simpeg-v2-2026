-- =====================================================================================================================
-- DBV-024 — Kueri audit `penyesuaian_tukin` (Fase 5B, F5B-14) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-024-penyesuaian-tukin-schema.md Bagian 6.
--
-- Tidak ada tabel legacy yang diimpor: sumber legacy berupa daftar NIP & periode di kode (`L_presensi.php:11755-11806`),
-- bukan tabel. Kueri di sini memeriksa DB v2 TUJUAN: prasyarat sebelum migration (Bagian A) dan konsistensi isi setelah
-- admin mengisi riwayat penyesuaian saat cutover lewat CRUD (Bagian B, F5B-21).
--
-- Klasifikasi (arti awalan sama dengan DBV-011 2.3 / DBV-023):
--   WAJIB_0 = hasil harus 0; baris yang melanggar diperbaiki lewat CRUD sebelum go-live.
--   STOP    = hasil tidak sesuai menghentikan migration; DB Validator memutus lebih dulu.
--   INFO    = dilaporkan, tidak memblokir.
--
-- Cara pakai (akun baca saja; tanpa --force):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2> \
--         < backend/docs/db-review/sql/DBV-024-audit-penyesuaian-tukin.sql > hasil_audit_dbv024.txt 2>&1
--
-- Hanya SELECT; tidak ada perubahan data. Hasil yang memuat NIP TIDAK dicommit ke repo publik.
-- =====================================================================================================================

-- ---------------------------------------------------------------------------------------------------------------------
-- A. PRASYARAT SEBELUM MIGRATION (DB v2)
-- ---------------------------------------------------------------------------------------------------------------------

-- A1 [STOP] Kolom induk FK `pegawai.nip` harus VARCHAR(30) utf8mb4_unicode_ci (tipe & collation kolom anak sama).
SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME,
       CASE WHEN COLUMN_TYPE = 'varchar(30)' AND COLLATION_NAME = 'utf8mb4_unicode_ci' THEN 'OK' ELSE 'STOP' END AS hasil
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pegawai' AND COLUMN_NAME = 'nip';

-- A2 [INFO] Tabel `penyesuaian_tukin` belum ada sebelum migration (0 = belum ada; 1 = sudah dibuat).
SELECT COUNT(*) AS penyesuaian_tukin_ada
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'penyesuaian_tukin';

-- A3 [STOP] ENUM `audit_logs.event` sudah memuat create/update/delete (DBV-Q3: tidak perlu nilai baru).
SELECT COLUMN_TYPE,
       CASE WHEN COLUMN_TYPE LIKE "%'create'%" AND COLUMN_TYPE LIKE "%'update'%" AND COLUMN_TYPE LIKE "%'delete'%"
            THEN 'OK' ELSE 'STOP' END AS hasil
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'event';

-- A4 [INFO] Nama FK baru belum dipakai di skema (nama FK unik per skema di MySQL).
SELECT COUNT(*) AS fk_nama_bentrok
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_nip_ptk_to_pegawai';

-- ---------------------------------------------------------------------------------------------------------------------
-- B. KONSISTENSI ISI SETELAH PENGISIAN CUTOVER (jalankan hanya bila A2 = 1)
-- ---------------------------------------------------------------------------------------------------------------------

-- B1 [WAJIB_0] Rentang aktif yang tumpang tindih untuk NIP yang sama (aplikasi menolaknya; baris lewat jalur lain = galat).
SELECT a.nip, a.id_penyesuaian_tukin AS id_a, b.id_penyesuaian_tukin AS id_b,
       a.bulan_mulai AS mulai_a, a.bulan_akhir AS akhir_a, b.bulan_mulai AS mulai_b, b.bulan_akhir AS akhir_b
FROM penyesuaian_tukin a
JOIN penyesuaian_tukin b
  ON b.nip = a.nip AND b.id_penyesuaian_tukin > a.id_penyesuaian_tukin
 AND b.status = 1 AND a.status = 1
 AND b.bulan_mulai <= a.bulan_akhir AND b.bulan_akhir >= a.bulan_mulai;

-- B2 [WAJIB_0] Rentang sebelum April 2024 (laporan v2 hanya mendukung periode ≥ 2024-04, F5B-4).
SELECT COUNT(*) AS sebelum_2024_04
FROM penyesuaian_tukin
WHERE status <> 10 AND bulan_mulai < '2024-04-01';

-- B3 [INFO] Ringkasan per status & rentang (tanpa NIP).
SELECT status, COUNT(*) AS jumlah, MIN(bulan_mulai) AS mulai_awal, MAX(bulan_akhir) AS akhir_akhir,
       MIN(persen) AS persen_min, MAX(persen) AS persen_max
FROM penyesuaian_tukin
GROUP BY status
ORDER BY status;

-- B4 [INFO] Penyesuaian aktif untuk pegawai yang sudah tidak aktif (status pegawai ≠ 1) — dicek admin, bukan galat.
SELECT COUNT(*) AS penyesuaian_pegawai_nonaktif
FROM penyesuaian_tukin p
JOIN pegawai g ON g.nip = p.nip
WHERE p.status = 1 AND g.status <> 1;
