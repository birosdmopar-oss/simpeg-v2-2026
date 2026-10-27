-- =====================================================================================================================
-- DBV-022 — Audit data layanan Fase 4 (cuti, TB, IB, PMK, inbox/ticketing, PIC, e-sign) — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen langkah: backend/docs/db-review/DBV-022-layanan-fase4-schema.md Bagian 6 (digabung ke runbook impor
-- DBV-011 Bagian 2.10). Sumber daftar kueri: PRD Fase 4 (`docs/fase4/PRD_Fase4.md`) §6.1.2 butir 7 (sepuluh butir).
--
-- Tujuan: membuktikan nilai di data legacy memenuhi syarat sebelum skema Fase 4 dipasang dan sebelum impor:
--   - FK `riwayat_cuti.nip_atasan_langsung` / `nip_yang_menyetujui` -> `pegawai.nip` (DQ-3): yatim harus 0 sebelum
--     FK diaktifkan; selama masih > 0, FK tetap DITAHAN di `app/Database/MigrationsDitahan/` (pola DBV-019/DBV-020);
--   - domain nilai status/flag yang akan dijaga CHECK [V2] (DQ-1, DQ-12, DQ-18);
--   - syarat UNIQUE yang diusulkan: `pegawai_sisa_cuti (nip, tahun)` (DQ-10), `petugas_layanan (id_jenis_layanan, nip)`
--     (DQ-12), tabrakan setelah penyeragaman collation ke `utf8mb4_unicode_ci` (G2);
--   - panjang kolom NIP yang diturunkan ke VARCHAR(30) (V4) dan elemen non-NIP di `riwayat_cuti.ketua_tim` (DQ-15).
--
-- Klasifikasi (arti awalan sama dengan DBV-011 2.3):
--   WAJIB_0 = harus 0 sebelum migration/aktivasi FK atau sebelum impor dibuka; > 0 menghentikan langkah itu.
--   INFO    = dilaporkan untuk keputusan DB Validator (pemetaan/normalisasi impor), tidak memblokir.
--
-- Nama tabel/kolom = D1 (dump struktur produksi `simpeg01` 01-10-2026). Tabel pengguna legacy: sesuaikan nama tabel
-- dan kolom id pada Bagian 7.3 dengan skema legacy yang dipakai (catat substitusinya di log DBV-011 2.8).
--
-- Cara pakai (akun baca saja; tanpa --force agar error langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_salinan_legacy> \
--         < backend/docs/db-review/sql/DBV-022-audit-layanan.sql > hasil_audit_layanan.txt 2>&1
--
-- LARANGAN: jangan jalankan di DB produksi legacy yang sedang melayani aplikasi, dan jangan di DB dev v2
-- (`simpeg_v2`). Jalankan di SALINAN dump final saat freeze atau salinan gladi (DBV-011 2.4 langkah 1).
-- Skrip ini hanya berisi SELECT.
--
-- Collation campuran (temuan 27-10-2026, error 1267 "Illegal mix of collations"): di D1, tabel legacy `inbox`, `inbox_msg`,
-- `question_category`, `pa_layanan`, `jenis_pa`, dan `firebase_token` ber-collation `utf8mb4_0900_ai_ci`, sedangkan
-- `pegawai` dan tabel layanan lain `utf8mb4_unicode_ci`. Setiap perbandingan string lintas kedua kelompok memakai
-- `COLLATE utf8mb4_unicode_ci` eksplisit di sisi kolom legacy (indeks `pegawai.nip` tetap terpakai). Perbandingan id INT
-- tidak terpengaruh. Bahan G2: collation diseragamkan ke `utf8mb4_unicode_ci` saat impor (DBV-022 Bagian 12.6).
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 1 — WAJIB_0 (prasyarat aktivasi FK). `riwayat_cuti` NIP approver: yatim, string kosong, panjang > 30.
-- Rujukan: PRD §6.1.1 #1, DQ-3; V4 (kolom NIP ber-FK VARCHAR(30)).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'nip_atasan_langsung_yatim' AS metrik, COUNT(*) AS jumlah
FROM riwayat_cuti c
WHERE c.nip_atasan_langsung IS NOT NULL
  AND TRIM(c.nip_atasan_langsung) <> ''
  AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip_atasan_langsung)
