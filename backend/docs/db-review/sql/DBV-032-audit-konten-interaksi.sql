-- =====================================================================================================================
-- DBV-032 — Audit data konten & interaksi Fase 7 (popup, banner, berita, DIBO, form digital, SKL) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-032-konten-interaksi-fase7.md Bagian 6. Sumber: PRD Fase 7 v2.0 §5.1.
--
-- Tujuan: membuktikan data legacy memenuhi syarat konstrain [V2] DBV-032 sebelum skema dipasang dan sebelum impor
-- (tiket impor Q7-24):
--   - UNIQUE (id_popup,nip), (id_banner,nip), (id_form_digital,nip) x2, skl (nip,jenis_rwy,id_rwy), news_flag
--     (id_user,id_news): ganda harus 0;
--   - FK anak -> induk dan FK NIP: yatim dilaporkan (dibuang + dicatat saat impor, bukan memblokir skema);
--   - domain nilai yang dijaga CHECK: skl.rate / skl.jenis_rwy 1-5, news.status 0-2, news.target 1-3, periode;
--   - panjang NIP > 30 (kolom NIP ber-FK VARCHAR(30), V4).
-- Struktur, jumlah baris, indeks, FK, dan sebaran kolom yang hanya ada di legacy (popup.target/type, dsb.) diambil oleh
-- kueri D1 Fase 7 milik Horii (G7-2), bukan oleh berkas ini.
--
-- Klasifikasi (arti sama dengan DBV-011 2.3 / DBV-022):
--   WAJIB_0 = harus 0 sebelum UNIQUE/CHECK dipasang atau impor dibuka; > 0 menghentikan langkah itu.
--   INFO    = dilaporkan untuk keputusan DB Validator / aturan impor, tidak memblokir.
--
-- Hanya kolom yang ada di legacy DAN di skema v2 DBV-032 yang dipakai, supaya berkas yang sama juga berjalan di skema v2
-- (test SkemaKontenInteraksiFase7Test::testKueriAuditBerjalan). Hasil hanya agregat (COUNT), tanpa isi baris.
--
-- Cara pakai (akun baca saja; tanpa --force agar error langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan_legacy> \
--         < backend/docs/db-review/sql/DBV-032-audit-konten-interaksi.sql > hasil_audit_dbv032.txt 2>&1
--
-- LARANGAN: jangan jalankan di DB produksi legacy yang sedang melayani aplikasi, dan jangan di DB dev v2. Jalankan di
-- SALINAN (dump freeze / gladi). Skrip ini hanya berisi SELECT.
-- Collation: bila salah satu tabel legacy ber-collation utf8mb4_0900_ai_ci (lihat D1), tambahkan
-- `COLLATE utf8mb4_unicode_ci` di sisi kolom legacy pada join NIP (pola DBV-022).
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 1 — WAJIB_0: ganda pada kunci UNIQUE [V2].
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'WAJIB_0 user_popup ganda (id_popup,nip)' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_popup, nip FROM user_popup GROUP BY id_popup, nip HAVING COUNT(*) > 1) g;

SELECT 'WAJIB_0 user_banner ganda (id_banner,nip)' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_banner, nip FROM user_banner GROUP BY id_banner, nip HAVING COUNT(*) > 1) g;

SELECT 'WAJIB_0 user_form_digital ganda (id_form_digital,nip)' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_form_digital, nip FROM user_form_digital GROUP BY id_form_digital, nip HAVING COUNT(*) > 1) g;

SELECT 'WAJIB_0 filled_form_digital ganda (id_form_digital,nip)' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_form_digital, nip FROM filled_form_digital GROUP BY id_form_digital, nip HAVING COUNT(*) > 1) g;

SELECT 'WAJIB_0 skl ganda (nip,jenis_rwy,id_rwy)' AS butir, COUNT(*) AS jumlah
FROM (SELECT nip, jenis_rwy, id_rwy FROM skl GROUP BY nip, jenis_rwy, id_rwy HAVING COUNT(*) > 1) g;

