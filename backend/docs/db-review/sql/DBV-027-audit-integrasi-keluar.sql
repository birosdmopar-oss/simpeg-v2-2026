-- =====================================================================================================================
-- DBV-027 — Kueri audit skema integrasi keluar (Fase 6 Jalur B, H-01 bagian B) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-027-integrasi-keluar.md Bagian 7.
--
-- Bagian A = prasyarat di DB v2 TUJUAN sebelum migration (termasuk pra-cek duplikat kunci UNIQUE `riwayat_skp_periodik`
-- pada data impor cutover, Q6-10). Bagian B = konsistensi setelah migration / setelah job integrasi berjalan.
--
-- Klasifikasi (arti awalan sama dengan DBV-011 2.3 / DBV-023 / DBV-024):
--   WAJIB_0 = hasil harus 0; pelanggaran diselesaikan (aturan DBV-027 Bagian 5) sebelum migration/go-live.
--   STOP    = hasil tidak sesuai menghentikan migration; DB Validator memutus lebih dulu.
--   INFO    = dilaporkan, tidak memblokir.
--
-- Cara pakai (akun baca saja; tanpa --force):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2> \
--         < backend/docs/db-review/sql/DBV-027-audit-integrasi-keluar.sql > hasil_audit_dbv027.txt 2>&1
--
-- Hanya SELECT; tidak ada perubahan data. Kueri hanya menghasilkan JUMLAH (tanpa NIP/nama/token); hasil tetap tidak
-- dicommit ke repo publik.
-- =====================================================================================================================

-- ---------------------------------------------------------------------------------------------------------------------
-- A. PRASYARAT SEBELUM MIGRATION (DB v2)
-- ---------------------------------------------------------------------------------------------------------------------

-- A1 [STOP] Induk FK `pegawai.nip` VARCHAR(30) utf8mb4_unicode_ci dan `pengguna.id_pengguna` INT UNSIGNED.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME,
       CASE
           WHEN TABLE_NAME = 'pegawai' AND COLUMN_TYPE = 'varchar(30)' AND COLLATION_NAME = 'utf8mb4_unicode_ci' THEN 'OK'
           WHEN TABLE_NAME = 'pengguna' AND COLUMN_TYPE = 'int unsigned' THEN 'OK'
           ELSE 'STOP'
       END AS hasil
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'pegawai' AND COLUMN_NAME = 'nip') OR (TABLE_NAME = 'pengguna' AND COLUMN_NAME = 'id_pengguna'));

-- A2 [INFO] Tabel baru DBV-027 belum ada (0 = belum ada).
SELECT COUNT(*) AS tabel_dbv027_sudah_ada
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('integrasi_log', 'siasn_status_pegawai', 'siasn_upload_arsip', 'double_riwayat_kp',
                     'siasn_ref_unor', 'siasn_referensi');

-- A3 [WAJIB_0] Kunci UNIQUE baru `riwayat_skp_periodik` (`id_pns`, `periode_id`, `skp_id`) ganda — jumlah KUNCI ganda
--    dan jumlah BARIS yang terlibat. Baris dengan `periode_id`/`skp_id` NULL tidak dibatasi UNIQUE (dihitung di A4).
SELECT COUNT(*) AS kunci_ganda, COALESCE(SUM(n), 0) AS baris_terlibat
FROM (
    SELECT COUNT(*) AS n
    FROM riwayat_skp_periodik
    WHERE id_pns IS NOT NULL AND periode_id IS NOT NULL AND skp_id IS NOT NULL
    GROUP BY id_pns, periode_id, skp_id
    HAVING COUNT(*) > 1
) d;

-- A3b [INFO] Rincian A3 per status pasangan ganda (bahan aturan pemilihan Bagian 5; tanpa NIP).
SELECT status_set, COUNT(*) AS kunci
FROM (
    SELECT GROUP_CONCAT(DISTINCT status ORDER BY status) AS status_set
    FROM riwayat_skp_periodik
    WHERE id_pns IS NOT NULL AND periode_id IS NOT NULL AND skp_id IS NOT NULL
    GROUP BY id_pns, periode_id, skp_id
    HAVING COUNT(*) > 1
) d
GROUP BY status_set;

-- A4 [INFO] Baris `riwayat_skp_periodik` dengan `periode_id` atau `skp_id` NULL (di luar jangkauan UNIQUE).
SELECT SUM(periode_id IS NULL) AS periode_null, SUM(skp_id IS NULL) AS skp_null, COUNT(*) AS total_baris
FROM riwayat_skp_periodik;