UNION ALL
SELECT 'nip_yang_menyetujui_yatim', COUNT(*)
FROM riwayat_cuti c
WHERE c.nip_yang_menyetujui IS NOT NULL
  AND TRIM(c.nip_yang_menyetujui) <> ''
  AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip_yang_menyetujui)
UNION ALL
SELECT 'nip_rwycuti_yatim', COUNT(*)
FROM riwayat_cuti c
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip);

-- INFO: nilai kosong (bukan NULL) dan panjang melebihi 30 — menentukan perlakuan impor (NULL-kan vs perbaiki).
SELECT 'nip_atasan_langsung_kosong' AS metrik, COUNT(*) AS jumlah
FROM riwayat_cuti WHERE nip_atasan_langsung IS NOT NULL AND TRIM(nip_atasan_langsung) = ''
UNION ALL
SELECT 'nip_yang_menyetujui_kosong', COUNT(*)
FROM riwayat_cuti WHERE nip_yang_menyetujui IS NOT NULL AND TRIM(nip_yang_menyetujui) = ''
UNION ALL
SELECT 'nip_atasan_langsung_lebih_30', COUNT(*)
FROM riwayat_cuti WHERE CHAR_LENGTH(nip_atasan_langsung) > 30
UNION ALL
SELECT 'nip_yang_menyetujui_lebih_30', COUNT(*)
FROM riwayat_cuti WHERE CHAR_LENGTH(nip_yang_menyetujui) > 30
UNION ALL
SELECT 'maks_panjang_nip_approver', GREATEST(
    COALESCE(MAX(CHAR_LENGTH(nip_atasan_langsung)), 0),
    COALESCE(MAX(CHAR_LENGTH(nip_yang_menyetujui)), 0))
