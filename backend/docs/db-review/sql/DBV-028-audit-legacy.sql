-- =====================================================================================================================
-- DBV-028 — Audit data helpdesk Halo Simpeg: SALINAN LEGACY (sebelum impor T12b/MAKE-097) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-028-skema-helpdesk.md Bagian 6. Sumber: PRD Fase 8 v1.3 §7.1, F8-03, F8-21 (H8-6).
-- Pasangan: DBV-028-audit-v2.sql (bagian A + B di DB v2). Isi berkas ini:
--   A. MASTER (Q-H1..Q-H10, Q-H18a..c) — memeriksa data legacy terhadap UNIQUE/CHECK/FK [V2] DBV-028.
--   C. LEGACY SAJA (Q-H17) — memakai kolom legacy `pengguna.id` (`LS:440`). Di v2 dijamin FK `fk_id_user_so_to_pengguna`;
--      baris yang dibuang impor dihitung di laporan impor (K-M12).
--
-- Klasifikasi:
--   WAJIB_0 = harus 0; > 0 di salinan legacy menghentikan impor T12b sampai diputus Horii/DB Validator (konstrain v2 tidak
--             dibersihkan impor).
--   INFO    = dilaporkan; baris dibuang/dicatat oleh impor atau runbook, tidak memblokir.
--   BERHENTI sebelum T12b = dilaporkan; > 0 di salinan legacy menghentikan runbook sebelum T12b sampai diputus Horii/DB
--             Validator (impor sendiri gagal bila panjang melebihi kolom v2, DBV-028 Q2).
--
-- Cara pakai (akun baca saja):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan_legacy> \
--         < backend/docs/db-review/sql/DBV-028-audit-legacy.sql > hasil_audit_dbv028_legacy.txt 2>&1
--
-- LARANGAN: jangan jalankan di DB produksi legacy maupun DB yang sedang melayani aplikasi. Jalankan di SALINAN (dump).
-- Tanpa `--force`: berkas ini hanya berisi kueri yang valid di skemanya, jadi berhenti di galat pertama berarti ada yang salah.
-- Hasil hanya agregat (COUNT), tanpa isi baris (percakapan = data pribadi, PRD Cara pakai 7).
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- A. MASTER (Q-H1..Q-H10, Q-H18a..c) — nama tabel/kolom SAMA di legacy dan v2. Blok A IDENTIK di DBV-028-audit-legacy.sql dan
--    DBV-028-audit-v2.sql (dijaga SkemaHaloSimpegTest::testKueriAuditBerjalan).
-- ---------------------------------------------------------------------------------------------------------------------
-- >>> A MULAI
-- Q-H1 nomor kontak ganda (UNIQUE `uk_help_desk_contact_contact`). Konservatif: TRIM kedua sisi, sedangkan UNIQUE _ci PAD
-- SPACE hanya mengabaikan spasi akhir; impor T12b men-trim `contact` (DBV-028 N4)
SELECT 'Q-H1 WAJIB_0 help_desk_contact.contact ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT TRIM(contact) AS c FROM help_desk_contact GROUP BY TRIM(contact) HAVING COUNT(*) > 1) x;

-- Q-H2 akun petugas ganda (UNIQUE `uk_support_officer_id_user`)
SELECT 'Q-H2 WAJIB_0 support_officer.id_user ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_user FROM support_officer GROUP BY id_user HAVING COUNT(*) > 1) x;

-- Q-H3 pasangan petugas-kategori ganda (UNIQUE `uk_officer_category`)
SELECT 'Q-H3 WAJIB_0 officer_category pasangan ganda' AS butir, COUNT(*) AS jumlah
FROM (SELECT id_support_officer, id_question_category FROM officer_category
      GROUP BY id_support_officer, id_question_category HAVING COUNT(*) > 1) x;

-- Q-H4 officer_category yatim ke support_officer. Aksi FK legacy [I] (DDL tidak ada; `delete_officer` LS:711 tidak menyentuh
-- officer_category) → INFO, dibuang + dicatat impor T12b, simetris dengan Q-H5 (usulan DBV-028 Q9)
SELECT 'Q-H4 INFO officer_category tanpa support_officer' AS butir, COUNT(*) AS jumlah
FROM officer_category oc
WHERE NOT EXISTS (SELECT 1 FROM support_officer so WHERE so.id_support_officer = oc.id_support_officer);

