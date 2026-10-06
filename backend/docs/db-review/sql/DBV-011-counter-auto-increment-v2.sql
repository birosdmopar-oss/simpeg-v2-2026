-- =====================================================================================================================
-- DBV-011 — Penyelarasan counter AUTO_INCREMENT setelah impor master legacy (syarat DBV-005 6.5 #10) — HANYA BACA
-- =====================================================================================================================
-- Dokumen: backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md Bagian 2.7.
--
-- Skrip ini TIDAK mengubah apa pun. Ia membandingkan counter AUTO_INCREMENT tabel v2 (skema aktif) dengan counter
-- tabel legacy bernama sama di skema salinan legacy pada instance yang sama, lalu MENCETAK perintah ALTER yang perlu
-- dijalankan. Perintah itu ditinjau dan dijalankan terpisah oleh pemegang akses (tulis skema = langkah runbook manual
-- setelah approval DBV, keputusan user ISSUE-014 29-09-2026), di jendela impor yang sama.
--
-- Pakai: SETELAH impor ID legacy apa adanya ke v2, dengan salinan legacy di-restore di instance yang sama. Nama skema
-- salinan diisi lewat --init-command (tanpa baris baru di dalam argumen, jadi bisa ditulis satu baris di shell Linux
-- maupun cmd Windows; opsi ini ada di klien MySQL 8 dan MariaDB):
--   mysql --table --default-character-set=utf8mb4 --init-command="SET @legacy = '<skema_salinan_legacy>'" \
--         --user=<akun> -p <db_v2> < DBV-011-counter-auto-increment-v2.sql
-- Skrip ini tidak punya error yang diharapkan di MySQL 8 maupun MariaDB, jadi cara lain (mis. -e "SET @legacy = …;"
-- lalu SOURCE di baris sendiri) juga berjalan sampai akhir. Error apa pun berarti hasil belum lengkap.
-- Bila salinan legacy tidak satu instance: pakai kolom `counter_ai` bagian C hasil skrip audit
-- (DBV-011-audit-duplikat-master-legacy.sql) dan tulis perintahnya manual dengan aturan yang sama.
-- Skrip ini membaca counter SALINAN, jadi hasilnya hanya benar bila counter salinan sama dengan sumber produksi. Dump
-- data-saja atau alat yang tidak menyertakan opsi tabel AUTO_INCREMENT= membuat counter salinan = MAX(id)+1 dan skrip
-- ini diam-diam menyalin terlalu sedikit. Kesetiaan itu dicek dulu di dokumen 2.4 langkah 1 (counter sumber vs salinan).
--
-- Aturan: impor dengan ID eksplisit hanya menaikkan counter ke MAX(id)+1. Legacy menghapus keras baris master, jadi
-- ID tertinggi yang pernah dihapus bisa dipakai ulang entri baru v2, sementara rujukan tanpa FK (siasn_simpeg, salinan
-- riwayat) masih menyimpan ID lama. Counter v2 dinaikkan ke counter legacy bila counter legacy lebih besar. Counter
-- tidak pernah diturunkan. PK TINYINT (maks. 127): counter >= 128 tidak bisa disalin → PERIKSA (perlu keputusan
-- pelebaran tipe, G-06 Bagian 3 #8 / G-04 6.3 #16). Diuji di MySQL 8.0.30: counter 128 (atau lebih) membuat setiap
-- INSERT gagal 1467 → 500, sedangkan tanpa ALTER, setelah id 127 terisi, INSERT berikutnya gagal 1062 yang
-- diterjemahkan aplikasi menjadi 422 "Kode … sudah mencapai batas" — jadi di MySQL 8 counter 128 justru memperburuk
-- keadaan. Di MariaDB 10.4 TINYINT yang habis memberi error 167 (G-04 Bagian 8 #10); perilaku AUTO_INCREMENT = 128 di
-- sana belum diuji (checklist dokumen Bagian 5). Keputusan "tidak disalin" aman di kedua engine.
-- Bila @legacy kosong atau skemanya tidak ada, skrip hanya mencetak BERHENTI (kueri utama menghasilkan Empty set).
-- =====================================================================================================================

-- MySQL 8: baca counter terkini (information_schema bawaan di-cache 86400 detik). MariaDB tidak punya variabel ini
-- (dan tidak meng-cache): penyetelan bersyarat, sehingga tidak ada error 1193 yang menghentikan SOURCE/skrip.
SET @dbv011_s = IF(VERSION() LIKE '%MariaDB%', 'DO 0', 'SET SESSION information_schema_stats_expiry = 0');
PREPARE dbv011_s FROM @dbv011_s;
EXECUTE dbv011_s;
DEALLOCATE PREPARE dbv011_s;

SELECT CASE WHEN @legacy IS NULL OR @legacy = '' THEN 'BERHENTI: set @legacy = nama skema salinan legacy dulu'
            WHEN NOT EXISTS (SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = @legacy)
              THEN CONCAT('BERHENTI: skema ', @legacy, ' tidak ditemukan di instance ini')
            ELSE CONCAT('legacy = ', @legacy, ', v2 = ', DATABASE()) END info;

SELECT v.TABLE_NAME tabel,
       l.AUTO_INCREMENT counter_legacy,
       v.AUTO_INCREMENT counter_v2,
       c.COLUMN_TYPE pk_v2,
       CASE
         WHEN l.TABLE_NAME IS NULL THEN 'INFO tabel tidak ada di salinan legacy'
         WHEN l.AUTO_INCREMENT IS NULL OR v.AUTO_INCREMENT IS NULL THEN 'PERIKSA counter tidak terbaca'
         -- Diuji di MySQL 8.0.30: TINYINT dengan counter >= 128 → setiap INSERT gagal 1467 "Failed to read
         -- auto-increment value from storage engine" (tidak diterjemahkan aplikasi → 500).
         WHEN c.DATA_TYPE = 'tinyint' AND GREATEST(l.AUTO_INCREMENT, v.AUTO_INCREMENT) >= 128
           THEN CONCAT('PERIKSA counter ', GREATEST(l.AUTO_INCREMENT, v.AUTO_INCREMENT), ' melewati kapasitas TINYINT (maks. id 127) — jangan disalin, putuskan pelebaran tipe')
         WHEN l.AUTO_INCREMENT <= v.AUTO_INCREMENT THEN '-- tidak perlu (counter v2 sudah >= legacy)'
         ELSE CONCAT('ALTER TABLE `', v.TABLE_NAME, '` AUTO_INCREMENT = ', l.AUTO_INCREMENT, ';')
       END perintah
  FROM information_schema.TABLES v
  JOIN information_schema.KEY_COLUMN_USAGE k
    ON k.TABLE_SCHEMA = v.TABLE_SCHEMA AND k.TABLE_NAME = v.TABLE_NAME AND k.CONSTRAINT_NAME = 'PRIMARY'
  JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA = v.TABLE_SCHEMA AND c.TABLE_NAME = v.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
  LEFT JOIN information_schema.TABLES l
    ON l.TABLE_SCHEMA = @legacy AND l.TABLE_NAME = v.TABLE_NAME
 WHERE v.TABLE_SCHEMA = DATABASE()
   AND @legacy IS NOT NULL AND @legacy <> ''
   AND EXISTS (SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = @legacy)
   AND c.EXTRA LIKE '%auto_increment%'
   AND v.TABLE_NAME IN ('agama', 'jenis_pegawai', 'jenis_status', 'faq_topic', 'faq_sub_topic', 'faq_article', 'jenis_libur',
                        'hari_libur', 'bidang_kursem', 'instansi_kursem', 'kantor', 'pangkat', 'jenis_kp', 'gol_pppk',
                        'jenjang_pendidikan', 'bidang_pendidikan', 'jurusan_pendidikan', 'diklat', 'tingkat_hukdis',
                        'jenis_hukdis', 'jenis_konket', 'tanda_jasa', 'pengguna')
 ORDER BY v.TABLE_NAME;

-- Setelah perintah ALTER dijalankan: jalankan ulang skrip ini — setiap baris harus berisi "-- tidak perlu" atau
-- "INFO"; baris "PERIKSA" dicatat di kartu DBV-011 bersama keputusannya.
