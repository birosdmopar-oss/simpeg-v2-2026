-- =====================================================================================================================
-- DBV-011 / ISSUE-009 — Audit data master legacy sebelum impor ke SIMPEG v2 (HANYA BACA)
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md (Bagian 2). Skrip ini langkah wajib
-- runbook impor: syarat approval DBV-005 (G-06 6.5), G-01 8.4 #2, G-04/G-05 6.3 #4-#5, G-07/G-08 6.3 #2-#6,
-- G-10 6.3 #5/#10, A-01 9.8 langkah 6, 9.10. Revisi putaran 2, 3, dan 4 (30-09-2026): dokumen 6.3, 6.4, dan 6.5.
--
-- STATUS: BELUM PERNAH DIJALANKAN ke data legacy. Mesin developer tidak punya salinan DATA legacy; skrip hanya diuji
-- sintaks & hasilnya di database scratch lokal (migrate + seed + data sintetis) — dokumen Bagian 6.
--
-- SIAPA & DI MANA: pemegang akses, pada SALINAN data produksi simpeg01 (dump di-restore ke skema tersendiri, MySQL
-- >= 8.0.30; dokumen 2.4 langkah 1). BUKAN di server produksi dan BUKAN di skema v2. Akun baca saja cukup (SELECT
-- skema salinan + information_schema).
--
--   mysql --force --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan> \
--         < DBV-011-audit-duplikat-master-legacy.sql > hasil_audit.txt 2>&1
--
--   --force  kueri yang error dicatat di hasil lalu dilewati; kueri lain tetap jalan.
--   -vvv     setiap kueri ikut tercetak beserta "N rows in set" / "Empty set", sehingga hasil membuktikan kueri jalan.
--   --default-character-set=utf8mb4  wajib: klien Windows bawaan memakai cp850/latin1 sehingga literal & tampilan rusak.
-- Lampirkan hasil_audit.txt ke kartu ISSUE-009 / DBV-011.
--
-- ERROR DI HASIL = AUDIT BELUM LENGKAP (dokumen 2.3/2.4 langkah 3). Kueri yang error tidak menghasilkan baris, jadi
-- "semua WAJIB_0 kosong" belum berarti lolos. Hanya satu error yang boleh dibiarkan:
--   1146  untuk tabel yang MEMANG tidak ada di salinan (dipastikan dengan SHOW TABLES dan dicatat di log 2.8).
-- ERROR lain (mis. 1054 karena kolom legacy [I] bernama lain) → sesuaikan kueri dengan kolom salinan yang nyata,
-- jalankan ulang, dan catat penyesuaiannya. Hal yang sama berlaku untuk baris "PERIKSA D/E: … terlewat".
-- WAJIB_0 panjang/tipe (D, B8, B9, A6 panjang akun) pada kolom berlabel [I] → cocokkan dulu tipe kolom v2 dengan DDL
-- dump produksi (G-01 8.3, G-04 7, G-06 3/6.4, G-07 3.1, G-10 3). Bila dugaan v2 yang salah, SKEMA dikoreksi lewat
-- migration ALTER + review DBV; data baru diubah bila tipe v2 memang benar (dokumen 2.3 dan 2.4 langkah 6).
-- Skrip ini ditulis dan diuji untuk MySQL 8 (≥ 8.0.30; dokumen 2.4 langkah 1). Tiruan stripslashes memakai rujukan
-- grup '$1' (ICU); di MariaDB rujukan grup ditulis '\\1', sehingga hasil untuk nilai ber-backslash berbeda.
--
-- Skrip ini hanya berisi SELECT, SET SESSION/variabel, transaksi READ ONLY, PREPARE/EXECUTE atas SELECT yang disusun
-- dari information_schema (teks kueri hasil susunan ikut dicetak sebelum dijalankan), dan satu PREPARE/EXECUTE atas
-- 'SET SESSION information_schema_stats_expiry = 0' / 'DO 0' (bersyarat engine). Tidak ada INSERT/UPDATE/DELETE/DDL.
--
-- ARTI KOLOM `cek` (awal teks):
--   WAJIB_0  harus 0 baris sebelum impor: duplikat UNIQUE, pelanggaran NOT NULL/CHECK/FK/panjang/rentang kolom v2 →
--            impor gagal (1062/1048/1452/3819/1406/1264/1366/1292) atau data rusak.
--   PERIKSA  tidak menggagalkan impor, tetapi wajib ditinjau dan diputuskan bersama pemilik proses.
--   INFO     profil untuk dicatat (jumlah per status, ID maks, counter AUTO_INCREMENT, baris hard-coded).
--
-- ATURAN PEMBANDING (sama dengan v2, dokumen 2.2):
--   * Kunci nama = normalisasi aplikasi v2 (MasterService::normalizeName): stripslashes (kecuali jenis_hukdis) →
--     setiap deret whitespace menjadi satu spasi → trim; dibandingkan dengan COLLATE utf8mb4_unicode_ci (tidak peka
--     huruf besar/kecil & aksen, PAD SPACE). Kolom legacy ber-collation lain (mis. agama.agama utf8mb4_0900_ai_ci NO
--     PAD di tabel latin1, simpeg_prod.sql:177) selalu di-CONVERT dulu, jadi 'Islam' dan 'islam ' SATU kunci.
--   * Kolom unik selain nama (jenjang_pendidikan_singkat, username, id_pegawai, email) = trim saja, tanpa merapatkan
--     spasi di tengah (MasterField::normalize / UserService). Kelas karakternya sama dengan trim() PHP: spasi, \t, \n,
--     \r, \0, \x0B (bukan [[:space:]], yang juga membuang NBSP/\f/U+2000..U+3000 sehingga lapor-lebih).
--   * Lingkup jenis_status.status_pegawai (v2 TINYINT, scopeValues() men-trim lalu meng-cast) dibandingkan sebagai
--     bilangan: '1', ' 1', '01', '+1' satu lingkup. Nilai yang bukan bilangan dilaporkan di B8.
--   * Kode wilayah (PK provinsi/kabupaten_kota/kecamatan/kelurahan, kolom induknya, dan empat kode wilayah kantor) =
--     nilai impor hasil trim() PHP (@dbv011_trim), sama dengan aplikasi v2 (MasterService::createLocked() men-trim
--     kode & induk, MasterField::normalize() men-trim field rujukan kantor). Aturan ini dipakai di SEMUA kueri yang
--     menyentuh kode wilayah: lingkup A1, sentinel B1, rujukan B2, kode ganda & panjang/format B9; transformasi impor
--     men-trim kode yang sama (dokumen 2.2 #7).
--   * Status NULL (kolom status v2 NOT NULL) dihitung eksplisit: st_null di C, st_null di B6, 'NULL' di kolom anggota;
--     st_lain di C ikut menghitung NULL.
--   * stripslashes ditiru penuh dengan REGEXP_REPLACE pola \\(.?) → $1: setiap backslash dibuang dan karakter sesudahnya
--     dipertahankan, dibaca dari kiri (\\ → \, \' → ', \d → d, backslash di akhir dibuang). Sama dengan stripslashes
--     PHP kecuali \0 (PHP: byte NUL, SQL: '0'). Setiap nilai ber-backslash tetap muncul di bagian D dan dicocokkan
--     ulang dengan stripslashes PHP sebelum grup duplikat diputuskan (dokumen 2.5).
--   * Lingkup = kolom depan UNIQUE v2 (induk / uniqueScope). SEMUA status ikut (UNIQUE v2 berlaku juga status 2/10).
--
-- POLA EKSPRESI KUNCI (diulang per kueri karena MariaDB 10.4 tidak punya JSON_TABLE dan akun baca tidak boleh
-- membuat fungsi):
--   nama + stripslashes : TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(k USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' '))
--                         COLLATE utf8mb4_unicode_ci
--   kode (trim() PHP)   : REGEXP_REPLACE(CONVERT(k USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci
--   Kolom kode/bilangan di bagian B (old_id, row_jurusan, status_pegawai, cpns) di-trim dengan [[:space:]] sebelum
--   diuji sebagai bilangan/kode tetap; transformasi impor men-trim nilai yang sama sebelum di-cast.
-- Pola yang memuat backslash disimpan di variabel sesi yang disusun dengan CHAR(), sehingga teks SQL skrip ini TIDAK
-- memuat backslash sama sekali dan hasilnya tidak bergantung pada NO_BACKSLASH_ESCAPES. Literal hex (putaran 2) tidak
-- cukup: di MySQL 8.0.30 derived condition pushdown mem-parse ulang kondisi WHERE, dan pada NO_BACKSLASH_ESCAPES
-- literal ber-backslash di kondisi itu berubah (terukur, dokumen 6.4).
--   @dbv011_ss   = \\(.?)                                              (stripslashes, rujukan grup '$1')
--   @dbv011_trim = ^[ \t\n\r\x{0B}\x{00}]+|[ \t\n\r\x{0B}\x{00}]+$     (escape regex; = trim() PHP)
--   @dbv011_bs   = \                                                   (satu backslash, untuk LOCATE di bagian D)
-- @dbv011_trim berisi ESCAPE regex (\t \n \r \x{0B} \x{00}, dikenal ICU MySQL 8 dan PCRE MariaDB), bukan karakter
-- kontrol asli. Putaran 3 memuat byte NUL asli (CHAR(0)); MariaDB 10.4 (PCRE1) meng-compile pola sebagai string C yang
-- berakhir di NUL, sehingga pola itu terpotong menjadi "^[ <TAB><LF><CR><VT>" (kelas tanpa "]", diperkirakan ERROR
-- 1139; MariaDB tidak tersedia untuk diuji). Pola ini tidak memuat byte NUL maupun karakter kontrol (dokumen 2.2 #6).
-- =====================================================================================================================

SET SESSION group_concat_max_len = 1048576;
SET @dbv011_ss   = CONVERT(CONCAT(CHAR(92, 92), '(.?)') USING utf8mb4);
SET @dbv011_kls  = CONCAT(' ', CHAR(92), 't', CHAR(92), 'n', CHAR(92), 'r', CHAR(92), 'x{0B}', CHAR(92), 'x{00}');
SET @dbv011_trim = CONVERT(CONCAT('^[', @dbv011_kls, ']+|[', @dbv011_kls, ']+$') USING utf8mb4);
SET @dbv011_bs   = CONVERT(CHAR(92) USING utf8mb4);
-- MySQL 8: counter AUTO_INCREMENT di information_schema di-cache (bawaan 86400 detik); 0 = baca nilai terkini.
-- MariaDB tidak punya variabel ini (dan tidak meng-cache), jadi penyetelannya bersyarat agar tidak ada error 1193.
SET @dbv011_s = IF(VERSION() LIKE '%MariaDB%', 'DO 0', 'SET SESSION information_schema_stats_expiry = 0');
PREPARE dbv011_s FROM @dbv011_s;
EXECUTE dbv011_s;
DEALLOCATE PREPARE dbv011_s;
START TRANSACTION READ ONLY;

-- ---------------------------------------------------------------------------------------------------------- 0. Sesi
-- Jam & sql_mode instance SALINAN (bukan produksi). Untuk D-11/ISSUE-022, DBA menjalankan baris yang sama di server
-- produksi legacy (dokumen 3.6).
SELECT 'INFO sesi' cek, DATABASE() db, VERSION() versi, @@SESSION.time_zone tz_sesi, @@system_time_zone tz_sistem,
       NOW() jam_sesi, UTC_TIMESTAMP() jam_utc, @@SESSION.sql_mode sql_mode_sesi;

-- ===================================================================================================== A. DUPLIKAT
-- Satu kueri per UNIQUE v2 di tabel master (28 index) — WAJIB_0. Kolom `anggota` = id:status:nilai legacy (QUOTE);
-- status NULL tampil sebagai 'NULL'.

-- ---------------------------------------------------------------- A1. Batch 1 (DBV-001, G-01 8.2 / 8.4 #2)
SELECT 'WAJIB_0 duplikat uq_provinsi_nama' cek, 'provinsi' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(provinsi USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_provinsi, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(provinsi)) ORDER BY id_provinsi SEPARATOR ' | ') anggota
  FROM provinsi GROUP BY kunci HAVING COUNT(*) > 1;

-- Lingkup wilayah = kode induk hasil trim() PHP (nilai impor, dokumen 2.2 #7): ' 11' dan '11' satu lingkup. Anggota:
-- id:status:kode induk mentah:nama.
SELECT 'WAJIB_0 duplikat uq_kabupaten_kota_nama' cek, 'kabupaten_kota' tabel,
       CONCAT('id_provinsi=', IFNULL(REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, ''), 'NULL')) COLLATE utf8mb4_unicode_ci lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(kabupaten_kota USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_kabupaten_kota, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(id_provinsi), ':', QUOTE(kabupaten_kota)) ORDER BY id_kabupaten_kota SEPARATOR ' | ') anggota
  FROM kabupaten_kota GROUP BY lingkup, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_kecamatan_nama' cek, 'kecamatan' tabel,
       CONCAT('id_kabupaten_kota=', IFNULL(REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, ''), 'NULL')) COLLATE utf8mb4_unicode_ci lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(kecamatan USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_kecamatan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(id_kabupaten_kota), ':', QUOTE(kecamatan)) ORDER BY id_kecamatan SEPARATOR ' | ') anggota
  FROM kecamatan GROUP BY lingkup, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_kelurahan_nama' cek, 'kelurahan' tabel,
       CONCAT('id_kecamatan=', IFNULL(REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, ''), 'NULL')) COLLATE utf8mb4_unicode_ci lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(kelurahan USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_kelurahan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(id_kecamatan), ':', QUOTE(kelurahan)) ORDER BY id_kelurahan SEPARATOR ' | ') anggota
  FROM kelurahan GROUP BY lingkup, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_agama_nama' cek, 'agama' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(agama USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_agama, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(agama)) ORDER BY id_agama SEPARATOR ' | ') anggota
  FROM agama GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_jenis_pegawai_nama' cek, 'jenis_pegawai' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenis_pegawai USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_pegawai, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenis_pegawai)) ORDER BY id_jenis_pegawai SEPARATOR ' | ') anggota
  FROM jenis_pegawai GROUP BY kunci HAVING COUNT(*) > 1;