FROM riwayat_cuti;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 2 — INFO + prasyarat CHECK [V2]. Distribusi domain `riwayat_cuti`.
-- Rujukan: PRD §6.1.1 #1 (CHECK status 0..4, status_al 0/1/2/4, status_pyb 0..4, skip_* 0/1, status_regen 0/1,
-- rated 0/1/2), D1 (status [K] 0 Pengajuan, 3 Disetujui AL, 1 Disetujui, 2 Ditolak, 4 Ditangguhkan).
-- Nilai di luar domain => normalisasi impor atau pelebaran CHECK; diputuskan DB Validator (DQ-1, DQ-18).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'status' AS kolom, CAST(status AS CHAR) AS nilai, COUNT(*) AS jumlah FROM riwayat_cuti GROUP BY status
UNION ALL
SELECT 'status_al', CAST(status_al AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY status_al
UNION ALL
SELECT 'status_pyb', CAST(status_pyb AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY status_pyb
UNION ALL
SELECT 'rated', CAST(rated AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY rated
UNION ALL
SELECT 'skip_al', CAST(skip_al AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY skip_al
UNION ALL
SELECT 'skip_pyb', CAST(skip_pyb AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY skip_pyb
UNION ALL
SELECT 'status_regen', CAST(status_regen AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY status_regen
UNION ALL
SELECT 'id_jenis_cuti', CAST(id_jenis_cuti AS CHAR), COUNT(*) FROM riwayat_cuti GROUP BY id_jenis_cuti
ORDER BY kolom, nilai;

-- INFO: `satuan_lama_cuti` di luar 'Hari' dan `lama_cuti` <= 0 (memeriksa asumsi §6.3.2).
SELECT 'satuan_lama_cuti' AS kolom, COALESCE(satuan_lama_cuti, '(NULL)') AS nilai, COUNT(*) AS jumlah
FROM riwayat_cuti GROUP BY satuan_lama_cuti
UNION ALL
SELECT 'lama_cuti_nol_atau_negatif', '-', COUNT(*) FROM riwayat_cuti WHERE lama_cuti <= 0;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 3 — WAJIB_0 untuk UNIQUE usulan. `pegawai_sisa_cuti`: duplikat (nip, tahun), tahun tidak wajar, nilai negatif.
-- Rujukan: PRD §6.1.1 #4, DQ-10 (UNIQUE (nip, tahun) bila audit duplikat = 0; CHECK nilai >= 0).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'psc_pasangan_duplikat' AS metrik, COUNT(*) AS jumlah FROM (
    SELECT nip, tahun FROM pegawai_sisa_cuti GROUP BY nip, tahun HAVING COUNT(*) > 1
) d;

-- INFO: rincian duplikat (10 teratas) untuk keputusan penggabungan baris saat impor.
SELECT nip, tahun, COUNT(*) AS baris
FROM pegawai_sisa_cuti GROUP BY nip, tahun HAVING COUNT(*) > 1
ORDER BY baris DESC, nip LIMIT 10;

SELECT 'psc_tahun_nol' AS metrik, COUNT(*) AS jumlah FROM pegawai_sisa_cuti WHERE tahun = 0 OR tahun IS NULL
UNION ALL
SELECT 'psc_nip_yatim', COUNT(*) FROM pegawai_sisa_cuti s
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = s.nip)
UNION ALL
SELECT 'psc_nilai_negatif', COUNT(*) FROM pegawai_sisa_cuti
WHERE sisa_cuti < 0 OR sisa_cuti_n1 < 0 OR sisa_cuti_n2 < 0 OR sisa_cuti_ditangguhkan < 0 OR utang_cuti < 0
UNION ALL
-- INFO: baris yang melanggar batas D8 (N<=12, N-1<=6, N-2<=6, utang<=12) — menentukan apakah CHECK batas atas
-- bisa dipasang atau hanya divalidasi di aplikasi (§6.3.6).
SELECT 'psc_melebihi_batas_d8', COUNT(*) FROM pegawai_sisa_cuti
WHERE sisa_cuti > 12 OR sisa_cuti_n1 > 6 OR sisa_cuti_n2 > 6 OR utang_cuti > 12
UNION ALL
SELECT 'psc_digunakan_di_luar_0_1', COUNT(*) FROM pegawai_sisa_cuti WHERE digunakan NOT IN (0, 1);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 4 — INFO + prasyarat CHECK. Distribusi status TB / IB / PMK req dan `jenis_pmk`.
-- Rujukan: PRD §6.1.1 #5, #7, #9; DQ-18 (CHECK TB/IB 0..3, PMK req 0..3, default 1 [K] atau 0 [V2]).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'riwayat_tb.status' AS kolom, CAST(status AS CHAR) AS nilai, COUNT(*) AS jumlah FROM riwayat_tb GROUP BY status
UNION ALL
SELECT 'riwayat_tb.status_admin_upt', CAST(status_admin_upt AS CHAR), COUNT(*) FROM riwayat_tb GROUP BY status_admin_upt
UNION ALL
SELECT 'riwayat_tb.status_admin_deputi', CAST(status_admin_deputi AS CHAR), COUNT(*) FROM riwayat_tb GROUP BY status_admin_deputi
UNION ALL
SELECT 'riwayat_ib.status', CAST(status AS CHAR), COUNT(*) FROM riwayat_ib GROUP BY status
UNION ALL
SELECT 'riwayat_pmk.status', CAST(status AS CHAR), COUNT(*) FROM riwayat_pmk GROUP BY status
UNION ALL
SELECT 'riwayat_pmk_req.status', CAST(status AS CHAR), COUNT(*) FROM riwayat_pmk_req GROUP BY status
UNION ALL
SELECT 'riwayat_pmk_req.jenis_pmk', COALESCE(jenis_pmk, '(NULL)'), COUNT(*) FROM riwayat_pmk_req GROUP BY jenis_pmk
ORDER BY kolom, nilai;

-- WAJIB_0: NIP yatim di keempat tabel layanan (FK -> pegawai RESTRICT).
SELECT 'riwayat_tb_nip_yatim' AS metrik, COUNT(*) AS jumlah FROM riwayat_tb t
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = t.nip)
UNION ALL
SELECT 'riwayat_ib_nip_yatim', COUNT(*) FROM riwayat_ib i
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = i.nip)
UNION ALL
SELECT 'riwayat_pmk_nip_yatim', COUNT(*) FROM riwayat_pmk m
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = m.nip)
UNION ALL
SELECT 'riwayat_pmk_req_nip_yatim', COUNT(*) FROM riwayat_pmk_req r
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = r.nip)
UNION ALL
-- WAJIB_0: perpanjangan TB yatim terhadap induk (FK RESTRICT, hapus anak dikerjakan service §6.7.4).
SELECT 'riwayat_tb_perpan_induk_yatim', COUNT(*) FROM riwayat_tb_perpan x
WHERE NOT EXISTS (SELECT 1 FROM riwayat_tb t WHERE t.id_riwayat_tb = x.id_riwayat_tb);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 5 — `petugas_layanan` (PIC): NIP yatim/panjang, status di luar domain, jenis layanan NULL, duplikat.
-- Rujukan: PRD §6.1.1 #16, DQ-12 (domain status [K] 0/1 — kode juga memakai '10'; UNIQUE (id_jenis_layanan, nip)).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'pl_nip_yatim' AS metrik, COUNT(*) AS jumlah FROM petugas_layanan l
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = l.nip)
UNION ALL
SELECT 'pl_nip_lebih_30', COUNT(*) FROM petugas_layanan WHERE CHAR_LENGTH(nip) > 30
UNION ALL
SELECT 'pl_status_di_luar_0_1_10', COUNT(*) FROM petugas_layanan WHERE CAST(status AS CHAR) NOT IN ('0', '1', '10')
UNION ALL
SELECT 'pl_id_jenis_layanan_null', COUNT(*) FROM petugas_layanan WHERE id_jenis_layanan IS NULL
UNION ALL
SELECT 'pl_id_jenis_layanan_yatim', COUNT(*) FROM petugas_layanan l
WHERE l.id_jenis_layanan IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jenis_layanan j WHERE j.id_jenis_layanan = l.id_jenis_layanan)
UNION ALL
SELECT 'pl_pasangan_duplikat', (SELECT COUNT(*) FROM (
    SELECT id_jenis_layanan, nip FROM petugas_layanan GROUP BY id_jenis_layanan, nip HAVING COUNT(*) > 1
) d);