SELECT 'WAJIB_0 news_flag ganda (id_user,id_news)' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_user, id_news FROM news_flag GROUP BY id_user, id_news HAVING COUNT(*) > 1) g;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 2 — WAJIB_0: domain nilai CHECK dan NIP.
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'WAJIB_0 skl rate di luar 1-5' AS butir, COUNT(*) AS jumlah FROM skl WHERE rate IS NULL OR rate NOT BETWEEN 1 AND 5;

SELECT 'WAJIB_0 skl jenis_rwy di luar 1-5' AS butir, COUNT(*) AS jumlah FROM skl WHERE jenis_rwy IS NULL OR jenis_rwy NOT BETWEEN 1 AND 5;

SELECT 'WAJIB_0 news status di luar 0-2' AS butir, COUNT(*) AS jumlah FROM news WHERE status IS NULL OR status NOT IN (0, 1, 2);

SELECT 'WAJIB_0 news target di luar 1-3' AS butir, COUNT(*) AS jumlah FROM news WHERE target IS NULL OR target NOT IN (1, 2, 3);

SELECT 'WAJIB_0 news_category allow_ap di luar 0-1' AS butir, COUNT(*) AS jumlah FROM news_category WHERE allow_ap IS NULL OR allow_ap NOT IN (0, 1);

SELECT 'WAJIB_0 periode terbalik popup' AS butir, COUNT(*) AS jumlah FROM popup WHERE tgl_akhir < tgl_mulai;

SELECT 'WAJIB_0 periode terbalik banner' AS butir, COUNT(*) AS jumlah FROM banner WHERE tgl_akhir < tgl_mulai;

SELECT 'WAJIB_0 periode terbalik form_digital' AS butir, COUNT(*) AS jumlah FROM form_digital WHERE tgl_akhir < tgl_mulai;

SELECT 'WAJIB_0 NIP kosong/panjang > 30 (5 tabel)' AS butir, SUM(n) AS jumlah FROM (
    SELECT COUNT(*) AS n FROM user_popup WHERE nip IS NULL OR nip = '' OR CHAR_LENGTH(nip) > 30
    UNION ALL SELECT COUNT(*) FROM user_banner WHERE nip IS NULL OR nip = '' OR CHAR_LENGTH(nip) > 30
    UNION ALL SELECT COUNT(*) FROM user_form_digital WHERE nip IS NULL OR nip = '' OR CHAR_LENGTH(nip) > 30
    UNION ALL SELECT COUNT(*) FROM filled_form_digital WHERE nip IS NULL OR nip = '' OR CHAR_LENGTH(nip) > 30
    UNION ALL SELECT COUNT(*) FROM skl WHERE nip IS NULL OR nip = '' OR CHAR_LENGTH(nip) > 30
) t;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 3 — INFO: yatim (FK anak -> induk, FK NIP). Dibuang + dicatat saat impor (aturan tiket impor Q7-24).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'INFO user_popup tanpa popup' AS butir, COUNT(*) AS jumlah
FROM user_popup a LEFT JOIN popup p ON p.id_popup = a.id_popup WHERE p.id_popup IS NULL;

SELECT 'INFO user_banner tanpa banner' AS butir, COUNT(*) AS jumlah
FROM user_banner a LEFT JOIN banner b ON b.id_banner = a.id_banner WHERE b.id_banner IS NULL;

SELECT 'INFO user_form_digital tanpa form' AS butir, COUNT(*) AS jumlah
FROM user_form_digital a LEFT JOIN form_digital f ON f.id_form_digital = a.id_form_digital WHERE f.id_form_digital IS NULL;

SELECT 'INFO filled_form_digital tanpa form' AS butir, COUNT(*) AS jumlah
FROM filled_form_digital a LEFT JOIN form_digital f ON f.id_form_digital = a.id_form_digital WHERE f.id_form_digital IS NULL;

