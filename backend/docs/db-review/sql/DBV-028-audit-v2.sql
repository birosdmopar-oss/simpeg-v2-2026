-- =====================================================================================================================
-- DBV-028 — Audit data helpdesk Halo Simpeg: DB V2 (sesudah impor F-02 + T12b, gladi cutover) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-028-skema-helpdesk.md Bagian 6. Sumber: PRD Fase 8 v1.3 §7.1, F8-03, F8-21 (H8-6),
-- AC-E04.11, AC-E05.7/8; laporan A0 Handling Case §R B6, B7, C4.
-- Pasangan: DBV-028-audit-legacy.sql (bagian A + C di salinan legacy). Isi berkas ini:
--   A. MASTER (Q-H1..Q-H10, Q-H18a..c) — sama persis dengan berkas legacy.
--   B. TIKET V2 PASCA IMPOR (Q-H11..Q-H16, Q-H19) — memakai `disiplin_ticket(_msg)`/`pengguna` v2; bahan runbook MAKE-074
--      (H8-16 (a1), Q-A0-6 (a')).
-- Berkas ini dijalankan test SkemaHaloSimpegTest::testKueriAuditBerjalan di skema v2 dengan data uji kecil (Q-H13, Q-H15,
-- Q-H19).
--
-- Klasifikasi:
--   WAJIB_0 = harus 0. Di v2 butir WAJIB_0 (blok A) dijamin konstrain UNIQUE DBV-028; > 0 di DB gladi berarti impor atau
--             skema menyimpang dan diputus Horii/DB Validator sebelum cutover.
--   INFO    = dilaporkan; baris dibuang/dicatat oleh impor atau runbook, tidak memblokir.
--   BERHENTI sebelum T12b = dilaporkan; > 0 di salinan legacy menghentikan runbook sebelum T12b sampai diputus Horii/DB
--             Validator (impor sendiri gagal bila panjang melebihi kolom v2, DBV-028 Q2).
--
-- Cara pakai (akun baca saja):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2_gladi> \
--         < backend/docs/db-review/sql/DBV-028-audit-v2.sql > hasil_audit_dbv028_v2.txt 2>&1
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
-- B. TIKET V2 PASCA IMPOR (F-02 + T12b)
-- ---------------------------------------------------------------------------------------------------------------------

-- Q-H11 tiket berkategori NULL (H8-16 (a1): hanya pemilik yang melihat/menutup)
SELECT 'Q-H11 INFO disiplin_ticket kategori NULL' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket WHERE id_question_category IS NULL;

-- Q-H12 irisan kategori NULL + Open + pengirim yatim (tidak terlihat siapa pun; dicatat di runbook, review A0 B6)
SELECT 'Q-H12 INFO tiket NULL Open berpengirim yatim' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket t
WHERE t.id_question_category IS NULL AND t.status = 0
  AND NOT EXISTS (SELECT 1 FROM pengguna p WHERE p.id_pengguna = t.sender);

-- Q-H13 lampiran lama ber-path di luar aturan tulis ketat adapter, termasuk segmen `.`/`..` yang ditolak
-- `LocalStorageAdapter` (berkas ini tidak dihapus fisik saat hapus tiket, Q-A0-6 (a'), B7)
SELECT 'Q-H13 INFO lampiran path di luar aturan ketat' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket_msg
WHERE attachments IS NOT NULL AND attachments <> ''
  AND (attachments NOT REGEXP '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$'
       OR CONCAT('/', attachments, '/') LIKE '%/./%'
       OR CONCAT('/', attachments, '/') LIKE '%/../%');

-- Q-H14 pembanding Q-H13: semua lampiran tidak kosong
SELECT 'Q-H14 INFO lampiran tidak kosong' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket_msg WHERE attachments IS NOT NULL AND attachments <> '';

-- Q-H15 tiket Open di kategori tanpa petugas aktif = petugas status 1 DAN akun aktif (F8-06, LS:1035; review A0 C4)
SELECT 'Q-H15 INFO tiket Open di kategori tanpa petugas aktif' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket t
WHERE t.status = 0 AND t.id_question_category IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM officer_category oc
                  JOIN support_officer so ON so.id_support_officer = oc.id_support_officer AND so.status = 1
                  JOIN pengguna p ON p.id_pengguna = so.id_user AND p.status = '1' AND p.deleted_at IS NULL
                  WHERE oc.id_question_category = t.id_question_category);

-- Q-H16 tiket tanpa pesan (ditampilkan di akhir daftar, review A0 B3)
SELECT 'Q-H16 INFO tiket tanpa pesan' AS butir, COUNT(*) AS jumlah
FROM disiplin_ticket t
WHERE NOT EXISTS (SELECT 1 FROM disiplin_ticket_msg m WHERE m.id_inbox = t.id_inbox);

-- Q-H19 tiket terbanyak yang terlihat oleh satu petugas aktif = petugas status 1 DAN akun aktif (definisi sama dengan Q-H15).
-- Pemicu terukur kolom `last_msg_at` (DBV-028 Q8 / Bagian 4.3). Tiket milik petugas sendiri tidak dikecualikan, jadi angka ini
-- batas atas (konservatif).
SELECT 'Q-H19 INFO tiket maksimum per petugas aktif' AS butir, COALESCE(MAX(x.n), 0) AS jumlah
FROM (SELECT so.id_support_officer, COUNT(*) AS n
      FROM support_officer so
      JOIN pengguna p ON p.id_pengguna = so.id_user AND p.status = '1' AND p.deleted_at IS NULL
      JOIN officer_category oc ON oc.id_support_officer = so.id_support_officer
      JOIN disiplin_ticket t ON t.id_question_category = oc.id_question_category
      WHERE so.status = 1
      GROUP BY so.id_support_officer) x;
