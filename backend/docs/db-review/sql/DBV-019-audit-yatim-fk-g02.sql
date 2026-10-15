-- =====================================================================================================================
-- DBV-019 — Audit data yatim FK master G-02 dan FK masuk `pengguna`/`faq_rate` -> `pegawai` — HANYA BACA (SELECT)
-- =====================================================================================================================
-- Dokumen langkah: backend/docs/db-review/DBV-011-runbook-impor-master-zona-waktu.md Bagian 2.10 (runbook impor
-- tunggal). Sumber kueri: backend/docs/db-review/DBV-019-fk-g02-pegawai-schema.md Bagian 6, disalin APA ADANYA
-- (teks kueri tidak diubah; hanya komentar pembagi yang ditambahkan). Bagian 2 memakai COALESCE(SUM(...), 0) agar tabel
-- anak kosong terbaca 0, bukan NULL (T-1 review DB Validator PR #26, 15-10-2026; DBV-019 Bagian 6 diselaraskan).
--
-- Tujuan: membuktikan tidak ada nilai rujukan yatim sebelum FK DBV-019 dipasang/dipakai:
--   - 55 FK snapshot/riwayat -> master G-02 (`jabatan`, `unit`, `satker`, `group_jabatan`, `sub_group_jabatan`,
--     `jabatan_koordinasi`, `rumpun_jabatan`), migration `2026-10-07-100000_AddFkG02Pegawai` dan
--     `2026-10-07-100100_AddFkG02Riwayat` (daftar lengkap: DBV-019 Bagian 2.3);
--   - 2 FK masuk `pengguna.nip`/`faq_rate.nip` -> `pegawai.nip` (DBV-019 Bagian 2.4; migration masih DITAHAN di
--     `app/Database/MigrationsDitahan/`).
--
-- Klasifikasi (arti awalan sama dengan DBV-011 2.3):
--   Bagian 1 dan 2 = WAJIB_0: setiap kolom/baris `yatim` harus 0 sebelum `php spark migrate` memasang FK G-02 pada DB
--                    hasil impor, atau sebelum aplikasi dibuka bila impor dijalankan dengan FOREIGN_KEY_CHECKS = 0.
--   Bagian 3       = WAJIB_0 khusus prasyarat aktivasi FK masuk (bukan pemblokir migrate FK G-02 selama migration
--                    FK masuk masih ditahan).
--   Bagian 4       = INFO: rujukan ke master berstatus 10 (dihapus lunak); tidak memblokir FK, dilaporkan bersama.
--
-- Cakupan: kueri Bagian 1 adalah POLA untuk satu FK; ganti nama FK, tabel/kolom anak, dan tabel/kolom induk sesuai
-- DBV-019 Bagian 2.3 untuk ke-55 FK. Kueri Bagian 2 adalah contoh ringkasan per tabel anak (`pegawai_plh`, 10 FK);
-- tabel anak lain mengikuti pola yang sama. Migration DBV-019 sendiri menjalankan pola Bagian 1 untuk ke-55 FK
-- sebelum perubahan skema pertama dan berhenti (fail-closed) dengan jumlah per FK bila ada yang > 0.
--
-- Cara pakai (akun baca saja; tanpa --force agar error langsung terlihat):
--   mysql --table -vvv --default-character-set=utf8mb4 --user=<akun_baca> -p <db_v2_tujuan> \
--         < backend/docs/db-review/sql/DBV-019-audit-yatim-fk-g02.sql > hasil_audit_fk_g02.txt 2>&1
--   Untuk perkiraan awal di salinan legacy: jalankan di skema salinan (bukan produksi); nama tabel/kolom anak dan induk
--   = D1, kecuali `pengguna.nip` yang di legacy bernama `id_pegawai` (sesuaikan kueri Bagian 3 dan catat di log 2.8).
--
-- LARANGAN: jangan jalankan skrip ini di DB produksi legacy yang sedang melayani aplikasi. Jalankan hanya di DB v2
-- tujuan (setelah impor) atau di SALINAN dump final saat freeze / salinan gladi (DBV-011 2.4 langkah 1). Skrip ini
-- hanya berisi SELECT; perbaikan nilai yatim dikerjakan di salinan kerja atau transformasi impor (DBV-011 2.10).
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 1 — WAJIB_0. Pola per FK (contoh `fk_id_jabatan_pmj_to_jabatan`); ulangi untuk ke-55 FK DBV-019 Bagian 2.3.
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'fk_id_jabatan_pmj_to_jabatan' AS fk, COUNT(*) AS yatim
FROM pegawai_mutasi_jabatan c
WHERE c.id_jabatan IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jabatan p WHERE p.id_jabatan = c.id_jabatan);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 2 — WAJIB_0. Ringkasan per tabel anak (satu pemindaian per tabel), contoh `pegawai_plh` (10 FK).
-- ---------------------------------------------------------------------------------------------------------------------
SELECT
  COALESCE(SUM(c.id_jabatan               IS NOT NULL AND j.id_jabatan            IS NULL), 0) AS fk_pegawai_plh_ibfk_02,
  COALESCE(SUM(c.id_jabatan_koord         IS NOT NULL AND k.id_jabatan_koordinasi IS NULL), 0) AS fk_pegawai_plh_ibfk_03,
  COALESCE(SUM(c.id_group_jabatan         IS NOT NULL AND g.id_group_jabatan      IS NULL), 0) AS fk_pegawai_plh_ibfk_04,
  COALESCE(SUM(c.id_sub_group_jabatan     IS NOT NULL AND s.id_sub_group_jabatan  IS NULL), 0) AS fk_pegawai_plh_ibfk_05,
  COALESCE(SUM(c.id_unit                  IS NOT NULL AND u.id_unit               IS NULL), 0) AS fk_pegawai_plh_ibfk_06,
  COALESCE(SUM(c.id_satker                IS NOT NULL AND t.id_satker             IS NULL), 0) AS fk_pegawai_plh_ibfk_07,
  COALESCE(SUM(c.id_group_jabatan_plh     IS NOT NULL AND g2.id_group_jabatan     IS NULL), 0) AS fk_pegawai_plh_ibfk_08,
  COALESCE(SUM(c.id_sub_group_jabatan_plh IS NOT NULL AND s2.id_sub_group_jabatan IS NULL), 0) AS fk_pegawai_plh_ibfk_09,
  COALESCE(SUM(c.id_unit_plh              IS NOT NULL AND u2.id_unit              IS NULL), 0) AS fk_pegawai_plh_ibfk_10,
  COALESCE(SUM(c.id_satker_plh            IS NOT NULL AND t2.id_satker            IS NULL), 0) AS fk_pegawai_plh_ibfk_11