SELECT CAST(status AS CHAR) AS status, COUNT(*) AS jumlah FROM petugas_layanan GROUP BY status ORDER BY status;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 6 — `pa_layanan` (aturan PyB): id master yatim dan NIP yatim.
-- Rujukan: PRD §6.1.1 #17 (11 FK SET NULL -> RESTRICT, V1), DQ-17.
-- Master G-02 sudah ada di `main`; pemeriksaan dijalankan terhadap master legacy dengan nama kolom D1.
-- `pa_layanan` D1 = `utf8mb4_0900_ai_ci`: NIP dibandingkan dengan `COLLATE utf8mb4_unicode_ci`; id master = INT.
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'pa_nip_yatim' AS metrik, COUNT(*) AS jumlah FROM pa_layanan a
WHERE a.nip IS NOT NULL AND TRIM(a.nip) <> ''
  AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = a.nip COLLATE utf8mb4_unicode_ci)
UNION ALL
SELECT 'pa_nip_lebih_30', COUNT(*) FROM pa_layanan WHERE CHAR_LENGTH(nip) > 30
UNION ALL
SELECT 'pa_id_jenis_pa_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_jenis_pa IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jenis_pa j WHERE j.id_jenis_pa = a.id_jenis_pa)
UNION ALL
SELECT 'pa_id_unit_pemohon_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_unit_pemohon IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM unit u WHERE u.id_unit = a.id_unit_pemohon)
UNION ALL
SELECT 'pa_id_unit_petugas_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_unit_petugas IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM unit u WHERE u.id_unit = a.id_unit_petugas)
UNION ALL
SELECT 'pa_id_satker_petugas_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_satker_petugas IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM satker s WHERE s.id_satker = a.id_satker_petugas)
UNION ALL
SELECT 'pa_id_jabatan_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_jabatan IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = a.id_jabatan)
UNION ALL
SELECT 'pa_id_jabatan_koord_yatim', COUNT(*) FROM pa_layanan a
WHERE a.id_jabatan_koord IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jabatan_koordinasi k WHERE k.id_jabatan_koordinasi = a.id_jabatan_koord);
-- Catatan: pola yang sama diulang untuk `id_jenis_pegawai`, `id_group_jabatan_pemohon`,
-- `id_sub_group_jabatan_pemohon`, `id_group_jabatan_petugas`, `id_sub_group_jabatan_petugas` terhadap master
-- `jenis_pegawai`, `group_jabatan`, `sub_group_jabatan` (daftar 11 FK: DBV-022 Bagian 2 butir 17).


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 7 — `inbox` / `inbox_msg` LEGACY (= calon `disiplin_ticket` / `disiplin_ticket_msg`, D10).
-- Rujukan: PRD §6.1.1 #10, #11, #14; DQ-4, DQ-6; temuan T1 (legacy `inbox` 100% tiket Halo Simpeg).
-- CATATAN PENTING: seluruh isi `inbox`/`inbox_msg` legacy diimpor ke `disiplin_ticket(_msg)` (Fase 8), BUKAN ke
-- `inbox` v2 (notifikasi satu arah) — lihat DBV-022 Bagian 3.
-- ---------------------------------------------------------------------------------------------------------------------
-- 7.1 Integritas kategori & NIP. `inbox`/`inbox_msg`/`question_category` D1 = `utf8mb4_0900_ai_ci`: NIP dibandingkan
-- dengan `COLLATE utf8mb4_unicode_ci`; `id_question_category`/`id_inbox` = INT.
SELECT 'inbox_id_question_category_null' AS metrik, COUNT(*) AS jumlah FROM inbox WHERE id_question_category IS NULL
UNION ALL
SELECT 'inbox_id_question_category_yatim', COUNT(*) FROM inbox i
WHERE i.id_question_category IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM question_category q WHERE q.id_question_category = i.id_question_category)
UNION ALL
SELECT 'inbox_nip_yatim', COUNT(*) FROM inbox i
WHERE i.nip IS NOT NULL AND TRIM(i.nip) <> ''
  AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = i.nip COLLATE utf8mb4_unicode_ci)