-- Q-H5 officer_category yatim ke question_category (legacy hapus kategori tanpa cek, B-12) → dibuang impor T12b
SELECT 'Q-H5 INFO officer_category tanpa question_category' AS butir, COUNT(*) AS jumlah
FROM officer_category oc
WHERE NOT EXISTS (SELECT 1 FROM question_category qc WHERE qc.id_question_category = oc.id_question_category);

-- Q-H6 status petugas di luar domain 0/1 (tipe kolom legacy [I]; nilai lain → berkas keputusan MAKE-097)
SELECT 'Q-H6 INFO support_officer.status di luar 0/1' AS butir, COUNT(*) AS jumlah
FROM support_officer WHERE status IS NULL OR TRIM(status) NOT IN ('0', '1');

-- Q-H7 status kontak di luar domain 0/1
SELECT 'Q-H7 INFO help_desk_contact.status di luar 0/1' AS butir, COUNT(*) AS jumlah
FROM help_desk_contact WHERE status IS NULL OR TRIM(status) NOT IN ('0', '1');

-- Q-H8 nama kategori kembar (trim, abaikan huruf besar/kecil) — unik nama ditegakkan aplikasi (DBV-028 Q3)
SELECT 'Q-H8 INFO question_category nama kembar' AS butir, COUNT(*) AS jumlah
FROM (SELECT LOWER(TRIM(question_category)) AS n FROM question_category GROUP BY LOWER(TRIM(question_category))
      HAVING COUNT(*) > 1) x;

-- Q-H9 BERHENTI sebelum T12b (DBV-028 Q2): panjang melebihi kolom v2 [I] (email 150, no_hp 20, kontak name 150 / contact 20 / email 150). Hasil > 0 di
-- salinan legacy diputus sebelum impor T12b (DBV-028 Q2)
SELECT 'Q-H9 BERHENTI sebelum T12b (DBV-028 Q2) panjang melebihi kolom v2' AS butir,
       (SELECT COUNT(*) FROM support_officer WHERE CHAR_LENGTH(email) > 150 OR CHAR_LENGTH(no_hp) > 20)
     + (SELECT COUNT(*) FROM help_desk_contact WHERE CHAR_LENGTH(name) > 150 OR CHAR_LENGTH(contact) > 20 OR CHAR_LENGTH(email) > 150) AS jumlah;

-- Q-H10 email petugas kosong (wajib di form legacy LS:756; kolom v2 NOT NULL)
SELECT 'Q-H10 INFO support_officer.email kosong' AS butir, COUNT(*) AS jumlah
FROM support_officer WHERE email IS NULL OR TRIM(email) = '';

-- Q-H18a..c stempel created_at master kosong/nol per tabel (legacy mengisi lewat DEFAULT DB [I]; impor T12b kelas R1a,
-- DBV-028 N2)
SELECT 'Q-H18a INFO help_desk_contact.created_at NULL/nol' AS butir, COUNT(*) AS jumlah
FROM help_desk_contact WHERE created_at IS NULL OR CAST(created_at AS CHAR) LIKE '0000-00-00%';

SELECT 'Q-H18b INFO support_officer.created_at NULL/nol' AS butir, COUNT(*) AS jumlah
FROM support_officer WHERE created_at IS NULL OR CAST(created_at AS CHAR) LIKE '0000-00-00%';

SELECT 'Q-H18c INFO officer_category.created_at NULL/nol' AS butir, COUNT(*) AS jumlah
FROM officer_category WHERE created_at IS NULL OR CAST(created_at AS CHAR) LIKE '0000-00-00%';
-- <<< A SELESAI


-- ---------------------------------------------------------------------------------------------------------------------
-- C. LEGACY SAJA
-- ---------------------------------------------------------------------------------------------------------------------

-- Q-H17 akun petugas yang tidak ada di `pengguna` legacy (impor T12b membuang + mencatat, K-M12; PRD v1.3 §7.1 pra-cek)
SELECT 'Q-H17 INFO support_officer.id_user tanpa pengguna' AS butir, COUNT(*) AS jumlah
FROM support_officer so
WHERE NOT EXISTS (SELECT 1 FROM pengguna p WHERE p.id = so.id_user);