-- Lingkup status_pegawai = nilai impor (v2 TINYINT; scopeValues() men-trim lalu meng-cast): '1', ' 1', '01', '+1' satu
-- lingkup. Nilai yang bukan bilangan dikelompokkan per nilai mentah dan dilaporkan di B8. Anggota: id:status:status_pegawai:nama.
SELECT 'WAJIB_0 duplikat uq_jenis_status_nama' cek, 'jenis_status' tabel,
       CONCAT('status_pegawai=', IF(REGEXP_REPLACE(CAST(status_pegawai AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[-+]?[0-9]+$',
                                    CAST(CAST(REGEXP_REPLACE(CAST(status_pegawai AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS SIGNED) AS CHAR),
                                    CONCAT('tidak valid ', QUOTE(CAST(status_pegawai AS CHAR))))) lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenis_status USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_status, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(CAST(status_pegawai AS CHAR)), ':', QUOTE(jenis_status)) ORDER BY id_jenis_status SEPARATOR ' | ') anggota
  FROM jenis_status GROUP BY lingkup, kunci HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------- A2. FAQ (DBV-002, G-10 6.3 #5)
SELECT 'WAJIB_0 duplikat uq_faq_topic_nama' cek, 'faq_topic' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(faq_topic USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_faq_topic, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(faq_topic)) ORDER BY id_faq_topic SEPARATOR ' | ') anggota
  FROM faq_topic GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_faq_sub_topic_nama' cek, 'faq_sub_topic' tabel, CONCAT('id_faq_topic=', IFNULL(id_faq_topic, 'NULL')) lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(faq_sub_topic USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_faq_sub_topic, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(faq_sub_topic)) ORDER BY id_faq_sub_topic SEPARATOR ' | ') anggota
  FROM faq_sub_topic GROUP BY id_faq_topic, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_faq_article_nama' cek, 'faq_article' tabel, CONCAT('id_faq_sub_topic=', IFNULL(id_faq_sub_topic, 'NULL')) lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(title USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_faq_article, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(title)) ORDER BY id_faq_article SEPARATOR ' | ') anggota
  FROM faq_article GROUP BY id_faq_sub_topic, kunci HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------- A3. G-07/G-08 (DBV-003, 6.3 #2-#3)
SELECT 'WAJIB_0 duplikat uq_jenis_libur_nama' cek, 'jenis_libur' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenis_libur USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_libur, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenis_libur)) ORDER BY id_jenis_libur SEPARATOR ' | ') anggota
  FROM jenis_libur GROUP BY kunci HAVING COUNT(*) > 1;

-- hari_libur legacy tanpa kolom status; UNIQUE v2 pada tanggal mulai (DATE, tanpa collation).
SELECT 'WAJIB_0 duplikat uq_hari_libur_tgl_mulai' cek, 'hari_libur' tabel, '' lingkup, CAST(tgl_mulai AS CHAR) kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_libur, ':s.d.', IFNULL(CAST(tgl_akhir AS CHAR), 'NULL'), ':', QUOTE(nama_libur)) ORDER BY id_libur SEPARATOR ' | ') anggota
  FROM hari_libur GROUP BY tgl_mulai HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_bidang_kursem_nama' cek, 'bidang_kursem' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(bidang_kursem USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_bidang_kursem, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(bidang_kursem)) ORDER BY id_bidang_kursem SEPARATOR ' | ') anggota
  FROM bidang_kursem GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_instansi_kursem_nama' cek, 'instansi_kursem' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(instansi_kursem USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_instansi_kursem, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(instansi_kursem)) ORDER BY id_instansi_kursem SEPARATOR ' | ') anggota
  FROM instansi_kursem GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_kantor_nama' cek, 'kantor' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(nama_kantor USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_kantor, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(nama_kantor)) ORDER BY id_kantor SEPARATOR ' | ') anggota
  FROM kantor GROUP BY kunci HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------- A4. G-04/G-05 (DBV-004, 6.3 #4)
SELECT 'WAJIB_0 duplikat uq_pangkat_nama' cek, 'pangkat' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(gol_ruang USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_pangkat, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(gol_ruang)) ORDER BY id_pangkat SEPARATOR ' | ') anggota
  FROM pangkat GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_jenis_kp_nama' cek, 'jenis_kp' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenis_kp USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_kp, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenis_kp)) ORDER BY id_jenis_kp SEPARATOR ' | ') anggota
  FROM jenis_kp GROUP BY kunci HAVING COUNT(*) > 1;

-- gol_pppk.status legacy ENUM('1','2') (simpeg_prod.sql:1283): CAST(status AS CHAR) memberi nilai, bukan indeks ENUM.
SELECT 'WAJIB_0 duplikat uq_gol_pppk_nama' cek, 'gol_pppk' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(gol_pppk USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_gol_pppk, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(gol_pppk)) ORDER BY id_gol_pppk SEPARATOR ' | ') anggota
  FROM gol_pppk GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_jenjang_pendidikan_nama' cek, 'jenjang_pendidikan' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenjang_pendidikan USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenjang_pendidikan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenjang_pendidikan)) ORDER BY id_jenjang_pendidikan SEPARATOR ' | ') anggota
  FROM jenjang_pendidikan GROUP BY kunci HAVING COUNT(*) > 1;