UNION ALL
SELECT 'inbox_nip_lebih_30', COUNT(*) FROM inbox WHERE CHAR_LENGTH(nip) > 30;

-- 7.2 Domain status/flag tiket (CHECK [V2]: status 0/1 [+10 bila DBV memutuskan hapus lunak], allow_reply 0/1,
-- is_read/is_read_off 1/2, notify_pic 0/1/2).
SELECT 'inbox.status' AS kolom, CAST(status AS CHAR) AS nilai, COUNT(*) AS jumlah FROM inbox GROUP BY status
UNION ALL
SELECT 'inbox.allow_reply', CAST(allow_reply AS CHAR), COUNT(*) FROM inbox GROUP BY allow_reply
UNION ALL
SELECT 'inbox.is_read', CAST(is_read AS CHAR), COUNT(*) FROM inbox GROUP BY is_read
UNION ALL
SELECT 'inbox.is_read_off', CAST(is_read_off AS CHAR), COUNT(*) FROM inbox GROUP BY is_read_off
UNION ALL
SELECT 'inbox.notify_pic', CAST(notify_pic AS CHAR), COUNT(*) FROM inbox GROUP BY notify_pic
ORDER BY kolom, nilai;

-- 7.3 Pelaku tiket tanpa baris pengguna (DQ-6: FK ke `pengguna` atau tanpa FK; nilai 0 = "semua petugas").
-- Ganti `<tabel_pengguna_legacy>` dan `<kolom_id_pengguna_legacy>` sesuai skema legacy sebelum dijalankan.
-- `sender`/`receiver`/`closed_by` = INT (tanpa collation); bila diganti ke kolom string, tambahkan COLLATE seperti 7.1.
-- SELECT 'inbox_sender_tanpa_pengguna' AS metrik, COUNT(*) AS jumlah FROM inbox i
-- WHERE i.sender <> 0 AND NOT EXISTS (
--     SELECT 1 FROM <tabel_pengguna_legacy> u WHERE u.<kolom_id_pengguna_legacy> = i.sender)
-- UNION ALL
-- SELECT 'inbox_receiver_tanpa_pengguna', COUNT(*) FROM inbox i
-- WHERE i.receiver <> 0 AND NOT EXISTS (
--     SELECT 1 FROM <tabel_pengguna_legacy> u WHERE u.<kolom_id_pengguna_legacy> = i.receiver)
-- UNION ALL
-- SELECT 'inbox_closed_by_tanpa_pengguna', COUNT(*) FROM inbox i
-- WHERE i.closed_by IS NOT NULL AND i.closed_by <> 0 AND NOT EXISTS (
--     SELECT 1 FROM <tabel_pengguna_legacy> u WHERE u.<kolom_id_pengguna_legacy> = i.closed_by);