SELECT 'INFO news tanpa kategori' AS butir, COUNT(*) AS jumlah
FROM news n LEFT JOIN news_category c ON c.id_news_category = n.id_news_category WHERE c.id_news_category IS NULL;

SELECT 'INFO news_flag tanpa news' AS butir, COUNT(*) AS jumlah
FROM news_flag f LEFT JOIN news n ON n.id_news = f.id_news WHERE n.id_news IS NULL;

SELECT 'INFO dibo tanpa jenis' AS butir, COUNT(*) AS jumlah
FROM dibo d LEFT JOIN jenis_dibo j ON j.id_jenis_dibo = d.id_jenis_dibo WHERE j.id_jenis_dibo IS NULL;

SELECT 'INFO NIP yatim terhadap pegawai (5 tabel)' AS butir, SUM(n) AS jumlah FROM (
    SELECT COUNT(*) AS n FROM user_popup a LEFT JOIN pegawai p ON p.nip = a.nip WHERE p.nip IS NULL
    UNION ALL SELECT COUNT(*) FROM user_banner a LEFT JOIN pegawai p ON p.nip = a.nip WHERE p.nip IS NULL
    UNION ALL SELECT COUNT(*) FROM user_form_digital a LEFT JOIN pegawai p ON p.nip = a.nip WHERE p.nip IS NULL
    UNION ALL SELECT COUNT(*) FROM filled_form_digital a LEFT JOIN pegawai p ON p.nip = a.nip WHERE p.nip IS NULL
    UNION ALL SELECT COUNT(*) FROM skl a LEFT JOIN pegawai p ON p.nip = a.nip WHERE p.nip IS NULL
) t;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 4 — INFO: bahan aturan impor & keputusan DBV.
-- ---------------------------------------------------------------------------------------------------------------------
-- filled tanpa target: legacy menghapus user_form_digital saat submit (Announcement.php:584); v2 target permanen
-- (Q7-20) -> impor menambah baris target dari filled_form_digital.
SELECT 'INFO filled_form_digital tanpa baris target' AS butir, COUNT(*) AS jumlah
FROM filled_form_digital a
LEFT JOIN user_form_digital u ON u.id_form_digital = a.id_form_digital AND u.nip = a.nip WHERE u.nip IS NULL;

-- SKL yang menunjuk riwayat yang tidak ada (id_rwy polimorfik tanpa FK).
SELECT 'INFO skl id_rwy tanpa riwayat' AS butir, SUM(n) AS jumlah FROM (
    SELECT COUNT(*) AS n FROM skl s LEFT JOIN riwayat_cuti r ON r.id_riwayat_cuti = s.id_rwy WHERE s.jenis_rwy = 1 AND r.id_riwayat_cuti IS NULL
    UNION ALL SELECT COUNT(*) FROM skl s LEFT JOIN riwayat_kariskarsu r ON r.id_riwayat_kariskarsu = s.id_rwy WHERE s.jenis_rwy = 2 AND r.id_riwayat_kariskarsu IS NULL
    UNION ALL SELECT COUNT(*) FROM skl s LEFT JOIN riwayat_karpeg r ON r.id_riwayat_karpeg = s.id_rwy WHERE s.jenis_rwy = 3 AND r.id_riwayat_karpeg IS NULL
    UNION ALL SELECT COUNT(*) FROM skl s LEFT JOIN riwayat_tb r ON r.id_riwayat_tb = s.id_rwy WHERE s.jenis_rwy = 4 AND r.id_riwayat_tb IS NULL
    UNION ALL SELECT COUNT(*) FROM skl s LEFT JOIN riwayat_ib r ON r.id_riwayat_ib = s.id_rwy WHERE s.jenis_rwy = 5 AND r.id_riwayat_ib IS NULL
) t;

-- Kategori aktif bernama sama (aplikasi legacy menolak, DB tidak menjaga; L_news.php:1326).
SELECT 'INFO news_category nama ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT category FROM news_category GROUP BY category HAVING COUNT(*) > 1) g;
