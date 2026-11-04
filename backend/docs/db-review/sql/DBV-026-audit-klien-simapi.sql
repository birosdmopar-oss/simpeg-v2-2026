-- =====================================================================================================================
-- DBV-026 — Kueri audit klien layanan & SIMAPI (Fase 6 Jalur A, H-01 bagian A) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-026-klien-layanan-simapi.md Bagian 6.
--
-- Tabel baru tanpa data lama (F6-08), jadi tidak ada Bagian A (pra-cek). Bagian B = konsistensi setelah aplikasi berjalan.
--
-- Klasifikasi (arti awalan sama dengan DBV-027):
--   WAJIB_0 = hasil harus 0; pelanggaran diselesaikan sebelum go-live.
--   INFO    = dilaporkan, tidak memblokir.
--
-- Cara pakai (akun baca saja; tanpa --force):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2> \
--         < backend/docs/db-review/sql/DBV-026-audit-klien-simapi.sql > hasil_audit_dbv026.txt 2>&1
--
-- Hanya SELECT; hanya JUMLAH (tanpa NIP/username/token/hash); hasil tetap tidak dicommit ke repo publik.
-- =====================================================================================================================

-- B1 [WAJIB_0] Rahasia klien bukan hash password_hash (awalan $2y$ bcrypt atau $argon2).
SELECT COUNT(*) AS rahasia_bukan_hash FROM klien_layanan
WHERE rahasia_hash NOT LIKE '$2y$%' AND rahasia_hash NOT LIKE '$argon2%';

-- B2 [WAJIB_0] Hash token bukan 64 heks huruf kecil (pola DBV-030 V-01: REGEXP _ci + perbandingan BINARY).
SELECT COUNT(*) AS token_hash_tidak_sah FROM simapi_token
WHERE token_hash NOT REGEXP '^[0-9a-f]{64}$' OR BINARY token_hash <> BINARY LOWER(token_hash);

-- B3 [WAJIB_0] Token aktif belum kedaluwarsa milik klien yang tidak aktif (filter tetap menolak; token harus dicabut).
SELECT COUNT(*) AS token_aktif_klien_nonaktif
FROM simapi_token t
JOIN klien_layanan k ON k.id_klien = t.id_klien
WHERE t.status = 1 AND t.expired_at > UTC_TIMESTAMP() AND k.status <> 1;

-- B4 [WAJIB_0] Klien SIMAPI (jenis 1/2) tanpa hak 'simapi', atau klien jenis 3 dengan hak 'simapi'.
SELECT COUNT(*) AS jenis_hak_tidak_cocok FROM klien_layanan
WHERE status <> 10 AND ((jenis IN (1, 2) AND NOT FIND_IN_SET('simapi', hak)) OR (jenis = 3 AND FIND_IN_SET('simapi', hak)));

-- B5 [INFO] Klien per jenis dan status.
SELECT jenis, status, COUNT(*) AS klien FROM klien_layanan GROUP BY jenis, status ORDER BY jenis, status;

-- B6 [INFO] Token kedaluwarsa yang masih tersimpan (bahan pembersihan berkala).
SELECT COUNT(*) AS token_kedaluwarsa FROM simapi_token WHERE expired_at <= UTC_TIMESTAMP();

-- B7 [INFO] Baris change history per jenis data dan baris yang belum diambil klien type 2 aktif mana pun.
SELECT ch.id_simapi_jd, COUNT(*) AS baris FROM simapi_ch ch GROUP BY ch.id_simapi_jd ORDER BY ch.id_simapi_jd;

SELECT COUNT(*) AS baris_belum_dikirim
FROM simapi_ch ch
WHERE NOT EXISTS (SELECT 1 FROM simapi_auth_ch a WHERE a.id_simapi_ch = ch.id_simapi_ch);
