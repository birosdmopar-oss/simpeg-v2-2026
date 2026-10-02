# DB Validator Review — G-03 Master Lokasi Presensi (`lokasi_presensi`, `dm_user_lokasi_presensi`, skema legacy)

**Key review:** `DBV-007` (review DB Validator, skema) + `CR-031` (review kode) — satu pull request, judul `[DBV-007][CR-031] G-03 Master Lokasi Presensi`, branch `dbv-007/g03-lokasi-presensi` (berbasis `main` `eb86d09`, memuat CR-028 komponen form redesign). Alur CR+DBV: DB Validator me-review/approve, merge oleh reviewer CR setelah kedua review setuju (AGENTS.md §2). Antrean DBV: setelah PR #16 (DBV-011) dan PR #17 (DBV-008).

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR (DBV-007) DAN REVIEW KODE (CR-031).** Kolom **Keputusan** di Bagian 4 masih kosong (⏳); isi Bagian 7 adalah verifikasi developer sebelum review, **bukan** hasil review DB Validator. Migration jangan dijalankan di Dev/Production sebelum disetujui.

**Rujukan:** PRD G-03 (`PRD_G-03_Lokasi_Presensi.md`, di luar repo; penomoran S-/X-/L-/P-/K- di dokumen ini mengikuti PRD), `02-MasterData.md` G-03, Matriks Role x Endpoint Modul G (CRUD master = role 1), dump struktur produksi 01-10-2026 (selanjutnya **D1**, `simpeg01_struktur_lengkap_20261001.sql`, di luar repo), kode legacy baseline `9545385` (`application/libraries/hr/master/Lm_lokasi.php`, `application/controllers/hr/master/C_lokasi.php`, view `hr/master/lokasi/{presensi,pegpren}`).

