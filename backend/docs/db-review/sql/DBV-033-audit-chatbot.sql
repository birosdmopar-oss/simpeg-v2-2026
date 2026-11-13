-- =====================================================================================================================
-- DBV-033 — Audit data chatbot "Vanya" (qna, qna_kategori, qna_tag, qna_has_tag, log_chatbot) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-033-skema-chatbot.md Bagian 6. Sumber: PRD Fase 8 v1.1 §7.10.1, F8-40, F8-46.
--
-- Tujuan: membuktikan data sumber (DB dev legacy, sebelum impor isi C4) memenuhi syarat konstrain [V2] DBV-033, atau
-- melaporkan baris yang harus dibersihkan/digabung oleh perintah impor:
--   - UNIQUE (qna_id, qna_tag_id): relasi ganda;
--   - judul kategori/tag kembar (trim + abaikan huruf besar/kecil) di antara baris yang akan dibawa (K-12: digabung);
--   - relasi yatim (QnA/tag tidak ada), QnA tanpa kategori (F8-46), QnA dengan kategori yang tidak ada;
--   - domain nilai yang dijaga CHECK: status, tampil_bubble, is_fallback_option, status_match, feedback, skor 0..1.
-- Kolom yang dipakai ada di DDL dev DAN skema v2, sehingga berkas yang sama juga berjalan di skema v2 (test skema).
-- Di skema v2 butir WAJIB_0 selalu 0 karena konstrain sudah terpasang.
--
-- Klasifikasi (arti sama dengan DBV-011 2.3 / DBV-032):
--   WAJIB_0 = harus 0 sesudah pembersihan impor; > 0 = baris dilaporkan dan dibuang/digabung oleh impor C4.
--   INFO    = dilaporkan untuk keputusan DB Validator / aturan impor, tidak memblokir.
-- Setiap kueri mengembalikan dua kolom: butir, jumlah.
-- =====================================================================================================================

-- [A1] WAJIB_0 relasi QnA-tag ganda (UNIQUE / PK komposit v2). D1 dev: 1.
SELECT 'A1 WAJIB_0 relasi qna_has_tag ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT qna_id, qna_tag_id FROM qna_has_tag GROUP BY qna_id, qna_tag_id HAVING COUNT(*) > 1) g;

-- [A2] WAJIB_0 relasi yatim ke QnA.
SELECT 'A2 WAJIB_0 qna_has_tag tanpa qna' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h LEFT JOIN qna q ON q.id_qna = h.qna_id WHERE q.id_qna IS NULL;

-- [A3] WAJIB_0 relasi yatim ke tag.
SELECT 'A3 WAJIB_0 qna_has_tag tanpa tag' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h LEFT JOIN qna_tag t ON t.id_qna_tag = h.qna_tag_id WHERE t.id_qna_tag IS NULL;

-- [A4] INFO QnA tanpa kategori (F8-46: dilaporkan, tidak dibawa). D1 dev: 0.
SELECT 'A4 INFO qna tanpa kategori' AS butir, COUNT(*) AS jumlah FROM qna WHERE id_qna_kategori_fk IS NULL;

-- [A5] WAJIB_0 QnA dengan kategori yang tidak ada.
SELECT 'A5 WAJIB_0 qna kategori tidak ada' AS butir, COUNT(*) AS jumlah
FROM qna q LEFT JOIN qna_kategori k ON k.id_qna_kategori = q.id_qna_kategori_fk
WHERE q.id_qna_kategori_fk IS NOT NULL AND k.id_qna_kategori IS NULL;

-- [A6] INFO judul kategori kembar (trim, abaikan huruf besar/kecil) di antara semua baris dev; K-12 digabung. D1 dev: 2.
SELECT 'A6 INFO judul kategori kembar' AS butir, COUNT(*) AS jumlah
FROM (SELECT LOWER(TRIM(judul_kategori)) j FROM qna_kategori GROUP BY LOWER(TRIM(judul_kategori)) HAVING COUNT(*) > 1) g;

-- [A7] INFO judul tag kembar; K-12 digabung. D1 dev: 11.
SELECT 'A7 INFO judul tag kembar' AS butir, COUNT(*) AS jumlah
FROM (SELECT LOWER(TRIM(judul_tag)) j FROM qna_tag GROUP BY LOWER(TRIM(judul_tag)) HAVING COUNT(*) > 1) g;

-- [A8] INFO judul kategori/tag berspasi tepi (di-trim impor; judul_unik v2 membandingkan nilai tersimpan).
SELECT 'A8 INFO judul berspasi tepi' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE judul_kategori <> TRIM(judul_kategori))
     + (SELECT COUNT(*) FROM qna_tag WHERE judul_tag <> TRIM(judul_tag)) AS jumlah;

-- [A9] WAJIB_0 domain status di luar 1/2/10 (dev 1/2; impor memetakan sesuai F8-46).
SELECT 'A9 WAJIB_0 status di luar 1/2/10' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE status_qna_kategori_fk NOT IN (1, 2, 10))
     + (SELECT COUNT(*) FROM qna_tag WHERE status_qna_tag_fk NOT IN (1, 2, 10))
     + (SELECT COUNT(*) FROM qna WHERE status_qna_fk NOT IN (1, 2, 10)) AS jumlah;

-- [A10] WAJIB_0 flag di luar 0/1.
SELECT 'A10 WAJIB_0 tampil_bubble/is_fallback_option di luar 0/1' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE tampil_bubble NOT IN (0, 1))
     + (SELECT COUNT(*) FROM qna WHERE is_fallback_option NOT IN (0, 1)) AS jumlah;

-- [A11] INFO QnA aktif di kategori nonaktif (tetap ikut korpus, F8-39; kategori dibawa sebagai status 2, F8-46).
SELECT 'A11 INFO qna aktif di kategori nonaktif' AS butir, COUNT(*) AS jumlah
FROM qna q JOIN qna_kategori k ON k.id_qna_kategori = q.id_qna_kategori_fk
WHERE q.status_qna_fk = 1 AND k.status_qna_kategori_fk <> 1;

-- [A12] INFO relasi QnA aktif ke tag nonaktif (tag dibawa sebagai status 2, F8-46; tidak menambah skor, F8-39).
SELECT 'A12 INFO relasi qna aktif ke tag nonaktif' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h JOIN qna q ON q.id_qna = h.qna_id JOIN qna_tag t ON t.id_qna_tag = h.qna_tag_id
WHERE q.status_qna_fk = 1 AND t.status_qna_tag_fk <> 1;

-- [A13] INFO log di luar domain v2 (log tidak dimigrasi; hanya pembanding). status_match NULL / feedback bukan -1/1 / skor di luar 0..1.
SELECT 'A13 INFO log di luar domain v2' AS butir, COUNT(*) AS jumlah
FROM log_chatbot
WHERE status_match IS NULL OR status_match NOT IN (0, 1)
   OR (feedback IS NOT NULL AND feedback NOT IN (-1, 1))
   OR top_1_skor < 0 OR top_1_skor > 1;
