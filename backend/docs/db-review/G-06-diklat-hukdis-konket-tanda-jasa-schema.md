# DB Validator Review — G-06 Diklat, Hukuman Disiplin, Konfirmasi Ketidakhadiran, Tanda Jasa (skema legacy)

**Key review:** `DBV-005` (review DB Validator, skema) + `CR-012` (review kode) — satu pull request, judul `[DBV-005][CR-012] …`, branch `dbv-005/g06-diklat-hukdis-konket-tandajasa`. Merge hanya setelah **kedua** review setuju. DB Validator hanya me-review/approve; merge dilakukan **user (reviewer CR)** (aturan PR berisi CR + DBV, 24-09-2026; `AGENTS.md` bagian 2). Urutan merge grup: DBV-003 → DBV-004 → DBV-005 (DBV-010 bebas).

**Status:** ✅ **DISETUJUI DB VALIDATOR (DBV-005, jjoseph48, komentar PR #14, 29-09-2026) DAN REVIEW KODE (CR-012)**; di-merge ke `main` oleh reviewer CR 29-09-2026 (merge commit `e602205`). Keputusan Bagian 4 no. 1–15 disetujui seluruhnya, termasuk butir [I], **dengan syarat**: audit data sebelum impor (6.5) dan penyalinan counter AUTO_INCREMENT legacy (6.5 #10) wajib menjadi langkah runbook impor. Migration `2026-09-25-120000_CreateDiklatHukdisKonketTandaJasa.php` boleh dijalankan di Dev. Endpoint master G-06 lewat engine generik CR-009 (entri `Config\MasterData`, 4 controller, halaman Master Data generik) ikut di-merge — perilakunya di Bagian 2.7. Nilai [I] tetap wajib dicocokkan dengan dump struktur produksi (6.4); koreksi lewat migration ALTER baru.

Verifikasi DB Validator di MariaDB 10.4 (komentar PR #14, 29-09-2026; butir mengikuti 6.3):

| # | Butir | Hasil |
|---|---|---|
| 1 | `migrate` → `migrate:rollback` → `migrate` | ✅ G-06 masuk batch tersendiri; rollback hanya menghapus kelima tabel G-06 — daftar tabel setelah rollback identik dengan sebelum G-06; `migrate` ulang bersih |
| 2 | `DiklatHukdisKonketSchemaTest` | ✅ `OK (6 tests, 184 assertions)` |
| 3 | CHECK | ✅ 3 `chk_…` terdaftar di `TABLE_CONSTRAINTS`; ditegakkan dengan error 4025: `jenis_diklat` = 6, `affect_tukin` = 3, `masa_sanksi_bulan` = 0 |
| 4 | FK RESTRICT | ✅ `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` UPDATE/DELETE = RESTRICT di `REFERENTIAL_CONSTRAINTS`; hapus induk yang punya anak ditolak 1451 |
| 5 | Tipe kolom | ✅ `tinyint(1)` `jenis_diklat`, `tinyint(3) unsigned` `masa_sanksi_bulan`, `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`, `int(11)` `updated_by`, PK/`order`/`status` TINYINT signed. Beda tampilan lebar (4)/(11) hanya gaya MariaDB, setara dengan 6.2 |
| 6 | UNIQUE 1.024 byte | ✅ Terbesar `uq_diklat_nama` dan `uq_jenis_hukdis_nama` = 1.024 byte, diterima (batas InnoDB 3.072) |
| 7 | `DiklatHukdisKonketTest` (opsional) | ✅ `OK (10 tests, 415 assertions)` — head PR sebelum merge `main` `acd8693`; test ke-11 `testDiklatFullTinyintKeyGives422` dan jalur 167 untuk `diklat` (2.7 batas #1) belum dijalankan di MariaDB (opsional) |
| + | Tambahan | ✅ `MasterGenericTcTest` + `RbacMasterEndpointsTest` `OK (33 tests, 2.662 assertions)`. Constraint kelima tabel: 5 PK, 6 UNIQUE, 1 FK, 3 CHECK, semua InnoDB + `utf8mb4_unicode_ci` — cocok dengan 6.2 |

Catatan DB Validator (tidak memblokir):
- **Zona waktu stempel waktu.** Default `CURRENT_TIMESTAMP` memakai jam server (terukur WIB 11:36:43), sedangkan aplikasi menulis UTC (04:36:43) — selisih 7 jam di kolom yang sama. Ini mengikuti legacy [K], tetapi setiap penulisan di luar aplikasi (impor, perbaikan manual, `ON UPDATE` dari SQL manual) memakai WIB. Usul DBV: set `time_zone = '+00:00'` di server DB Dev/Prod, atau catat eksplisit di runbook impor. Tindak lanjut: Trello **ISSUE-022** (lihat juga 6.5 #8).
- **Lingkungan reviewer.** Bila database test sebelumnya dipakai branch lain yang punya migration lebih baru (mis. DBV-010), PHPUnit gagal "There is a gap in the migration sequence". Bersihkan database test dulu saat berpindah branch. Bukan cacat PR.
- PK TINYINT `diklat` habis di MariaDB → error 167; ditangani perbaikan engine DBV-004/CR-011 (di `main` `acd8693`, 2.7 batas #1).

**Rujukan:** `02-MasterData.md` G-06 (:63-70) & G-TC (:115-127), Tech Spec G-06 (:805-817) dan §3.5 (:2097), `FSD_SIMPEG_v2.md:73,104`, `03-Kepegawaian.md:146`, `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 1 ("diklat, tingkat_hukdis, jenis_hukdis, jenis_konket, tanda_jasa — sama — Copy langsung"), Matriks Role x Endpoint Modul G (`c_diklat`, `c_hukdis`, `c_konket`, `c_tj`, role 1), DDL produksi `simpeg_prod.sql:443-452` (`diklat`) & :33-69 (`absen_ijin`) (HeidiSQL, host 172.17.100.83, MySQL 8.0.21), ERD legacy `simpeg01.erd` (nama FK), kode legacy (`application/libraries/hr/master/Lm_{diklat,hukdis,konket,tj}.php`, `application/libraries/hr/rwy/L_{diklat,hukdis,konket,tj}.php`, `application/controllers/hr/master/C_{diklat,hukdis,konket,tj}.php`, `L_presensi.php`, `services/Siasn.php`), keputusan user K5 (25-09-2026), `G-01-master-schema.md` (Keputusan #5, Bagian 8 / DBV-001), `G-10-faq-schema.md` (pola pilot DBV-002).

**Label sumber:** **[K]** terkonfirmasi — DDL `simpeg_prod.sql` (nomor baris), ERD `simpeg01.erd`, atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3); **[I]** dugaan (tidak ada DDL, wajib dikonfirmasi dengan dump struktur produksi).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-25-120000_CreateDiklatHukdisKonketTandaJasa.php` | 5 tabel: `diklat`, `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket`, `tanda_jasa` (SQL mentah, sadar prefix tabel; `down()` men-drop anak → induk; `up()` yang gagal di tengah membersihkan tabel yang dibuat pada run itu) |
| `tests/MasterData/DiklatHukdisKonketSchemaTest.php` | skema hasil migration dibandingkan dengan Bagian 2 lewat `information_schema`, constraint DB (UNIQUE, FK, CHECK, NOT NULL, batas tipe), tanpa baris seed, rollback |
| `app/Config/MasterData.php` (blok `DBV-005`) | 5 entri master: `diklat`, `tingkat-hukdis`, `jenis-hukdis`, `jenis-konket`, `tanda-jasa` + konstanta `AUDIT_G06` (Bagian 2.7) |
| `app/Controllers/Api/MasterData/{Diklat,Hukdis,Konket,TandaJasa}Controller.php` | 4 controller grup (`02-MasterData.md` G-06), hanya mendaftarkan key master; route & RBAC dibangkitkan dari config |
| `tests/_support/Database/Seeds/MasterDataSeeder.php`, `tests/_support/MasterDataTestTrait.php` (blok `DBV-005`) | seed G-06 (baris ber-ID hard-coded legacy, Bagian 2.6) dan fixture G-TC generik |
| `tests/MasterData/DiklatHukdisKonketTest.php` | perilaku khusus G-06 lewat HTTP (11 test, Bagian 2.7) |
| `app/Libraries/MasterData/MasterField.php` | Tidak berbeda lagi dari `main` setelah merge `main` `acd8693`: hunk `normalize()` cabang ini (nilai yang dianggap kosong oleh rule `permit_empty` — spasi/tab saja, JSON `false` — disimpan NULL, bukan `(int) 0` yang melanggar CHECK `masa_sanksi_bulan`) identik dengan DBV-004/CR-011 (#13) dan tergabung tanpa duplikasi |
| `frontend/src/features/master-data/__tests__/MasterDataView.g06.spec.ts` | kelima master G-06 di halaman Master Data generik dengan meta bentuk backend: menu aksi baris ⋮ (`AGENTS.md` bagian 1), item urutan per jenis pelatihan/per tingkat/global, kolom Status badge (2.7). Komponen FE tidak diubah |
| `tests/MasterData/MasterGenericTcTest.php` | `testMariaDbAutoIncrementOutOfRangeGives422ForEveryAutoIncrementMaster` (dari DBV-004/CR-011): daftar master ber-PK TINYINT kini dicocokkan dua arah dengan tipe kolom PK di DDL dan memuat `diklat` (11 master); kelima master G-06 ikut disimulasikan error MariaDB 167 lewat fixture blok `DBV-005`. Perubahan `testLegacyAuditColumnsAreFilledWithActor` cabang ini (`updated_at` untuk tabel tanpa `created_at`, kelima tabel G-06) identik dengan DBV-003/DBV-004 dan sudah ada di `main` |

## 1. Latar belakang & keputusan

Dari kelima tabel G-06, hanya `diklat` yang DDL-nya ada di dump produksi (`simpeg_prod.sql:443-452`) [K]. Isinya identik dengan `SHOW CREATE TABLE` di DB lokal `simpeg_prod_duplikat` dan `simpeg01`; kedua salinan berisi 0 baris. Dump berhenti di tabel `jabatan` (urutan alfabetis), jadi `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket`, dan `tanda_jasa` tidak ada. Kolom keempat tabel itu **[I]**, disusun dari:
- kode legacy (kolom yang ditulis/dibaca `Lm_hukdis`, `Lm_konket`, `Lm_tj`, `rwy/L_konket`);
- ERD `simpeg01.erd`: entity 27 `diklat`, 77 `jenis_hukdis`, 78 `jenis_konket`, 264 `tanda_jasa`, 271 `tingkat_hukdis`, dan relasi `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` (ERD tidak memuat kolom maupun aksi ON DELETE/UPDATE);
- `simpegdev_local`, satu-satunya DB lokal yang punya `tingkat_hukdis` (`id_tingkat_hukdis int AUTO_INCREMENT`, `tingkat_hukdis varchar(100) NULL`, `bobot_ipasn int DEFAULT '5'`) dan `jenis_konket` (`id_jenis_konket int AUTO_INCREMENT`, `old_id varchar(50) NULL`, `jenis_konket varchar(255) NULL`, `status varchar(10) DEFAULT '1'`), keduanya tanpa `order`/`updated_*`. DB ini **rekonstruksi, bukan salinan produksi**: `riwayat_diklat.id_diklat` di sana `int DEFAULT '1'` padahal `diklat.id_diklat` produksi `tinyint` (FK `fk_id_diklat_rdiklat_to_diklat` tidak mungkin dibuat dengan tipe berbeda), `riwayat_tanda_jasa.id_tanda_jasa` `varchar(10) DEFAULT '28'`, dan default kolom berisi data contoh (`'Pelatihan Teknis Komputasi Awan'`, `'Pranata Komputer'`). `jenis_hukdis` dan `tanda_jasa` tidak ada di DB lokal mana pun (dicek lewat `information_schema.TABLES` seluruh server).

Perilaku legacy yang memengaruhi skema [K]:
- **Hapus.** `tingkat_hukdis`, `jenis_konket`, `tanda_jasa`, dan `diklat` (lewat `Lm_*`) dihapus keras (`Lm_diklat.php:134`, `Lm_hukdis.php:134`, `Lm_konket.php:134`, `Lm_tj.php:134`). `jenis_hukdis` di-soft-delete status 10 (`Lm_hukdis.php:349-354`). CRUD master kedua `dm_diklat` (`rwy/L_diklat.php:829-1061`) juga memakai status 10 (:966-971); jalur ini sekarang mati (tidak ada controller yang memanggilnya dan view `views/hr/employee/diklat/master/` tidak ada), tetapi COMMENT DDL `diklat` memuat `10: Deleted` (:448), jadi data produksi `diklat` **bisa** berisi baris status 10.
- **Ubah/hapus hanya baris status 1.** `C_diklat/C_hukdis/C_konket/C_tj.php:88` (ubah) dan :140 (hapus) mensyaratkan `status NOT IN ('2','10')`. Baris yang pernah dinonaktifkan tidak bisa diaktifkan lagi lewat UI legacy.
- **Cek duplikat** legacy mengecualikan status 10 (`Lm_diklat.php:179,197` per jenis; `Lm_hukdis.php:174,191` tingkat; :399,412 jenis per tingkat; `Lm_tj.php:174,191`). `Lm_konket.php` hanya mengecek saat tambah (:174); cek saat ubah memeriksa `$postData['tanda_jasa']` (:189), jadi tidak pernah jalan.
- **`addslashes`.** Nama `diklat` (`Lm_diklat.php:215`), `tingkat_hukdis` (`Lm_hukdis.php:207`), `jenis_konket` (`Lm_konket.php:208`), dan `tanda_jasa` (`Lm_tj.php:208`) di-`addslashes` lalu di-escape lagi oleh query builder, sehingga data produksi berisi `\'`/`\"` literal. `jenis_hukdis` (`Lm_hukdis.php:424-430`) dan jalur `dm_diklat` (:871, :1051-1057) menyimpan `$postData` mentah. Karena cek duplikat membandingkan string ber-`addslashes` dengan data yang sudah ber-backslash, nama yang mengandung kutip lolos cek duplikat.
- **Form master konket** hanya berisi `status`, `order`, `jenis_konket` (`views/hr/master/konket/form.php`), tanpa `old_id`/`affect_tukin`, padahal value pilihan pengajuan adalah `old_id` (`views/hr/employee/rwy/konket/form.php:81`). Jenis baru dari UI legacy tidak bisa dipakai di pengajuan.
- **Form master diklat** hanya menawarkan jenis 4/1/2; opsi 3 `disabled`, 5 tidak ada (`views/hr/master/diklat/form.php:41-45`). Riwayat jenis 3 memakai `sub_group_jabatan`, jenis 5 memakai nama bebas + rumpun/lembaga sertifikasi (`rwy/L_diklat.php:62-67, 499-525, 655-684`).
- **Kolom audit.** Semua `set_param` hanya mengisi `updated_by` (`Lm_hukdis.php:212, 428`, `Lm_konket.php:212`, `Lm_tj.php:212`). `diklat` [K] dan `bidang_pendidikan` (`simpeg_prod.sql:257-265`, pustaka dengan template yang sama) memakai `updated_at` NOT NULL ON UPDATE + `updated_by` tanpa `created_*`; tetapi `group_jabatan` (:1294-1304, pustakanya juga hanya menulis `updated_by`) punya `created_at` dari default DB. Pola audit empat tabel [I] karena itu tetap [I].
- **Label.** Matriks Modul G (dan PR #3) menyebut `c_konket` "Jenis kondisi kerja"; label legacy adalah "Jenis Konfirmasi Ketidakhadiran" (`Lm_konket.php:56`, `konket/form.php:46`). v2 memakai label legacy.

### 1.1 Keputusan user (K5, 25-09-2026)

| # | Topik | Keputusan | Diterapkan |
|---|---|---|---|
| K5a | `affect_tukin` di master konket | Kolom baru `jenis_konket.affect_tukin` TINYINT NOT NULL DEFAULT 1, nilai 1 Ya / 2 Tidak seperti `absen_ijin.affect_tukin` (`simpeg_prod.sql:41`). Nilainya jadi nilai awal saat pengajuan; admin tetap bisa mengubah per pengajuan | migration (2.4) |
| K5b | Masa sanksi | Kolom `jenis_hukdis.masa_sanksi_bulan` nullable, dipakai sebagai default hitung `akhir_hukdis`. Riwayat tetap menyimpan masa sendiri per SK, termasuk dari SIASN. Konsekuensi: masih ada input manual per SK, berbeda dengan Tech Spec §3.5 (:2097, "bukan input manual") | migration (2.3) |

### 1.2 Keputusan proyek (final, tidak dibuka ulang)

| # | Isi | Diterapkan |
|---|---|---|
| B1 | `diklat`: DDL [K] + `UNIQUE(jenis_diklat, nama_diklat)`; urutan per `jenis_diklat` (opsi engine `orderScope`, CR-009); pilihan `jenis_diklat` 1–5 sesuai COMMENT kolom [K] | 2.1, 2.7 |
| B2 | `jenis_konket.old_id` wajib dan UNIQUE, dikelola admin [V2 UNIQUE]; tipe ikut `absen_ijin.kategori` INT [I] | 2.4 |
| B3 | UNIQUE nama `tingkat_hukdis` dan `tanda_jasa`; `UNIQUE(id_tingkat_hukdis, jenis_hukdis)`; semuanya dengan audit duplikat, termasuk baris status 10 legacy | 2.2, 2.3, 2.5 |
| B4 | FK `jenis_hukdis → tingkat_hukdis` RESTRICT dengan nama legacy | 2.3 |
| B5 | `bobot_ipasn` disimpan tetapi disembunyikan | 2.2, 2.7 |
| B6 | Baris hard-coded (konket id 2/4/5/6, `old_id` 8/10/13, tanda jasa 26/27/28/44, diklat 8) didokumentasikan di dokumen ini dan fixture test, **tanpa** penguncian; ditinjau lagi di Fase 3 | 2.6 |
| B7 | Filter konket per role dan opsi "Lain-lain" tanda jasa = tugas B-13/B-17, bukan master | 2.7 |
| B8 | Tipe PK ikut legacy (termasuk TINYINT signed; `diklat` TINYINT signed). Impor wajib memakai ID legacy apa adanya. Nilai [I] boleh dipakai dengan label; approval final menunggu dump struktur penuh; tiap PR DBV meminta verifikasi MariaDB 10.4 eksplisit | semua, 6.3 |
| B9 | `rumpun_sertifikasi`/`lembaga_sertifikasi` bukan bagian DBV-005 (DBV berikutnya bersama B-11; nomor DBV-010 kini dipakai collation auth & identitas `pengguna`) | di luar lingkup |
| G2 | (DBV-001/002, disetujui) Status 1/2/10, hapus = soft delete, UNIQUE nama termasuk status 2/10 dan case-insensitive lewat collation, `utf8mb4_unicode_ci` per tabel, FK `ON DELETE RESTRICT ON UPDATE RESTRICT` dengan nama legacy, AUTO_INCREMENT awal tidak ditulis, kolom audit diisi aplikasi (UTC, `id_pengguna`); G-01 Keputusan #5: semua master wajib `order` + `status` | semua |

### 1.3 Usulan (diimplementasikan sesuai usulan, ✅ disetujui DBV-005 29-09-2026 — Bagian 4)

| # | Usulan |
|---|---|
| D1 | PK `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket`, `tanda_jasa` = **INT** signed AUTO_INCREMENT [I] (satu-satunya bukti tipe: `simpegdev_local`; aman untuk ID legacy berapa pun). Alternatif TINYINT seperti master kecil produksi (Bagian 4 #2) |
| D2 | Kolom audit empat tabel [I] meniru `diklat` [K]: `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL`, tanpa `created_*` |
| D3 | `masa_sanksi_bulan` TINYINT UNSIGNED (1–255 bulan), 0 ditolak CHECK (tanpa masa = NULL) |
| D4 | 3 CHECK: `chk_diklat_jenis_diklat` (1–5), `chk_jenis_hukdis_masa_sanksi_bulan` (NULL atau ≥ 1), `chk_jenis_konket_affect_tukin` (1/2). Prefix `chk_` sama dengan DBV-003 (`chk_hari_libur_rentang`) dan DBV-004 (`chk_jenjang_pendidikan_row_jurusan`) |
| D5 | UNIQUE nama `jenis_konket` (`uq_jenis_konket_nama`) — aturan umum UNIQUE nama master; cek legacy hanya saat tambah |
| D6 | Tanpa baris seed/sentinel di migration (Bagian 2.6) |

### 1.4 Konflik yang dicatat (keputusan final tetap berlaku)

| # | Konflik | Sikap |
|---|---|---|
| 1 | B1 (opsi `jenis_diklat` 1–5) vs legacy: form master hanya 4/1/2 (3 `disabled`, 5 tidak ada, `master/diklat/form.php:41-45`); riwayat jenis 3 dan 5 tidak memakai baris master; `master/diklat/list.php:70` memberi label jenis 5 sebagai "PRAJABATAN" (cabang else) | B1 dipakai (COMMENT DDL [K] memuat 1–5). Konsekuensi dicatat untuk B-11 (2.7) |
| 2 | B2 (`old_id` INT NOT NULL UNIQUE) vs satu-satunya bukti langsung `simpegdev_local` (`varchar(50) NULL`). UI master legacy tidak pernah mengisi `old_id`, jadi produksi bisa berisi `old_id` NULL, ganda, atau bukan angka | B2 dipakai. Audit data sebelum impor (6.5 #3); tipe final dicocokkan dengan dump (Bagian 4 #7) |
| 3 | B8 (tipe PK ikut legacy) padahal tidak ada DDL legacy untuk empat tabel [I]; master kecil produksi memakai TINYINT, `simpegdev_local` INT | INT [I] (D1); keputusan final di DBV (Bagian 4 #2). Tipe kolom FK `riwayat_*` di B-11/B-14/B-17 wajib mengikuti pilihan ini |
| 4 | K5b (masa sanksi di master sebagai default, riwayat menyimpan masa sendiri) vs Tech Spec §3.5 :2097 ("bukan input manual") | Keputusan user K5b dipakai |
| 5 | Catatan lama `decisions (C) #7` "jenis_konket dan jenis_hukdis menunggu K5" | Sudah tidak berlaku (K5 final); satu migration untuk kelima tabel |
| 6 | G-10 menulis "merge oleh reviewer CR"; aturan user untuk PR CR + DBV: user yang merge | Tidak bertentangan: user adalah reviewer CR (`AGENTS.md` bagian 2: DB Validator review/approve, reviewer CR yang merge) |
| 7 | Seed buatan user `simpeg_v2_local_seed_legacy.sql:744-880`: FK CASCADE, `old_id` VARCHAR dengan nilai fiktif 14/15, `status` konket VARCHAR, tanpa `masa_sanksi_bulan`, tanpa `updated_at` | Bukan sumber kebenaran; diselaraskan nanti dengan izin user (tidak disentuh di PR ini) |
| 8 | PR #3 (`feat/fase-2-master-data-lengkap`, pembanding): status ENUM('0','1'), `order INT UNSIGNED DEFAULT 0`, PK TINYINT UNSIGNED, nama FK `fk_jenis_hukdis_tingkat_hukdis` (bukan legacy), `jenis_konket` PK VARCHAR(5) tanpa `old_id`, `affect_tukin` 0/1 dengan arti terbalik | Hanya pembanding; tidak dipakai |

## 2. Skema hasil DBV-005

Berlaku untuk kelima tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; kolom string mewarisi collation tabel ([K] untuk `diklat`, [I] lainnya). Koneksi aplikasi masih `DBCollat utf8mb4_general_ci`, jadi collation ditulis eksplisit di CREATE TABLE (G-01 8.5).
- Nama index/constraint **tanpa** prefix tabel (DBPrefix hanya untuk nama tabel, mis. `t_` di DB test).
- Nilai `AUTO_INCREMENT` awal tidak ditulis [V2] (legacy `diklat` AUTO_INCREMENT=17; preseden DBV-001/002). Impor dengan ID eksplisit hanya menaikkan counter ke `MAX(id)+1`, bukan ke counter legacy. Legacy menghapus keras baris `diklat`, `tingkat_hukdis`, `jenis_konket`, dan `tanda_jasa` (`Lm_*.php:134`), jadi ID tertinggi yang pernah dihapus akan dipakai ulang oleh entri baru v2, padahal tabel pemetaan `siasn_simpeg` (tanpa FK) masih menyimpan ID lama. Karena itu counter diselaraskan saat impor (6.5 #10).
- `status TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus'` — tipe & default [K] `diklat` :448, [I] tabel lain; COMMENT [V2] (legacy `'1: Active, 2: Inactive, 10: Deleted'`).
- `order TINYINT NOT NULL DEFAULT 1` — [K] `diklat` :447; kolom [K] kode untuk tabel lain, tipe [I].
- Kolom audit pola `diklat` [K] (:449-450): `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'` (COMMENT [V2]). Tanpa `created_at`/`created_by` ([K] `diklat`, [I] lainnya). Tanpa FK (preseden DBV-001). Engine mode Batch 1 (`auditColumns ['updated_at','updated_by']`) mengisi keduanya saat insert maupun update; penulisan saudara yang hanya bergeser urutannya memakai `updated_at = updated_at`, aman untuk kolom NOT NULL.
- UNIQUE nama berlaku juga untuk baris status 2/10, tidak peka huruf besar/kecil maupun aksen (collation) [V2, G2]. Key terpanjang 1.024 byte (4 + 255 × 4), di bawah batas 3.072 byte InnoDB (row format DYNAMIC).
- Tanpa CHECK pada `status` (konsisten dengan DBV-001/002; nilai dijaga engine).

### 2.1 diklat — `simpeg_prod.sql:443-452`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_diklat` | TINYINT (signed) NOT NULL AUTO_INCREMENT | [K] :444. Batas 127 (Bagian 3 #8) |
| `jenis_diklat` | TINYINT(1) NOT NULL DEFAULT 1, COMMENT legacy verbatim `'1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan,5:Sertifikasi'` | [K] :445. COMMENT dipertahankan karena menjadi dasar opsi 1–5 (B1). Lebar (1) ikut legacy dan terlihat di `COLUMN_TYPE` MySQL 8 maupun MariaDB |
| `nama_diklat` | VARCHAR(255) NOT NULL | [K] :446 (legacy menulis collation di kolom; di sini diwarisi dari tabel, hasilnya sama) |
| `order` | TINYINT NOT NULL DEFAULT 1 | [K] :447. Urutan per `jenis_diklat` (`Lm_diklat.php:13`, `form.php:55`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :448; COMMENT [V2] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [K] :449 |
| `updated_by` | INT NULL DEFAULT NULL | [K] :450; COMMENT [V2] |

Kunci: `PRIMARY (id_diklat)` [K] :451; **UNIQUE** `uq_diklat_nama (jenis_diklat, nama_diklat)` [V2] (B1; cek legacy per jenis `Lm_diklat.php:179,197` mengecualikan status 10, v2 tidak); **CHECK** `chk_diklat_jenis_diklat` (`jenis_diklat BETWEEN 1 AND 5`) [V2] (D4).

### 2.2 tingkat_hukdis — [I] (ERD entity 271; `simpegdev_local`; `Lm_hukdis.php`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_tingkat_hukdis` | INT NOT NULL AUTO_INCREMENT | tipe [I] `simpegdev_local` (D1); AUTO_INCREMENT [K] `Lm_hukdis.php:53` |
| `tingkat_hukdis` | VARCHAR(100) NOT NULL | panjang [I] `simpegdev_local`; NOT NULL [I] (dev_local NULL, tetapi wajib di kode :161 dan ber-UNIQUE) |
| `bobot_ipasn` | INT NULL DEFAULT 5, COMMENT `'bobot skor IPASN dimensi disiplin (legacy); tidak dikelola v2'` | [I] `simpegdev_local` (`int DEFAULT '5'`); dipakai skor IPASN `L_user.php:1059-1070`, tidak ada di form legacy; COMMENT [V2]. Disimpan, disembunyikan (B5) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] kode (:13, :165, :208); tipe [I] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | nilai 1/2 [K] kode (`C_hukdis.php:47`, form); tipe [I]; legacy hard delete (:134) |
| `updated_at`, `updated_by` | pola umum | `updated_by` [K] kode :212; tipe & `updated_at` [I] (D2) |

Kunci: `PRIMARY (id_tingkat_hukdis)` [I]; **UNIQUE** `uq_tingkat_hukdis_nama (tingkat_hukdis)` [V2] (B3; cek legacy :174, :191). Diacu satu FK (`jenis_hukdis`).

### 2.3 jenis_hukdis — [I] (ERD entity 77; `Lm_hukdis.php`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenis_hukdis` | INT NOT NULL AUTO_INCREMENT | [I] (tidak ada DDL lokal; AUTO_INCREMENT dari `insert2` tanpa input PK :273) (D1). Dirujuk `siasn_simpeg.JENIS_HUKUMAN_ID` (`Siasn.php:2895-2897`) |
| `id_tingkat_hukdis` | INT NOT NULL | tipe [I], wajib sama dengan PK induk; NOT NULL [I] (wajib di kode :377). Legacy menghapus tingkat secara keras (:134) → audit NULL/yatim sebelum impor |
| `jenis_hukdis` | VARCHAR(255) NOT NULL | [I]; wajib :385 |
| `masa_sanksi_bulan` | TINYINT UNSIGNED NULL DEFAULT NULL, COMMENT `'masa sanksi bawaan dalam bulan untuk hitung akhir_hukdis; NULL = tanpa masa'` | **[V2]** K5b. Nullable = keputusan final; tipe usulan D3: 1–255 bulan cukup (PP 94/2021 ≤ 12 bulan, PP 53/2010 ≤ 36 bulan), unsigned karena negatif tidak bermakna, 0 ditolak CHECK |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] kode (`jenis/form.php:56` "Urutan jenis hukdis pada tingkat hukdis … Min: 1, Maks: 100"); tipe [I] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | nilai 10 [K] kode (soft delete :349-354); tipe [I] |
| `updated_at`, `updated_by` | pola umum | `updated_by` [K] kode :352, :428; lainnya [I] (D2) |

Kunci: `PRIMARY (id_jenis_hukdis)` [I]; **UNIQUE** `uq_jenis_hukdis_nama (id_tingkat_hukdis, jenis_hukdis)` [V2] (B3; cek legacy per tingkat :399, :412 mengecualikan status 10); `KEY fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis (id_tingkat_hukdis)` — nama [K] ERD, secara teknis berlebih karena UNIQUE sudah diawali kolom yang sama, tetapi dipertahankan agar DDL sebanding (preseden G-10); FK `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` → `tingkat_hukdis (id_tingkat_hukdis)` **ON DELETE RESTRICT ON UPDATE RESTRICT** — nama [K] ERD, aksi [V2] (B4, G2; aksi legacy tidak tercatat di ERD → [I]); **CHECK** `chk_jenis_hukdis_masa_sanksi_bulan` (`masa_sanksi_bulan IS NULL OR masa_sanksi_bulan >= 1`) [V2] (D4).

### 2.4 jenis_konket — [I] (ERD entity 78; `simpegdev_local`; `Lm_konket.php`, `rwy/L_konket.php`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenis_konket` | INT NOT NULL AUTO_INCREMENT | tipe [I] `simpegdev_local` (D1); AUTO_INCREMENT [K] `Lm_konket.php:53`. ID 2/4/5/6 di-hard-code (`rwy/L_konket.php:36, 91, 149, 153`) → wajib impor ID legacy |
| `old_id` | INT NOT NULL, COMMENT `'kode kategori konket = absen_ijin.kategori'` | tipe [I] ikut `absen_ijin.kategori int NOT NULL` [K] `simpeg_prod.sql:38` (B2); NOT NULL & UNIQUE [V2] (B2). Deviasi dari `simpegdev_local` (`varchar(50) NULL`). Tanpa FK fisik (legacy juga relasi logis, satu-satunya FK `absen_ijin` ke `pegawai`, :68) |
| `jenis_konket` | VARCHAR(255) NOT NULL | panjang [I] `simpegdev_local`, didukung salinan `absen_ijin.jenis_konket varchar(255)` [K] :39; NOT NULL [I] (wajib :165) |
| `affect_tukin` | TINYINT NOT NULL DEFAULT 1, COMMENT `'1: Ya, 2: Tidak (nilai awal affect_tukin pengajuan konket)'` | **[V2]** K5a. Nilai 1/2 mengikuti `absen_ijin.affect_tukin` [K] :41 (legacy `tinyint(1)`; lebar tampilan tidak ditiru agar kolom tidak dibaca sebagai boolean) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] kode (:13, :161, :207); tipe [I] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe [I] (`simpegdev_local` VARCHAR(10) DEFAULT '1'; bila produksi VARCHAR, ini deviasi [V2] seperti `gol_pppk` DBV-004); legacy hard delete (:134) |
| `updated_at`, `updated_by` | pola umum | `updated_by` [K] kode :212; lainnya [I] (D2) |

Kunci: `PRIMARY (id_jenis_konket)` [I]; **UNIQUE** `uq_jenis_konket_nama (jenis_konket)` [V2] (D5; cek legacy hanya saat tambah :174, cek ubah rusak :189 → risiko duplikat tinggi); **UNIQUE** `uq_jenis_konket_old_id (old_id)` [V2] (B2); **CHECK** `chk_jenis_konket_affect_tukin` (`affect_tukin IN (1, 2)`) [V2] (D4).

### 2.5 tanda_jasa — [I] (ERD entity 264; `Lm_tj.php`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_tanda_jasa` | INT NOT NULL AUTO_INCREMENT | [I] (tidak ada DDL lokal) (D1); AUTO_INCREMENT [K] `Lm_tj.php:53`. ID 26/27/28/44 di-hard-code; dirujuk `siasn_simpeg.HARGA_ID` (`Siasn.php:5958-5959`) |
| `tanda_jasa` | VARCHAR(255) NOT NULL | [I]; wajib :165. Baris 44 = `LAIN-LAIN` [I kuat] (2.6) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] kode (:13, :161, :207); tipe [I] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe [I]; legacy hard delete (:134) |
| `updated_at`, `updated_by` | pola umum | `updated_by` [K] kode :212; lainnya [I] (D2) |

Kunci: `PRIMARY (id_tanda_jasa)` [I]; **UNIQUE** `uq_tanda_jasa_nama (tanda_jasa)` [V2] (B3; cek legacy :174, :191).

Ringkasan nama constraint:

| Tabel | PRIMARY | UNIQUE | KEY | FK | CHECK |
|---|---|---|---|---|---|
| `diklat` | `id_diklat` | `uq_diklat_nama` | — | — | `chk_diklat_jenis_diklat` |
| `tingkat_hukdis` | `id_tingkat_hukdis` | `uq_tingkat_hukdis_nama` | — | — (diacu 1 FK) | — |
| `jenis_hukdis` | `id_jenis_hukdis` | `uq_jenis_hukdis_nama` | `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` | `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` | `chk_jenis_hukdis_masa_sanksi_bulan` |
| `jenis_konket` | `id_jenis_konket` | `uq_jenis_konket_nama`, `uq_jenis_konket_old_id` | — | — | `chk_jenis_konket_affect_tukin` |
| `tanda_jasa` | `id_tanda_jasa` | `uq_tanda_jasa_nama` | — | — | — |

Sengaja **tidak** dibuat:
- FK dari tabel riwayat/pegawai ke master ini (`fk_id_diklat_rdiklat_to_diklat`, `fk_id_diklat_pegdiklat_to_diklat`, `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis`, `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis`, `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis`, `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis`, `fk_id_tanda_jasa_rtj_to_tj`, `fk_id_tanda_jasa_pegtj_to_tj`): dibuat di B-11/B-14/B-17 bersama tabelnya. Syarat: tipe kolom anak sama persis dengan PK di sini (`diklat` TINYINT signed; empat lainnya INT bila D1 disetujui).
- FK `jenis_konket.old_id ↔ absen_ijin.kategori`: legacy tidak punya FK fisik; B-13 memvalidasi di aplikasi.
- Baris seed/sentinel (2.6), kolom penguncian baris sistem dan kolom filter per role (B6/B7), tabel `rumpun_sertifikasi`/`lembaga_sertifikasi` (B9).

### 2.6 Baris yang di-hard-code kode legacy (didokumentasikan, TIDAK dikunci — keputusan B6)

| Tabel | ID | Arti di kode legacy | Bukti |
|---|---|---|---|
| `jenis_konket` | `id` 4, 5 | selalu disembunyikan dari pilihan pengajuan | `rwy/L_konket.php:36, 149` |
| `jenis_konket` | `id` 2, 6 | juga disembunyikan untuk form pegawai dan admin level 3 → filter per role = B-13 | `rwy/L_konket.php:91, 153` |
| `jenis_konket` | `old_id` 8 | Dinas (pribadi/gabungan, kolom `jenis_dinas`) | `rwy/L_konket.php:96, 178` + 34 tempat lain |
| `jenis_konket` | `old_id` 10 | izin sakit | `rwy/L_konket.php:1280-1301`, `Local.php:2240` |
| `jenis_konket` | `old_id` 13 | konket lain-lain; dapat uang makan bila `affect_tukin` = 1 | `L_presensi.php:2079, 4753, 4763` |
| `tanda_jasa` | 26, 27, 28 | Satyalancana Karya Satya XXX/XX/X Tahun | `L_employee.php:2922-2932`, `siasn_sync/Rw_tj.php:325, 393` |
| `tanda_jasa` | 44 | `LAIN-LAIN`: dibuang dari daftar dan memicu isian `tanda_jasa_lain` → opsi Lain-lain = B-17. Kode juga membandingkan salinan nama `riwayat_tanda_jasa.tanda_jasa == 'LAIN-LAIN'`, jadi nama master hampir pasti persis `LAIN-LAIN` [I kuat]; mengganti namanya mematahkan logika legacy. B-17 wajib memakai `id_tanda_jasa = 44`, bukan nama | `rwy/L_tj.php:49, 437-438, 499-501`, `tj/form.php:101`, `function_helper.php:692`; pembandingan nama `A_employee.php:2209`, `L_employee.php:7444`, `print/drh.php:477`, `drh_pdf.php:325` |
| `diklat` | 8 | dikecualikan dari push SIASN | `Siasn.php:1810`, `Rw_pelatihan.php:172` |

- Tabel pemetaan SIASN (DB `siasn_simpeg`) merujuk ID master: `LATIHAN_STRUKTURAL_ID.id_diklat` (`Siasn.php:1677-1678`), `JENIS_HUKUMAN_ID.id_jenis_hukdis` (:2895-2897), `HARGA_ID.id_tanda_jasa` (:5958-5959) → impor wajib ID legacy apa adanya (B8), tidak boleh dinomori ulang.
- Tidak ada seed di migration: seed akan bentrok PK/UNIQUE dengan impor "Copy langsung", nama/atribut baris-baris itu belum diketahui dari data produksi (kecuali 44), dan B6 memutuskan tanpa kunci. `DiklatHukdisKonketSchemaTest::testMigrationCreatesNoRows` memastikan migration tidak menulis baris.
- Konsekuensi tanpa kunci: admin bisa mengganti nama atau menonaktifkan baris-baris ini; dampaknya ke B-13/B-17/D-06/SIASN ditinjau lagi di Fase 3.

### 2.7 Perilaku aplikasi (CR-012)

Kelima tabel dikelola lewat endpoint master generik (README `app/Controllers/Api/MasterData/README.md`), dari entri `Config\MasterData` blok `DBV-005`. Route, service, dan komponen frontend tidak diubah: semua opsi yang dipakai sudah ada sejak CR-009 (menu aksi baris sejak CR-015). PR ini tidak lagi mengubah engine: satu-satunya hunk engine semula (`MasterField::normalize()`: nilai yang dianggap kosong oleh rule `permit_empty` — spasi/tab saja, JSON `false` — disimpan NULL, bukan `(int) 0`) identik dengan hunk DBV-004/CR-011 yang masuk `main` lewat #13, sehingga setelah merge `main` (`acd8693`) file itu sama dengan `main`. Perbaikan engine CR-011 lain dari #13 ikut berlaku untuk G-06 dan menutup batas #1 dan #2 di bawah.

| Key (`master/{key}`) | Controller | Nama (panjang, label) | Induk | Kolom tambahan & aturan | Opsi engine |
|---|---|---|---|---|---|
| `diklat` | `DiklatController` | `nama_diklat` (≤255, "Nama Pelatihan") | — | `jenis_diklat` "Jenis Pelatihan": select **wajib** 1 Struktural, 2 Teknis, 3 Fungsional, 4 Prajabatan, 5 Sertifikasi (B1); nilai lain → 422 | `uniqueScope`, `orderScope`, `filters` = `['jenis_diklat']`; `idMaxLength` 3 (meta `id_max_length`; batas rule field ref yang nanti merujuk `diklat`, mis. B-11) |
| `tingkat-hukdis` | `HukdisController` | `tingkat_hukdis` (≤100, "Tingkat Hukuman Disiplin") | — | `bobot_ipasn` tidak ada di form/meta/respons dan tidak bisa ditulis: tambah memakai default DB (5), ubah mempertahankan nilai lama (B5) | `hiddenColumns ['bobot_ipasn']` |
| `jenis-hukdis` | `HukdisController` | `jenis_hukdis` (≤255, "Jenis Hukuman Disiplin") | `id_tingkat_hukdis` → `tingkat-hukdis` (wajib aktif) | `masa_sanksi_bulan` "Masa Sanksi (bulan)": int opsional 1–255; kosong/`null` = NULL; 0, 256, negatif, desimal, teks → 422 (K5b) | `statusChain` |
| `jenis-konket` | `KonketController` | `jenis_konket` (≤255, "Jenis Konfirmasi Ketidakhadiran") | — | `old_id` "Kode Kategori": int **wajib** 1–2.147.483.647, unik global termasuk entri tidak aktif/dihapus (422 menyebut pemiliknya + saran aktifkan/pulihkan; balapan 1062 → 422), bisa diubah admin (B2). `affect_tukin` "Pengaruh ke Tukin": select **wajib** 1 Ya / 2 Tidak (K5a) | `uniqueFields ['old_id']` |
| `tanda-jasa` | `TandaJasaController` | `tanda_jasa` (≤255, "Tanda Jasa") | — | — | — |

Semua entri: `autoIncrement` (kode diberikan DB), `auditColumns ['updated_at','updated_by']` (konstanta `AUDIT_G06`), `orderColumnType 'tinyint'`, urutan mode `shift`, `publicOptions` bawaan (dropdown UL_ALL), tanpa hook.

- **RBAC.** CRUD + `master/meta` = role 1; `{key}/options` = semua role login (dipakai B-11/B-13/B-14/B-17 dan D-06); tanpa token 401. CRUD dibuktikan `RbacMasterEndpointsTest` untuk kelima key. Dropdown UL_ALL dikunci `DiklatHukdisKonketTest::testOptionsAreOpenToEveryRole` (role 1–8, termasuk saringan jenis pelatihan dan tingkat hukdis), karena `RbacMasterEndpointsTest` membaca daftar master admin-only dari config itu sendiri.
- **Urutan.** `order` = posisi tampil 1..n per lingkup: `diklat` per jenis, `jenis-hukdis` per tingkat, lainnya global. Pindah jenis/tingkat = ditaruh di akhir lingkup baru, lingkup lama dirapatkan. Kolom `order` TINYINT: paling banyak 127 entri tampil per lingkup; tambah berikutnya (dengan atau tanpa `order`) → 422 `errors.order` "Urutan … sudah mencapai batas maksimal 127." (bukan 1264). Entri berstatus 10 tidak dihitung.
- **Kolom audit.** Tambah, ubah, pindah urutan, ubah status, hapus, dan pulihkan mengisi `updated_at` (jam aplikasi, UTC) dan `updated_by` (`id_pengguna` aktor) pada baris yang diedit saja. Saudara yang hanya bergeser urutannya tidak di-stamp (ON UPDATE CURRENT_TIMESTAMP tidak terpicu), tetapi tetap tercatat di `audit_logs`. Tidak ada `created_*`.
- **Nilai yang ditolak DB tidak sampai ke DB.** CR-007 hanya menerjemahkan 1406/1264/1366/1292/1265/1364 ke 422; 1048 (NOT NULL) dan pelanggaran CHECK (3819/4025) tetap 500. Karena itu `jenis_diklat`, `old_id`, dan `affect_tukin` wajib di validasi, dan `masa_sanksi_bulan` dibatasi 1–255 (kosong, spasi/tab saja, `null`, dan JSON `false` = NULL), sehingga setiap nilai isian skalar yang melanggar NOT NULL/CHECK sudah ditolak 422 pada field-nya (`DiklatHukdisKonketTest`). Isian JSON array/objek (mis. `[]`, `["3"]`) ditolak engine lebih dulu: 422 "`<Label>` tidak valid." pada field-nya (CR-011; batas #2 tertutup).
- **Dropdown & filter.** `diklat/options?jenis_diklat=1..5` dan daftar admin `diklat?jenis_diklat=` menyaring per jenis; nilai lain (termasuk bentuk array) → 422 "Filter Jenis Pelatihan tidak valid."; urut jenis → `order` → nama. `jenis-hukdis/options?parent=` hanya memuat jenis yang tingkatnya status 1 (legacy hanya menawarkan tingkat aktif, `Lm_hukdis.php:221`); status jenis itu sendiri tidak diubah, dan cache dropdown jenis ikut di-invalidate saat tingkat ditulis. Tambah/pindah jenis ke tingkat yang tidak aktif → 422 `id_tingkat_hukdis`.
- **Frontend.** Halaman generik `/master/:entity` (menu Master Data, role 1) dibangun dari `master/meta`: select wajib `jenis_diklat`/`affect_tukin` tanpa nilai awal (placeholder "Pilih …", C1), input angka `old_id`/`masa_sanksi_bulan` dengan batas min/max dari meta, filter jenis pelatihan (item "Naikkan urutan"/"Turunkan urutan" di menu ⋮ baris hanya muncul saat satu jenis dipilih; aksi baris lewat `RowActionsMenu` dan kolom Status berupa badge, sesuai `AGENTS.md` bagian 1 dari CR-015), dropdown induk tingkat hukdis. `bobot_ipasn` tidak ada di meta, jadi tidak tampil. Dikunci Vitest `MasterDataView.g06.spec.ts` lewat helper menu (`rowActionsMenu.helpers`): urutan & label item baku (Edit → Nonaktifkan/Aktifkan → Naikkan/Turunkan urutan → Hapus bertanda bahaya), item urutan diklat hanya setelah satu jenis dipilih, jenis hukdis hanya setelah satu tingkat dipilih, tiga master lain global; hapus tingkat lewat `ConfirmDialog` yang menyebut jenis hukdis ikut tersembunyi dari dropdown; satu tombol ⋮ per baris (`Aksi untuk <nama>`) dan Status hanya badge.
- **Fixture test** (`MasterDataSeeder::seedG06`, fixture blok `DBV-005`): baris ber-ID hard-coded 2.6 — diklat 8; konket id 2/4/5/6 dengan `old_id` 8/10/13; tanda jasa 26/27/28 dan 44 `LAIN-LAIN` — plus satu `diklat` berstatus 10 (contoh jalur `dm_diklat`). Nama contoh [I]; **pasangan id ↔ `old_id` konket fiktif** (data produksi belum ada). `DiklatHukdisKonketTest::testHardCodedLegacyRowsAreNotLocked` membuktikan baris itu tidak dikunci (B6).

Batas yang diketahui. Batas #1 dan #2 (dicatat saat PR diajukan, tidak diperbaiki di CR-012) **tertutup** oleh perbaikan engine generik DBV-004/CR-011 yang masuk `main` lewat #13; setelah merge `main` `acd8693` keduanya diverifikasi untuk master G-06 dengan test dan uji mutasi (Bagian 6.2). Batas #3 tetap.
1. **PK `diklat` habis — tertutup.** Setelah id 127 terpakai, tambah pelatihan dijawab **422** tanpa `errors`: `message` "Kode Pelatihan sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database.", tanpa baris maupun audit tertulis, dan geseran urutan saudara (tambah dengan `order`) ikut di-rollback. `MasterService::translateDuplicate()` mengenali PK AUTO_INCREMENT yang habis di kedua engine — MySQL 8: 1062 pada `PRIMARY`; MariaDB 10.4: error 167 `Out of range value for column 'id_diklat'` — setelah cek ulang nama/`uniqueFields` tidak menemukan duplikat (nama ganda tetap 422 pada nama). Dikunci `DiklatHukdisKonketTest::testDiklatFullTinyintKeyGives422` (jalur 1062) dan `MasterGenericTcTest::testMariaDbAutoIncrementOutOfRangeGives422ForEveryAutoIncrementMaster` (simulasi 167 untuk kelima master G-06; daftar master ber-PK TINYINT memuat `diklat`). Saat diajukan: **500**, karena 1062 PRIMARY tidak diterjemahkan (Bagian 3 #8, C5).
2. **Input JSON array di field angka opsional — tertutup.** Controller engine menolak kolom definisi bernilai array/objek JSON sebelum validasi dan normalisasi → 422 "Masa Sanksi (bulan) tidak valid." pada `masa_sanksi_bulan`, saat tambah maupun ubah (berlaku juga untuk `jenis_diklat`, `old_id`, `affect_tukin`, nama, induk, urutan, dan status). Dikunci `DiklatHukdisKonketTest::testJenisHukdisFollowsTingkatChainAndMasaSanksi` (`[]`, `["3"]`, objek) dan loop nilai salah `jenis_diklat`/`old_id`/`affect_tukin`. Saat diajukan: `masa_sanksi_bulan` bernilai `[]` lolos `permit_empty`, dinormalkan menjadi 0, lalu melanggar CHECK → **500**.
3. **Tabel daftar FE generik** hanya menampilkan urutan, kode, nama, induk, dan status. `jenis_diklat`, `old_id`, `affect_tukin`, dan `masa_sanksi_bulan` hanya terlihat di form Edit, dan daftar diklat tanpa filter mencampur jenis tanpa penanda (sama dengan `kd_area`/`kd_pos` master lain). Usulan issue FE generik: tampilkan field `order_scope`/select di tabel.

Keputusan kode yang diminta dari reviewer CR-012:

| # | Keputusan | Diimplementasikan | Alternatif |
|---|---|---|---|
| C1 | `affect_tukin` di form/API | Select **wajib**, tanpa nilai awal di form: engine belum punya opsi `default` untuk field, dan kosong → NULL → 1048 → 500. "DEFAULT 1" K5a = default kolom (impor/SQL) dan nilai awal pengajuan di B-13 | Opsi engine `default` (MasterField + meta + `MasterFormDialog`) sebagai CR generik terpisah — juga berguna untuk `pangkat.cpns` DBV-004 |
| C2 | `old_id` bisa diubah admin | Ya (B2 "dikelola admin"); hint form memperingatkan bahwa mengubahnya memutus pengajuan lama yang memakai kode itu | Kunci setelah dipakai pengajuan → B-13 (tabel `absen_ijin` belum ada) |
| C3 | Dropdown jenis hukdis mengikuti status tingkat | Ya, `statusChain` | — |
| C4 | `old_id` minimal 1 | Ya (kode 0 dibaca "kosong" oleh PHP legacy); audit impor 6.5 #3 mencakup `old_id` ≤ 0 | Minimal 0 |
| C5 | PK `diklat` habis | **Tertutup** oleh engine CR-011 (#13, di `main`): 422 dengan penjelasan di MySQL (1062 PRIMARY) maupun MariaDB (167), tanpa kode khusus G-06 (batas #1). Saat diajukan: 500, diterima dan didokumentasikan | — (hook cek `MAX(id_diklat)` sebelum tambah tidak diperlukan lagi) |
| C6 | Label | Legacy: "Pelatihan"/"Nama Pelatihan"/"Jenis Pelatihan" (`Lm_diklat.php:56`, `master/diklat/form.php:38`), "Tingkat/Jenis Hukuman Disiplin" (`Lm_hukdis.php:56, 276`), "Jenis Konfirmasi Ketidakhadiran" (`Lm_konket.php:56`), "Pengaruh ke Tukin" (`rwy/konket/form_ad.php:113`); v2: "Kode Kategori", "Masa Sanksi (bulan)" | — |

Catatan modul B/D (bukan pekerjaan master):
- B-11: FK anak `riwayat_diklat.id_diklat` TINYINT signed; jenis 3 memakai `sub_group_jabatan` (`id_group_jabatan=2`), jenis 5 memakai rumpun/lembaga + nama bebas (1.4 #1). Dropdown pelatihan per jenis = `diklat/options?jenis_diklat=`.
- B-13: value pilihan = `old_id` (= `absen_ijin.kategori` INT); sembunyikan id 4/5 untuk semua, 2/6 untuk pegawai & role 3; `affect_tukin` pengajuan diisi awal dari master dan bisa diubah admin; salin nama ke `absen_ijin.jenis_konket`; pertimbangkan mengunci `old_id` setelah dipakai pengajuan (C2).
- B-14: FK anak INT (bila D1 disetujui); `masa_hukuman`/`akhir_hukdis` per SK; default `akhir_hukdis = tmt + masa_sanksi_bulan` bila tidak NULL; SIASN tetap mengisi masa per SK. Dropdown jenis = `jenis-hukdis/options?parent=` (sudah menyaring tingkat non-aktif).
- B-17: opsi `LAIN-LAIN` (id 44) di luar daftar master aktif + wajib `tanda_jasa_lain`; logika memakai id 44, bukan nama.
- D-06: `kategori` 13 + `affect_tukin` 1 → uang makan (`L_presensi.php:2079`). `L_presensi.php:3721-3725` membangun peta `constJK[old_id]` dengan `ORDER BY old_id` — bila `old_id` produksi VARCHAR urutannya leksikografis ('10' < '8'), di v2 numerik; port D-06 jangan bergantung pada urutan ini.
- Riwayat menyimpan salinan nama master (`rwy/L_diklat.php:677-679`, `rwy/L_hukdis.php:478-484`, `rwy/L_tj.php:499-500`, `rwy/L_konket.php:1367-1368`); mengganti nama master tidak mengubah riwayat.

## 3. Deviasi dari legacy & nilai [I]

| # | Item | Legacy [K] | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Status 10 & COMMENT | `tingkat_hukdis`, `jenis_konket`, `tanda_jasa` dan `diklat` (lewat `Lm_diklat`) hard DELETE; `jenis_hukdis` dan jalur `dm_diklat` status 10; COMMENT `diklat` `'1: Active, 2: Inactive, 10: Deleted'` (:448) | Kelima tabel status 1/2/10, hapus = soft delete, COMMENT `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` | Aturan DBV-001/002. Tidak ada hard delete dari aplikasi, sehingga FK RESTRICT tidak terpicu dari aplikasi |
| 2 | UNIQUE nama (5 index) | Tidak ada UNIQUE selain PK. Cek duplikat kode mengecualikan status 10; cek ubah konket rusak (:189); cek gagal untuk nama berkutip (`addslashes`) | 5 UNIQUE nama, termasuk baris status 2/10, case-insensitive | Nama yang pernah dihapus (10) tidak bisa dibuat ulang, harus dipulihkan. **Audit duplikat data legacy wajib sebelum impor** (6.5 #1), khususnya `jenis_hukdis` & `diklat` (bisa punya status 10) dan `jenis_konket` |
| 3 | `jenis_konket.old_id` | Satu-satunya DDL lokal `varchar(50) NULL` (`simpegdev_local`); form master tidak mengisinya | INT NOT NULL + UNIQUE `uq_jenis_konket_old_id` (B2) | Tanpa `old_id` jenis baru tidak bisa dipakai di pengajuan (value option = `old_id`). Audit NULL/ganda/bukan angka sebelum impor (6.5 #3) |
| 4 | `jenis_konket.affect_tukin` | Tidak ada di master; hanya per pengajuan `absen_ijin.affect_tukin` 1 Yes / 2 No (`form_ad.php:116-122`) | Kolom baru TINYINT NOT NULL DEFAULT 1 (K5a) | Nilai awal pengajuan; admin tetap bisa mengubah per pengajuan |
| 5 | `jenis_hukdis.masa_sanksi_bulan` | Tidak ada; `riwayat_hukdis.masa_hukuman` teks bebas + `akhir_hukdis` manual (`rwy/hukdis/form.php:122-130`); SIASN mengisi per SK (`Siasn.php:2940-2946`) | Kolom baru TINYINT UNSIGNED NULL (K5b, D3) | Default hitung `akhir_hukdis`; riwayat tetap menyimpan masa sendiri. Berbeda dengan Tech Spec §3.5 "bukan input manual" (1.4 #4) |
| 6 | Aksi FK | Tidak tercatat (ERD hanya nama) → [I] | `ON DELETE RESTRICT ON UPDATE RESTRICT`, nama legacy | G2/B4. MySQL 8 menampilkan RESTRICT eksplisit di `SHOW CREATE TABLE`; MariaDB bisa menghilangkannya karena itu default — verifikasi lewat `information_schema.REFERENTIAL_CONSTRAINTS` (dilakukan schema test) |
| 7 | 3 CHECK | Tidak ada | `chk_diklat_jenis_diklat`, `chk_jenis_hukdis_masa_sanksi_bulan`, `chk_jenis_konket_affect_tukin` (D4, prefix `chk_` seperti DBV-003/004) | Menegakkan B1/K5 di lapis DB; aplikasi menolak nilai yang sama lebih dulu (422, 2.7). MySQL ≥ 8.0.16 dan MariaDB ≥ 10.2.1 menegakkan CHECK (MySQL error 3819, MariaDB 4025), juga saat `sql_mode=''` (diuji di MySQL 8.0.30). Impor dengan nilai di luar rentang akan gagal → normalkan dulu (6.5 #5) |
| 8 | Tipe PK | `diklat` TINYINT signed [K]; empat tabel lain tidak ada DDL | `diklat` TINYINT signed; empat lainnya INT [I] (D1) | TINYINT signed berhenti di 127: setelah id 127, insert berikutnya gagal (`1062 Duplicate entry '127' for key PRIMARY` di MySQL 8.0.30, diuji schema test). Sisa ID seumur hidup = 127 − counter AUTO_INCREMENT legacy setelah diselaraskan (6.5 #10); produksi `diklat` AUTO_INCREMENT=17 → ± 110 (soft delete tidak membebaskan ID). Tanpa penyelarasan counter v2 mulai dari `MAX(id_diklat)+1`. MariaDB 10.4 menolak insert itu dengan error 167 (`Out of range value for column 'id_diklat'`). Aplikasi: tambah pelatihan setelah ID habis dijawab 422 dengan penjelasan di kedua engine (engine CR-011; 2.7 batas #1, C5 — saat diajukan 500); perluasan PK = migration ALTER baru + tipe kolom FK anak. Tipe PK menentukan tipe kolom FK `riwayat_*` di B-11/B-14/B-17 |
| 9 | Kolom audit empat tabel | Kode hanya menulis `updated_by`; DDL tidak ada | Pola `diklat` [K]: `updated_at` NOT NULL ON UPDATE + `updated_by`, tanpa `created_*` [I] (D2) | `group_jabatan` membuktikan tabel dengan pustaka serupa bisa punya `created_at` dari default DB. Bila dump menunjukkan `created_at`, tambahkan lewat ALTER selagi tabel kosong |
| 10 | Opsi `jenis_diklat` | COMMENT 1–5 [K]; form master hanya 4/1/2 (3 `disabled`, 5 tidak ada); riwayat jenis 3/5 tidak memakai baris master | 1–5 (B1) + CHECK | Konsekuensi untuk B-11 (1.4 #1, 2.7) |
| 11 | `bobot_ipasn` | Dipakai skor IPASN (`L_user.php:1059-1070`), tidak ada di form | Disimpan INT NULL DEFAULT 5 [I], tidak dikelola (B5) | IPASN tidak ada di dokumen v2; nilai legacy disalin apa adanya |
| 12 | AUTO_INCREMENT awal, COMMENT audit | `diklat` AUTO_INCREMENT=17; kolom audit tanpa COMMENT | Tidak ditulis / COMMENT `updated_by` [V2] | Preseden DBV-001/002. Berbeda dari Batch 1 & FAQ, empat tabel G-06 dihapus keras di legacy, jadi counter disalin dari legacy saat impor (6.5 #10) agar ID yang pernah dihapus tidak dipakai ulang |
| 13 | `order` TINYINT | `diklat` [K]; legacy form 1–100 tanpa validasi server (`jenis/form.php:56`) | TINYINT NOT NULL DEFAULT 1 untuk kelima tabel ([I] empat tabel) | Batas 127 entri per lingkup urutan (diklat per jenis, jenis hukdis per tingkat, lainnya global). Nilai `order` legacy 0/ganda/> 127 dinormalkan saat impor (6.5 #5) |

Nilai **[I]** yang tersisa ada di Bagian 6.4.

## 4. Keputusan yang diminta dari DB Validator (DBV-005)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2 + migration `2026-09-25-120000_CreateDiklatHukdisKonketTandaJasa` untuk dijalankan di Dev | Setujui, setelah `migrate` → `migrate:rollback` → `migrate` dan schema test lolos di MariaDB 10.4 (6.3) | ✅ Setuju (29-09-2026, jjoseph48). Terverifikasi di MariaDB 10.4: batch tersendiri, rollback hanya kelima tabel G-06, `migrate` ulang bersih; schema test lolos (6.3 #1–#2) |
| 2 | PK empat tabel [I] INT (vs TINYINT pola master kecil produksi) — D1 | INT: satu-satunya bukti (`simpegdev_local`) dan aman untuk ID legacy berapa pun; cocokkan dengan dump. Tipe FK `riwayat_*` (B-11/14/17) mengikuti | ✅ Setuju INT [I] (disebut eksplisit DBV); tetap dicocokkan dengan dump (6.4). Tipe FK `riwayat_*` (B-11/14/17) mengikuti |
| 3 | Kolom audit [I] = pola `diklat` (`updated_at` NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE, `updated_by`), tanpa `created_*` — D2 | Setujui; bila dump menunjukkan `created_at`, ALTER selagi kosong | ✅ Setuju [I] (disebut eksplisit DBV); bila dump menunjukkan `created_at`, ALTER selagi kosong |
| 4 | Panjang/NOT NULL kolom nama [I] (`tingkat_hukdis` 100, `jenis_hukdis`/`jenis_konket`/`tanda_jasa` 255, semua NOT NULL) | Setujui | ✅ Disetujui sesuai usulan* |
| 5 | `order`/`status` TINYINT NOT NULL DEFAULT 1 untuk tabel [I] (konket `simpegdev_local` `status` VARCHAR(10)) | Setujui (status 1/2/10 TINYINT seperti DBV-001/002) | ✅ Disetujui sesuai usulan*; tipe terverifikasi (PK/`order`/`status` TINYINT signed, 6.3 #5) |
| 6 | 6 UNIQUE (`uq_diklat_nama`, `uq_tingkat_hukdis_nama`, `uq_jenis_hukdis_nama`, `uq_jenis_konket_nama`, `uq_jenis_konket_old_id`, `uq_tanda_jasa_nama`), termasuk baris status 2/10 | Setujui; audit duplikat `utf8mb4_unicode_ci` wajib sebelum impor (6.5 #1) | ✅ Setuju; audit duplikat sebelum impor (6.5 #1) **wajib jadi langkah runbook impor**. Terverifikasi: UNIQUE 1.024 byte diterima (6.3 #6) |
| 7 | `jenis_konket.old_id` INT NOT NULL UNIQUE tanpa FK fisik (B2 final; 1.4 #2) | Konfirmasi tipe dengan dump; audit NULL/ganda/bukan angka (6.5 #3) | ✅ Setuju INT (disebut eksplisit DBV); tipe tetap dicocokkan dengan dump; audit NULL/ganda/bukan angka (6.5 #3) wajib jadi langkah runbook impor |
| 8 | `jenis_konket.affect_tukin` TINYINT NOT NULL DEFAULT 1, 1 Ya / 2 Tidak (K5a final) | Cek nama, tipe, dan COMMENT | ✅ Disetujui sesuai usulan*; CHECK menolak `affect_tukin` = 3 (4025, 6.3 #3) |
| 9 | `jenis_hukdis.masa_sanksi_bulan` TINYINT UNSIGNED NULL (nullable final K5b; tipe usulan D3) | Setujui | ✅ Disetujui sesuai usulan*; terverifikasi `tinyint(3) unsigned`, CHECK menolak 0 (4025, 6.3 #3, #5) |
| 10 | FK `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` RESTRICT/RESTRICT; `jenis_hukdis.id_tingkat_hukdis` NOT NULL | Setujui; audit NULL/yatim (6.5 #4) | ✅ Setuju; terverifikasi RESTRICT di `REFERENTIAL_CONSTRAINTS` dan hapus induk yang punya anak ditolak 1451 (6.3 #4); audit NULL/yatim (6.5 #4) wajib jadi langkah runbook impor |
| 11 | 3 CHECK [V2] (D4): `chk_diklat_jenis_diklat`, `chk_jenis_hukdis_masa_sanksi_bulan`, `chk_jenis_konket_affect_tukin` (prefix `chk_` disamakan dengan DBV-003/004) | Setujui; verifikasi penegakan di MariaDB 10.4 (6.3 #3) | ✅ Setuju, termasuk penamaan `chk_…` (disebut eksplisit DBV). Terverifikasi: ketiganya terdaftar di `TABLE_CONSTRAINTS` dan ditegakkan dengan 4025 (6.3 #3) |
| 12 | `tingkat_hukdis.bobot_ipasn` INT NULL DEFAULT 5 disimpan, disembunyikan (B5) | Setujui | ✅ Disetujui sesuai usulan* |
| 13 | COMMENT `jenis_diklat` legacy dipertahankan verbatim; COMMENT `status` diganti versi v2 | Setujui | ✅ Disetujui sesuai usulan* |
| 14 | Tanpa seed/sentinel dan tanpa kunci baris hard-coded (B6); impor memakai ID legacy apa adanya | Setujui; DBV cek keberadaan & nama baris 2.6 di data produksi (6.5 #6) | ✅ Disetujui sesuai usulan*; keberadaan & nama baris 2.6 dicek di data produksi saat audit impor (6.5 #6) |
| 15 | AUTO_INCREMENT awal tidak ditulis di migration, counter legacy disalin saat impor (6.5 #10); batas TINYINT `diklat` (127). Setelah ID habis aplikasi menjawab 422 dengan penjelasan saat tambah pelatihan (2.7 batas #1; saat diajukan 500, tertutup engine CR-011 setelah merge `main`); perluasan PK butuh migration ALTER + tipe kolom FK anak | Setujui; cek `MAX(id_diklat)` dan counter `diklat` produksi ≤ 127 | ✅ Setuju (disebut eksplisit DBV) **dengan syarat**: penyalinan counter AUTO_INCREMENT legacy (6.5 #10) wajib jadi langkah runbook impor; cek `MAX(id_diklat)` dan counter `diklat` produksi ≤ 127 |

Approval tanpa catatan per poin dicatat mengikuti kolom **Usulan** (preseden DBV-001/002). Koreksi setelah approval dilakukan lewat migration ALTER baru, bukan mengedit migration ini.

\* Sumber keputusan: komentar DB Validator (jjoseph48) di PR #14, 29-09-2026 — "setuju seluruhnya" untuk no. 1–15, termasuk butir [I] yang disebut eksplisit (#2 PK INT empat tabel, #3 pola audit tanpa `created_*`, #7 `old_id` INT, #11 penamaan `chk_…`, #15 AUTO_INCREMENT tidak ditulis di migration dengan counter legacy disalin saat impor). **Syarat:** audit data sebelum impor (6.5) dan penyalinan counter (6.5 #10) wajib menjadi langkah runbook impor. Kesimpulan DBV: dari sisi skema layak disetujui, tidak ada temuan yang memblokir; catatan zona waktu bersifat tindak lanjut (Trello ISSUE-022, bagian Status).

## 5. Status keputusan G-01 terkait

| G-01 | Isi | Sebelumnya | Setelah DBV-005 |
|---|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status`, termasuk `diklat`, `hukdis`, `konket`, `tanda_jasa` | ✅ YA (23-09-2026) | Dijalankan: `diklat.order` ternyata sudah ada di DDL legacy [K]; empat tabel lain `order` TINYINT [I] (kolom sudah dipakai kode legacy) |
| Keputusan #2 / Bagian 6 #2 | UNIQUE index nama (ISSUE-009) | Dikerjakan untuk Batch 1 (DBV-001) | 6 UNIQUE di G-06 (Bagian 4 #6); audit duplikat wajib sebelum impor |
| Keputusan #9 / Bagian 6 #3 | Collation `utf8mb4_unicode_ci` (ISSUE-010) | Selesai sebagian (DBV-001) | Kelima tabel `utf8mb4_unicode_ci` per tabel; `DBCollat` koneksi tetap belum diubah (DBV-010) |
| Bagian 3 | "beberapa master (… diklat, hukdis, konket, tanda_jasa …) tanpa kolom `order`" | Menunggu DDL | Dijalankan untuk G-06. Rujukan di G-01 ditambahkan setelah ketiga PR grup (DBV-003/004/005) merge (dokumen G-01 tidak diubah di PR ini) |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon, `sql_mode` STRICT_TRANS_TABLES, row format default DYNAMIC). Database test `simpeg_v2_t_dbv005x7` (DBPrefix `t_`, `strictOn=true`; migration dijalankan otomatis oleh PHPUnit lewat `migrate:refresh`). Database scratch `simpeg_v2_s_dbv005x7` (koneksi default, tanpa prefix) untuk siklus `migrate` → `migrate:rollback` → `migrate`. Database dev `simpeg_v2` **tidak** dijalankan migration ini. Verifikasi di MariaDB 10.4 dilakukan DB Validator saat review (29-09-2026): hasil di bagian Status dan 6.3. Catatan lingkungan (DBV): database test yang sebelumnya dipakai branch dengan migration lebih baru membuat PHPUnit gagal "There is a gap in the migration sequence" — bersihkan database test saat berpindah branch.

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| `php spark migrate --all` (DB scratch; state `main` sudah di batch 1) | `CreateDiklatHukdisKonketTandaJasa` masuk batch 2 tersendiri |
| `php spark migrate:rollback` | hanya batch 2 yang dibatalkan: kelima tabel G-06 hilang; `provinsi`, `agama`, `faq_topic` tetap ada; tabel `migrations` kembali 13 baris, batch maks 1 |
| `php spark migrate --all` (ulang) | kelima tabel dibuat lagi, batch 2 (`migrations` 14 baris) |
| `information_schema` DB scratch | `TABLE_CONSTRAINTS` kelima tabel: 5 PRIMARY, 6 UNIQUE, 1 FK, 3 CHECK (nama `chk_…`) sesuai Bagian 2; `REFERENTIAL_CONSTRAINTS` `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` UPDATE/DELETE = RESTRICT; `SHOW CREATE TABLE` identik dengan blok di bawah |
| `phpunit --no-coverage tests/MasterData/DiklatHukdisKonketSchemaTest.php` | `OK (6 tests, 184 assertions)` — kolom & tipe persis (termasuk `tinyint(1)` dan `tinyint unsigned`, tanpa `created_*`), collation `utf8mb4_unicode_ci` per tabel & kolom, InnoDB, index persis, 1 FK + kolom induk + RESTRICT/RESTRICT, `old_id` tanpa FK, 3 CHECK (nama); default & COMMENT (`status`, `order`, `updated_at` ON UPDATE, `updated_by`, `jenis_diklat` legacy, `bobot_ipasn` 5, `masa_sanksi_bulan` NULL, `affect_tukin` 1, `old_id` tanpa default); migration tidak menulis baris; constraint DB menolak nama ganda beda kapitalisasi (termasuk terhadap baris status 10), `jenis_diklat` 0/6, `id_diklat` 128 dan AUTO_INCREMENT setelah 127, `id_tingkat_hukdis` yatim/NULL, `masa_sanksi_bulan` 0/-1/256, `old_id` kosong/ganda, `affect_tukin` 0/3, hapus & ganti PK induk yang masih punya anak; lingkup UNIQUE per jenis/per tingkat; `down()` lalu `up()` mengembalikan skema yang sama tanpa menyentuh `provinsi`/`faq_topic`; `up()` yang gagal di tengah (penghalang `jenis_konket`) men-drop hanya tabel yang dibuat run itu lalu bisa diulang |
| Uji mutasi manual pada migration (dipulihkan setelahnya; diulang setelah nama CHECK diganti ke `chk_`) | 6/6 mutasi tertangkap: CHECK `affect_tukin` dilonggarkan, FK ON DELETE CASCADE, `jenis_diklat` tanpa `(1)`, `uq_jenis_konket_old_id` jadi KEY biasa, CHECK `masa_sanksi_bulan >= 0`, nama CHECK kembali ke `ck_diklat_jenis_diklat` |
| `phpunit --no-coverage tests/MasterData/DiklatHukdisKonketTest.php` (CR-012) | `OK (10 tests, 415 assertions)` — meta kelima master (label, pilihan, batas angka, lingkup urutan & filter, induk + `statusChain`, batas urutan 127, `id_max_length` 3/11, tanpa `bobot_ipasn`); dropdown kelima master untuk role 1–8 (termasuk saringan jenis & tingkat), tanpa token 401; urutan & keunikan nama per jenis diklat + filter (nilai filter salah → 422); `bobot_ipasn` tidak pernah dikirim/ditulis; rantai status tingkat → jenis hukdis & `masa_sanksi_bulan` 1–255/NULL (spasi, tab, JSON `false` = NULL, tambah & ubah); `old_id` wajib/unik (termasuk entri non-aktif/dihapus)/bisa diubah; `affect_tukin` 1/2; baris hard-coded tidak dikunci; kapasitas urutan 127 → 422 `order`; stamp `updated_at`/`updated_by` hanya pada baris yang diedit. Setiap nilai isian yang ditolak NOT NULL/CHECK dijawab 422 pada field-nya dan jumlah baris tidak berubah |
| Uji mutasi CR-012 (dipulihkan setelahnya) | 20/20 mutasi tertangkap: `uniqueScope`/`orderScope` diklat dihapus, `hiddenColumns` tingkat dihapus, `statusChain` false, `uniqueFields` dihapus (1062 → 500), `affect_tukin` tidak wajib, `min` `old_id` dihapus, `columnType` masa jadi `tinyint`/dihapus, `min` masa dihapus (0 → CHECK → 500), `orderColumnType` tanda jasa dihapus, `MasterGenericTcTest` dikembalikan ke versi `main` (`created_at` tidak ada → gagal pada `diklat`), `HukdisController` tanpa `jenis-hukdis`, pilihan `jenis_diklat` tanpa 5, `AUDIT_G06` tanpa `updated_by`, `jenis_diklat` tidak wajib + tanpa `orderScope`, `KonketController` kosong; susulan review: `MasterField::normalize()` dikembalikan ke versi `main` (spasi → 0 → CHECK → 500), `publicOptions => false` pada `jenis-konket` (role 2 → 403), `idMaxLength` diklat dihapus (meta 11, bukan 3) |
| `vitest run src/features/master-data/__tests__/MasterDataView.g06.spec.ts` (FE, `AGENTS.md` bagian 1) | `5 passed` — meta kelima master bentuk backend di halaman Master Data generik; menu ⋮ tiap baris berisi item baku berurutan (Edit → Nonaktifkan/Aktifkan → Naikkan/Turunkan urutan → Hapus bertanda bahaya); item urutan `diklat` hanya setelah satu jenis pelatihan dipilih (6 pilihan filter), `jenis-hukdis` hanya setelah satu tingkat dipilih (kolom induk tampil), `jenis-konket`/`tanda-jasa` global; Hapus tingkat membuka konfirmasi yang menyebut jenis hukdis ikut tersembunyi dari dropdown; Status hanya badge, satu tombol ⋮ per baris (`Aksi untuk <nama>`). Uji mutasi `MasterDataView.vue` 4/4 tertangkap: induk tidak wajib dipilih untuk urutan, lingkup `order_scope` diabaikan, Hapus tanpa `danger`, Hapus dipindah sebelum item urutan |
| Quality gate penuh setelah rebase ke `main` `bdd87f6` (CR-015) dan tindak lanjut review — langkah sama dengan `./check.sh` (PHPStan level 5, PHP-CS-Fixer, PHPUnit, ESLint, vue-tsc, Vitest, build), PHPUnit dipecah 3 shard paralel dengan DB test & cache per shard | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 200 files that can be fixed`, PHPUnit `OK` di ketiga shard (129 + 137 + 119 = 385 test, 11.955 assertion; `MasterGenericTcTest` dan `RbacMasterEndpointsTest` juga menjalankan kelima key G-06), ESLint dan vue-tsc bersih, Vitest `24 passed` file / `233 passed` test, `vite build` selesai |
| Setelah merge `main` `acd8693` (#12 DBV-003, #13 DBV-004 termasuk perbaikan engine 167/PK habis dan `bobot_ipasn`, #15 DBV-010): verifikasi batas #1/#2 2.7 — `phpunit tests/MasterData/DiklatHukdisKonketTest.php` dan `MasterGenericTcTest.php` | `DiklatHukdisKonketTest` `OK (11 tests, 458 assertions)` (baru: `testDiklatFullTinyintKeyGives422`; array/objek `masa_sanksi_bulan` saat tambah & ubah dan `[]` pada `jenis_diklat`/`old_id`/`affect_tukin` → 422); `MasterGenericTcTest` `OK (31 tests, 1155 assertions)` — simulasi MariaDB 167 mencakup kelima master G-06 dan daftar master ber-PK TINYINT (dicocokkan dengan DDL) = 11 termasuk `diklat`. Uji mutasi (dipulihkan setelahnya): `autoIncrementExhausted()` dimatikan → `testDiklatFullTinyintKeyGives422` gagal `Duplicate entry '127' for key 't_diklat.PRIMARY'` (500); penolakan array di `BaseMasterController` dimatikan → `testJenisHukdisFollowsTingkatChainAndMasaSanksi` gagal `Check constraint 'chk_jenis_hukdis_masa_sanksi_bulan' is violated` (500). Dengan engine utuh keduanya 422 |
| Quality gate penuh setelah merge `main` `acd8693` — langkah sama dengan `./check.sh`, PHPUnit dipecah 4 shard paralel dengan DB test & cache per shard | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 232 files that can be fixed`, PHPUnit `OK` di keempat shard (127 + 160 + 120 + 94 = 501 test, 16.468 assertion), ESLint dan vue-tsc bersih, Vitest `30 passed` file / `308 passed` test, `vite build` selesai. Percobaan pertama gagal hanya di Vitest (5 test timeout 5000 ms dan `Timeout waiting for worker to respond` saat mesin sibuk); dijalankan ulang tanpa perubahan kode dan lolos |

`SHOW CREATE TABLE` di DB scratch (MySQL 8.0.30):

```sql
CREATE TABLE `diklat` (
  `id_diklat` tinyint NOT NULL AUTO_INCREMENT,
  `jenis_diklat` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan,5:Sertifikasi',
  `nama_diklat` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_diklat`),
  UNIQUE KEY `uq_diklat_nama` (`jenis_diklat`,`nama_diklat`),
  CONSTRAINT `chk_diklat_jenis_diklat` CHECK ((`jenis_diklat` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `tingkat_hukdis` (
  `id_tingkat_hukdis` int NOT NULL AUTO_INCREMENT,
  `tingkat_hukdis` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bobot_ipasn` int DEFAULT '5' COMMENT 'bobot skor IPASN dimensi disiplin (legacy); tidak dikelola v2',
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_tingkat_hukdis`),
  UNIQUE KEY `uq_tingkat_hukdis_nama` (`tingkat_hukdis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `jenis_hukdis` (
  `id_jenis_hukdis` int NOT NULL AUTO_INCREMENT,
  `id_tingkat_hukdis` int NOT NULL,
  `jenis_hukdis` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `masa_sanksi_bulan` tinyint unsigned DEFAULT NULL COMMENT 'masa sanksi bawaan dalam bulan untuk hitung akhir_hukdis; NULL = tanpa masa',
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_jenis_hukdis`),
  UNIQUE KEY `uq_jenis_hukdis_nama` (`id_tingkat_hukdis`,`jenis_hukdis`),
  KEY `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` (`id_tingkat_hukdis`),
  CONSTRAINT `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` FOREIGN KEY (`id_tingkat_hukdis`) REFERENCES `tingkat_hukdis` (`id_tingkat_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_jenis_hukdis_masa_sanksi_bulan` CHECK (((`masa_sanksi_bulan` is null) or (`masa_sanksi_bulan` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `jenis_konket` (
  `id_jenis_konket` int NOT NULL AUTO_INCREMENT,
  `old_id` int NOT NULL COMMENT 'kode kategori konket = absen_ijin.kategori',
  `jenis_konket` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `affect_tukin` tinyint NOT NULL DEFAULT '1' COMMENT '1: Ya, 2: Tidak (nilai awal affect_tukin pengajuan konket)',
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_jenis_konket`),
  UNIQUE KEY `uq_jenis_konket_nama` (`jenis_konket`),
  UNIQUE KEY `uq_jenis_konket_old_id` (`old_id`),
  CONSTRAINT `chk_jenis_konket_affect_tukin` CHECK ((`affect_tukin` in (1,2)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `tanda_jasa` (
  `id_tanda_jasa` int NOT NULL AUTO_INCREMENT,
  `tanda_jasa` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_tanda_jasa`),
  UNIQUE KEY `uq_tanda_jasa_nama` (`tanda_jasa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 6.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator) — WAJIB sebelum approval

DBV-001 dan DBV-002 disetujui tanpa laporan verifikasi MariaDB terpisah. Untuk DBV-005 mohon hasil berikut dilaporkan di PR:

**Hasil (DB Validator jjoseph48, komentar PR #14, 29-09-2026, MariaDB 10.4)** dicatat per butir di bawah.

1. `php spark migrate` → `php spark migrate:rollback` → `php spark migrate` (migration ini masuk batch tersendiri; rollback hanya membatalkan batch ini).
    - ✅ **Hasil DBV:** G-06 masuk batch tersendiri; rollback hanya menghapus kelima tabel G-06 (daftar tabel setelah rollback identik dengan sebelum G-06); `migrate` ulang bersih.
2. `vendor/bin/phpunit --no-coverage tests/MasterData/DiklatHukdisKonketSchemaTest.php` di database test MariaDB (helper test sudah menormalkan lebar tampilan `tinyint(4)`/`tinyint(3) unsigned`/`int(11)` dan default `'NULL'`/`current_timestamp()` MariaDB).
    - ✅ **Hasil DBV:** `OK (6 tests, 184 assertions)`.
3. CHECK ditegakkan (MariaDB error 4025, MySQL 3819) dan terlihat di `information_schema.TABLE_CONSTRAINTS` dengan `CONSTRAINT_TYPE = 'CHECK'`.
    - ✅ **Hasil DBV:** 3 `chk_…` terdaftar di `TABLE_CONSTRAINTS`; ditegakkan dengan 4025 untuk `jenis_diklat` = 6, `affect_tukin` = 3, `masa_sanksi_bulan` = 0.
4. FK RESTRICT dicek lewat `information_schema.REFERENTIAL_CONSTRAINTS` (`SHOW CREATE TABLE` MariaDB bisa menyembunyikan klausa RESTRICT).
    - ✅ **Hasil DBV:** `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` UPDATE/DELETE = RESTRICT; hapus induk yang punya anak ditolak 1451.
5. `COLUMN_TYPE` `tinyint(1)` pada `jenis_diklat`, `tinyint(3) unsigned` pada `masa_sanksi_bulan`, dan `updated_at` `DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`.
    - ✅ **Hasil DBV:** sesuai, plus `int(11)` `updated_by` dan PK/`order`/`status` TINYINT signed; beda tampilan lebar (4)/(11) hanya gaya MariaDB, setara dengan 6.2.
6. UNIQUE 1.024 byte diterima (row format DYNAMIC, `innodb_large_prefix`/default MariaDB 10.4).
    - ✅ **Hasil DBV:** terbesar `uq_diklat_nama` dan `uq_jenis_hukdis_nama` = 1.024 byte, diterima (batas InnoDB 3.072).
7. Opsional (perilaku aplikasi di atas MariaDB): `vendor/bin/phpunit --no-coverage tests/MasterData/DiklatHukdisKonketTest.php` — memastikan setiap nilai yang ditolak NOT NULL/CHECK sudah dijawab 422 oleh validasi, juga di MariaDB.
    - ✅ **Hasil DBV:** `OK (10 tests, 415 assertions)` pada head PR sebelum merge `main` `acd8693`. Test ke-11 (`testDiklatFullTinyintKeyGives422`, jalur 1062/167 PK `diklat` habis, 2.7 batas #1) ditambahkan setelahnya; menjalankannya di MariaDB bersifat opsional (belum dilaporkan). Tambahan DBV: `MasterGenericTcTest` + `RbacMasterEndpointsTest` `OK (33 tests, 2.662 assertions)`; constraint kelima tabel 5 PK, 6 UNIQUE, 1 FK, 3 CHECK, semua InnoDB + `utf8mb4_unicode_ci` — cocok dengan 6.2.
8. Laporkan hasilnya di PR (poin mana yang lolos/gagal).
    - ✅ **Hasil DBV:** dilaporkan di PR #14; semua butir lolos, dengan dua catatan tidak memblokir (zona waktu → Trello ISSUE-022, dan lingkungan database test) di bagian Status.

### 6.4 Nilai [I] yang menunggu dump struktur produksi (`mysqldump --no-data` penuh simpeg01)

1. `SHOW CREATE TABLE` `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket`, `tanda_jasa`: tipe PK (INT/TINYINT, signed), panjang & NULL kolom nama, `bobot_ipasn`, tipe `old_id` dan `status` konket, keberadaan `order`/`created_at`/`updated_at`/`updated_by`, bentuk `updated_at`, collation, AUTO_INCREMENT (nilai counter dipakai 6.5 #10).
2. Aksi ON DELETE/UPDATE `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` dan nullability `jenis_hukdis.id_tingkat_hukdis`.
3. Tipe kolom anak: `riwayat_diklat.id_diklat`, `pegawai_diklat.id_diklat`, `riwayat_hukdis`/`pegawai_hukdis` `.id_tingkat_hukdis`/`.id_jenis_hukdis`, `riwayat_tanda_jasa`/`pegawai_tanda_jasa` `.id_tanda_jasa` (beserta aksi FK-nya).
4. Tabel pemetaan `siasn_simpeg` (`LATIHAN_STRUKTURAL_ID`, `JENIS_HUKUMAN_ID`, `HARGA_ID`): tipe kolom ID master.
5. Data: lihat 6.5 #1–#8 (disatukan dengan permintaan data gabungan grup DBV-003..005).

Bila dump berbeda: koreksi lewat migration ALTER baru selagi tabel masih kosong (migration ini tidak diedit setelah disetujui).

### 6.5 Catatan migrasi data untuk Mapping (audit sebelum impor)

Salinan lokal kelima tabel berisi 0 baris (atau tidak ada), jadi semua butir di bawah hanya bisa diperiksa di data produksi.

**Syarat approval DBV-005 (29-09-2026):** audit data sebelum impor (butir di bawah) dan penyalinan counter AUTO_INCREMENT legacy (#10) **wajib menjadi langkah runbook impor**.

1. **Audit duplikat** dengan perbandingan `utf8mb4_unicode_ci` (tidak peka huruf besar/kecil & aksen), setelah `stripslashes` (#2) dan trim, per lingkup UNIQUE: `diklat (jenis_diklat, nama_diklat)`, `tingkat_hukdis`, `jenis_hukdis (id_tingkat_hukdis, jenis_hukdis)`, `jenis_konket`, `jenis_konket.old_id`, `tanda_jasa` — **termasuk baris status 2/10** (`jenis_hukdis` dan `diklat` bisa punya status 10). Duplikat harus dirapikan dulu, karena impor akan gagal.
2. **`stripslashes`** hanya untuk nama `diklat`, `tingkat_hukdis`, `jenis_konket`, `tanda_jasa` (di-`addslashes` legacy); **jangan** untuk `jenis_hukdis` (disimpan mentah). Nama `diklat` dari jalur `dm_diklat` juga mentah → `stripslashes` kondisional: audit pola `\'`, `\"`, `\\` per baris sebelum memutuskan.
3. **`jenis_konket.old_id`**: baris dengan `old_id` NULL/kosong (dibuat lewat UI legacy), ≤ 0 (aplikasi mewajibkan ≥ 1, C4), ganda, atau bukan angka → beri kode baru > MAX yang tidak dipakai `absen_ijin.kategori`/`d_konket.kategori`; cek setiap `absen_ijin.kategori` punya pasangan `old_id`.
4. **`jenis_hukdis.id_tingkat_hukdis`** NULL atau yatim (tingkat dihapus keras) → rapikan sebelum impor (FK + NOT NULL akan menolak).
5. **Normalisasi nilai**: `jenis_diklat` di luar 1–5, `status` di luar 1/2/10, `order` bukan angka/0/> 127/ganda → normalkan (`order` 1..n per lingkup: diklat per jenis, jenis hukdis per tingkat, lainnya global).
6. **Baris hard-coded (2.6)**: pastikan ada dengan ID tersebut dan namanya sesuai, khususnya tanda jasa 44 = `LAIN-LAIN`; `MAX(id_diklat)` ≤ 127.
7. **Kolom baru**: `affect_tukin` diisi 1 (default legacy per pengajuan) lalu ditinjau admin; opsional usulkan nilai dari modus `absen_ijin.affect_tukin` per kategori. `masa_sanksi_bulan` NULL, diisi admin.
8. **Audit & status**: `updated_by` legacy = `user.id` akun legacy → petakan ke `id_pengguna`; `updated_at` jam server → konversi mengikuti keputusan zona waktu global (A-01). Catatan DBV-005: default `CURRENT_TIMESTAMP` di server memakai WIB sedangkan aplikasi menulis UTC, sehingga penulisan di luar aplikasi (impor, perbaikan manual, `ON UPDATE` dari SQL manual) bercampur zona; set `time_zone = '+00:00'` di server DB atau catat eksplisit di runbook impor (Trello ISSUE-022). Baris status 2 legacy tidak bisa diaktifkan lagi di UI lama (Bagian 1) → kemungkinan baris "macet", tinjau bersama admin.
9. **Salinan nama di riwayat** (`riwayat_diklat.nama_diklat`, `riwayat_hukdis.tingkat_hukdis/jenis_hukdis`, `riwayat_tanda_jasa.tanda_jasa`, `absen_ijin.jenis_konket`) disalin apa adanya, jangan di-join ulang ke master.
10. **Counter AUTO_INCREMENT.** Impor dengan ID eksplisit hanya menaikkan counter ke `MAX(id)+1`. Legacy menghapus keras baris `diklat` (`Lm_diklat.php:134`), `tingkat_hukdis` (`Lm_hukdis.php:134`), `jenis_konket` (`Lm_konket.php:134`), dan `tanda_jasa` (`Lm_tj.php:134`), sehingga ID tertinggi yang pernah dihapus bisa dipakai ulang entri baru, sementara `siasn_simpeg.LATIHAN_STRUKTURAL_ID.id_diklat` (`Siasn.php:1677-1678`), `JENIS_HUKUMAN_ID.id_jenis_hukdis` (:2895-2897), dan `HARGA_ID.id_tanda_jasa` (:5958-5959) masih menyimpan ID lama tanpa FK. Setelah impor tiap tabel G-06 (kelimanya, termasuk `jenis_hukdis`), bila counter legacy (dump 6.4 #1 atau `SHOW TABLE STATUS`) lebih besar dari `MAX(id)+1`, jalankan `ALTER TABLE <tabel> AUTO_INCREMENT = <counter legacy>`. Migration tetap tidak menulis AUTO_INCREMENT (Bagian 3 #12). Untuk `diklat`, counter legacy juga menentukan sisa ID (3 #8).

### 6.6 Pemulihan bila `up()` gagal di tengah

DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`. `up()` otomatis men-drop tabel G-06 yang sempat dibuat **pada run itu** (urutan terbalik, masih kosong) lalu melempar ulang error, sehingga setelah penyebabnya diperbaiki cukup jalankan ulang `php spark migrate` (diuji `DiklatHukdisKonketSchemaTest::testFailedUpDropsOnlyTablesCreatedInThatRun`). Tabel yang sudah ada sebelum run tidak disentuh. Bila pembersihan otomatis itu ikut gagal (mis. koneksi putus), pulihkan manual — **jangan `migrate:rollback` batch**: migration ini tidak tercatat, sehingga rollback justru membatalkan batch terakhir yang tercatat. Drop tabel G-06 kosong yang tersisa dengan urutan `tanda_jasa`, `jenis_konket`, `jenis_hukdis`, `tingkat_hukdis`, `diklat`, lalu `php spark migrate`. Catat kejadian di kartu DBV-005.
