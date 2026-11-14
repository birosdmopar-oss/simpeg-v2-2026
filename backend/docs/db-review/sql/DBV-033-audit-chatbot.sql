-- =====================================================================================================================
-- DBV-033 — Audit data chatbot "Vanya" (qna, qna_kategori, qna_tag, qna_has_tag, log_chatbot) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-033-skema-chatbot.md Bagian 6. Sumber: PRD Fase 8 v1.1 §7.10.1, F8-40, F8-46.
--
-- Tujuan: memeriksa data sumber (salinan DB dev chatbot, sebelum impor isi C4) terhadap konstrain [V2] DBV-033 dan
-- melaporkan baris yang dibersihkan/digabung oleh perintah impor (F8-46, K-12, K-13):
--   - relasi QnA-tag ganda (PK komposit v2), relasi yatim, QnA tanpa kategori / kategori tidak ada;
--   - judul kategori/tag kembar (trim + abaikan huruf besar/kecil) di antara baris yang AKAN DIBAWA, yaitu status 1, atau
--     status 2 yang masih dipakai QnA status 1 (F8-46);
--   - domain nilai yang dijaga CHECK: status 1/2/10, tampil_bubble, is_fallback_option.
-- Kolom yang dipakai ada di DDL dev DAN skema v2, sehingga berkas yang sama juga berjalan di skema v2
-- (test SkemaChatbotTest::testKueriAuditBerjalan). Hasil hanya agregat (COUNT), tanpa isi baris.
--
-- Klasifikasi:
--   WAJIB_0 = harus 0 di sumber; > 0 menghentikan impor C4 sampai diputus Horii/DB Validator. Hanya dipakai untuk butir
--             yang dijamin konstrain dev (FK, domain status dev 1/2) atau konstrain v2 yang tidak dibersihkan impor.
--   INFO    = dilaporkan; baris dibuang, digabung, atau tidak dibawa oleh impor C4 (F8-46), tidak memblokir.
-- Kode kueri Q-A1..Q-A13 (berbeda dari kode anomali A1..A16 di PRD Lampiran B.2).
--
-- Cara pakai (akun baca saja; tanpa --force agar error langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan_dev> \
--         < backend/docs/db-review/sql/DBV-033-audit-chatbot.sql > hasil_audit_dbv033.txt 2>&1
--
-- LARANGAN: jangan jalankan di DB produksi legacy maupun DB dev yang sedang melayani aplikasi, dan jangan di DB dev v2.
-- Jalankan di SALINAN DB dev (dump). Skrip ini hanya berisi SELECT. Hasil (angka) boleh dilaporkan; isi QnA tidak.
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 1 — relasi dan kategori.
-- ---------------------------------------------------------------------------------------------------------------------
-- [Q-A1] INFO relasi QnA-tag ganda (PK komposit v2): dibuang + dilaporkan saat impor (F8-46). D1 dev: 1.
SELECT 'Q-A1 INFO relasi qna_has_tag ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT qna_id, qna_tag_id FROM qna_has_tag GROUP BY qna_id, qna_tag_id HAVING COUNT(*) > 1) g;

-- [Q-A2] WAJIB_0 relasi tanpa QnA (dev FK CASCADE menjamin 0).
SELECT 'Q-A2 WAJIB_0 qna_has_tag tanpa qna' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h LEFT JOIN qna q ON q.id_qna = h.qna_id WHERE q.id_qna IS NULL;

-- [Q-A3] WAJIB_0 relasi tanpa tag (dev FK CASCADE menjamin 0).
SELECT 'Q-A3 WAJIB_0 qna_has_tag tanpa tag' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h LEFT JOIN qna_tag t ON t.id_qna_tag = h.qna_tag_id WHERE t.id_qna_tag IS NULL;

-- [Q-A4] INFO QnA status 1 tanpa kategori: dilaporkan, tidak dibawa (F8-46). D1 dev: 0.
SELECT 'Q-A4 INFO qna aktif tanpa kategori' AS butir, COUNT(*) AS jumlah
FROM qna WHERE id_qna_kategori_fk IS NULL AND status_qna_fk = 1;

-- [Q-A5] WAJIB_0 QnA dengan kategori yang tidak ada (dev FK menjamin 0).
SELECT 'Q-A5 WAJIB_0 qna kategori tidak ada' AS butir, COUNT(*) AS jumlah
FROM qna q LEFT JOIN qna_kategori k ON k.id_qna_kategori = q.id_qna_kategori_fk
WHERE q.id_qna_kategori_fk IS NOT NULL AND k.id_qna_kategori IS NULL;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 2 — judul kategori/tag di antara baris yang akan dibawa (K-12: digabung; K-13: uji/nonaktif tak dipakai tidak dibawa).
-- ---------------------------------------------------------------------------------------------------------------------
-- [Q-A6] INFO judul kategori kembar di antara kategori yang dibawa. D1 dev: 1 ("Tunjangan Kinerja"; pasangan
-- "Syarat dan Hak Pensiun" selesai oleh K-13 karena baris keduanya nonaktif tanpa QnA).
SELECT 'Q-A6 INFO judul kategori kembar (dibawa)' AS butir, COUNT(*) AS jumlah
FROM (SELECT LOWER(TRIM(k.judul_kategori)) j
      FROM qna_kategori k
      WHERE k.status_qna_kategori_fk = 1
         OR (k.status_qna_kategori_fk = 2
             AND EXISTS (SELECT 1 FROM qna q WHERE q.id_qna_kategori_fk = k.id_qna_kategori AND q.status_qna_fk = 1))
      GROUP BY LOWER(TRIM(k.judul_kategori)) HAVING COUNT(*) > 1) g;

-- [Q-A7] INFO judul tag kembar di antara tag yang dibawa. D1 dev (semua status): 11.
SELECT 'Q-A7 INFO judul tag kembar (dibawa)' AS butir, COUNT(*) AS jumlah
FROM (SELECT LOWER(TRIM(t.judul_tag)) j
      FROM qna_tag t
      WHERE t.status_qna_tag_fk = 1
         OR (t.status_qna_tag_fk = 2
             AND EXISTS (SELECT 1 FROM qna_has_tag h JOIN qna q ON q.id_qna = h.qna_id
                         WHERE h.qna_tag_id = t.id_qna_tag AND q.status_qna_fk = 1))
      GROUP BY LOWER(TRIM(t.judul_tag)) HAVING COUNT(*) > 1) g;

-- [Q-A8] INFO judul berspasi tepi (di-trim impor). utf8mb4_unicode_ci = PAD SPACE, sehingga perbandingan '<>' dengan
-- TRIM() tidak mendeteksi spasi di akhir; dipakai CHAR_LENGTH.
SELECT 'Q-A8 INFO judul berspasi tepi' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE CHAR_LENGTH(judul_kategori) <> CHAR_LENGTH(TRIM(judul_kategori)))
     + (SELECT COUNT(*) FROM qna_tag WHERE CHAR_LENGTH(judul_tag) <> CHAR_LENGTH(TRIM(judul_tag))) AS jumlah;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 3 — domain nilai CHECK.
