# DB Validator Review — G-01 Migration Tabel Master (Modul G)

**Status:** ✅ **BATCH 1 DISETUJUI DB VALIDATOR** (23-09-2026, jjoseph48) — boleh dijalankan di Dev. G-01 secara keseluruhan **masih SEBAGIAN**: sisa ~38 tabel Tier 0/1 belum ditulis (menunggu DDL legacy, Trello **ISSUE-003**) dan perlu review batch berikutnya sebelum sign-off Fase 2.

**Revisi Batch 1 — DBV-001 (✅ DISETUJUI 24-09-2026, di main lewat PR #4 / `633dbb0`):** skema Batch 1 diubah ke skema SIMPEG legacy lewat migration ALTER baru (bukan mengedit migration yang sudah disetujui), sekaligus menyelesaikan ISSUE-008/009/010 — lihat **Bagian 8**. Riwayat approval Batch 1 di bawah ini tetap berlaku sebagai catatan.

**Syarat persetujuan:** temuan #1–#3 di Bagian 6 (panjang kode wilayah, UNIQUE index nama, collation) **ditunda perbaikannya sampai seluruh DEV-003 Fase 2 selesai** — keputusan user, 23-09-2026. Aman selama tabel master masih kosong; **ketiganya wajib selesai sebelum impor data master legacy** dan sebelum sign-off Fase 2, karena perbaikan setelah tabel terisi butuh `ALTER TABLE` + dedupe data.

**Rujukan:** `02-MasterData.md` G-01, Tech Spec §2.3 G-01, `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 0 & Tier 1, `simpeg_v2_local_seed.sql`, ERD legacy `simpeg01.erd` (relasi FK), `Legacy_System_Audit_SIMPEG.pdf` (Master Data & Pengaturan).

## 1. Checklist DoD G-01

| # | Kriteria DoD | Hasil | Bukti |
|---|---|---|---|
| 1 | Skema direview & di-approve DB Validator sebelum dijalankan | ✅ DISETUJUI untuk Batch 1 (23-09-2026); revisi skema legacy DBV-001 ✅ (24-09-2026, Bagian 8); batch berikutnya menyusul | dokumen ini, Bagian 4 & 6 |
| 2 | Urutan migration mengikuti dependency (Tier 0 dulu, lalu Tier 1 sesuai hierarki) | Batch 1 OK (provinsi → kabupaten_kota → kecamatan → kelurahan dalam satu migration, berurutan). Tier 1 belum | `2026-09-22-000001_CreateWilayah.php` |
| 3 | FK aktif di seluruh relasi Tier 1 | Batch 1: FK wilayah aktif (`ON DELETE RESTRICT`). Tier 1 belum | test `MasterGenericTcTest::testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` |

## 2. Batch 1 — sudah ditulis (7 tabel, Tier 0)

Sumber kolom: `simpeg_v2_local_seed.sql`. Struktur & relasi konsisten dengan ERD legacy (wilayah: rantai FK 4 level; agama/jenis_*: tabel independen).

| File migration | Tabel |
|---|---|
| `app/Database/Migrations/2026-09-22-000001_CreateWilayah.php` | `provinsi`, `kabupaten_kota`, `kecamatan`, `kelurahan` |
| `app/Database/Migrations/2026-09-22-000002_CreateReferensiUmum.php` | `agama`, `jenis_pegawai`, `jenis_status` |

### Wilayah

| Tabel | Kolom | Index / FK |
|---|---|---|
| provinsi | `id_provinsi` VARCHAR(10) PK, `nama_provinsi` VARCHAR(100) NOT NULL, `order` INT UNSIGNED DEFAULT 0, `status` ENUM('0','1') DEFAULT '1' | KEY(order), KEY(status) |
| kabupaten_kota | `id_kabupaten_kota` VARCHAR(10) PK, `id_provinsi` VARCHAR(10) NOT NULL, `nama_kabupaten_kota` VARCHAR(100), `order`, `status` | KEY(id_provinsi, order), KEY(status), FK → provinsi RESTRICT/RESTRICT |
| kecamatan | `id_kecamatan` VARCHAR(10) PK, `id_kabupaten_kota` VARCHAR(10) NOT NULL, `nama_kecamatan` VARCHAR(100), `order`, `status` | KEY(id_kabupaten_kota, order), KEY(status), FK → kabupaten_kota |
| kelurahan | `id_kelurahan` VARCHAR(10) PK, `id_kecamatan` VARCHAR(10) NOT NULL, `nama_kelurahan` VARCHAR(100), `order`, `status` | KEY(id_kecamatan, order), KEY(status), FK → kecamatan |

### Referensi umum

| Tabel | Kolom | Index |
|---|---|---|
| agama | `id_agama` VARCHAR(5) PK, `nama_agama` VARCHAR(50) NOT NULL, `order`, `status` | KEY(order), KEY(status) |
| jenis_pegawai | `id_jenis_pegawai` VARCHAR(5) PK, `nama_jenis_pegawai` VARCHAR(50) NOT NULL, `order`, `status` | idem |
| jenis_status | `id_jenis_status` VARCHAR(5) PK, `nama_jenis_status` VARCHAR(50) NOT NULL, `order`, `status` | idem |

### Pola & keputusan desain (berlaku ke semua master)

- **`order` + `status`** di setiap master (02-MasterData.md "Pola berulang"). `status` '1' aktif, '0' non-aktif/soft delete. Tidak ada `deleted_at` — status '0' *adalah* soft delete (sama dengan legacy).
- **Tidak ada hard delete dari aplikasi.** `DELETE` = `status='0'` (audit event `delete`). FK `ON DELETE RESTRICT` = lapis kedua di DB. `ON UPDATE RESTRICT` karena kode (PK) immutable di aplikasi.
- **Keunikan nama** ditegakkan di aplikasi (per induk, case-insensitive via collation `*_ci`, termasuk entri non-aktif), **belum** sebagai UNIQUE index — lihat Keputusan #2.
- **Tanpa `created_at`/`updated_at`**: mengikuti kolom legacy (Mapping Prinsip #1); jejak perubahan ada di `audit_logs`. Lihat Keputusan #3.
- `order` diberi `UNSIGNED` (legacy `INT` biasa) — nilai negatif tidak bermakna.

## 3. Belum ditulis — menunggu DDL legacy (Trello ISSUE-003)

**Tanpa definisi kolom sama sekali** (tidak ada di seed maupun Legacy Audit):
`kelas_jabatan`, `peta_jabatan`, `struktur_jabatan`, `periode_struktur_jabatan`, `jabatan_koordinasi`, `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `dm_ak_jf`, `faq_rate`, `bidang_kursem`, `instansi_kursem`, `jenis_layanan`.

**Ada di seed, tapi berbeda dari ERD legacy** (perlu cross-check DDL sebelum ditulis):

| Tabel | Seed lokal | ERD legacy `simpeg01` |
|---|---|---|
| jabatan | `id_sub_group_jabatan`, `eselon`, `kelas_jabatan INT` | FK ke `group_jabatan`, `sub_group_jabatan`, **`kelas_jabatan` (tabel)**, **`satker`**, **`jenjang_jf`** |
| kantor | `nama_kantor`, `alamat` | FK ke `provinsi`, `kabupaten_kota`, `kecamatan`, `kelurahan` |
| user_lokasi_presensi | `id` AI, `nip`, `id_lokasi_presensi` (FK pegawai, lokasi_presensi) | FK ke `lokasi_presensi`, `pegawai`, **`dm_user_lokasi_presensi`** |
| gol_pppk | `nama_gol_pppk` | Legacy Audit: "master nominal uang makan per golongan PPPK" → kolom nominal tidak ada di seed |
| web_config | `config_key`, `config_value`, `tipe_data`, `keterangan` | Legacy Audit: `config_name`, `config_value` |
| beberapa master (jenis_libur, jenis_kp, gol_pppk, bidang/jurusan_pendidikan, diklat, hukdis, konket, tanda_jasa, lokasi_presensi, faq_sub_topic, faq_article) | tanpa kolom `order` | 02-MasterData.md: semua master punya `order` + `status` |
| hari_libur | tanpa `status` | idem |

**Ada di legacy, tidak ada di Mapping Tier 0/1:** `jenjang_jf` (direferensikan `jabatan`), `faq_related_article`, `dm_user_lokasi_presensi` (disebut Mapping di Tier 3).

## 4. Keputusan yang diminta dari DB Validator / Tech Lead

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Batch 1 (Bagian 2) untuk dijalankan di Dev | Setujui | ✅ **DISETUJUI** (23-09-2026, jjoseph48) |
| 2 | Keunikan nama master: cukup di aplikasi, atau tambah UNIQUE index (`nama` / `induk+nama`)? | Aplikasi dulu; UNIQUE index setelah data quality audit legacy (duplikat legacy akan menggagalkan migrasi data) | ⏳ **DITUNDA** sampai seluruh DEV-003 Fase 2 selesai (lihat Bagian 6 #2) → **dikerjakan di DBV-001 ✅** (UNIQUE index, Bagian 8.5) |
| 3 | Kolom `created_at`/`updated_at` di tabel master? | Tidak (ikut legacy; histori di `audit_logs`) | ✅ **TIDAK** — ikut usulan (23-09-2026). Jejak perubahan mengandalkan `audit_logs`; catat bahwa audit bersifat fail-open (A-01) |
| 4 | Panjang kode wilayah VARCHAR(10) cukup? (kode Kemendagri kelurahan tanpa titik = 10 digit; dengan titik = 13) | Konfirmasi format kode legacy dari DDL/data `simpeg01` | ⏳ **DITUNDA** sampai seluruh DEV-003 Fase 2 selesai (lihat Bagian 6 #1) → **dikerjakan di DBV-001 ✅** (CHAR(2/4/7/10), Bagian 8.5) |
| 5 | Master tanpa `order` di seed: tambahkan `order` sesuai pola 02-MasterData.md? | Ya, semua master `order` + `status` | ✅ **YA** — ikut usulan (23-09-2026). Semua master Tier 0/1 wajib `order` + `status`, termasuk yang di seed belum punya (`jenis_libur`, `jenis_kp`, `gol_pppk`, `bidang`/`jurusan_pendidikan`, `diklat`, `hukdis`, `konket`, `tanda_jasa`, `lokasi_presensi`, `faq_sub_topic`, `faq_article`) dan `status` untuk `hari_libur` |
| 6 | `jenjang_jf`, `faq_related_article`, `dm_user_lokasi_presensi`: ikut dimigrasi di G-01? | Tunggu DDL, lalu putuskan | **BELUM DIPUTUSKAN** (blocked ISSUE-003) |
| 7 | FK `user_lokasi_presensi.nip` dan `faq_rate.nip` → `pegawai.nip`: tabel `pegawai` baru ada di Fase 3 | Defer FK ke B-01 (pola sama dengan A-01 `pengguna.nip`) | **BELUM DIPUTUSKAN** |
| 8 | FK `pengguna.id_unit`/`id_satker` → `unit`/`satker` (ditunda dari A-01 ke Fase 2) | Ditambahkan saat migration unit/satker (G-02) setelah DDL legacy ada | **BELUM DIPUTUSKAN** |
| 9 | Collation (A-01 Bagian 8 #3 — belum diputuskan): tabel master batch 1 mewarisi default koneksi (`utf8mb4_general_ci`). Keunikan nama master bergantung pada collation `*_ci` | Ikuti keputusan #3 A-01; kalau ditetapkan `utf8mb4_unicode_ci`, batch 1 ditambah `ALTER TABLE … CONVERT TO` sebelum dijalankan di Dev | ⏳ **DITUNDA** sampai seluruh DEV-003 Fase 2 selesai (lihat Bagian 6 #3) → **sebagian di DBV-001 ✅** (7 tabel `utf8mb4_unicode_ci`, Bagian 8.5) |

## 5. Hasil verifikasi DB Validator (23-09-2026)

Dijalankan di lokal (MariaDB 10.4, `simpeg_v2` + `simpeg_v2_testing`): `migrate --all` (DDL ter-apply sesuai file migration), PHPUnit **176/177 lolos** (1 gagal = test queue Fase 0, `SKIP LOCKED` tidak didukung MariaDB 10.4 — bukan Modul G), plus uji langsung di level DB.

| G-TC | Hasil |
|---|---|
| #1 Keunikan nama/kode | ⚠️ Ditegakkan di aplikasi saja — **terbukti**: lewat SQL langsung, `agama` menerima kode `'1'` dan `'01'` dengan nama `Islam` sama, plus `islam` beda kapitalisasi. Tidak ada UNIQUE index yang menahan (Bagian 6 #2) |
| #2 Soft-delete only | ✅ `DELETE` = `status='0'`; FK RESTRICT sebagai lapis kedua |
| #3 Toggle status ter-reflect di dropdown | ⚠️ Untuk master sendiri ✅. Induk non-aktif **tidak** menurunkan status ke anak — terbukti: provinsi `status='0'`, kabupaten di bawahnya tetap `'1'` dan tetap muncul di dropdown. Cache anak juga tidak di-invalidate (TTL 1 jam) |
| #4 Re-ordering | ✅ Bergeser & dinomori ulang 1..n per induk, dalam transaksi |
| #5 Role selain 1 → 403 | ✅ `RbacMasterEndpointsTest` |
| #6 Audit tiap tambah/ubah/hapus | ✅ Termasuk kode berawalan nol (`'01'` tidak lagi tercatat `1`) |
| #7 QA Lapis 1 vs Figma | ⏸ Desain belum ada |

DoD G-01 #2 (urutan dependency) dan #3 (FK aktif Tier 1) **terpenuhi untuk Batch 1**. Catatan: `ON DELETE/UPDATE RESTRICT` tidak muncul di `SHOW CREATE TABLE` karena RESTRICT adalah default MySQL — perilakunya tetap sesuai.

## 6. Temuan — perbaikan ditunda sampai seluruh DEV-003 Fase 2 selesai

Keputusan user 23-09-2026. Ketiganya **wajib selesai sebelum impor data master legacy** dan sebelum sign-off Fase 2.

| # | Temuan | Bukti | Tindakan |
|---|---|---|---|
| 1 | Kode wilayah `VARCHAR(10)` memotong kode berformat titik tanpa error (koneksi app `strictOn=false`) | `'11.01.01.2001'` (13 karakter) tersimpan `'11.01.01.2'` | Konfirmasi format & panjang kode di DDL/data legacy; aktifkan strict mode (A-01 Bagian 8 #8) |
| 2 | Keunikan nama hanya di aplikasi; impor data legacy tidak lewat aplikasi | 3 baris duplikat masuk lewat SQL langsung | Audit duplikat legacy → pasang UNIQUE (`nama` / `induk+nama`) **sebelum** data masuk; sesudahnya butuh dedupe |
| 3 | Collation batch 1 = `utf8mb4_general_ci`, keputusan collation A-01 belum final; keunikan nama bergantung collation `*_ci` | DDL `SHOW CREATE TABLE` | Tetapkan collation; konversi paling murah selagi tabel masih kosong |

## 7. Temuan tingkat menengah (belum masuk keputusan)

| # | Temuan | Lokasi | Usulan |
|---|---|---|---|
| 1 | Induk non-aktif tidak menurunkan status ke anak, padahal membuat anak baru di bawah induk non-aktif ditolak — perilaku tidak konsisten | `MasterService::assertParentUsable()`, `options()` | Tetapkan aturan: cascade non-aktif, atau `options()` menyaring sampai rantai induk |
| 2 | Cache dropdown hanya di-invalidate untuk master yang ditulis; master anak bisa basi s.d. 1 jam | `MasterService::invalidate()` | Invalidate turunan saat induk berubah status |
| 3 | `transactional()` tidak memeriksa `transStatus()` sebelum commit; digabung `strictOn=false`, penulisan gagal bisa ikut ter-commit | `MasterService::transactional()` | Cek `transStatus()` / pakai `transException(true)` |
| 4 | Reorder menulis 1 baris audit + 1 UPDATE per entri yang bergeser | `MasterService::applyOrder()` | Batasi untuk master besar, atau catat 1 audit per operasi reorder |
| 5 | Kode (PK) immutable + FK `ON UPDATE RESTRICT`; perubahan kode wilayah dari Kemendagri hanya bisa lewat SQL manual | migration wilayah | Siapkan prosedur perawatan kode master |
| 6 | `order` adalah kata kunci SQL | seluruh tabel master | Wajib backtick di script migrasi/report manual |

## 8. DBV-001 — Revisi skema Batch 1 ke skema SIMPEG legacy (✅ DISETUJUI DB Validator 24-09-2026)

**Key review:** `DBV-001` (review DB Validator) + `CR-001` (review kode) — satu pull request, judul `[DBV-001][CR-001] …`, branch `dbv-001/g01-batch1-skema-legacy`. Merge hanya setelah **kedua** review menyatakan setuju. Aturan 24-09-2026: untuk PR dengan dua key [CR] & [DBV], DB Validator **hanya me-review/approve** (tidak merge); **merge dilakukan oleh reviewer CR**. Status: CR-001 ✅ (24-09-2026), DBV-001 ✅ (24-09-2026, jjoseph48 — komentar "DBV-001 ✅" di PR #4); di-merge ke `main` 24-09-2026 oleh reviewer CR (merge commit `633dbb0`).

**Status:** ✅ DISETUJUI (24-09-2026). Migration `2026-09-23-000000_AlterBatch1KeSkemaLegacy.php` boleh dijalankan di Dev, dengan syarat ketujuh tabel Batch 1 **kosong** (`up()` menolak jalan bila berisi data). Deploy otomatis ke server Dev tetap mengikuti Trello ISSUE-014 (HOLD).

### 8.1 Latar belakang

Skema Batch 1 yang disetujui 23-09-2026 (Bagian 2) mengambil nama & tipe kolom dari `simpeg_v2_local_seed.sql`. File itu **seed buatan untuk uji lokal**, bukan skema legacy (README seed: *"BUKAN hasil migrasi dari legacy"*). Mapping Migrasi Prinsip #1 menetapkan nama tabel & kolom v2 **tetap sama dengan legacy** (ADR-023), dan Tier 0 ketujuh tabel ini "Copy langsung".

Keputusan user 23-09-2026: **skema mengikuti code legacy** (mis. kolom `provinsi`, `agama`, bukan `nama_provinsi`, `nama_agama`). Karena migration Batch 1 sudah disetujui dan sudah dijalankan (mis. `simpeg_v2` lokal: `migrations` batch 3), file 2026-09-22-000001/000002 **tidak diedit**; perubahan dilakukan lewat migration ALTER baru ini. (PR #3 mengedit `CreateWilayah` di tempat — itu harus dikembalikan ke versi `main`.)

Keputusan user yang ikut dieksekusi di DBV-001 (23-09-2026):

| # | Keputusan | Diterapkan |
|---|---|---|
| a | Skema ikut code legacy: nama kolom, tipe, PK, kolom tambahan | Bagian 8.2 |
| b | Status ikut legacy: `1` Aktif, `2` Tidak Aktif, `10` Dihapus. "Hapus" di v2 tetap **soft delete** = status `10` (legacy hard delete). Fitur spec v2 tetap: `order`, keunikan nama termasuk entri tidak aktif | engine master + test |
| c | Kolom audit ikut legacy (`created_at`, `updated_at`, `updated_by`; `agama` + `deleted_at`). **Membalik Keputusan #3** (premis "ikut legacy" keliru — DDL produksi `agama` punya kolom audit). `audit_logs` tetap berjalan | Bagian 8.2 |
| d | Nilai yang tidak ada di sumber lokal diisi dugaan terlabel; DB Validator menyetujui setelah dicocokkan dengan dump struktur penuh produksi | Bagian 8.3 |
| e | Collation dikonversi sekarang selagi tabel kosong → `utf8mb4_unicode_ci` (**ISSUE-010**) | semua 7 tabel |
| f | **ISSUE-009** diselesaikan: UNIQUE index nama | Bagian 8.2 |
| g | **ISSUE-008**: kode wilayah `CHAR(2/4/7/10)` sesuai legacy + validasi tepat N digit angka di aplikasi (kode bertitik ditolak 422, tidak lagi terpotong diam-diam) | migration + `BaseMasterController` |

### 8.2 Skema hasil DBV-001

Sumber: **[K]** terkonfirmasi — DDL produksi `simpeg_prod.sql` (HeidiSQL, host 172.17.100.83, MySQL 8.0.21) atau code legacy (`application/libraries/hr/master/Lm_umum.php`, `views/hr/master/umum/*`); **[I]** dugaan (DDL tabel tersebut tidak ada di sumber lokal).

Semua tabel: `ENGINE=InnoDB`, `utf8mb4` / `utf8mb4_unicode_ci`; `status TINYINT NOT NULL DEFAULT 1` (1/2/10) [K agama; wilayah & jenis_* nilai 1/2/10 K dari code]; `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL` [K agama DDL; tabel lain K `updated_by` ditulis code, tipe I].

| Tabel | Kolom (urut) | Kunci | Sumber |
|---|---|---|---|
| provinsi | `id_provinsi` CHAR(2), `provinsi` VARCHAR(255), `order` INT UNSIGNED DEFAULT 0, `status`, audit | PK id_provinsi; **UNIQUE** `uq_provinsi_nama`(provinsi); KEY order, status | nama kolom K (`Lm_umum.php:830`); CHAR(2) I kuat (form hint 11–99, `form_kesehatan.id_provinsi char(2)` di DDL produksi); VARCHAR(255) I |
| kabupaten_kota | `id_kabupaten_kota` CHAR(4), `id_provinsi` CHAR(2), `kabupaten_kota` VARCHAR(255), `kd_area` VARCHAR(4) NULL, `order`, `status`, audit | FK `fk_id_provinsi_kabupaten_kota_to_provinsi` (nama dari ERD legacy) RESTRICT; **UNIQUE** (id_provinsi, kabupaten_kota) | `kd_area` K (`Lm_umum.php:1079`, form `maxlength=4`); CHAR(4) I kuat |
| kecamatan | `id_kecamatan` CHAR(7), `id_kabupaten_kota` CHAR(4), `kecamatan` VARCHAR(255), `order`, `status`, audit | FK `fk_id_kabupaten_kota_kecamatan_to_kabupaten_kota`; **UNIQUE** (id_kabupaten_kota, kecamatan) | CHAR(7) I kuat — kode BPS 7 digit (bukan Kemendagri 6 digit/bertitik) |
| kelurahan | `id_kelurahan` CHAR(10), `id_kecamatan` CHAR(7), `kelurahan` VARCHAR(255), `kd_pos` VARCHAR(50) NULL, `order`, `status`, audit | FK `fk_id_kecamatan_kelurahan_to_kecamatan`; **UNIQUE** (id_kecamatan, kelurahan) | `kd_pos` K (`Lm_umum.php:1568`, beberapa kode pos dipisah koma); VARCHAR(50) I |
| agama | `id_agama` TINYINT AUTO_INCREMENT, `agama` VARCHAR(30), `order` TINYINT DEFAULT 1, `status`, audit, `deleted_at` DATETIME NULL | PK; **UNIQUE** `uq_agama_nama`(agama) | **K seluruhnya** (DDL produksi `simpeg_prod.sql`, tabel `agama`) |
| jenis_pegawai | `id_jenis_pegawai` TINYINT AUTO_INCREMENT, `jenis_pegawai` VARCHAR(50), `order` TINYINT DEFAULT 1, `status`, audit | PK; **UNIQUE** (jenis_pegawai) | nama kolom K (code); TINYINT I kuat (`pegawai.id_jenis_pegawai tinyint` di DB duplikat); VARCHAR(50) I |
| jenis_status | `id_jenis_status` TINYINT AUTO_INCREMENT, `jenis_status` VARCHAR(50), `status_pegawai` TINYINT DEFAULT 1 (1 Aktif / 2 Tidak Aktif), `order` TINYINT DEFAULT 1, `status`, audit | PK; **UNIQUE** (status_pegawai, jenis_status) — keunikan legacy (`Lm_umum.php:578-595`) | `status_pegawai` K (code, form wajib); tipe I |

Perilaku aplikasi (engine master): kode wilayah wajib tepat 2/4/7/10 digit; PK agama/jenis_* diberikan DB (input kode diabaikan); daftar default menyembunyikan status 10 (seperti legacy `status!='10'`), filter `?status=1|2|10`; entri terhapus dipulihkan lewat `PATCH …/status {1|2}`; `updated_by` = `id_pengguna` aktor; timestamp ditulis aplikasi dalam UTC.

### 8.3 Nilai dugaan [I] yang wajib dicocokkan dengan dump struktur produksi

DDL tabel master wilayah, `jenis_pegawai`, dan `jenis_status` **tidak ada** di sumber lokal (`simpeg_prod.sql` terpotong di tabel `jabatan`; DB lokal `simpeg_prod_duplikat`/`simpegdev_local` tidak memuat tabel wilayah). Yang perlu dipastikan dari `mysqldump --no-data` penuh server produksi:

1. Panjang kolom nama wilayah (VARCHAR(255)?), `jenis_pegawai`, `jenis_status` (VARCHAR(50)?).
2. Tipe PK wilayah: CHAR vs VARCHAR (CHAR(2/4/7/10) disimpulkan dari kolom kode wilayah di tabel lain).
3. Tipe & panjang `kd_area`, `kd_pos`, `status_pegawai`.
4. Keberadaan kolom audit & `deleted_at` di tabel selain `agama`; ada/tidaknya `order` di tabel wilayah (code legacy mengurutkan wilayah menurut nama).
5. Aturan FK wilayah legacy (ON DELETE/UPDATE) — di sini tetap RESTRICT (Keputusan Batch 1).

Kalau dump berbeda, koreksi lewat migration ALTER berikutnya (selagi tabel masih kosong).

### 8.4 Keputusan yang diminta dari DB Validator (DBV-001)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 8.2 + migration `2026-09-23-000000_AlterBatch1KeSkemaLegacy` untuk Dev | Setujui, dengan catatan 8.3 dicocokkan saat dump tersedia | ✅ Disetujui sesuai usulan* |
| 2 | UNIQUE index mencakup baris berstatus 10 (Dihapus): nama yang pernah dihapus tidak bisa dibuat ulang, harus dipulihkan (aplikasi memberi petunjuk) | Terima — sama dengan aturan aplikasi yang sudah disetujui (unik termasuk entri non-aktif). Konsekuensi: **audit duplikat data legacy wajib sebelum impor** | ✅ Disetujui sesuai usulan* |
| 3 | `created_at DEFAULT CURRENT_TIMESTAMP` memakai jam server DB (WIB), sedangkan aplikasi menulis UTC (catatan zona waktu A-01) | Aplikasi selalu mengisi eksplisit; default DB hanya cadangan. Keputusan zona waktu global tetap di item A-01 | ✅ Disetujui sesuai usulan* |
| 4 | `order` tetap ada di tabel wilayah walau code legacy mengurutkan menurut nama | Pertahankan (Keputusan #5 & G-TC #4); isi `order` saat impor = urutan nama | ✅ Disetujui sesuai usulan* |

\* Approval DBV-001 berupa satu komentar menyeluruh ("DBV-001 ✅", jjoseph48, PR #4, 24-09-2026) tanpa catatan per poin, sehingga keputusan no. 1–4 dicatat mengikuti kolom **Usulan**. Bila DB Validator bermaksud lain, koreksi lewat PR lanjutan. Catatan no. 1 (pencocokan nilai [I] Bagian 8.3 dengan dump struktur produksi) dilacak di Trello ISSUE-015 (checklist dump).

### 8.5 Status keputusan & temuan sebelumnya setelah DBV-001

| Item | Sebelumnya | Setelah DBV-001 |
|---|---|---|
| Keputusan #2 / Bagian 6 #2 (UNIQUE index) — ISSUE-009 | Ditunda | **Dikerjakan** (8.2). Tetap wajib: audit duplikat legacy sebelum impor |
| Keputusan #3 (tanpa created_at/updated_at) | TIDAK | **Dibalik** — kolom audit legacy ditambahkan (keputusan user c) |
| Keputusan #4 / Bagian 6 #1 (panjang kode) — ISSUE-008 | Ditunda | **Dikerjakan**: CHAR(2/4/7/10) + validasi tepat N digit. Strict mode koneksi (A-01 Bagian 8 #8) **masih terbuka** |
| Keputusan #9 / Bagian 6 #3 (collation) — ISSUE-010 | Ditunda | **Selesai sebagian**: 7 tabel ini → `utf8mb4_unicode_ci`. `DBCollat` koneksi (`Config\Database`) sengaja **belum** diubah (mengubahnya membuat tabel auth yang dibuat ulang berbeda collation antar-environment). Konsekuensi: **setiap migration baru yang mereferensikan kode wilayah/nama master ini wajib menulis `COLLATE utf8mb4_unicode_ci` eksplisit** (FK string beda collation ditolak MySQL 8, error 3780). Tabel auth (A-01) dikonversi terpisah sebelum ada FK/JOIN string lintas tabel (mis. `pengguna.nip → pegawai.nip`) |
| Bagian 7 #3 (`transactional()` tanpa cek status) | Belum diputuskan | **Dikerjakan** (review kode CR-001): tulisan bisnis master yang gagal melempar exception (`MasterModel::insert/update`) sehingga seluruh transaksi di-rollback; `transStatus` di-reset. Tulisan audit tetap fail-open (F0-04) |
| Bagian 7 #1, #2, #4–#6 (temuan menengah) | Belum diputuskan | Tidak berubah (di luar cakupan DBV-001) |

### 8.6 Verifikasi developer (sebelum review DB Validator)

- MySQL 8.0.30 lokal, DB kosong: `migrate --all` → `up()` sukses; `migrate:rollback` (hanya migration ini) → skema Batch 1 approved kembali **persis** (tipe kolom, collation `utf8mb4_general_ci`, nama FK approved); `migrate` ulang sukses.
- `up()` menolak berjalan bila salah satu dari 7 tabel berisi data (pesan menyebut tabel & jumlah baris; tidak ada ALTER yang sempat jalan) — terbukti juga di DB dev lokal `simpeg_v2` yang berisi data uji QA.
- `down()` mempertahankan data (dipakai `migrate:refresh` test): status `1` → `'1'`, `2`/`10` → `'0'`. Kolom legacy tambahan (`kd_area`, `kd_pos`, `status_pegawai`, audit, `deleted_at`) ikut terhapus; `down()` menolak jalan bila ada nama wilayah > 100 karakter (tidak muat VARCHAR(100) Batch 1).
- Keunikan: pelanggaran UNIQUE/PRIMARY (1062) akibat dua permintaan balapan membatalkan seluruh transaksi dan diterjemahkan ke 422. Catatan CR-001: di CodeIgniter 4.7 query yang gagal **di dalam transaksi** tidak melempar exception (hanya `false` + `transStatus`), sehingga sebelumnya tulisan gagal ikut "sukses" dan transaksi tetap di-commit; kini dicegah di `MasterModel` dan dibuktikan `MasterGenericTcTest::testDuplicateRaceIsRolledBackAndTranslatedTo422`.
- Test otomatis: `tests/MasterData/Batch1LegacySchemaTest.php` (kolom & tipe, collation, UNIQUE, AUTO_INCREMENT, FK legacy, rollback, penolakan saat berisi data) + `MasterGenericTcTest`/`RbacMasterEndpointsTest` diperbarui ke skema legacy.
- Verifikasi di MariaDB 10.4 (lingkungan DB Validator) **tidak dilaporkan terpisah** saat approval DBV-001 (24-09-2026). Sebelum migration ini dijalankan di server berbasis MariaDB, jalankan sekali `migrate` → `migrate:rollback` → `migrate` di sana.

**Pemulihan bila `up()` gagal di tengah** (DDL MySQL ter-commit per statement, migration tidak tercatat): karena `up()` hanya jalan saat ketujuh tabel kosong, tidak ada data yang hilang. Pulihkan manual (jangan `migrate:rollback` batch, karena di environment baru Batch 1 bisa satu batch dengan tabel auth): drop 7 tabel kosong itu (urutan kelurahan, kecamatan, kabupaten_kota, provinsi, agama, jenis_pegawai, jenis_status), hapus baris `2026-09-22-000001` & `2026-09-22-000002` di tabel `migrations`, lalu `php spark migrate`. Catat kejadian di kartu DBV-001.