**Label sumber:** **[K]** terkonfirmasi — DDL D1 (nomor baris) atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (alasan di Bagian 3); **[I]** dugaan (wajib dikonfirmasi).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-10-01-000001_CreateLokasiPresensi.php` | CREATE `lokasi_presensi` + `dm_user_lokasi_presensi` (SQL mentah sadar prefix); `up()` yang gagal di tengah men-DROP tabel yang sudah dibuat run itu; `down()` = DROP dua tabel batch ini |
| `tests/MasterData/LokasiPresensiSchemaTest.php` | skema hasil migration vs Bagian 2 (`information_schema`: kolom, urutan, tipe, nullable, default, komentar, collation, index, tanpa FK) + siklus `down()`/`up()` |

Kode aplikasi CR-031 (review kode; perilaku di Bagian 5):

| File | Isi |
|---|---|
| `app/Config/MasterData.php` (blok DBV-007) | definisi master `lokasi-presensi` dan `aturan-lokasi-presensi` (engine master generik, `UmumController`) |
| `app/Libraries/MasterData/LokasiPresensiHooks.php` | K-4 koordinat unik (numerik), K-5 lokasi yang dirujuk aturan aktif |
| `app/Libraries/MasterData/AturanLokasiPresensiHooks.php` | validasi + normalisasi `target_*`, `*_desc` = nama, `hari_berlaku`, aktivasi aturan |
| `app/Libraries/MasterData/MasterStatusHooks.php`, `MasterService.php` | hook opsional perubahan status (PATCH status, DELETE, PUT status); master lain tidak berubah perilakunya |
| `app/Libraries/MasterData/MasterDefinition.php`, `app/Controllers/Api/MasterData/BaseMasterController.php` | opsi definisi `nameRequired` (nama turunan, tanpa cek nama unik) |
| `tests/MasterData/LokasiPresensiTest.php` (+ fixture G-TC di `MasterDataTestTrait`/`MasterDataSeeder`) | test fitur G-03 |
| `frontend/src/features/master-data/…` (`MasterFormDialog.vue`, `CheckboxGroupField.vue`, `MasterDataView.vue`, `master.schema.ts`) | form aturan tanpa JSON mentah, menu ⋮ generik |

## 1. Latar belakang & cakupan

Presensi legacy memakai geotagging: absen sah bila posisi pegawai di dalam radius titik lokasi kantor. Admin mengelola (a) **lokasi presensi** — titik koordinat + radius — dan (b) **aturan penargetan** ("pegpren") — lokasi mana berlaku untuk unit/satker × jenis pegawai (atau daftar NIP), dengan pengecualian NIP dan pembatasan hari. Saat aturan disimpan, legacy mengekspansinya ke tabel `user_lokasi_presensi` per NIP (P-7).

Masuk lingkup DBV-007 (PRD S-1..S-5): tabel `lokasi_presensi` dan `dm_user_lokasi_presensi` + CRUD admin. **Ditunda** (PRD X-1..X-4): `user_lokasi_presensi` (FK ke `pegawai`, Fase 3 B-01/DBV-012; DDL di Bagian 6), target/pengecualian per NIP (`target_peg`/`excl_peg`, kolom ada tetapi tidak ditampilkan form; nilai impor dipertahankan), logika jarak saat absen (Fase 5), map picker.

## 2. Skema hasil DBV-007

### 2.1 `lokasi_presensi` — D1:2212-2224

DDL [K] (D1, tanpa header dump):

```sql
CREATE TABLE IF NOT EXISTS `lokasi_presensi` (
  `id_lokasi_presensi` int NOT NULL AUTO_INCREMENT,
  `nama_lokasi` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `longitude` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `radius` double NOT NULL DEFAULT '0' COMMENT 'Meter',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`id_lokasi_presensi`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Hasil migration v2 (`SHOW CREATE TABLE`, MySQL 8.0.30):

```sql
CREATE TABLE `lokasi_presensi` (
  `id_lokasi_presensi` int NOT NULL AUTO_INCREMENT,
  `nama_lokasi` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `longitude` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `radius` double NOT NULL DEFAULT '0' COMMENT 'Meter',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL COMMENT 'id_pengguna pembuat',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_lokasi_presensi`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 2.2 `dm_user_lokasi_presensi` — D1:474-493

DDL [K] (D1, tanpa header dump):

```sql
CREATE TABLE IF NOT EXISTS `dm_user_lokasi_presensi` (
  `id_dm_user_lokasi_presensi` int NOT NULL AUTO_INCREMENT,
  `target_lp` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_lp_desc` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_uns` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_uns_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_jp` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_jp_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_peg` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_peg_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `excl_peg` mediumtext COLLATE utf8mb4_unicode_ci,
  `excl_peg_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `hari_berlaku` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hari berlaku geotagging, ISO: 1=Senin..7=Minggu, dipisah koma. NULL = setiap hari',
  PRIMARY KEY (`id_dm_user_lokasi_presensi`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Hasil migration v2 (`SHOW CREATE TABLE`, MySQL 8.0.30). Urutan, tipe, dan komentar kolom [K] — termasuk posisi `hari_berlaku` setelah `updated_by` dan komentarnya — sama persis dengan D1; kolom `status` [V2] diletakkan paling akhir agar urutan kolom [K] tidak bergeser:

```sql
CREATE TABLE `dm_user_lokasi_presensi` (
  `id_dm_user_lokasi_presensi` int NOT NULL AUTO_INCREMENT,
  `target_lp` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_lp_desc` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_uns` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_uns_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_jp` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_jp_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_peg` mediumtext COLLATE utf8mb4_unicode_ci,
  `target_peg_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `excl_peg` mediumtext COLLATE utf8mb4_unicode_ci,
  `excl_peg_desc` mediumtext COLLATE utf8mb4_unicode_ci,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL COMMENT 'id_pengguna pembuat',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  `hari_berlaku` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hari berlaku geotagging, ISO: 1=Senin..7=Minggu, dipisah koma. NULL = setiap hari',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  PRIMARY KEY (`id_dm_user_lokasi_presensi`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

Tanpa FK di batch ini (rujukan ke lokasi/jenis pegawai/unit/satker ada di dalam JSON, Bagian 4 K-7).

## 3. Deviasi dari legacy [V2]

| # | Item | Legacy [K] (D1) | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Kolom `dm_user_lokasi_presensi.status` | Tidak ada; hapus aturan = DELETE fisik + CASCADE ke `user_lokasi_presensi` (P-8) | TINYINT NOT NULL DEFAULT 1, COMMENT `1: Aktif, 2: Tidak Aktif, 10: Dihapus`, **kolom terakhir** (setelah `hari_berlaku`) | K-1. Soft delete & pulihkan seperti master v2 lain. Urutan kolom [K] tidak bergeser. Konsumen Fase 3/5 (ekspansi `user_lokasi_presensi`, cek absen) **wajib** hanya memakai aturan status 1 |
| 2 | KEY `status` di `dm_user_lokasi_presensi` | Tidak ada | `KEY status (status)` | Daftar default menyaring `status != 10`; cek K-5 membaca aturan status 1 |
| 3 | KEY `status` di `lokasi_presensi` | Tidak ada (kolom status ada) | `KEY status (status)` | Options/dropdown dan cek koordinat menyaring status. Pola tabel master v2 |
| 4 | COMMENT status `lokasi_presensi` | `1: Aktif, 2: Tidak Aktif` | `1: Aktif, 2: Tidak Aktif, 10: Dihapus` | Hapus = soft delete 10 (L-4, DBV-001 keputusan b) |
| 5 | COMMENT `created_by`/`updated_by` | Tanpa COMMENT | `id_pengguna pembuat` / `id_pengguna yang terakhir mengubah` | Preseden DBV-001..005; isi = `id_pengguna` (bukan id user legacy) |
| 6 | `hari_berlaku` (posisi & COMMENT) | Setelah `updated_by`, COMMENT D1 | **Sama dengan D1** (tidak ada deviasi) | Dicatat karena draf sebelumnya menaruh kolom ini sebelum `status` dengan komentar berbeda; sudah disamakan |
| 7 | `AUTO_INCREMENT` awal | 5 / 9 | Tidak ditulis | Impor memakai ID legacy apa adanya; counter mengikuti (Bagian 6) |
| 8 | Hapus lokasi | DELETE fisik (L-4) | Soft delete 10, bisa dipulihkan; ditolak 422 bila dirujuk aturan aktif (K-5) | Lokasi dirujuk lewat JSON, bukan FK — tanpa K-5 aturan aktif bisa menunjuk lokasi terhapus |
| 9 | Isi `target_*` | JSON ber-`addslashes` (P-4) | JSON tanpa `addslashes`, elemen teks (`["1","2"]`) | Kompatibel bentuk (K-7); impor menerapkan `stripslashes` (Bagian 6) |

Tidak ada deviasi tipe, nullability, default, atau collation kolom [K]. Tidak ada UNIQUE/CHECK baru (K-3, K-4).

## 4. Keputusan yang diminta dari DB Validator / reviewer CR (penomoran PRD K-1..K-8)

| # | Pertanyaan | Usulan (yang diimplementasikan) | Keputusan |
|---|---|---|---|
| K-1 | Status di `dm_user_lokasi_presensi` (D1 tanpa status, hapus fisik) | Tambah `status` [V2] 1/2/10 (Bagian 3 #1-#2), soft delete + pulihkan lewat menu ⋮. Alternatif: hapus fisik seperti legacy | ⏳ |
| K-2 | Tipe `latitude`/`longitude` (D1 `VARCHAR(50)`) | Tetap `VARCHAR(50)` [K] + validasi aplikasi: angka desimal, latitude −90..90, longitude −180..180 (batas inklusif). Alternatif DECIMAL(10,7)/(11,7) = deviasi [V2] + konversi saat impor | ⏳ |
| K-3 | Radius ≥ 10 m | Divalidasi aplikasi (422; 9,99 ditolak, 10 diterima). **Tanpa CHECK DB** sampai audit data legacy: impor radius < 10 ditandai & dilaporkan, tidak ditolak | ⏳ |
| K-4 | Keunikan | Koordinat (lat+long) unik **di aplikasi** terhadap lokasi status ≠ 10, sama untuk tambah, ubah, dan pemulihan dari 10; dibanding **numerik** (`-6.175400` = `-6.1754`). Lokasi status 10 boleh dipakai ulang koordinatnya. Nama lokasi unik di aplikasi (cek nama engine master, case-insensitive, termasuk status 2/10). **Tanpa UNIQUE DB** (koordinat VARCHAR tidak bisa dibandingkan numerik oleh index; UNIQUE `nama_lokasi` diputuskan setelah audit duplikat impor) | ⏳ |
| K-5 | Lokasi yang dirujuk aturan | **Diimplementasikan:** nonaktifkan/hapus lokasi yang masih ada di `target_lp` aturan **status 1** → 422 dengan daftar aturan perujuk (`#id (keterangan)`), lewat PATCH status, DELETE, maupun PUT status. Sebaliknya, aturan tidak dapat diaktifkan/dipulihkan ke status 1 selama ada lokasi target-nya yang tidak aktif/tidak dikenal. Pemulihan lokasi (10 → 1/2) memeriksa ulang K-4. Batas: cek di aplikasi (JSON, tanpa FK); dua penulis paralel pada tabel berbeda (lock engine per tabel) bisa lolos bersamaan — risiko diterima, ditinjau ulang bila K-7 dinormalisasi | ⏳ |
| K-6 | Kolom `order` | Tidak ditambah (D1 tidak punya); daftar & options urut nama/ID | ⏳ |
| K-7 | Format `target_*` dan `*_desc` | Tetap JSON di MEDIUMTEXT [K], **format sama dengan legacy** (`Lm_lokasi::set_param_dm`): `target_*` = JSON array kode (`["1","2"]`, `["0"]`, `["sat_12"]`), `*_desc` = JSON array **nama** (`["Kantor Pusat"]`). Tabel relasi ternormalisasi dipertimbangkan di Fase 5 | ⏳ |
| K-8 | Jenis pegawai id 7 dikecualikan | Ikut legacy [K] `Lm_lokasi.php:276` (daftar jenis pegawai form `id_jenis_pegawai != '7'`): id 7 ditolak 422 di backend dan tidak ditampilkan di form. Arti id 7 belum terkonfirmasi (PRD pertanyaan terbuka #4) | ⏳ |
| D-1 | Unit/satker sebelum G-02 (DBV-008) di `main` | Tabel `unit`/`satker` belum ada di v2 → `target_uns` hanya menerima `0` (Seluruh Kementerian); id unit/`sat_<id>` ditolak 422 "tidak dikenal". Form hanya menampilkan "Seluruh Kementerian" + catatan; **tidak ada data satker karangan**. Begitu tabel/master `unit`/`satker` ada, validasi & nama dibaca otomatis (tabel dicek tanpa cache; opsi form dari endpoint options `unit`/`satker` bila master terdaftar) | ⏳ |
| D-2 | Nama kementerian untuk kode `0` | `web_config.nama_kementerian` bila tabel (G-09, DBV-006) dan barisnya ada [K] `Lm_lokasi.php` (`$webConfig['nama_kementerian']`); selain itu teks `Seluruh Kementerian` | ⏳ |
| D-3 | Hak akses | CRUD kedua master = **role 1 saja** (pola Matriks Modul G; PRD §4 — Matriks belum memuat G-03, peran ditetapkan reviewer CR). Endpoint options lokasi = semua role login (ISSUE-012) | ⏳ |

## 5. Perilaku aplikasi yang bergantung pada skema (CR-031)

- **Lokasi** (engine master): nama wajib ≤ 255; latitude/longitude wajib angka desimal dalam rentang (`1e2`, `abc` → 422); radius wajib ≥ 10 (pesan per field); K-4/K-5 di `LokasiPresensiHooks`. Ubah tanpa menyentuh koordinat tidak memeriksa ulang koordinat (data lama tetap bisa diubah nama/radiusnya).
- **Aturan:** `target_lp`, `target_uns`, `target_jp` wajib saat tambah (P-1..P-3; target per NIP ditunda X-2 sehingga target unit/satker wajib). Payload harus string JSON array tak kosong berisi teks/angka bulat; kosong, JSON rusak, objek, bersarang (`[[1]]`), desimal, boolean, null, id berawalan 0/negatif/> INT → **422 per field, bukan 500**. Nilai non-teks di body (array/objek JSON) ditolak controller (422). `target_lp` = id lokasi **ada dan aktif**; `target_jp` = id jenis pegawai ada dan aktif, bukan 7; `target_uns` = Bagian 4 D-1/D-2 (memilih `0` menormalkan isi menjadi `["0"]`, seperti legacy). Duplikat dibuang, urutan input dipertahankan.
- **`*_desc`** = kolom turunan: dihitung ulang dari master saat kolom target-nya berubah; nilai kiriman klien diabaikan. Saat ubah, hanya target yang berubah yang divalidasi & dihitung ulang — aturan hasil impor (desc lama, lokasi yang kini nonaktif) tetap bisa diubah keterangannya.
- **`hari_berlaku`**: angka 1-7 dipisah koma (rule field), dinormalkan unik + terurut (`5,1,3,3` → `1,3,5`); kosong → NULL (setiap hari). `0`, `8`, `a`, `1,,2` → 422.
- **Nama turunan**: definisi `aturan-lokasi-presensi` memakai `target_lp_desc` sebagai kolom nama dengan `nameRequired: false` → tidak ada cek nama unik (dua aturan boleh menargetkan lokasi yang sama) dan nama tidak diinput admin. Daftar admin menampilkan JSON nama sebagai teks "A, B".
- **Status**: hook opsional `MasterStatusHooks` dipanggil engine sebelum status berubah (PATCH status, DELETE, PUT berstatus). Hanya master G-03 yang mengimplementasikannya; perilaku master lain tidak berubah (G-TC generik lolos tanpa perubahan asersi).
- **Frontend:** form aturan memakai daftar centang (komponen `UiCheckbox` redesign) untuk lokasi, unit/satker, jenis pegawai, dan hari Senin..Minggu; frontend menyusun JSON-nya. Aksi baris lewat menu ⋮ `RowActionsMenu` generik halaman Master Data (`master-actions-<id>`, label "Aksi untuk <nama>"; AGENTS.md §1). Testid khusus `lokasi-actions-<id>`/`aturanlokasi-actions-<id>` di PRD §6.3 tidak dibuat karena G-03 memakai halaman master generik, bukan halaman sendiri.

## 6. Bahan Fase 3 dan aturan impor

### 6.1 `user_lokasi_presensi` — D1:7595-7605 (ditunda, X-1)

DDL [K] dicatat sebagai bahan Fase 3 (B-01, DBV-012); **tidak** dibuat di batch ini karena FK ke `pegawai(nip)`:

```sql
CREATE TABLE IF NOT EXISTS `user_lokasi_presensi` (
  `nip` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_dm_user_lokasi_presensi` int NOT NULL,
  `id_lokasi_presensi` int NOT NULL,
  KEY `fk_user_lokasi_presensi_01` (`nip`),
  KEY `fk_user_lokasi_presensi_02` (`id_dm_user_lokasi_presensi`),
  KEY `fk_user_lokasi_presensi_03` (`id_lokasi_presensi`),
  CONSTRAINT `fk_user_lokasi_presensi_01` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_lokasi_presensi_02` FOREIGN KEY (`id_dm_user_lokasi_presensi`) REFERENCES `dm_user_lokasi_presensi` (`id_dm_user_lokasi_presensi`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_lokasi_presensi_03` FOREIGN KEY (`id_lokasi_presensi`) REFERENCES `lokasi_presensi` (`id_lokasi_presensi`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Catatan untuk DBV-012: tabel tanpa PK (ekspansi bisa ganda); aksi CASCADE legacy perlu ditinjau terhadap pola v2 (RESTRICT, soft delete) — dengan K-1 aturan tidak lagi dihapus fisik, jadi ekspansi harus disaring/dibangun ulang berdasarkan `status` aturan dan lokasi. Ekspansi (P-7) memakai data `pegawai` + `pegawai_mutasi_jabatan` (unit/satker) dan `target_peg`/`excl_peg`.

### 6.2 Aturan impor (bahan runbook)

1. **ID** kedua tabel diimpor apa adanya; AUTO_INCREMENT mengikuti nilai produksi.
2. **`stripslashes`** pada `target_*`, `*_desc`, `keterangan`, `nama_lokasi` (legacy `addslashes`). `target_*` dinormalkan menjadi JSON array teks (`[1]` → `["1"]`); `*_desc` dibiarkan (JSON array nama).
3. **Status:** `lokasi_presensi.status` 1/2 tetap; aturan diberi status 1 (K-1).
4. **Laporan (tidak ditolak):** radius < 10 (K-3); koordinat bukan angka/di luar rentang (K-2); koordinat ganda di antara lokasi status 1/2 (K-4, dibanding numerik); nama lokasi ganda (K-4); `target_*` JSON rusak/kosong; aturan aktif yang menunjuk lokasi tidak aktif/tidak ada (K-5) — diputuskan admin sebelum cutover (nonaktifkan aturan atau perbaiki target).
5. **`target_uns`** berisi id unit/`sat_<id>` baru tervalidasi setelah G-02 (DBV-008) diimpor; impor aturan dijalankan **setelah** unit/satker.
6. **`user_lokasi_presensi`** diimpor di Fase 3 bersama `pegawai` (6.1).

## 7. Verifikasi developer (sebelum review DB Validator)

### 7.1 Lingkungan

MySQL 8.0.30 lokal (Laragon). Database scratch `simpeg_v2_scr_codexg03` (DBPrefix kosong) untuk siklus `spark migrate` — nama diambil dari `.env` worktree khusus uji (tidak di-commit, bukan salinan `.env` checkout utama), tabel hasil diverifikasi berada di DB itu, lalu DB di-DROP dengan nama persis. Database dev `simpeg_v2` tidak disentuh (tabel `migrations` tetap 23 baris, versi terakhir `2026-09-25-130200`, tanpa tabel G-03). PHPUnit memakai DB test per shard (`fastcheck`, setara `./check.sh`). Verifikasi MariaDB 10.4 belum dilakukan (Bagian 8).

### 7.2 Hasil (02-10-2026, basis `main` `eb86d09`)

| Langkah | Hasil |
|---|---|
| `php spark migrate --all` tanpa file DBV-007 | batch 1 = 23 migration `App` (+ Queue); 36 tabel |
| `php spark migrate --all` (file DBV-007 dikembalikan) | batch 2 = `CreateLokasiPresensi` saja; 38 tabel |
| `php spark migrate:rollback` | hanya batch 2 yang turun; 36 tabel (identik dengan sebelum DBV-007), `lokasi_presensi`/`dm_user_lokasi_presensi` tidak ada, batch 1 utuh (23 baris) |
| `php spark migrate --all` ulang | 38 tabel; `SHOW CREATE TABLE` kedua tabel identik dengan run pertama (md5 sama) dan sesuai Bagian 2 |
| `DROP DATABASE simpeg_v2_scr_codexg03` | selesai |
| `LokasiPresensiSchemaTest` | kolom/urutan/tipe/nullable/default/komentar/collation per kolom, InnoDB + `utf8mb4_unicode_ci`, PRIMARY + KEY `status`, tanpa FK; `down()` hanya men-DROP dua tabel DBV-007 dan `up()` mengembalikan skema persis |
| `LokasiPresensiTest` | radius 9,99/10; lat ±90 vs ±90.0001, long ±180 vs ±180.0001, `abc`/`1e2`; koordinat ganda saat tambah & ubah dengan perbandingan numerik, pakai ulang koordinat baris status 10, pemulihan bentrok ditolak (PATCH 1/2, PUT); K-5 (PATCH/DELETE/PUT, data legacy `[1]` dan ber-`addslashes`, aktivasi aturan); `hari_berlaku` normalisasi & penolakan; 19 bentuk JSON salah × 3 field (tambah & ubah) → 422; lokasi/jenis pegawai tak dikenal, nonaktif, terhapus, id 7 → 422; `*_desc` = nama (termasuk unit/satker/`web_config` bila tabelnya ada, id unit dan satker beririsan); role 2-8 → 403 untuk seluruh CRUD, options lokasi terbuka |
| Quality gate penuh (`fastcheck`, langkah = `./check.sh`) | Lolos: PHPStan level 5 `[OK] No errors`; PHP-CS-Fixer `Found 0 of 271 files that can be fixed`; PHPUnit 2 shard `OK` (351 + 355 = 706 test, 19.164 assertion); ESLint (`--max-warnings=0`) dan vue-tsc bersih; Vitest `33 passed` file, `376 passed` test; build sukses. `MasterGenericTcTest` (G-TC #1–#6) mencakup kedua master G-03; `RbacMasterEndpointsTest` menerima 422 untuk PATCH order pada master tanpa kolom `order` |

## 8. Checklist DB Validator

- [ ] Bagian 2 cocok dengan DDL [K] D1:2212-2224 dan D1:474-493; deviasi hanya yang tercatat di Bagian 3
- [ ] `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4: rollback hanya membatalkan batch DBV-007, `SHOW CREATE TABLE` identik antar-run
- [ ] `LokasiPresensiSchemaTest` + `LokasiPresensiTest` + `MasterGenericTcTest` lolos di MariaDB 10.4
- [ ] Keputusan Bagian 4 K-1..K-8 dan D-1..D-3 diisi (K-5 sudah diimplementasikan; bila ditolak, perilaku dikembalikan lewat `MasterStatusHooks`)
- [ ] Aturan impor Bagian 6.2 diterima sebagai langkah runbook; urutan impor aturan setelah unit/satker (G-02)
- [ ] DDL `user_lokasi_presensi` (6.1) dicatat untuk DBV-012 (Fase 3)