-- A5 [INFO] NIP dengan lebih dari satu baris SKP aktif (status 1, hasil_akhir terisi) per periode — kunci BERBEDA dari
--    UNIQUE (skp_id lain, mis. SKP Plt/Plh). Tukin memakai baris id terkecil (SumberDataPegawaiTukin:162-172).
SELECT periode_id, COUNT(*) AS nip_lebih_dari_satu
FROM (
    SELECT nip, periode_id
    FROM riwayat_skp_periodik
    WHERE status = 1 AND hasil_akhir IS NOT NULL
    GROUP BY nip, periode_id
    HAVING COUNT(*) > 1
) d
GROUP BY periode_id
ORDER BY periode_id;

-- A6 [WAJIB_0] Token FCM ganda per pemilik (dedup sebelum UNIQUE `nip`+`token_hash`, DBV-Q8 ikut legacy). Tabel kosong
--    saat cutover (AC-01.2): normalnya 0. Semua baris lama ber-nip (platform 2).
SELECT COUNT(*) AS token_ganda_per_pemilik
FROM (
    SELECT 1 FROM firebase_token WHERE fcm_token IS NOT NULL GROUP BY nip, SHA2(fcm_token, 256) HAVING COUNT(*) > 1
) d;

-- A6b [WAJIB_0] Baris token lama tanpa `nip` (setelah ALTER menjadi platform 2 dan wajib ber-nip, CHECK pemilik).
SELECT COUNT(*) AS token_tanpa_nip FROM firebase_token WHERE nip IS NULL;

-- A7 [STOP] Username akun sistem integrasi belum dipakai (0).
SELECT COUNT(*) AS username_terpakai FROM pengguna WHERE username = 'sistem.integrasi';

-- ---------------------------------------------------------------------------------------------------------------------
-- B. SETELAH MIGRATION / JOB INTEGRASI BERJALAN
-- ---------------------------------------------------------------------------------------------------------------------

-- B1 [WAJIB_0] Akun sistem integrasi bisa login (harus nonaktif dan tanpa password).
SELECT COUNT(*) AS akun_sistem_bisa_login
FROM pengguna
WHERE username = 'sistem.integrasi' AND (status <> '0' OR password IS NOT NULL OR password_legacy IS NOT NULL);

-- B1b [INFO] Level akun sistem integrasi (ikut legacy = 1, keputusan Horii 03-11-2026 DBV-Q10; pengamannya B1).
SELECT user_level, status FROM pengguna WHERE username = 'sistem.integrasi';

-- B2 [WAJIB_0] Token tanpa hash (aplikasi wajib mengisi `token_hash` saat upsert, F6-13).
SELECT COUNT(*) AS token_tanpa_hash FROM firebase_token WHERE fcm_token IS NOT NULL AND token_hash IS NULL;

-- B3 [INFO] Token per platform.
SELECT platform, COUNT(*) AS token FROM firebase_token GROUP BY platform;

-- B4 [INFO] Antrean unggah arsip SIASN per status (0 antre, 1 terunggah, 2 gagal, 3 diproses).
SELECT status, COUNT(*) AS baris FROM siasn_upload_arsip GROUP BY status;

-- B5 [WAJIB_0] Antrean "diproses" (3) yang macet lebih dari 1 hari (job terhenti di tengah).
SELECT COUNT(*) AS diproses_macet
FROM siasn_upload_arsip
WHERE status = 3 AND updated_at < UTC_TIMESTAMP() - INTERVAL 1 DAY;

-- B6 [INFO] Pegawai aktif tanpa baris status SIASN (job `siasn:tarik --jenis=data-utama` belum menyelaraskan).
SELECT COUNT(*) AS pegawai_tanpa_status_siasn
FROM pegawai p
LEFT JOIN siasn_status_pegawai s ON s.nip = p.nip
WHERE p.status = 1 AND s.nip IS NULL;

-- B7 [INFO] Log integrasi per layanan × status, 7 hari terakhir.
SELECT layanan, status, COUNT(*) AS baris
FROM integrasi_log
WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
GROUP BY layanan, status
ORDER BY layanan, status;

-- B8 [WAJIB_0] Log lebih tua dari retensi 180 hari (Q6-16) — `integrasi:bersihkan-log` belum berjalan.
SELECT COUNT(*) AS log_melewati_retensi FROM integrasi_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 180 DAY;

-- B9 [INFO] Referensi SIASN per jenis dan yang belum dipetakan ke master v2.
SELECT jenis, COUNT(*) AS baris, SUM(id_lokal IS NULL) AS belum_dipetakan FROM siasn_referensi GROUP BY jenis;
SELECT COUNT(*) AS unor, SUM(id_unit IS NULL) AS unor_belum_dipetakan FROM siasn_ref_unor;