-- ---------------------------------------------------------------------------------------------------------------------
-- [Q-A9] WAJIB_0 status di luar 1/2/10 (dev hanya 1/2, FK ke qna_status).
SELECT 'Q-A9 WAJIB_0 status di luar 1/2/10' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE status_qna_kategori_fk NOT IN (1, 2, 10))
     + (SELECT COUNT(*) FROM qna_tag WHERE status_qna_tag_fk NOT IN (1, 2, 10))
     + (SELECT COUNT(*) FROM qna WHERE status_qna_fk NOT IN (1, 2, 10)) AS jumlah;

-- [Q-A10] WAJIB_0 flag di luar 0/1.
SELECT 'Q-A10 WAJIB_0 tampil_bubble/is_fallback_option di luar 0/1' AS butir,
       (SELECT COUNT(*) FROM qna_kategori WHERE tampil_bubble NOT IN (0, 1))
     + (SELECT COUNT(*) FROM qna WHERE is_fallback_option NOT IN (0, 1)) AS jumlah;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 4 — induk nonaktif dan log (INFO).
-- ---------------------------------------------------------------------------------------------------------------------
-- [Q-A11] INFO QnA aktif di kategori nonaktif (tetap ikut korpus, F8-39; kategori dibawa sebagai status 2, F8-46).
SELECT 'Q-A11 INFO qna aktif di kategori nonaktif' AS butir, COUNT(*) AS jumlah
FROM qna q JOIN qna_kategori k ON k.id_qna_kategori = q.id_qna_kategori_fk
WHERE q.status_qna_fk = 1 AND k.status_qna_kategori_fk <> 1;

-- [Q-A12] INFO relasi QnA aktif ke tag nonaktif (tag dibawa sebagai status 2, F8-46; tidak menambah skor, F8-39).
SELECT 'Q-A12 INFO relasi qna aktif ke tag nonaktif' AS butir, COUNT(*) AS jumlah
FROM qna_has_tag h JOIN qna q ON q.id_qna = h.qna_id JOIN qna_tag t ON t.id_qna_tag = h.qna_tag_id
WHERE q.status_qna_fk = 1 AND t.status_qna_tag_fk <> 1;

-- [Q-A13] INFO log di luar domain v2 (log dev tidak dimigrasi; hanya pembanding).
SELECT 'Q-A13 INFO log di luar domain v2' AS butir, COUNT(*) AS jumlah
FROM log_chatbot
WHERE status_match IS NULL OR status_match NOT IN (0, 1)
   OR (feedback IS NOT NULL AND feedback NOT IN (-1, 1))
   OR top_1_skor < 0 OR top_1_skor > 1;
