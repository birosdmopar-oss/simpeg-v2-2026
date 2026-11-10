-- =====================================================================================================================
-- DBV-031 — Kueri audit koreksi SIMAPI hasil D1 — HANYA BACA (SELECT), hanya JUMLAH (tanpa NIP/username/token)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-031-simapi-d1.md §5. Klasifikasi: WAJIB_0 = hasil harus 0; INFO = dilaporkan.
-- Cara pakai (akun baca saja): mysql --table --user=<akun_baca> -p <db_v2> < backend/docs/db-review/sql/DBV-031-audit-simapi-d1.sql
-- =====================================================================================================================

-- C1 [WAJIB_0] Seed simapi_jd: id 1..14 harus ada (hasil = jumlah id yang HILANG).
SELECT 14 - COUNT(*) AS simapi_jd_hilang FROM simapi_jd WHERE id_simapi_jd BETWEEN 1 AND 14;

-- C2 [WAJIB_0] Baris simapi_ch / simapi_update dengan nip kosong.
SELECT COUNT(*) AS simapi_ch_nip_kosong FROM simapi_ch WHERE nip = '';

SELECT COUNT(*) AS simapi_update_nip_kosong FROM simapi_update WHERE nip = '';

-- C3 [WAJIB_0] last_action di luar domain (dijaga CHECK; audit untuk server tanpa CHECK).
SELECT COUNT(*) AS last_action_tidak_sah FROM simapi_update WHERE last_action NOT IN ('INSERT', 'UPDATE', 'DELETE');

-- C4 [INFO] Antrean simapi_update per aksi dan yang belum diambil klien mana pun.
SELECT last_action, COUNT(*) AS baris FROM simapi_update GROUP BY last_action ORDER BY last_action;

SELECT COUNT(*) AS update_belum_dikirim
FROM simapi_update u
WHERE NOT EXISTS (SELECT 1 FROM simapi_update_hist h WHERE h.id_simapi_update = u.id_simapi_update);
