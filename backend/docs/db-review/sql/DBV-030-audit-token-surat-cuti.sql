-- =====================================================================================================================
-- DBV-030 — Kueri audit token publik surat cuti (Fase 6 Jalur B, MAKE-065 F6-18 K3b/K3c) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-030-token-surat-cuti.md Bagian 6.
--
-- Bagian B = konsistensi setelah migration / setelah aplikasi menerbitkan token. Tabel baru tanpa data lama, jadi tidak
-- ada Bagian A (pra-cek).
--
-- Klasifikasi (arti awalan sama dengan DBV-027):
--   WAJIB_0 = hasil harus 0; pelanggaran diselesaikan sebelum go-live.
--   INFO    = dilaporkan, tidak memblokir.
--
-- Cara pakai (akun baca saja; tanpa --force):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2> \
--         < backend/docs/db-review/sql/DBV-030-audit-token-surat-cuti.sql > hasil_audit_dbv030.txt 2>&1
--
-- Hanya SELECT; hanya JUMLAH (tanpa NIP/nama/token); hasil tetap tidak dicommit ke repo publik.
-- =====================================================================================================================

-- B1 [WAJIB_0] Token aktif untuk cuti yang tidak final (status selain 1 disetujui / 4 ditangguhkan).
SELECT COUNT(*) AS token_aktif_cuti_tidak_final
FROM surat_cuti_token t
JOIN riwayat_cuti c ON c.id_riwayat_cuti = t.id_riwayat_cuti
WHERE t.status = 1 AND c.status NOT IN (1, 4);

-- B2 [WAJIB_0] Hash bukan 64 heks huruf kecil (aplikasi wajib menulis hash('sha256', token)).
SELECT COUNT(*) AS hash_tidak_sah FROM surat_cuti_token WHERE token_hash NOT REGEXP '^[0-9a-f]{64}$';

-- B3 [WAJIB_0] Salinan terenkripsi kosong (QR tidak bisa dicetak ulang).
SELECT COUNT(*) AS enkripsi_kosong FROM surat_cuti_token WHERE token_enkripsi IS NULL OR LENGTH(token_enkripsi) = 0;

-- B4 [INFO] Token per status.
SELECT status, COUNT(*) AS token FROM surat_cuti_token GROUP BY status ORDER BY status;

-- B5 [INFO] Cuti final tanpa token (surat terbit sebelum DBV-030 / belum dicetak ulang).
SELECT COUNT(*) AS cuti_final_tanpa_token
FROM riwayat_cuti c
LEFT JOIN surat_cuti_token t ON t.id_riwayat_cuti = c.id_riwayat_cuti
WHERE c.status IN (1, 4) AND t.id_surat_cuti_token IS NULL;