-- 7.4 `inbox_msg`: induk NULL/yatim (usulan [V2] `id_inbox` NOT NULL).
SELECT 'inbox_msg_id_inbox_null' AS metrik, COUNT(*) AS jumlah FROM inbox_msg WHERE id_inbox IS NULL
UNION ALL
SELECT 'inbox_msg_id_inbox_yatim', COUNT(*) FROM inbox_msg m
WHERE m.id_inbox IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM inbox i WHERE i.id_inbox = m.id_inbox);

-- 7.5 Tabrakan setelah penyeragaman collation `utf8mb4_0900_ai_ci`/`utf8mb4_general_ci` -> `utf8mb4_unicode_ci` (G2).
-- `question_category` dipakai sebagai kandidat UNIQUE nama kategori; > 0 berarti nama bertabrakan setelah konversi.
SELECT 'question_category_tabrakan_unicode_ci' AS metrik, COUNT(*) AS jumlah FROM (
    SELECT CONVERT(question_category USING utf8mb4) COLLATE utf8mb4_unicode_ci AS k
    FROM question_category
    GROUP BY k HAVING COUNT(*) > 1
) d;

SELECT 'question_category.status' AS kolom, CAST(status AS CHAR) AS nilai, COUNT(*) AS jumlah
FROM question_category GROUP BY status ORDER BY nilai;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 8 — `esign_hist`: domain status dan NIP yatim.
-- Rujukan: PRD §6.1.1 #19, DQ-7 (status VARCHAR '1' Berhasil / '2' Gagal; FK nip nullable RESTRICT; append-only).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'esign_status_di_luar_1_2' AS metrik, COUNT(*) AS jumlah
FROM esign_hist WHERE status IS NULL OR status NOT IN ('1', '2')
UNION ALL
SELECT 'esign_nip_yatim', COUNT(*) FROM esign_hist e
WHERE e.nip IS NOT NULL AND TRIM(e.nip) <> ''
  AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = e.nip)
UNION ALL
SELECT 'esign_nip_lebih_30', COUNT(*) FROM esign_hist WHERE CHAR_LENGTH(nip) > 30
UNION ALL
-- INFO: panjang `ip_address` tersimpan (usulan [V2] VARCHAR(45) untuk IPv6).
SELECT 'esign_ip_lebih_20', COUNT(*) FROM esign_hist WHERE CHAR_LENGTH(ip_address) > 20;

SELECT COALESCE(modul, '(NULL)') AS modul, COUNT(*) AS jumlah FROM esign_hist GROUP BY modul ORDER BY jumlah DESC;

-- INFO: `firebase_token` (tabel tanpa PK di D1; usulan [V2] PK surrogate + FK nip). D1 = `utf8mb4_0900_ai_ci`.
SELECT 'firebase_token_baris' AS metrik, COUNT(*) AS jumlah FROM firebase_token
UNION ALL
SELECT 'firebase_token_nip_yatim', COUNT(*) FROM firebase_token f
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = f.nip COLLATE utf8mb4_unicode_ci)
UNION ALL
SELECT 'firebase_token_nip_duplikat', (SELECT COUNT(*) FROM (
    SELECT nip FROM firebase_token GROUP BY nip HAVING COUNT(*) > 1
) d);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 9 — `riwayat_cuti.ketua_tim`: elemen yang bukan NIP pegawai (TEXT, dipisah '***').
-- Rujukan: PRD §6.2.5, DQ-15 (jenis registry baru untuk `ketua_tim`), §6.1.1 #1.
-- Tanpa tabel bantu angka, pemecahan dibatasi 5 elemen pertama per baris (cukup untuk mendeteksi pola yang salah).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'ketua_tim_terisi' AS metrik, COUNT(*) AS jumlah
FROM riwayat_cuti WHERE ketua_tim IS NOT NULL AND TRIM(ketua_tim) <> ''
UNION ALL
SELECT 'ketua_tim_maks_elemen',
       COALESCE(MAX((CHAR_LENGTH(ketua_tim) - CHAR_LENGTH(REPLACE(ketua_tim, '***', ''))) / 3 + 1), 0)