-- Singkatan = field uniqueFields (trim() PHP saja, spasi di tengah TIDAK dirapatkan).
SELECT 'WAJIB_0 duplikat uq_jenjang_pendidikan_singkat' cek, 'jenjang_pendidikan' tabel, '' lingkup,
       REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenjang_pendidikan_singkat USING utf8mb4), @dbv011_ss, '$1'), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenjang_pendidikan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenjang_pendidikan_singkat)) ORDER BY id_jenjang_pendidikan SEPARATOR ' | ') anggota
  FROM jenjang_pendidikan GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_bidang_pendidikan_nama' cek, 'bidang_pendidikan' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(bidang_pendidikan USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_bidang_pendidikan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(bidang_pendidikan)) ORDER BY id_bidang_pendidikan SEPARATOR ' | ') anggota
  FROM bidang_pendidikan GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_jurusan_pendidikan_nama' cek, 'jurusan_pendidikan' tabel, CONCAT('id_bidang_pendidikan=', IFNULL(id_bidang_pendidikan, 'NULL')) lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jurusan_pendidikan USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jurusan_pendidikan, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jurusan_pendidikan)) ORDER BY id_jurusan_pendidikan SEPARATOR ' | ') anggota
  FROM jurusan_pendidikan GROUP BY id_bidang_pendidikan, kunci HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------- A5. G-06 (DBV-005, 6.5 #1)
-- diklat: stripslashes kondisional (jalur dm_diklat menyimpan mentah, 6.5 #2) → kunci memakai stripslashes; nama
-- ber-backslash ditinjau per baris lewat bagian D.
SELECT 'WAJIB_0 duplikat uq_diklat_nama' cek, 'diklat' tabel, CONCAT('jenis_diklat=', IFNULL(jenis_diklat, 'NULL')) lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(nama_diklat USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_diklat, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(nama_diklat)) ORDER BY id_diklat SEPARATOR ' | ') anggota
  FROM diklat GROUP BY jenis_diklat, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_tingkat_hukdis_nama' cek, 'tingkat_hukdis' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(tingkat_hukdis USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_tingkat_hukdis, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(tingkat_hukdis)) ORDER BY id_tingkat_hukdis SEPARATOR ' | ') anggota
  FROM tingkat_hukdis GROUP BY kunci HAVING COUNT(*) > 1;

-- jenis_hukdis disimpan mentah di legacy (Lm_hukdis.php:424-430): kunci TANPA stripslashes (6.5 #2).
SELECT 'WAJIB_0 duplikat uq_jenis_hukdis_nama' cek, 'jenis_hukdis' tabel, CONCAT('id_tingkat_hukdis=', IFNULL(id_tingkat_hukdis, 'NULL')) lingkup,
       TRIM(REGEXP_REPLACE(CONVERT(jenis_hukdis USING utf8mb4), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_hukdis, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenis_hukdis)) ORDER BY id_jenis_hukdis SEPARATOR ' | ') anggota
  FROM jenis_hukdis GROUP BY id_tingkat_hukdis, kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_jenis_konket_nama' cek, 'jenis_konket' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(jenis_konket USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_konket, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(jenis_konket)) ORDER BY id_jenis_konket SEPARATOR ' | ') anggota
  FROM jenis_konket GROUP BY kunci HAVING COUNT(*) > 1;

-- old_id v2 INT: '08' dan '8' bertabrakan. Nilai yang bukan angka dilaporkan di B5.
SELECT 'WAJIB_0 duplikat uq_jenis_konket_old_id' cek, 'jenis_konket' tabel, '' lingkup,
       CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED) kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_jenis_konket, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(old_id), ':', QUOTE(jenis_konket)) ORDER BY id_jenis_konket SEPARATOR ' | ') anggota
  FROM jenis_konket
 WHERE REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[0-9]+$'
 GROUP BY kunci HAVING COUNT(*) > 1;

SELECT 'WAJIB_0 duplikat uq_tanda_jasa_nama' cek, 'tanda_jasa' tabel, '' lingkup,
       TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(tanda_jasa USING utf8mb4), @dbv011_ss, '$1'), '[[:space:]]+', ' ')) COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id_tanda_jasa, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(tanda_jasa)) ORDER BY id_tanda_jasa SEPARATOR ' | ') anggota
  FROM tanda_jasa GROUP BY kunci HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------- A6. Akun (DBV-010: A-01 9.8 langkah 6, D-4, D-6)
-- Kolom legacy `pengguna`: id, username, UserLevel, id_pegawai (berisi NIP → v2 `nip`), name, email, status.
SELECT 'WAJIB_0 duplikat pengguna.username' cek, 'pengguna' tabel, '' lingkup,
       REGEXP_REPLACE(CONVERT(username USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(username)) ORDER BY id SEPARATOR ' | ') anggota
  FROM pengguna GROUP BY kunci HAVING COUNT(*) > 1;

-- UNIQUE `nip` v2 [V2]: legacy tidak punya UNIQUE di id_pegawai (A-01 9.2); semua status, termasuk 0/2.
SELECT 'WAJIB_0 duplikat pengguna.id_pegawai (UNIQUE nip v2)' cek, 'pengguna' tabel, '' lingkup,
       REGEXP_REPLACE(CONVERT(id_pegawai USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(username)) ORDER BY id SEPARATOR ' | ') anggota
  FROM pengguna
 WHERE id_pegawai IS NOT NULL AND REGEXP_REPLACE(CONVERT(id_pegawai USING utf8mb4), @dbv011_trim, '') <> ''
 GROUP BY kunci HAVING COUNT(*) > 1;

-- D-6: tanpa UNIQUE di DB (impor tidak gagal), tetapi aplikasi menolak email ganda saat email berubah (CR-019) dan email
-- menjadi kanal reset (K3) → setiap grup diputuskan sebelum driver SMTP (CR-014) aktif.
SELECT 'PERIKSA email ganda (D-6)' cek, 'pengguna' tabel, '' lingkup,
       REGEXP_REPLACE(CONVERT(email USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kunci,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(id, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(username)) ORDER BY id SEPARATOR ' | ') anggota
  FROM pengguna
 WHERE email IS NOT NULL AND REGEXP_REPLACE(CONVERT(email USING utf8mb4), @dbv011_trim, '') <> ''
 GROUP BY kunci HAVING COUNT(*) > 1;

-- A-01 9.5/9.10: role 2/6/7 (UL_PEGAWAI) wajib NIP.
SELECT 'PERIKSA akun role 2/6/7 tanpa id_pegawai' cek, id, username, UserLevel, CAST(status AS CHAR) status
  FROM pengguna
 WHERE UserLevel IN ('2', '6', '7')
   AND (id_pegawai IS NULL OR REGEXP_REPLACE(CONVERT(id_pegawai USING utf8mb4), @dbv011_trim, '') = '');

-- A-01 9.10: nilai impor (trim() PHP, seperti UserService) yang melebihi kolom v2 → strict 1406. v2: username
-- VARCHAR(100) NOT NULL, nip VARCHAR(30), name/email VARCHAR(150). Legacy [K] simpeg_prod_duplikat sama lebarnya
-- (provenance DDL belum pasti); temuan di sini berarti DDL produksi berbeda → cocokkan dulu dengan dump (dokumen 2.3).
SELECT 'WAJIB_0 pengguna kolom lebih dari v2 (username 100, nip 30, name/email 150)' cek, x.id, QUOTE(x.username) username,
       CHAR_LENGTH(x.u) p_username, CHAR_LENGTH(x.n) p_nip, CHAR_LENGTH(x.nm) p_name, CHAR_LENGTH(x.e) p_email
  FROM (SELECT id, username,
               REGEXP_REPLACE(CONVERT(username USING utf8mb4), @dbv011_trim, '') u,
               REGEXP_REPLACE(CONVERT(id_pegawai USING utf8mb4), @dbv011_trim, '') n,
               REGEXP_REPLACE(CONVERT(name USING utf8mb4), @dbv011_trim, '') nm,
               REGEXP_REPLACE(CONVERT(email USING utf8mb4), @dbv011_trim, '') e
          FROM pengguna) x
 WHERE CHAR_LENGTH(x.u) > 100 OR CHAR_LENGTH(x.n) > 30 OR CHAR_LENGTH(x.nm) > 150 OR CHAR_LENGTH(x.e) > 150;

-- ========================================================================================= B. INTEGRITAS PENDUKUNG
-- ---------------------------------------------------------------- B1. Sentinel LAIN-LAIN wilayah (G-07/G-08 2.5, 6.3 #5)
-- Kode yang dipakai sentinel v2 (migration 2026-09-25-100200), dibandingkan sebagai kode hasil trim() PHP (dokumen
-- 2.2 #7). Baris legacy berkode ini TIDAK diimpor (dilewati atau dibandingkan dengan sentinel v2); PERIKSA nama &
-- status. Kolom `kode` = nilai legacy mentah (QUOTE).
SELECT 'PERIKSA sentinel_wilayah' cek, 'provinsi' tabel, QUOTE(CONVERT(id_provinsi USING utf8mb4)) kode, CONVERT(provinsi USING utf8mb4) nama,
       CAST(status AS CHAR) status FROM provinsi WHERE REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') = '99'
UNION ALL SELECT 'PERIKSA sentinel_wilayah', 'kabupaten_kota', QUOTE(CONVERT(id_kabupaten_kota USING utf8mb4)), CONVERT(kabupaten_kota USING utf8mb4),
       CAST(status AS CHAR) FROM kabupaten_kota WHERE REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, '') = '9999'
UNION ALL SELECT 'PERIKSA sentinel_wilayah', 'kecamatan', QUOTE(CONVERT(id_kecamatan USING utf8mb4)), CONVERT(kecamatan USING utf8mb4),
       CAST(status AS CHAR) FROM kecamatan WHERE REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') = '9999999'
UNION ALL SELECT 'PERIKSA sentinel_wilayah', 'kelurahan', QUOTE(CONVERT(id_kelurahan USING utf8mb4)), CONVERT(kelurahan USING utf8mb4),
       CAST(status AS CHAR) FROM kelurahan WHERE REGEXP_REPLACE(CONVERT(id_kelurahan USING utf8mb4), @dbv011_trim, '') = '9999999999';

-- ---------------------------------------------------------------- B2. Rujukan yatim (FK v2 menolak impor, 1452)
-- Rantai wilayah: kode hasil trim() PHP di kedua sisi (nilai impor, dokumen 2.2 #7), dibandingkan dalam
-- utf8mb4_unicode_ci seperti kolom v2. Kolom `rujukan` = nilai legacy mentah (QUOTE; NULL = induk kosong). Induk
-- di-DISTINCT agar dimaterialisasi sekali dengan index otomatis (data wilayah besar).
SELECT 'WAJIB_0 yatim kabupaten_kota.id_provinsi' cek, a.id, QUOTE(a.mentah) rujukan
  FROM (SELECT id_kabupaten_kota id, CONVERT(id_provinsi USING utf8mb4) mentah, REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kabupaten_kota) a
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM provinsi) p ON p.k = a.k
 WHERE p.k IS NULL;
SELECT 'WAJIB_0 yatim kecamatan.id_kabupaten_kota' cek, a.id, QUOTE(a.mentah) rujukan
  FROM (SELECT id_kecamatan id, CONVERT(id_kabupaten_kota USING utf8mb4) mentah, REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kecamatan) a
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kabupaten_kota) p ON p.k = a.k
 WHERE p.k IS NULL;
SELECT 'WAJIB_0 yatim kelurahan.id_kecamatan' cek, a.id, QUOTE(a.mentah) rujukan
  FROM (SELECT id_kelurahan id, CONVERT(id_kecamatan USING utf8mb4) mentah, REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kelurahan) a
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kecamatan) p ON p.k = a.k
 WHERE p.k IS NULL;