FROM pegawai_plh c
LEFT JOIN jabatan            j  ON j.id_jabatan            = c.id_jabatan
LEFT JOIN jabatan_koordinasi k  ON k.id_jabatan_koordinasi = c.id_jabatan_koord
LEFT JOIN group_jabatan      g  ON g.id_group_jabatan      = c.id_group_jabatan
LEFT JOIN sub_group_jabatan  s  ON s.id_sub_group_jabatan  = c.id_sub_group_jabatan
LEFT JOIN unit               u  ON u.id_unit               = c.id_unit
LEFT JOIN satker             t  ON t.id_satker             = c.id_satker
LEFT JOIN group_jabatan      g2 ON g2.id_group_jabatan     = c.id_group_jabatan_plh
LEFT JOIN sub_group_jabatan  s2 ON s2.id_sub_group_jabatan = c.id_sub_group_jabatan_plh
LEFT JOIN unit               u2 ON u2.id_unit              = c.id_unit_plh
LEFT JOIN satker             t2 ON t2.id_satker            = c.id_satker_plh;


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 3 — WAJIB_0 untuk aktivasi FK masuk (#56–#57). NIP akun/rating yang tidak ada di `pegawai`; akun non-pegawai
-- (`nip IS NULL`) bukan yatim; akun soft-deleted ikut dihitung karena FK berlaku untuk semua baris.
-- ---------------------------------------------------------------------------------------------------------------------
SELECT 'pengguna.nip' AS kolom, COUNT(*) AS yatim
FROM pengguna c
WHERE c.nip IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip)
UNION ALL
SELECT 'faq_rate.nip', COUNT(*)
FROM faq_rate c
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip);


-- ---------------------------------------------------------------------------------------------------------------------
-- Bagian 4 — INFO. Rujukan ke master berstatus 10 (dihapus lunak), contoh `riwayat_mutasi_jabatan.id_jabatan`;
-- ulangi per FK bila diperlukan. Bukan pemblokir FK, tetapi memengaruhi tampilan.
-- ---------------------------------------------------------------------------------------------------------------------
SELECT COUNT(*) FROM riwayat_mutasi_jabatan c JOIN jabatan p ON p.id_jabatan =
c.id_jabatan WHERE p.status = 10;