FROM riwayat_cuti WHERE ketua_tim IS NOT NULL AND TRIM(ketua_tim) <> '';

SELECT n AS posisi_elemen, COUNT(*) AS elemen_bukan_nip_pegawai
FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) angka
JOIN riwayat_cuti c
  ON c.ketua_tim IS NOT NULL AND TRIM(c.ketua_tim) <> ''
 AND (CHAR_LENGTH(c.ketua_tim) - CHAR_LENGTH(REPLACE(c.ketua_tim, '***', ''))) / 3 + 1 >= angka.n
WHERE TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.ketua_tim, '***', angka.n), '***', -1)) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM pegawai p
      WHERE p.nip = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.ketua_tim, '***', angka.n), '***', -1)))
GROUP BY n ORDER BY n;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 10 — `riwayat_cuti_notif_kt` (tembusan ketua tim): distribusi status dan baris yatim.
-- Rujukan: PRD §6.1.1 #2 (PK (id_riwayat_cuti, nip_kt); status 0 Belum Dibaca / 1 Sudah / 2 Delete; FK RESTRICT).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'rcnkt_baris' AS metrik, COUNT(*) AS jumlah FROM riwayat_cuti_notif_kt
UNION ALL
SELECT 'rcnkt_cuti_yatim', COUNT(*) FROM riwayat_cuti_notif_kt k
WHERE NOT EXISTS (SELECT 1 FROM riwayat_cuti c WHERE c.id_riwayat_cuti = k.id_riwayat_cuti)
UNION ALL
SELECT 'rcnkt_nip_kt_yatim', COUNT(*) FROM riwayat_cuti_notif_kt k
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = k.nip_kt)
UNION ALL
SELECT 'rcnkt_nip_kt_lebih_30', COUNT(*) FROM riwayat_cuti_notif_kt WHERE CHAR_LENGTH(nip_kt) > 30;

SELECT CAST(status AS CHAR) AS status, COUNT(*) AS jumlah
FROM riwayat_cuti_notif_kt GROUP BY status ORDER BY status;