SELECT 'WAJIB_0 yatim faq_sub_topic.id_faq_topic' cek, a.id_faq_sub_topic id, a.id_faq_topic rujukan
  FROM faq_sub_topic a LEFT JOIN faq_topic p ON p.id_faq_topic = a.id_faq_topic WHERE p.id_faq_topic IS NULL;
SELECT 'WAJIB_0 yatim faq_article.id_faq_sub_topic' cek, a.id_faq_article id, a.id_faq_sub_topic rujukan
  FROM faq_article a LEFT JOIN faq_sub_topic p ON p.id_faq_sub_topic = a.id_faq_sub_topic WHERE p.id_faq_sub_topic IS NULL;
-- Tabel anak FAQ (legacy FK ON DELETE CASCADE [K], simpeg_prod.sql:971-996, jadi yatim kecil kemungkinannya), tetapi
-- GABUNG faq_article di salinan kerja (dokumen 2.5) bisa membuat relasi ke diri sendiri → dicek ulang di setiap putaran.
SELECT 'WAJIB_0 yatim faq_rate.id_faq_article' cek, CONCAT(a.id_faq_article, '/', a.nip) id, a.id_faq_article rujukan
  FROM faq_rate a LEFT JOIN faq_article p ON p.id_faq_article = a.id_faq_article WHERE p.id_faq_article IS NULL;
SELECT 'WAJIB_0 yatim faq_related_article' cek, CONCAT(a.id_article_main, '/', a.id_article_related) id,
       CONCAT_WS(',', IF(m.id_faq_article IS NULL, CONCAT('id_article_main=', a.id_article_main), NULL),
                      IF(r.id_faq_article IS NULL, CONCAT('id_article_related=', a.id_article_related), NULL)) rujukan
  FROM faq_related_article a
  LEFT JOIN faq_article m ON m.id_faq_article = a.id_article_main
  LEFT JOIN faq_article r ON r.id_faq_article = a.id_article_related
 WHERE m.id_faq_article IS NULL OR r.id_faq_article IS NULL;
SELECT 'PERIKSA faq_related_article merujuk diri sendiri' cek, id_article_main, id_article_related
  FROM faq_related_article WHERE id_article_main = id_article_related;
SELECT 'WAJIB_0 yatim jurusan_pendidikan.id_bidang_pendidikan' cek, a.id_jurusan_pendidikan id, a.id_bidang_pendidikan rujukan
  FROM jurusan_pendidikan a LEFT JOIN bidang_pendidikan p ON p.id_bidang_pendidikan = a.id_bidang_pendidikan WHERE p.id_bidang_pendidikan IS NULL;
-- G-06 6.5 #4: legacy menghapus keras tingkat (Lm_hukdis.php:134) → id_tingkat_hukdis NULL atau yatim.
SELECT 'WAJIB_0 yatim/NULL jenis_hukdis.id_tingkat_hukdis' cek, a.id_jenis_hukdis id, a.id_tingkat_hukdis rujukan
  FROM jenis_hukdis a LEFT JOIN tingkat_hukdis p ON p.id_tingkat_hukdis = a.id_tingkat_hukdis WHERE p.id_tingkat_hukdis IS NULL;