-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 11 — `pegawai_sisa_cuti`: saldo negatif atau di luar batas D8 per kolom (PRD v1.1 §6.1.2 #7 butir 11, DQ-23).
-- Rujukan: CHECK v2 `chk_psc_saldo_nonnegatif` (>= 0) WAJIB_0; batas atas D8 (12/6/6/12) INFO — ditegakkan aplikasi,
-- bukan CHECK, tetapi nilai di atasnya perlu dicatat untuk rekonsiliasi kuota (PRD §6.3.6).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'psc_sisa_cuti_negatif' AS metrik, COUNT(*) AS jumlah FROM pegawai_sisa_cuti WHERE sisa_cuti < 0
UNION ALL
SELECT 'psc_sisa_cuti_n1_negatif', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti_n1 < 0
UNION ALL
SELECT 'psc_sisa_cuti_n2_negatif', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti_n2 < 0
UNION ALL
SELECT 'psc_sisa_cuti_ditangguhkan_negatif', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti_ditangguhkan < 0
UNION ALL
SELECT 'psc_utang_cuti_negatif', COUNT(*) FROM pegawai_sisa_cuti WHERE utang_cuti < 0
UNION ALL
-- INFO: di luar batas atas D8.
SELECT 'psc_sisa_cuti_lebih_12', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti > 12
UNION ALL
SELECT 'psc_sisa_cuti_n1_lebih_6', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti_n1 > 6
UNION ALL
SELECT 'psc_sisa_cuti_n2_lebih_6', COUNT(*) FROM pegawai_sisa_cuti WHERE sisa_cuti_n2 > 6
UNION ALL
SELECT 'psc_utang_cuti_lebih_12', COUNT(*) FROM pegawai_sisa_cuti WHERE utang_cuti > 12
UNION ALL
SELECT 'psc_total_lebih_24', COUNT(*) FROM pegawai_sisa_cuti
WHERE COALESCE(sisa_cuti, 0) + COALESCE(sisa_cuti_n1, 0) + COALESCE(sisa_cuti_n2, 0) > 24;

-- Daftar baris saldo negatif (untuk keputusan normalisasi: 0 atau nilai legacy dipertahankan tanpa CHECK).
SELECT nip, tahun, sisa_cuti, sisa_cuti_n1, sisa_cuti_n2, sisa_cuti_ditangguhkan, utang_cuti
FROM pegawai_sisa_cuti
WHERE sisa_cuti < 0 OR sisa_cuti_n1 < 0 OR sisa_cuti_n2 < 0 OR sisa_cuti_ditangguhkan < 0 OR utang_cuti < 0
ORDER BY nip, tahun;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 12 — panjang `reason_note*` > 255 byte (PRD v1.1 §6.1.2 #7 butir 12, DQ-22, Q-23).
-- Kolom `reason_note`/`reason_note_al`/`reason_note_pyb` tetap TEXT/MEDIUMTEXT/TINYTEXT [K] di v2; aplikasi v2 menolak
-- > 255 byte (422). Nilai legacy yang lebih panjang tetap diimpor apa adanya — INFO, bukan prasyarat. Dihitung dalam
-- byte (LENGTH), bukan karakter, karena batas aplikasi = byte (TINYTEXT `riwayat_pmk_req.reason_note` = 255 byte).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'cuti_reason_note_lebih_255' AS metrik, COUNT(*) AS jumlah FROM riwayat_cuti WHERE LENGTH(reason_note) > 255
UNION ALL
SELECT 'cuti_reason_note_al_lebih_255', COUNT(*) FROM riwayat_cuti WHERE LENGTH(reason_note_al) > 255
UNION ALL
SELECT 'cuti_reason_note_pyb_lebih_255', COUNT(*) FROM riwayat_cuti WHERE LENGTH(reason_note_pyb) > 255
UNION ALL
SELECT 'tb_reason_note_lebih_255', COUNT(*) FROM riwayat_tb WHERE LENGTH(reason_note) > 255
UNION ALL
SELECT 'ib_reason_note_lebih_255', COUNT(*) FROM riwayat_ib WHERE LENGTH(reason_note) > 255
UNION ALL
SELECT 'pmk_req_reason_note_lebih_255', COUNT(*) FROM riwayat_pmk_req WHERE LENGTH(reason_note) > 255
UNION ALL
-- INFO: panjang maksimum terpakai per kolom (byte).
SELECT 'cuti_reason_note_maks_byte', COALESCE(MAX(LENGTH(reason_note)), 0) FROM riwayat_cuti
UNION ALL
SELECT 'cuti_reason_note_al_maks_byte', COALESCE(MAX(LENGTH(reason_note_al)), 0) FROM riwayat_cuti
UNION ALL
SELECT 'cuti_reason_note_pyb_maks_byte', COALESCE(MAX(LENGTH(reason_note_pyb)), 0) FROM riwayat_cuti
UNION ALL
SELECT 'tb_reason_note_maks_byte', COALESCE(MAX(LENGTH(reason_note)), 0) FROM riwayat_tb
UNION ALL
SELECT 'ib_reason_note_maks_byte', COALESCE(MAX(LENGTH(reason_note)), 0) FROM riwayat_ib
UNION ALL
SELECT 'pmk_req_reason_note_maks_byte', COALESCE(MAX(LENGTH(reason_note)), 0) FROM riwayat_pmk_req;

-- =====================================================================================================================
-- Akhir skrip (12 bagian). Ringkasan hasil dicatat di DBV-022 Bagian 6 dan di log runbook impor DBV-011 Bagian 2.8.
-- =====================================================================================================================