-- hari_libur → jenis_libur (legacy FK ON DELETE SET NULL). Kolom v2 boleh NULL, tetapi aplikasi mewajibkan jenis dan
-- daftar legacy menyembunyikannya (INNER JOIN) → NULL = keputusan pemetaan (G-07 6.3 #2, catatan DBV-003 #6/#7).
SELECT 'WAJIB_0 yatim hari_libur.id_jenis_libur' cek, a.id_libur id, a.id_jenis_libur rujukan
  FROM hari_libur a LEFT JOIN jenis_libur p ON p.id_jenis_libur = a.id_jenis_libur
 WHERE a.id_jenis_libur IS NOT NULL AND p.id_jenis_libur IS NULL;
SELECT 'PERIKSA hari_libur tanpa jenis (NULL)' cek, id_libur id, tgl_mulai, nama_libur
  FROM hari_libur WHERE id_jenis_libur IS NULL;
-- kantor → wilayah: kode hasil trim() PHP di kedua sisi (dokumen 2.2 #7); kode sentinel dilewati (baris sentinel v2
-- sudah ada dari migration 100200). Kode NULL ikut dilaporkan (keempat kolom wilayah v2 NOT NULL → 1048);
-- `NOT (x <=> kode)` agar NULL tidak lolos perbandingan. `rujukan` = nilai legacy mentah (QUOTE).
SELECT 'WAJIB_0 yatim/NULL kantor.wilayah' cek, t.id_kantor id,
       CONCAT_WS(',', IF(p.k IS NULL AND NOT (t.pv <=> '99'), CONCAT('id_provinsi=', QUOTE(t.pv0)), NULL),
                      IF(b.k IS NULL AND NOT (t.kb <=> '9999'), CONCAT('id_kabupaten=', QUOTE(t.kb0)), NULL),
                      IF(c.k IS NULL AND NOT (t.kc <=> '9999999'), CONCAT('id_kecamatan=', QUOTE(t.kc0)), NULL),
                      IF(l.k IS NULL AND NOT (t.kl <=> '9999999999'), CONCAT('id_kelurahan=', QUOTE(t.kl0)), NULL)) rujukan
  FROM (SELECT id_kantor,
               CONVERT(id_provinsi USING utf8mb4) pv0, REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci pv,
               CONVERT(id_kabupaten USING utf8mb4) kb0, REGEXP_REPLACE(CONVERT(id_kabupaten USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kb,
               CONVERT(id_kecamatan USING utf8mb4) kc0, REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kc,
               CONVERT(id_kelurahan USING utf8mb4) kl0, REGEXP_REPLACE(CONVERT(id_kelurahan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kl
          FROM kantor) t
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM provinsi) p ON p.k = t.pv
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kabupaten_kota) b ON b.k = t.kb
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kecamatan) c ON c.k = t.kc
  LEFT JOIN (SELECT DISTINCT REGEXP_REPLACE(CONVERT(id_kelurahan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci k FROM kelurahan) l ON l.k = t.kl
 WHERE (p.k IS NULL AND NOT (t.pv <=> '99')) OR (b.k IS NULL AND NOT (t.kb <=> '9999'))
    OR (c.k IS NULL AND NOT (t.kc <=> '9999999')) OR (l.k IS NULL AND NOT (t.kl <=> '9999999999'));

-- ---------------------------------------------------------------- B3. hari_libur (G-07/G-08 6.3 #2)
SELECT 'WAJIB_0 hari_libur rentang terbalik (CHECK chk_hari_libur_rentang)' cek, id_libur, tgl_mulai, tgl_akhir
  FROM hari_libur WHERE tgl_akhir < tgl_mulai;
SELECT 'PERIKSA hari_libur rentang tumpang tindih' cek, a.id_libur id_a, a.tgl_mulai mulai_a, a.tgl_akhir akhir_a,
       b.id_libur id_b, b.tgl_mulai mulai_b, b.tgl_akhir akhir_b
  FROM hari_libur a JOIN hari_libur b ON a.id_libur < b.id_libur AND a.tgl_mulai <= b.tgl_akhir AND b.tgl_mulai <= a.tgl_akhir
 WHERE MONTH(a.tgl_mulai) <> 0 AND MONTH(b.tgl_mulai) <> 0;  -- tanggal nol dilaporkan di bagian E

-- ---------------------------------------------------------------- B4. CHECK v2 lain (G-04/G-05 6.3 #11, G-06 6.5 #5)
SELECT 'WAJIB_0 diklat.jenis_diklat di luar 1-5 (CHECK chk_diklat_jenis_diklat)' cek, id_diklat, jenis_diklat, nama_diklat
  FROM diklat WHERE jenis_diklat IS NULL OR jenis_diklat NOT BETWEEN 1 AND 5;
-- CHECK biner: peka huruf & spasi. Trim dulu dan kosong → NULL saat impor; sisanya harus persis salah satu 7 kode.
SELECT 'WAJIB_0 jenjang_pendidikan.row_jurusan di luar 7 kode (CHECK chk_jenjang_pendidikan_row_jurusan)' cek,
       id_jenjang_pendidikan, QUOTE(row_jurusan) row_jurusan
  FROM jenjang_pendidikan
 WHERE row_jurusan IS NOT NULL AND REGEXP_REPLACE(CONVERT(row_jurusan USING utf8mb4), '^[[:space:]]+|[[:space:]]+$', '') <> ''
   AND CAST(REGEXP_REPLACE(CONVERT(row_jurusan USING utf8mb4), '^[[:space:]]+|[[:space:]]+$', '') AS BINARY)
       NOT IN (_utf8mb4 'D_I', _utf8mb4 'D_II', _utf8mb4 'D_III', _utf8mb4 'D_IV', _utf8mb4 'S_1', _utf8mb4 'S_2', _utf8mb4 'S_3');

-- ---------------------------------------------------------------- B5. jenis_konket.old_id (G-06 6.5 #3, C4)
-- v2: INT NOT NULL UNIQUE, aplikasi mewajibkan 1..2147483647. NULL/kosong/bukan angka/<= 0/terlalu besar → kode baru.
SELECT 'WAJIB_0 jenis_konket.old_id tidak valid' cek, id_jenis_konket, QUOTE(old_id) old_id, jenis_konket
  FROM jenis_konket
 WHERE old_id IS NULL
    OR REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') NOT REGEXP '^[0-9]{1,10}$'
    OR CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED) NOT BETWEEN 1 AND 2147483647;
-- G-06 6.5 #3: setiap `kategori` pengajuan (absen_ijin, d_konket; INT NOT NULL, simpeg_prod.sql:33-69, 639-670 [K])
-- harus punya pasangan `jenis_konket.old_id`. Tanpa pasangan, jenis pengajuan itu hilang saat modul presensi
-- dimigrasi. PERIKSA: tidak menggagalkan impor master, diputuskan bersama pemilik proses (dicatat di log 2.8).
SELECT 'PERIKSA absen_ijin.kategori tanpa pasangan jenis_konket.old_id' cek, a.kategori, a.jumlah
  FROM (SELECT kategori, COUNT(*) jumlah FROM absen_ijin GROUP BY kategori) a
  LEFT JOIN (SELECT CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED) old_id
               FROM jenis_konket
              WHERE REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[0-9]{1,10}$') j
    ON j.old_id = a.kategori
 WHERE j.old_id IS NULL ORDER BY a.kategori;
SELECT 'PERIKSA d_konket.kategori tanpa pasangan jenis_konket.old_id' cek, a.kategori, a.jumlah
  FROM (SELECT kategori, COUNT(*) jumlah FROM d_konket GROUP BY kategori) a
  LEFT JOIN (SELECT CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED) old_id
               FROM jenis_konket
              WHERE REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[0-9]{1,10}$') j
    ON j.old_id = a.kategori
 WHERE j.old_id IS NULL ORDER BY a.kategori;
-- Kode baru untuk old_id yang bermasalah (dokumen 2.5 kasus khusus) harus lebih besar dari nilai `batas` ini.
SELECT 'INFO batas kode baru old_id' cek,
       (SELECT MAX(CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED)) FROM jenis_konket
         WHERE REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[0-9]{1,10}$') old_id_maks,
       (SELECT MAX(kategori) FROM absen_ijin) absen_ijin_maks, (SELECT MAX(kategori) FROM d_konket) d_konket_maks,
       GREATEST(IFNULL((SELECT MAX(CAST(REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS UNSIGNED)) FROM jenis_konket
                         WHERE REGEXP_REPLACE(CAST(old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[0-9]{1,10}$'), 0),
                IFNULL((SELECT MAX(kategori) FROM absen_ijin), 0), IFNULL((SELECT MAX(kategori) FROM d_konket), 0)) batas;

-- ---------------------------------------------------------------- B6. Kapasitas urutan TINYINT per lingkup (G-06 6.5 #5)
-- `order` v2 TINYINT: paling banyak 127 entri tampil (status selain 10) per lingkup urutan. Master global dicek di C.
-- Status NULL dihitung tampil (belum dinormalkan; st_null = jumlahnya, dilaporkan juga di profil C).
SELECT 'WAJIB_0 diklat > 127 entri per jenis_diklat' cek, jenis_diklat lingkup, COUNT(*) jumlah, SUM(status IS NULL) st_null
  FROM diklat WHERE IFNULL(CAST(status AS CHAR), '') <> '10' GROUP BY jenis_diklat HAVING COUNT(*) > 127;
SELECT 'WAJIB_0 jenis_hukdis > 127 entri per tingkat' cek, id_tingkat_hukdis lingkup, COUNT(*) jumlah, SUM(status IS NULL) st_null
  FROM jenis_hukdis WHERE IFNULL(CAST(status AS CHAR), '') <> '10' GROUP BY id_tingkat_hukdis HAVING COUNT(*) > 127;
-- pangkat: order = level (mode manual, G-04 6.3 #5) — tidak dinormalkan; nilai harus muat TINYINT signed.
SELECT 'WAJIB_0 pangkat.order di luar TINYINT' cek, id_pangkat, gol_ruang, `order`
  FROM pangkat WHERE `order` IS NULL OR `order` NOT BETWEEN -128 AND 127;
-- pangkat.order per cpns (G-04 6.3 #5): peta lengkap nilai level per jenis pangkat sebagai dasar keputusan
-- UNIQUE(cpns, order) yang ditunda (P3). Ganda dalam satu cpns atau bernilai 0 = PERIKSA; sisanya INFO. Semua status;
-- cpns = nilai impor (di-trim lalu di-cast ke bilangan: '1', ' 1', '01' sama); nilai cpns tidak valid dilaporkan di B8.
SELECT IF(COUNT(*) > 1 OR `order` = 0, 'PERIKSA pangkat.order ganda/0 per cpns', 'INFO pangkat.order per cpns') cek,
       IF(REGEXP_REPLACE(CAST(cpns AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') REGEXP '^[-+]?[0-9]+$',
          CAST(CAST(REGEXP_REPLACE(CAST(cpns AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') AS SIGNED) AS CHAR),
          CONCAT('tidak valid ', QUOTE(CAST(cpns AS CHAR)))) cpns_impor, `order`, COUNT(*) jumlah, SUM(status IS NULL) st_null,
       GROUP_CONCAT(CONCAT(id_pangkat, ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(gol_ruang)) ORDER BY id_pangkat SEPARATOR ' | ') anggota
  FROM pangkat GROUP BY cpns_impor, `order` ORDER BY cpns_impor, `order`;

-- ---------------------------------------------------------------- B7. Baris yang di-hard-code kode legacy (INFO)
-- G-06 2.6, G-04/G-05 6.4. Wajib ada dengan ID tersebut; ID ini selalu menjadi PENYINTAS saat dedupe (dokumen 2.5).
SELECT 'INFO hard_coded tanda_jasa' cek, x.id, t.tanda_jasa nama, CAST(t.status AS CHAR) status,
       CASE WHEN t.id_tanda_jasa IS NULL THEN 'TIDAK ADA'
            WHEN x.id = 44 AND CONVERT(t.tanda_jasa USING utf8mb4) COLLATE utf8mb4_bin <> _utf8mb4 'LAIN-LAIN' THEN 'nama bukan LAIN-LAIN (logika legacy membandingkan nama)'
            ELSE '' END catatan
  FROM (SELECT 26 id UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 44) x
  LEFT JOIN tanda_jasa t ON t.id_tanda_jasa = x.id ORDER BY x.id;
SELECT 'INFO hard_coded jenis_konket' cek, x.id, t.jenis_konket nama, QUOTE(t.old_id) old_id, CAST(t.status AS CHAR) status,
       IF(t.id_jenis_konket IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 2 id UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) x
  LEFT JOIN jenis_konket t ON t.id_jenis_konket = x.id ORDER BY x.id;
SELECT 'INFO hard_coded jenis_konket.old_id' cek, x.old_id, t.id_jenis_konket id, t.jenis_konket nama, CAST(t.status AS CHAR) status,
       IF(t.id_jenis_konket IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 8 old_id UNION ALL SELECT 10 UNION ALL SELECT 13) x
  LEFT JOIN jenis_konket t ON REGEXP_REPLACE(CAST(t.old_id AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') = CAST(x.old_id AS CHAR)
 ORDER BY x.old_id;
SELECT 'INFO hard_coded diklat' cek, x.id, t.nama_diklat nama, t.jenis_diklat, CAST(t.status AS CHAR) status,
       IF(t.id_diklat IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 8 id) x LEFT JOIN diklat t ON t.id_diklat = x.id;
SELECT 'INFO hard_coded jenis_kp' cek, x.id, t.jenis_kp nama, CAST(t.status AS CHAR) status, IF(t.id_jenis_kp IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 1 id UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 5 UNION ALL SELECT 6) x
  LEFT JOIN jenis_kp t ON t.id_jenis_kp = x.id ORDER BY x.id;
SELECT 'INFO hard_coded jenjang_pendidikan' cek, x.id, t.jenjang_pendidikan nama, t.jenjang_pendidikan_singkat singkat,
       CAST(t.status AS CHAR) status, IF(t.id_jenjang_pendidikan IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 1 id UNION ALL SELECT 2 UNION ALL SELECT 3) x
  LEFT JOIN jenjang_pendidikan t ON t.id_jenjang_pendidikan = x.id ORDER BY x.id;
SELECT 'INFO hard_coded bidang_pendidikan' cek, x.id, t.bidang_pendidikan nama, CAST(t.status AS CHAR) status,
       IF(t.id_bidang_pendidikan IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 98 id) x LEFT JOIN bidang_pendidikan t ON t.id_bidang_pendidikan = x.id;
SELECT 'INFO hard_coded jurusan_pendidikan' cek, x.id, t.jurusan_pendidikan nama, t.id_bidang_pendidikan, CAST(t.status AS CHAR) status,
       IF(t.id_jurusan_pendidikan IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 1185 id) x LEFT JOIN jurusan_pendidikan t ON t.id_jurusan_pendidikan = x.id;
SELECT 'INFO hard_coded gol_pppk' cek, x.id, t.gol_pppk nama, CAST(t.status AS CHAR) status, IF(t.id_gol_pppk IS NULL, 'TIDAK ADA', '') catatan
  FROM (SELECT 7 id UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) x
  LEFT JOIN gol_pppk t ON t.id_gol_pppk = x.id ORDER BY x.id;

-- ---------------------------------------------------------------- B8. Kolom kode NOT NULL v2 (lingkup & pilihan tetap)
-- jenis_status.status_pegawai (lingkup uq_jenis_status_nama) dan pangkat.cpns: TINYINT NOT NULL di v2, pilihan
-- aplikasi 1/2 (Config\MasterData). Nilai impor = di-trim lalu di-cast ke bilangan ('01', ' 1', '+1' = 1; sama dengan
-- lingkup A1 dan B6). NULL / bukan bilangan / di luar TINYINT = WAJIB_0 (strict → 1048/1366/1264); bilangan lain di
-- luar 1/2 = PERIKSA (DB menerima, form v2 tidak punya pilihannya). Tipe legacy kedua kolom [I]. Kolom lingkup UNIQUE
-- lain sudah dicek di B2 (induk NULL = yatim), B4 (jenis_diklat), dan B5 (old_id).
SELECT CASE WHEN x.v IS NULL OR x.v NOT REGEXP '^[-+]?[0-9]+$' OR CAST(x.v AS SIGNED) NOT BETWEEN -128 AND 127
            THEN 'WAJIB_0 jenis_status.status_pegawai NULL/bukan bilangan/di luar TINYINT'
            ELSE 'PERIKSA jenis_status.status_pegawai di luar pilihan 1/2' END cek,
       x.id_jenis_status id, QUOTE(x.status_pegawai) nilai, IF(x.v REGEXP '^[-+]?[0-9]+$', CAST(x.v AS SIGNED), NULL) nilai_impor,
       x.jenis_status nama, CAST(x.status AS CHAR) status
  FROM (SELECT id_jenis_status, status_pegawai, jenis_status, status,
               REGEXP_REPLACE(CAST(status_pegawai AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') v FROM jenis_status) x
 WHERE x.v IS NULL OR x.v NOT REGEXP '^[-+]?[0-9]+$' OR CAST(x.v AS SIGNED) NOT IN (1, 2);
SELECT CASE WHEN x.v IS NULL OR x.v NOT REGEXP '^[-+]?[0-9]+$' OR CAST(x.v AS SIGNED) NOT BETWEEN -128 AND 127
            THEN 'WAJIB_0 pangkat.cpns NULL/bukan bilangan/di luar TINYINT'
            ELSE 'PERIKSA pangkat.cpns di luar pilihan 1/2' END cek,
       x.id_pangkat id, QUOTE(x.cpns) nilai, IF(x.v REGEXP '^[-+]?[0-9]+$', CAST(x.v AS SIGNED), NULL) nilai_impor,
       x.gol_ruang nama, CAST(x.status AS CHAR) status
  FROM (SELECT id_pangkat, cpns, gol_ruang, status,
               REGEXP_REPLACE(CAST(cpns AS CHAR), '^[[:space:]]+|[[:space:]]+$', '') v FROM pangkat) x
 WHERE x.v IS NULL OR x.v NOT REGEXP '^[-+]?[0-9]+$' OR CAST(x.v AS SIGNED) NOT IN (1, 2);

-- ---------------------------------------------------------------- B9. Kode wilayah: ganda setelah trim, panjang & format
-- Nilai impor kode wilayah = kode hasil trim() PHP (dokumen 2.2 #7), sama di A1 (lingkup), B1, B2, dan di sini.
-- (1) PK ganda setelah trim = WAJIB_0 (PK v2 menolak impor → 1062): mis. ' 11' dan '11' di provinsi. Anggota:
--     kode legacy mentah:status:nama.
SELECT 'WAJIB_0 kode wilayah ganda setelah trim (PK provinsi)' cek, 'provinsi' tabel, REGEXP_REPLACE(CONVERT(id_provinsi USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kode_impor,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(QUOTE(id_provinsi), ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(provinsi)) ORDER BY id_provinsi SEPARATOR ' | ') anggota
  FROM provinsi GROUP BY kode_impor HAVING COUNT(*) > 1;
SELECT 'WAJIB_0 kode wilayah ganda setelah trim (PK kabupaten_kota)' cek, 'kabupaten_kota' tabel, REGEXP_REPLACE(CONVERT(id_kabupaten_kota USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kode_impor,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(QUOTE(id_kabupaten_kota), ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(kabupaten_kota)) ORDER BY id_kabupaten_kota SEPARATOR ' | ') anggota
  FROM kabupaten_kota GROUP BY kode_impor HAVING COUNT(*) > 1;
SELECT 'WAJIB_0 kode wilayah ganda setelah trim (PK kecamatan)' cek, 'kecamatan' tabel, REGEXP_REPLACE(CONVERT(id_kecamatan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kode_impor,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(QUOTE(id_kecamatan), ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(kecamatan)) ORDER BY id_kecamatan SEPARATOR ' | ') anggota
  FROM kecamatan GROUP BY kode_impor HAVING COUNT(*) > 1;
SELECT 'WAJIB_0 kode wilayah ganda setelah trim (PK kelurahan)' cek, 'kelurahan' tabel, REGEXP_REPLACE(CONVERT(id_kelurahan USING utf8mb4), @dbv011_trim, '') COLLATE utf8mb4_unicode_ci kode_impor,
       COUNT(*) jumlah, GROUP_CONCAT(CONCAT(QUOTE(id_kelurahan), ':', IFNULL(CAST(status AS CHAR), 'NULL'), ':', QUOTE(kelurahan)) ORDER BY id_kelurahan SEPARATOR ' | ') anggota
  FROM kelurahan GROUP BY kode_impor HAVING COUNT(*) > 1;
-- (2) Panjang & format. v2 CHAR(2/4/7/10) [I] (G-01 8.3, G-07 3.1; dugaan dari form_kesehatan), aplikasi mewajibkan
-- tepat N digit (ISSUE-008). Lebih panjang dari CHAR(N) = WAJIB_0 (strict → 1406): JANGAN dipotong — cocokkan dulu
-- tipe kolom v2 dengan DDL dump (dokumen 2.3). Bukan tepat N digit (titik, huruf, lebih pendek) = PERIKSA: DB
-- menerima, tetapi aplikasi v2 tidak bisa membuat kode seperti itu. Kode yang berubah oleh trim (spasi/tab/LF/CR/VT/NUL
-- di awal/akhir) = PERIKSA: diimpor sebagai hasil trim, jadi tabrakan dan rujukannya sudah dihitung di A1/B2/B9 (1).
-- NULL dilaporkan di B2.
SELECT CASE WHEN CHAR_LENGTH(y.kode) > y.n THEN CONCAT('WAJIB_0 kode wilayah lebih dari CHAR(', y.n, ') v2')
            WHEN NOT (y.kode REGEXP '^[0-9]+$' AND CHAR_LENGTH(y.kode) = y.n) THEN CONCAT('PERIKSA kode wilayah bukan tepat ', y.n, ' digit')
            ELSE 'PERIKSA kode wilayah berubah oleh trim' END cek,
       y.tabel, y.kolom, y.id, QUOTE(y.mentah) nilai_legacy, QUOTE(y.kode) nilai_impor, CHAR_LENGTH(y.kode) panjang, y.n panjang_v2
  FROM (SELECT u.tabel, u.kolom, u.id, u.mentah, u.n,
               REGEXP_REPLACE(u.mentah, @dbv011_trim, '') kode
          FROM (          SELECT 'provinsi' tabel, 'id_provinsi' kolom, CONVERT(id_provinsi USING utf8mb4) id, CONVERT(id_provinsi USING utf8mb4) mentah, 2 n FROM provinsi
                UNION ALL SELECT 'kabupaten_kota', 'id_kabupaten_kota', CONVERT(id_kabupaten_kota USING utf8mb4), CONVERT(id_kabupaten_kota USING utf8mb4), 4 FROM kabupaten_kota
                UNION ALL SELECT 'kabupaten_kota', 'id_provinsi', CONVERT(id_kabupaten_kota USING utf8mb4), CONVERT(id_provinsi USING utf8mb4), 2 FROM kabupaten_kota
                UNION ALL SELECT 'kecamatan', 'id_kecamatan', CONVERT(id_kecamatan USING utf8mb4), CONVERT(id_kecamatan USING utf8mb4), 7 FROM kecamatan
                UNION ALL SELECT 'kecamatan', 'id_kabupaten_kota', CONVERT(id_kecamatan USING utf8mb4), CONVERT(id_kabupaten_kota USING utf8mb4), 4 FROM kecamatan
                UNION ALL SELECT 'kelurahan', 'id_kelurahan', CONVERT(id_kelurahan USING utf8mb4), CONVERT(id_kelurahan USING utf8mb4), 10 FROM kelurahan
                UNION ALL SELECT 'kelurahan', 'id_kecamatan', CONVERT(id_kelurahan USING utf8mb4), CONVERT(id_kecamatan USING utf8mb4), 7 FROM kelurahan
                UNION ALL SELECT 'kantor', 'id_provinsi', CONVERT(CAST(id_kantor AS CHAR) USING utf8mb4), CONVERT(id_provinsi USING utf8mb4), 2 FROM kantor
                UNION ALL SELECT 'kantor', 'id_kabupaten', CONVERT(CAST(id_kantor AS CHAR) USING utf8mb4), CONVERT(id_kabupaten USING utf8mb4), 4 FROM kantor
                UNION ALL SELECT 'kantor', 'id_kecamatan', CONVERT(CAST(id_kantor AS CHAR) USING utf8mb4), CONVERT(id_kecamatan USING utf8mb4), 7 FROM kantor
                UNION ALL SELECT 'kantor', 'id_kelurahan', CONVERT(CAST(id_kantor AS CHAR) USING utf8mb4), CONVERT(id_kelurahan USING utf8mb4), 10 FROM kantor) u) y
 -- Pola REGEXP sengaja konstan dan panjang dicek terpisah: versi awal dengan pola per baris CONCAT('^[0-9]{', n, '}$')
 -- melaporkan kode 7 digit yang benar sebagai "bukan 7 digit" pada salah satu sql_mode uji (MySQL 8.0.30, dokumen 6.4).
 WHERE y.mentah IS NOT NULL
   AND (NOT (y.kode REGEXP '^[0-9]+$' AND CHAR_LENGTH(y.kode) = y.n) OR CAST(y.kode AS BINARY) <> CAST(y.mentah AS BINARY))
 ORDER BY y.tabel, y.kolom, y.id;

-- ======================================================================= C. PROFIL & COUNTER AUTO_INCREMENT
-- Satu baris per tabel (28 master + pengguna). Dipakai untuk: status NULL / di luar 1/2/10 (st_lain harus 0 setelah
-- normalisasi, G-06 6.5 #5), kapasitas PK/urutan TINYINT v2 (maks. 127; G-04 6.3 #16, G-06 6.5 #6, G-07 6.3 #6),
-- counter AUTO_INCREMENT legacy yang WAJIB disalin setelah impor (syarat DBV-005 6.5 #10, dokumen 2.7; kesetiaannya
-- terhadap sumber dicek di dokumen 2.4 langkah 1), dan jumlah baris faq_rate / faq_related_article (G-10 6.3 #10).
-- st_null = status NULL (kolom status v2 NOT NULL → 1048 bila tidak dinormalkan); st_lain = status NULL ATAU di luar
-- 1/2/10 (jadi st_lain mencakup st_null). non_10 = baris tampil (status selain 10; NULL ikut dihitung tampil).
-- Kolom `cek` mengikuti isi `peringatan`:
--   WAJIB_0 profil  PK TINYINT > 127, atau baris tampil > 127 untuk master berurutan TINYINT global → impor gagal
--                   1264 (strict), sama dengan B6.
--   PERIKSA profil  status NULL atau di luar 1/2/10 (dinormalkan di langkah 5), atau counter >= 128 di tabel ber-PK
--                   TINYINT (counter itu tidak bisa disalin: INSERT berikutnya gagal 1467 → 500; dokumen 2.7).
--   INFO profil     tanpa peringatan.
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'provinsi' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_provinsi) id_maks,
       NULL counter_ai, 'CHAR(2)' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM provinsi) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'kabupaten_kota' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_kabupaten_kota) id_maks,
       NULL counter_ai, 'CHAR(4)' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM kabupaten_kota) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'kecamatan' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_kecamatan) id_maks,
       NULL counter_ai, 'CHAR(7)' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM kecamatan) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'kelurahan' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_kelurahan) id_maks,
       NULL counter_ai, 'CHAR(10)' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM kelurahan) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'agama' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_agama) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agama') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_agama) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agama') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM agama) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_pegawai' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_pegawai) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_pegawai') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_jenis_pegawai) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_pegawai') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenis_pegawai) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_status' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_status) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_status') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_jenis_status) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_status') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenis_status) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'faq_topic' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_faq_topic) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faq_topic') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM faq_topic) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'faq_sub_topic' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_faq_sub_topic) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faq_sub_topic') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM faq_sub_topic) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'faq_article' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_faq_article) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faq_article') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM faq_article) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_libur' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_libur) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_libur') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_jenis_libur) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_libur') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenis_libur) x;
-- hari_libur legacy tanpa kolom status (v2 mengisi 1, G-07 6.3 #10).
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'hari_libur' tabel, COUNT(*) baris, NULL st_1, NULL st_2, NULL st_10, NULL st_null, NULL st_lain, NULL non_10, MAX(id_libur) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hari_libur') counter_ai, 'INT' pk_v2,
       '' peringatan FROM hari_libur) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'bidang_kursem' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_bidang_kursem) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang_kursem') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_bidang_kursem) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang_kursem') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM bidang_kursem) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'instansi_kursem' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_instansi_kursem) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instansi_kursem') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_instansi_kursem) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instansi_kursem') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM instansi_kursem) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'kantor' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_kantor) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kantor') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM kantor) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'pangkat' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_pangkat) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pangkat') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_pangkat) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pangkat') >= 128, 'PERIKSA counter >= 128', NULL)) peringatan FROM pangkat) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_kp' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_kp) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_kp') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_jenis_kp) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_kp') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenis_kp) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'gol_pppk' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_gol_pppk) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gol_pppk') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10 (ENUM kosong = indeks 0)', NULL), IF(MAX(id_gol_pppk) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gol_pppk') >= 128, 'PERIKSA counter >= 128', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM gol_pppk) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenjang_pendidikan' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenjang_pendidikan) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenjang_pendidikan') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenjang_pendidikan) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'bidang_pendidikan' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_bidang_pendidikan) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang_pendidikan') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_bidang_pendidikan) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang_pendidikan') >= 128, 'PERIKSA counter >= 128', NULL)) peringatan FROM bidang_pendidikan) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jurusan_pendidikan' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jurusan_pendidikan) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jurusan_pendidikan') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM jurusan_pendidikan) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'diklat' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_diklat) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diklat') counter_ai, 'TINYINT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(MAX(id_diklat) > 127, 'WAJIB_0 PK > 127', NULL), IF((SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diklat') >= 128, 'PERIKSA counter >= 128', NULL)) peringatan FROM diklat) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'tingkat_hukdis' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_tingkat_hukdis) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tingkat_hukdis') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM tingkat_hukdis) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_hukdis' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_hukdis) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_hukdis') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL)) peringatan FROM jenis_hukdis) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'jenis_konket' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_jenis_konket) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'jenis_konket') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM jenis_konket) x;
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'tanda_jasa' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, SUM(IFNULL(CAST(status AS CHAR), '') <> '10') non_10, MAX(id_tanda_jasa) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tanda_jasa') counter_ai, 'INT' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL), IF(SUM(CAST(status AS CHAR) NOT IN ('1','2','10')) > 0, 'PERIKSA status di luar 1/2/10', NULL), IF(SUM(IFNULL(CAST(status AS CHAR), '') <> '10') > 127, 'WAJIB_0 urutan > 127', NULL)) peringatan FROM tanda_jasa) x;
-- faq_rate / faq_related_article: PK komposit tanpa AUTO_INCREMENT; cukup jumlah baris (G-10 6.3 #10: bila
-- faq_related_article berisi data, salin apa adanya).
SELECT 'INFO profil' cek, 'faq_rate' tabel, COUNT(*) baris, NULL st_1, NULL st_2, NULL st_10, NULL st_null, NULL st_lain, NULL non_10, NULL id_maks, NULL counter_ai, 'PK (id_faq_article, nip)' pk_v2, '' peringatan FROM faq_rate;
SELECT 'INFO profil' cek, 'faq_related_article' tabel, COUNT(*) baris, NULL st_1, NULL st_2, NULL st_10, NULL st_null, NULL st_lain, NULL non_10, NULL id_maks, NULL counter_ai, 'PK (id_article_main, id_article_related)' pk_v2, '' peringatan FROM faq_related_article;
-- pengguna: status legacy 0/1/2 (bukan pola master; pemetaannya di A-01), jadi st_lain berisi status 0 dan tidak
-- diberi peringatan. Status NULL tetap PERIKSA (kolom status v2 NOT NULL). Counter = ruang id_pengguna (A-01 9.4,
-- dokumen 2.7).
SELECT CASE WHEN LOCATE('WAJIB_0', x.peringatan) > 0 THEN 'WAJIB_0 profil' WHEN x.peringatan <> '' THEN 'PERIKSA profil' ELSE 'INFO profil' END cek, x.* FROM (SELECT 'pengguna' tabel, COUNT(*) baris, SUM(CAST(status AS CHAR) = '1') st_1, SUM(CAST(status AS CHAR) = '2') st_2, SUM(CAST(status AS CHAR) = '10') st_10, SUM(status IS NULL) st_null, SUM(status IS NULL OR CAST(status AS CHAR) NOT IN ('1','2','10')) st_lain, NULL non_10, MAX(id) id_maks,
       (SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengguna') counter_ai, 'INT UNSIGNED' pk_v2,
       CONCAT_WS('; ', IF(SUM(status IS NULL) > 0, 'PERIKSA status NULL (v2 NOT NULL)', NULL)) peringatan FROM pengguna) x;

-- ====================================================================== D. NAMA YANG BERUBAH / TIDAK MUAT (dinamis)
-- Untuk setiap kolom nama/unik: nilai setelah normalisasi impor (sama dengan kunci bagian A, tanpa COLLATE).
--   WAJIB_0  nilai NULL (ke-27 kolom katalog NOT NULL di v2 → 1048), atau hasil normalisasi kosong atau lebih panjang
--            dari kolom v2 (strict mode → 1406).
--   PERIKSA  nilai berubah oleh normalisasi (backslash, spasi ganda/awal/akhir, tab/baris baru) atau hasilnya masih
--            memuat backslash — tinjau per baris, terutama diklat (jalur dm_diklat mentah, 6.5 #2) dan jenis_hukdis
--            (disimpan mentah, tidak di-stripslashes). Nilai legacy ber-backslash dicocokkan ulang dengan stripslashes
--            PHP sebelum grup duplikat A diputuskan (dokumen 2.5).
-- Katalog (tabel, PK, kolom, panjang v2, normalisasi). Entri yang tabel/kolom/PK-nya tidak ada di salinan tidak bisa
-- diperiksa; daftarnya dicetak sebagai "PERIKSA D: … terlewat" (audit belum lengkap bila tabelnya ada di salinan).
SET @q = NULL, @d_lewat = NULL;
SELECT GROUP_CONCAT(IF(c.COLUMN_NAME IS NULL OR p.COLUMN_NAME IS NULL, NULL,
         CONCAT('SELECT IF(`', k.kolom, '` IS NULL OR v = '''' OR CHAR_LENGTH(v) > ', k.maks, ', ''WAJIB_0 nama_null_kosong_atau_lebih_dari_kolom_v2'', ''PERIKSA nama_berubah_atau_masih_ber_backslash'') cek, ',
                QUOTE(k.tabel), ' tabel, ', QUOTE(k.kolom), ' kolom, CAST(`', k.pk, '` AS CHAR) id, QUOTE(CONVERT(`', k.kolom, '` USING utf8mb4)) nilai_legacy, QUOTE(v) nilai_impor, ',
                'CHAR_LENGTH(v) panjang, ', k.maks, ' maks_v2 FROM (SELECT `', k.pk, '`, `', k.kolom, '`, ',
                REPLACE(CASE k.normal
                          WHEN 'nama_ss' THEN 'TRIM(REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(`{c}` USING utf8mb4), @dbv011_ss, ''$1''), ''[[:space:]]+'', '' ''))'
                          WHEN 'nama'    THEN 'TRIM(REGEXP_REPLACE(CONVERT(`{c}` USING utf8mb4), ''[[:space:]]+'', '' ''))'
                          ELSE                'REGEXP_REPLACE(REGEXP_REPLACE(CONVERT(`{c}` USING utf8mb4), @dbv011_ss, ''$1''), @dbv011_trim, '''')'
                        END, '{c}', k.kolom),
                ' v FROM `', k.tabel, '`) x WHERE `', k.kolom, '` IS NULL OR v = '''' OR CHAR_LENGTH(v) > ', k.maks,
                ' OR LOCATE(@dbv011_bs, v) > 0 OR CAST(v AS BINARY) <> CAST(CONVERT(`', k.kolom, '` USING utf8mb4) AS BINARY)'))
         ORDER BY k.urut SEPARATOR ' UNION ALL '),
       GROUP_CONCAT(IF(c.COLUMN_NAME IS NULL OR p.COLUMN_NAME IS NULL,
         CONCAT(k.tabel, '.', k.kolom, ' (', IF(t.TABLE_NAME IS NULL, 'tabel tidak ada', IF(c.COLUMN_NAME IS NULL, 'kolom tidak ada', CONCAT('PK ', k.pk, ' tidak ada'))), ')'),
         NULL) ORDER BY k.urut SEPARATOR '; ')
  INTO @q, @d_lewat
  FROM (          SELECT  1 urut, 'provinsi' tabel, 'id_provinsi' pk, 'provinsi' kolom, 255 maks, 'nama_ss' normal
        UNION ALL SELECT  2, 'kabupaten_kota', 'id_kabupaten_kota', 'kabupaten_kota', 255, 'nama_ss'
        UNION ALL SELECT  3, 'kecamatan', 'id_kecamatan', 'kecamatan', 255, 'nama_ss'
        UNION ALL SELECT  4, 'kelurahan', 'id_kelurahan', 'kelurahan', 255, 'nama_ss'
        UNION ALL SELECT  5, 'agama', 'id_agama', 'agama', 30, 'nama_ss'
        UNION ALL SELECT  6, 'jenis_pegawai', 'id_jenis_pegawai', 'jenis_pegawai', 50, 'nama_ss'
        UNION ALL SELECT  7, 'jenis_status', 'id_jenis_status', 'jenis_status', 50, 'nama_ss'
        UNION ALL SELECT  8, 'faq_topic', 'id_faq_topic', 'faq_topic', 255, 'nama_ss'
        UNION ALL SELECT  9, 'faq_sub_topic', 'id_faq_sub_topic', 'faq_sub_topic', 255, 'nama_ss'
        UNION ALL SELECT 10, 'faq_article', 'id_faq_article', 'title', 255, 'nama_ss'
        UNION ALL SELECT 11, 'jenis_libur', 'id_jenis_libur', 'jenis_libur', 255, 'nama_ss'
        UNION ALL SELECT 12, 'hari_libur', 'id_libur', 'nama_libur', 100, 'nama_ss'
        UNION ALL SELECT 13, 'bidang_kursem', 'id_bidang_kursem', 'bidang_kursem', 255, 'nama_ss'
        UNION ALL SELECT 14, 'instansi_kursem', 'id_instansi_kursem', 'instansi_kursem', 255, 'nama_ss'
        UNION ALL SELECT 15, 'kantor', 'id_kantor', 'nama_kantor', 255, 'nama_ss'
        UNION ALL SELECT 16, 'pangkat', 'id_pangkat', 'gol_ruang', 10, 'nama_ss'
        UNION ALL SELECT 17, 'jenis_kp', 'id_jenis_kp', 'jenis_kp', 100, 'nama_ss'
        UNION ALL SELECT 18, 'gol_pppk', 'id_gol_pppk', 'gol_pppk', 10, 'nama_ss'
        UNION ALL SELECT 19, 'jenjang_pendidikan', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 100, 'nama_ss'
        UNION ALL SELECT 20, 'jenjang_pendidikan', 'id_jenjang_pendidikan', 'jenjang_pendidikan_singkat', 50, 'kode_ss'
        UNION ALL SELECT 21, 'bidang_pendidikan', 'id_bidang_pendidikan', 'bidang_pendidikan', 100, 'nama_ss'
        UNION ALL SELECT 22, 'jurusan_pendidikan', 'id_jurusan_pendidikan', 'jurusan_pendidikan', 255, 'nama_ss'
        UNION ALL SELECT 23, 'diklat', 'id_diklat', 'nama_diklat', 255, 'nama_ss'
        UNION ALL SELECT 24, 'tingkat_hukdis', 'id_tingkat_hukdis', 'tingkat_hukdis', 100, 'nama_ss'
        UNION ALL SELECT 25, 'jenis_hukdis', 'id_jenis_hukdis', 'jenis_hukdis', 255, 'nama'
        UNION ALL SELECT 26, 'jenis_konket', 'id_jenis_konket', 'jenis_konket', 255, 'nama_ss'
        UNION ALL SELECT 27, 'tanda_jasa', 'id_tanda_jasa', 'tanda_jasa', 255, 'nama_ss') k
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = k.tabel
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = k.tabel AND c.COLUMN_NAME = k.kolom
  LEFT JOIN information_schema.COLUMNS p
    ON p.TABLE_SCHEMA = DATABASE() AND p.TABLE_NAME = k.tabel AND p.COLUMN_NAME = k.pk;
SELECT IF(@d_lewat IS NULL, 'INFO D: ke-27 entri katalog diperiksa',
          'PERIKSA D: entri katalog terlewat — audit belum lengkap bila tabelnya ada di salinan (sesuaikan nama kolom, jalankan ulang)') cek,
       @d_lewat terlewat;
SET @q = IFNULL(@q, 'SELECT ''INFO D: tidak ada tabel katalog di skema ini, atau kueri penyusun gagal (lihat ERROR di atas)'' cek');
SELECT 'INFO kueri_D' cek, @q kueri;
PREPARE audit_d FROM @q;
EXECUTE audit_d;
DEALLOCATE PREPARE audit_d;

-- ============================================================================== E. TANGGAL NOL (dinamis, WAJIB_0)
-- Seluruh kolom DATE/DATETIME/TIMESTAMP ke-28 tabel master + pengguna: tanggal nol / bulan-hari nol ditolak strict mode
-- v2 (1292) dan CONVERT_TZ mengembalikan NULL (aturan zona waktu R3, dokumen 3.2). Dibereskan sebelum konversi & impor.
-- Tabel daftar yang tidak ada di salinan dicetak sebagai "PERIKSA E: … tidak ada".
SET @q = NULL, @e_lewat = NULL;
SELECT GROUP_CONCAT(IF(c.COLUMN_NAME IS NULL, NULL,
         CONCAT('SELECT ''WAJIB_0 tanggal_nol'' cek, ', QUOTE(c.TABLE_NAME), ' tabel, ', QUOTE(c.COLUMN_NAME), ' kolom, COUNT(*) jumlah FROM `',
                c.TABLE_NAME, '` WHERE MONTH(`', c.COLUMN_NAME, '`) = 0 OR DAYOFMONTH(`', c.COLUMN_NAME, '`) = 0 HAVING COUNT(*) > 0'))
         ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION SEPARATOR ' UNION ALL '),
       GROUP_CONCAT(DISTINCT IF(t.TABLE_NAME IS NULL, x.tabel, NULL) ORDER BY IF(t.TABLE_NAME IS NULL, x.tabel, NULL) SEPARATOR ', ')
  INTO @q, @e_lewat
  FROM (          SELECT 'provinsi' tabel UNION ALL SELECT 'kabupaten_kota' UNION ALL SELECT 'kecamatan' UNION ALL SELECT 'kelurahan'
        UNION ALL SELECT 'agama' UNION ALL SELECT 'jenis_pegawai' UNION ALL SELECT 'jenis_status' UNION ALL SELECT 'faq_topic'
        UNION ALL SELECT 'faq_sub_topic' UNION ALL SELECT 'faq_article' UNION ALL SELECT 'faq_rate' UNION ALL SELECT 'faq_related_article'
        UNION ALL SELECT 'jenis_libur' UNION ALL SELECT 'hari_libur' UNION ALL SELECT 'bidang_kursem' UNION ALL SELECT 'instansi_kursem'
        UNION ALL SELECT 'kantor' UNION ALL SELECT 'pangkat' UNION ALL SELECT 'jenis_kp' UNION ALL SELECT 'gol_pppk'
        UNION ALL SELECT 'jenjang_pendidikan' UNION ALL SELECT 'bidang_pendidikan' UNION ALL SELECT 'jurusan_pendidikan'
        UNION ALL SELECT 'diklat' UNION ALL SELECT 'tingkat_hukdis' UNION ALL SELECT 'jenis_hukdis' UNION ALL SELECT 'jenis_konket'
        UNION ALL SELECT 'tanda_jasa' UNION ALL SELECT 'pengguna') x
  LEFT JOIN information_schema.TABLES t
    ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = x.tabel
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = x.tabel AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp');
SELECT IF(@e_lewat IS NULL, 'INFO E: ke-29 tabel daftar ada di salinan',
          'PERIKSA E: tabel tidak ada di salinan — pastikan memang tidak ada di produksi; bila bernama lain, audit belum lengkap') cek,
       @e_lewat tabel_tidak_ada;
SET @q = IFNULL(@q, 'SELECT ''INFO E: tidak ada kolom tanggal di tabel daftar, atau kueri penyusun gagal (lihat ERROR di atas)'' cek');
SELECT 'INFO kueri_E' cek, @q kueri;
PREPARE audit_e FROM @q;
EXECUTE audit_e;
DEALLOCATE PREPARE audit_e;

-- ------------------------------------------------------------------------ E2. Kolom TIMESTAMP legacy (aturan R1b)
-- Seluruh skema salinan. TIMESTAMP disimpan UTC dan dibaca mengikuti time_zone sesi: dibaca di sesi '+00:00' (R4)
-- nilainya sudah UTC dan TIDAK dikonversi lagi (R1b). Kolom DATETIME = jam dinding WIB → dikonversi (R1a). Dokumen 3.2.
SELECT 'INFO kolom_timestamp (R1b: baca di sesi +00:00, jangan dikonversi)' cek, TABLE_NAME tabel, COLUMN_NAME kolom,
       COLUMN_TYPE tipe, IS_NULLABLE boleh_null, COLUMN_DEFAULT bawaan, EXTRA ekstra
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'
 ORDER BY TABLE_NAME, ORDINAL_POSITION;

COMMIT;
-- Tindak lanjut: dokumen DBV-011 Bagian 2.4 (langkah runbook) dan 2.5 (prosedur dedupe). Jalankan ulang skrip ini
-- setelah setiap putaran dedupe sampai semua WAJIB_0 kosong; baru lanjut impor.
