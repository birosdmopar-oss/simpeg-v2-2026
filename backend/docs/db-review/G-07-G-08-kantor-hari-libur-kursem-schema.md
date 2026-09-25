# DB Validator Review — G-07/G-08 kantor, hari libur, jenis libur, kursem (skema legacy)

**Key review:** `DBV-003` (review DB Validator, skema) + `CR-010` (review kode) — satu pull request, judul `[DBV-003][CR-010] …`, branch `dbv-003/g07-g08-libur-kantor-kursem`. DB Validator hanya me-review/approve; merge oleh user (reviewer CR) setelah kedua review setuju (aturan 24-09-2026, G-01 Bagian 8). PR dibuka setelah CR-009 (perluasan engine master) ada di `main`.

**Status:** ⏳ **MENUNGGU APPROVAL DB VALIDATOR (DBV-003) DAN REVIEW KODE (CR-010)** — migration `2026-09-25-100000`, `2026-09-25-100100`, dan `2026-09-25-100300` JANGAN dijalankan di Dev/Production sebelum disetujui. Migration sentinel `2026-09-25-100200` **belum ada di branch ini dan belum diajukan untuk approval**: file-nya menyusul di tahap CR-010 dalam PR yang sama (D5, Bagian 2.5, Bagian 4 #14). Di Dev/Production keempat migration dijalankan bersama setelah PR itu disetujui dan di-merge, sehingga jatuh dalam satu batch.

**Rujukan:** `02-MasterData.md` G-07 ("CRUD … kantor") dan G-08 (hari libur: `tgl_mulai <= tgl_akhir`, tidak boleh overlap), DDL produksi `simpeg_prod.sql:245-252` (`bidang_kursem`), `:1308-1321` (`hari_libur`), `:1435-1442` (`instansi_kursem`), ERD legacy `simpeg01.erd` (entity 61 `hari_libur`, 81 `jenis_libur`, 90 `kantor`, 11 `bidang_kursem`, 68 `instansi_kursem`; 5 nama FK), kode legacy (`libraries/hr/master/Lm_umum.php`, `controllers/hr/master/C_umum.php`, `views/hr/master/umum/kantor/form.php`, `libraries/hr/L_presensi.php`, `controllers/hr/Presensi.php`, `views/hr/employee/presensi/holiday/form.php`, `libraries/hr/rwy/L_seminar.php`, `libraries/hr/rwy/L_alamat.php`, `libraries/hr/L_user.php`, `controllers/Tester.php`), `G-01-master-schema.md` (Keputusan #5, Bagian 3, Bagian 7 #1, Bagian 8 / DBV-001), `G-10-faq-schema.md` (pola pilot DBV-002), keputusan user DBV-003 (25-09-2026).

**Label sumber:** **[K]** terkonfirmasi — DDL `simpeg_prod.sql` (nomor baris), ERD `simpeg01.erd`, atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3); **[I]** dugaan (tidak ada sumber langsung, wajib dikonfirmasi dengan dump produksi). **[I kuat]** = dugaan yang didukung beberapa bukti tidak langsung.

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-25-100000_CreateHariLibur.php` | `jenis_libur`, lalu `hari_libur` (SQL mentah sadar prefix; FK + UNIQUE `tgl_mulai` + CHECK rentang; `down()` men-drop anak → induk) |
| `app/Database/Migrations/2026-09-25-100100_CreateKursem.php` | `bidang_kursem`, `instansi_kursem` |
| `app/Database/Migrations/2026-09-25-100200_SeedWilayahLainLain.php` | **tahap CR-010** (belum ada di branch ini, belum diajukan untuk approval): 4 baris sentinel LAIN-LAIN di tabel wilayah (rancangan di Bagian 2.5) |
| `app/Database/Migrations/2026-09-25-100300_CreateKantor.php` | `kantor` + 4 FK ke wilayah |
| `tests/MasterData/LiburKantorKursemSchemaTest.php` | skema hasil migration dibandingkan dengan Bagian 2 lewat `information_schema`, CHECK (query portabel), constraint DB, rollback |
| `tests/MasterData/Batch1LegacySchemaTest.php` | **diadaptasi** (test DBV-001): melepas/memasang ulang migration dependen wilayah di `setUp()`/`tearDown()`, assertion tidak berubah (Bagian 4 #13) |

## 1. Latar belakang & keputusan

Status sumber DDL:
- `hari_libur`, `bidang_kursem`, `instansi_kursem`: DDL lengkap di `simpeg_prod.sql` [K]. Isinya identik dengan `SHOW CREATE TABLE` di DB lokal `simpeg_prod_duplikat` dan `simpeg01`; di kedua salinan tabelnya berisi 0 baris.
- `jenis_libur` dan `kantor`: **tidak ada DDL**. Dump terpotong setelah `jabatan` (urutan alfabet) dan kedua tabel tidak ada di DB lokal. Nama kolom diambil dari kode legacy [K]; tipe kolom [I] dengan analog dari DDL yang ada. ERD hanya memuat entity dan nama FK (tanpa kolom maupun aksi FK).
- Tabel wilayah legacy tidak ada di sumber lokal. Keberadaan baris sentinel LAIN-LAIN (kode 99/9999/9999999/9999999999) disimpulkan dari kode legacy (Bagian 2.5).

Perilaku legacy yang memengaruhi skema [K]:
- **Hari libur:** daftar dibaca role 1/4/5/8 (`Presensi.php:1015`), tambah/ubah/hapus role 1 (`:1042, :1086, :1138`). Hapus = hard DELETE (`L_presensi.php:20477`). Daftar memakai **INNER JOIN** ke `jenis_libur` dan urut `tgl_mulai DESC` (`:20346-20351`), sehingga baris dengan jenis NULL (hasil FK SET NULL) hilang dari daftar tetapi tetap dihitung sebagai libur. Cek overlap inklusif `tgl_mulai<=akhir AND tgl_akhir>=mulai` (`:20526`; saat ubah `:20540` dengan `id_libur!=id`), rentang bersebelahan boleh. Aturan `tgl_mulai <= tgl_akhir` hanya ditegakkan datepicker klien (`holiday/form.php:93-94, 108-109`). `sp_holiday` mengisi `updated_by` untuk tambah maupun ubah (`:20553-20564`).
- **Konsumen hari libur:** presensi, tukin, uang makan, lama cuti, konket, dan LKH membaca `hari_libur` sebagai deret tanggal non-kerja **tanpa filter jenis maupun status** (> 20 lokasi, mis. `L_presensi.php:52, 461, 877`, `function_helper.php:2182`, `L_konket.php:123`, `L_lkh.php:741`).
- **Kantor:** hanya role 1 (`C_umum.php:1106, 1152, 1204`); daftar, ubah, dan hapus hanya untuk `status='1'` (`C_umum.php:1155, 1207`; `Lm_umum.php:1584`). Hapus = hard DELETE (`Lm_umum.php:1751`). Urutan baru = MAX(`order`)+1 (`:1636-1640`), UI membatasi `order` 1..99 (`kantor/form.php:240-244`). Kode LAIN-LAIN memakai kolom `*_lain` yang wajib diisi (`Lm_umum.php:1797-1831`); aturan "sentinel di satu level memaksa level di bawahnya ikut sentinel" **hanya lewat JS** (`kantor/form.php:280-299, 331-347, 375-387`). Server tidak memeriksa konsistensi rantai wilayah (`Lm_umum.php:1771-1836`). Provinsi 99 disaring keluar dari daftar pilihan (`:1594`). `kode_pos` teks bebas tanpa `maxlength` saat kelurahan sentinel atau `kd_pos` kosong (`kantor/form.php:189-190, 206, 418`).
- **Kursem:** legacy tidak punya UI CRUD. Dropdown riwayat seminar membaca `status='1'` urut nama (`L_seminar.php:49-50`) dan menyimpan **teks nama**, bukan id (`views/hr/employee/rwy/seminar/form.php:100-104, 165-169`).
- `kantor` dipakai sebagai templat alamat di riwayat alamat jenis 3 (`L_alamat.php:52-61`); nilainya disalin, tidak dirujuk FK. `kantor` dan kedua tabel kursem tidak dirujuk FK mana pun (ERD).

### 1.1 Keputusan final (user, 25-09-2026)

| # | Topik | Keputusan | Diterapkan |
|---|---|---|---|
| F1 | Implementasi hari libur | Controller + service khusus (`HariLiburController`, `HariLiburService`): validasi tanggal, cek overlap dalam transaksi dengan lock, `tanggalLibur()` hanya status 1 sebagai satu-satunya sumber kalkulasi Fase 5 | tahap CR-010 |
| F2 | Semantik status hari libur | Status 1/2/10; hanya status 1 dihitung sebagai libur; overlap dicek terhadap **semua** status (Paket A) | skema (status) + CR-010 |
| F3 | Constraint hari libur | `UNIQUE(tgl_mulai)` [V2] dan `CHECK (tgl_akhir >= tgl_mulai)` [V2] dengan nama constraint eksplisit (CHECK pertama di repo) | migration `CreateHariLibur` |
| F4 | Nama hari libur | **Tanpa** UNIQUE nama (nama berulang tiap tahun) — deviasi eksplisit dari aturan UNIQUE nama master | migration |
| F5 | `hari_libur.id_jenis_libur` | Tetap NULL seperti legacy [K], wajib diisi di aplikasi | migration + CR-010 |
| F6 | Hak akses hari libur | Tambah/ubah/hapus role 1; daftar dibaca role 1/4/5/8 seperti legacy | CR-010 |
| F7 | `jenis_libur` | Engine generik, CRUD role 1, dropdown untuk semua role | migration + CR-010 |
| F8 | `kantor` | Sentinel LAIN-LAIN ikut legacy: 4 baris sentinel di-seed ke tabel wilayah lewat migration baru, dilindungi dan dikeluarkan dari options (engine); server memeriksa konsistensi rantai wilayah untuk kode non-sentinel (`KantorHooks`); field `ref`; UNIQUE `nama_kantor` global; `kode_pos` 5 digit | migration `CreateKantor`; migration sentinel `100200` dan engine di tahap CR-010 (D5) |
| F9 | Kursem | Engine generik, CRUD role 1, dropdown semua role; tambah `order` [V2] (G-01 #5); tanpa kolom `*_by`; PK TINYINT [K] | migration `CreateKursem` + CR-010 |
| F10 | Umum master | Status 1/2/10, soft delete, UNIQUE nama, `utf8mb4_unicode_ci`, FK RESTRICT dengan nama legacy (ERD), tipe PK ikut legacy termasuk signed, impor pakai ID legacy apa adanya, nilai [I] berlabel, verifikasi MariaDB 10.4 diminta eksplisit | semua migration |

### 1.2 Usulan yang diimplementasikan (menunggu approval DBV — Bagian 4)

| # | Usulan |
|---|---|
| D1 | Tipe [I] `jenis_libur`: nama VARCHAR(255) (pola nama master legacy), `order` TINYINT DEFAULT 1 (pola [K] tabel ber-PK TINYINT), kolom audit seperti `jenis_pegawai` (DBV-001) |
| D2 | Tipe [I] `kantor`: kode wilayah CHAR(2/4/7/10), `*_lain` VARCHAR(255), `kode_pos` CHAR(5), `alamat` TEXT, `telp`/`faks` VARCHAR(50), `remark` TEXT, `order` INT DEFAULT 1, audit lengkap (Bagian 2.4) |
| D3 | `order` kursem bertipe TINYINT (bukan INT): pola [K] tabel ber-PK TINYINT (`agama` :178, `diklat` :447, `gol_pppk` :1281) |
| D4 | Baris sentinel wilayah ber-`order` 0 (di luar urutan tampil) dan status 1; engine mengeluarkannya dari options dan lingkup urutan (tahap CR-010) |
| D5 | Migration sentinel `2026-09-25-100200` ditambahkan di tahap CR-010 bersama fitur sentinel engine, bukan sekarang (Bagian 2.5). Isi F8 tidak berubah, hanya waktunya yang dipisah; pemisahan ini diajukan di Bagian 4 #14. Konsekuensi: sampai migration itu ada, `kantor` berkode LAIN-LAIN ditolak FK (error 1452) |
| D6 | Penempatan CRUD: `bidang-kursem`, `instansi-kursem`, `kantor` di `UmumController`; `jenis-libur` di `HariLiburController` (tahap CR-010) |
| D7 | Migration tidak men-seed `jenis_libur`/kursem: impor memakai ID legacy apa adanya. Nilai 1 = Libur Nasional, 2 = Cuti Bersama [I] hanya dipakai fixture test/seeder lokal |

## 2. Skema hasil DBV-003

Berlaku untuk kelima tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. Seluruh kolom string **mewarisi** collation tabel karena migration memakai `CREATE TABLE` SQL mentah dengan `COLLATE` di tingkat tabel (terverifikasi per kolom oleh schema test). Risiko G-01 8.5 (tabel baru mewarisi `DBCollat` `utf8mb4_general_ci`) hanya berlaku untuk tabel buatan Forge.
- Nama constraint/index **tanpa** prefix tabel (DBPrefix hanya berlaku untuk nama tabel, mis. `t_` di DB test).
- Nilai `AUTO_INCREMENT` awal tidak ditulis [V2] — dump legacy memuat snapshot 186 (`hari_libur`), 14 (`bidang_kursem`), 18 (`instansi_kursem`); impor dengan ID eksplisit menaikkan counter otomatis (preseden DBV-001/002).
- COMMENT status `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` [V2]. COMMENT audit `'id_pengguna pembuat'` / `'id_pengguna yang terakhir mengubah'` [V2, preseden `AlterBatch1KeSkemaLegacy`/`CreateFaq`]. Kolom audit tanpa FK.
- Row format DYNAMIC (default MySQL 8 dan MariaDB 10.4; ditulis eksplisit hanya di `hari_libur` seperti DDL legacy). UNIQUE nama VARCHAR(255) = key 1.020 byte, di bawah batas 3.072 byte InnoDB.

### 2.1 jenis_libur — DDL legacy tidak tersedia

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenis_libur` | TINYINT NOT NULL AUTO_INCREMENT (signed) | kolom [K] `L_presensi.php:20349`, `holiday/form.php:42`; tipe **[K turunan]**: kolom FK `hari_libur.id_jenis_libur tinyint` (`simpeg_prod.sql:1310, 1320`) wajib sama tipe dan signedness. Maksimal 127 ID |
| `jenis_libur` | VARCHAR(255) NOT NULL | kolom [K] `L_presensi.php:20348`; panjang **[I]** (pola nama master legacy `bidang_kursem`, `faq_*`) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] `L_presensi.php:20362` (`ORDER BY order`); tipe & default **[I]** (pola `agama` :178 [K]) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | kolom [K] `L_presensi.php:20362` (`status='1'`), `L_news.php:1171`; tipe & default [I]; nilai 10 & COMMENT [V2] |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | **[I]** (preseden DBV-001 `jenis_pegawai`; legacy tidak pernah menulis tabel ini) |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [I] |
| `updated_by` | INT NULL, COMMENT v2 | [I] |

Kunci: `PRIMARY (id_jenis_libur)`; **UNIQUE** `uq_jenis_libur_nama (jenis_libur)` [V2].

### 2.2 hari_libur — `simpeg_prod.sql:1308-1321`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_libur` | INT NOT NULL AUTO_INCREMENT | [K] :1309 |
| `id_jenis_libur` | TINYINT NULL DEFAULT NULL | [K] :1310 — wajib diisi di aplikasi (F5) |
| `tgl_mulai` | DATE NOT NULL | [K] :1311 |
| `tgl_akhir` | DATE NOT NULL | [K] :1312 |
| `nama_libur` | VARCHAR(100) NOT NULL | [K] :1313 — **tanpa** UNIQUE (F4) |
| `keterangan` | TEXT NULL | [K] :1314 |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | **[V2]** — G-01 Keputusan #5 ("`status` untuk `hari_libur`"); hanya status 1 dihitung sebagai libur (F2). Diletakkan sebelum kolom audit |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :1315 |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] :1316 |
| `updated_by` | INT NULL, COMMENT v2 | [K] :1317; COMMENT [V2]. Tanpa `created_by` [K]: `updated_by` diisi saat tambah maupun ubah (`L_presensi.php:20561`) |

Kunci:
- `PRIMARY (id_libur)` [K] :1318.
- **UNIQUE** `uq_hari_libur_tgl_mulai (tgl_mulai)` [V2, F3] — pengaman DB untuk Paket A, berlaku juga untuk baris status 2/10. Tidak menggantikan cek overlap (overlap tetap dicek service dalam transaksi dengan lock).
- `KEY fk_id_jenis_libur_harilibur_to_jenislibur (id_jenis_libur)` [K] :1319; FK dengan nama sama → `jenis_libur (id_jenis_libur)` **ON DELETE RESTRICT ON UPDATE RESTRICT** — nama [K] :1320 + ERD, aksi [V2] (legacy `ON DELETE SET NULL ON UPDATE CASCADE`).
- **CHECK** `chk_hari_libur_rentang CHECK (tgl_akhir >= tgl_mulai)` [V2, F3]. Ditegakkan MySQL ≥ 8.0.16 (terverifikasi di 8.0.30: error 3819, juga saat `sql_mode=''`) dan MariaDB ≥ 10.2.1 (error 4025, belum diverifikasi — Bagian 6.4). Nama CHECK di MySQL unik per **schema**, di MariaDB per tabel. `SHOW CREATE TABLE` MySQL 8.0.30 menampilkannya sebagai ``CONSTRAINT `chk_hari_libur_rentang` CHECK ((`tgl_akhir` >= `tgl_mulai`))``.
- `ROW_FORMAT=DYNAMIC` [K] :1321.

### 2.3 bidang_kursem — `simpeg_prod.sql:245-252`; instansi_kursem — `:1435-1442`

Kedua tabel identik strukturnya (`instansi_kursem`: `id_instansi_kursem`, `instansi_kursem`, `uq_instansi_kursem_nama`).

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_bidang_kursem` | TINYINT NOT NULL AUTO_INCREMENT (signed) | [K] :246 / :1436 — maksimal 127 ID; soft delete tidak membebaskan ID (F9) |
| `bidang_kursem` | VARCHAR(255) NOT NULL | [K] :247 / :1437 |
| `order` | TINYINT NOT NULL DEFAULT 1 | **[V2]** (F9, G-01 #5); tipe & default **[I]** (pola [K] tabel ber-PK TINYINT, D3). Legacy mengurutkan menurut nama (`L_seminar.php:49-50`); impor mengisi urut nama |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :248 / :1438; nilai 10 & COMMENT [V2] (legacy `'1: Aktif, 2: Tidak Aktif'`) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :249 / :1439 |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] :250 / :1440 |

Kunci: `PRIMARY` [K] :251 / :1441; **UNIQUE** `uq_bidang_kursem_nama (bidang_kursem)` / `uq_instansi_kursem_nama (instansi_kursem)` [V2]. Tanpa `created_by`/`updated_by` [K] (F9): aktor tetap tercatat di `audit_logs`.

### 2.4 kantor — DDL legacy tidak tersedia (kolom [K] dari kode, tipe [I])

Urutan kolom mengikuti `set_param_kantor` (`Lm_umum.php:1840-1856`), satu-satunya bukti urutan; `status` dan audit di akhir.

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_kantor` | INT NOT NULL AUTO_INCREMENT | kolom [K] `Lm_umum.php:1599, 1713, 1749`; tipe [I] |
| `order` | INT NOT NULL DEFAULT 1 | kolom [K] :1585, :1636-1640, wajib :1781, :1841; tipe [I] (pola [K] tabel ber-PK INT: `banner` :234, `faq_*`) |
| `nama_kantor` | VARCHAR(255) NOT NULL | kolom [K] wajib :1785, :1842; panjang [I] |
| `alamat` | TEXT NOT NULL | kolom [K] wajib :1789, :1843 (textarea `kantor/form.php:40`); tipe [I] — analog `form_kesehatan.alamat` TINYTEXT, TEXT dipilih agar impor tidak terpotong |
| `id_provinsi` | CHAR(2) NOT NULL | kolom [K] :1793, :1844; tipe [I kuat] — PK `provinsi` CHAR(2) (DBV-001), analog `form_kesehatan` :1216 |
| `provinsi_lain` | VARCHAR(255) NULL | kolom [K] :1797-1801, :1845 (NULL bila bukan 99); tipe [I kuat] (analog `*_lain varchar(255)` :1218) |
| `id_kabupaten` | CHAR(4) NOT NULL | kolom [K] :1581, :1803, :1846 + nama FK ERD; tipe [I kuat]. **Bukan** `id_kabupaten_kota` (`kantor/form.php:119` yang memakai `$dataset['id_kabupaten_kota']` adalah bug view legacy) |
| `kabupaten_lain` | VARCHAR(255) NULL | kolom [K] :1807-1811, :1847; tipe [I kuat]. **Bukan** `kabupaten_kota_lain` |
| `id_kecamatan` | CHAR(7) NOT NULL | kolom [K] :1813, :1848; tipe [I kuat] |
| `kecamatan_lain` | VARCHAR(255) NULL | kolom [K] :1817-1821, :1849; tipe [I kuat] |
| `id_kelurahan` | CHAR(10) NOT NULL | kolom [K] :1823, :1850; tipe [I kuat] |
| `kelurahan_lain` | VARCHAR(255) NULL | kolom [K] :1827-1831, :1851; tipe [I kuat] |
| `kode_pos` | CHAR(5) NULL | kolom [K] :1852 (NULL bila kosong); tipe [I] — aplikasi: tepat 5 digit (F8) |
| `telp`, `faks` | VARCHAR(50) NULL | kolom [K] :1853-1854; tipe [I] (analog terlebar `d_riwayat_cuti.no_telp_saat_cuti varchar(50)`; form legacy tanpa `maxlength`) |
| `remark` | TEXT NULL | kolom [K] :1855; tipe [I] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | kolom [K] :1584, `C_umum.php:1155, 1207`; default 1 [I kuat] (`set_param_kantor` tidak menulis `status`, insert legacy mengandalkan default DB; `C_umum.php:1113` hanya mengisi `$_POST`); nilai 10 & COMMENT [V2] |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [I] — kode legacy tidak menulisnya |
| `created_by` | INT NULL, COMMENT v2 | kolom [K] :1862 (hanya saat tambah); tipe [I] |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [I] |
| `updated_by` | INT NULL, COMMENT v2 | kolom [K] :1865 (hanya saat ubah); tipe [I] |

Kunci:
- `PRIMARY (id_kantor)`; **UNIQUE** `uq_kantor_nama (nama_kantor)` [V2], lingkup **global** (F8).
- 4 KEY + 4 FK, nama [K] ERD, aksi **RESTRICT/RESTRICT** [V2] (aksi legacy tidak diketahui):

  | FK | Kolom | Induk |
  |---|---|---|
  | `fk_id_provinsi_kantor_to_prov` | `id_provinsi` | `provinsi (id_provinsi)` |
  | `fk_id_kabupaten_kantor_to_kab` | `id_kabupaten` | `kabupaten_kota (id_kabupaten_kota)` |
  | `fk_id_kecamatan_kantor_to_kec` | `id_kecamatan` | `kecamatan (id_kecamatan)` |
  | `fk_id_kelurahan_kantor_to_kel` | `id_kelurahan` | `kelurahan (id_kelurahan)` |

- Kolom kode mewarisi `utf8mb4_unicode_ci` = collation PK wilayah pasca-DBV-001. FK string beda collation ditolak MySQL (error 3780), sehingga `CreateKantor` wajib berjalan setelah `2026-09-23-000000_AlterBatch1KeSkemaLegacy`.
- FK per kolom **tidak** memeriksa konsistensi rantai (mis. provinsi 32 dengan kabupaten 3171 milik provinsi 31 diterima DB, dibuktikan schema test). Penegakan rantai ada di aplikasi (`KantorHooks`, F8).
- Keempat kolom kode NOT NULL dengan FK, sehingga kantor berkode LAIN-LAIN (99/9999/9999999/9999999999) baru bisa disimpan setelah baris sentinel ada (migration `100200`, tahap CR-010). Sebelum itu DB menolaknya (error 1452). Dampaknya tidak ada selama `kantor` belum punya endpoint dan impor data kantor belum dijalankan; keduanya baru ada di tahap CR-010 dan sesudahnya.
- Tanpa KEY `order`/`status` (tabel kecil, tidak ada bukti index legacy).
- Konsekuensi untuk rollback DBV-001: selama `kantor` ada, `AlterBatch1KeSkemaLegacy::down()` langsung ditolak MySQL (error 1833 saat MODIFY kolom yang dirujuk, 3780 saat CONVERT collation). Rollback batch normal aman karena `CreateKantor` selalu di-rollback lebih dulu (versi lebih besar di batch yang sama, atau batch yang lebih baru).

### 2.5 Sentinel LAIN-LAIN di tabel wilayah (seed, migration `2026-09-25-100200_SeedWilayahLainLain`, tahap CR-010)

Bagian ini rancangan. File migration-nya belum ada di branch ini dan belum diajukan untuk approval (D5, Bagian 4 #14). Yang diminta dari DB Validator sekarang hanya persetujuan rancangan (Bagian 4 #10).

| Tabel | PK | Induk | Nama | Kolom lain |
|---|---|---|---|---|
| `provinsi` | `'99'` | — | `LAIN-LAIN` | `order` 0, `status` 1, `created_at` = `UTC_TIMESTAMP()`, `updated_at`/`updated_by` NULL |
| `kabupaten_kota` | `'9999'` | `id_provinsi` = `'99'` | `LAIN-LAIN` | `kd_area` NULL, sisanya sama |
| `kecamatan` | `'9999999'` | `id_kabupaten_kota` = `'9999'` | `LAIN-LAIN` | sama |
| `kelurahan` | `'9999999999'` | `id_kecamatan` = `'9999999'` | `LAIN-LAIN` | `kd_pos` NULL, sisanya sama |

Label dan bukti:
- Kode [K]: `Lm_umum.php:1797-1831`, `kantor/form.php`, `controllers/simapi/v1/A_employee.php:438-441`.
- Nama `LAIN-LAIN` dan status 1 **[I kuat]**: `L_user.php:4708-4739` menyalin nama wilayah untuk kode 99/9999/… ke kolom nama NOT NULL (`form_kesehatan.provinsi` :1217); `Tester.php:4009-4015` membandingkan nama salinan itu dengan `'lain-lain'`; filter `id_provinsi!='99'` dipasang di samping `status='1'` (`L_employee.php:18`, `L_user.php:4424, 5568`, `L_alamat.php:52`, `Lm_umum.php:1594`).
- Rantai induk 99 → 9999 → 9999999 → 9999999999 [I kuat]: daftar kabupaten/kecamatan/kelurahan legacy hanya menyaring induk (`Local.php:9-49`), dan tidak pernah menampilkan sentinel di bawah wilayah riil.
- `order` 0 [V2] (D4): di luar urutan tampil; engine CR-010 mengeluarkan sentinel dari lingkup urutan.
- `kd_pos` NULL [K]: kode pos untuk kelurahan sentinel diisi bebas di form (`kantor/form.php:189-190`).
- UNIQUE nama wilayah (`uq_provinsi_nama`, dst.) tidak bentrok selama tidak ada wilayah riil bernama "Lain-lain" (perbandingan tidak peka huruf besar/kecil).

Rencana migration (dikerjakan di tahap CR-010):
- `up()`: pastikan skema wilayah sudah legacy (kolom `provinsi.provinsi` ada), lalu cek keempat PK sentinel. Bila **satu saja** sudah ada, lempar exception yang menyebut tabel dan barisnya (fail-closed, tanpa `INSERT IGNORE`). Lalu 4 `INSERT` dalam satu transaksi (provinsi → kabupaten_kota → kecamatan → kelurahan); gagal → rollback + lempar.
- `down()`: DELETE dalam satu transaksi (kelurahan → … → provinsi), `WHERE pk = <sentinel>`. Bila FK RESTRICT menolak (sentinel masih dirujuk), rollback dan lempar pesan "baris sentinel LAIN-LAIN masih dirujuk … — hapus rujukannya dulu".
- Nilai sentinel ditulis literal di migration (tidak diambil dari kelas aplikasi); test mencocokkan literal migration dengan konstanta sentinel engine.
- Versi `100200` sengaja **lebih kecil** dari `CreateKantor` (`100300`). Urutan rollback "kantor dulu, baru sentinel" hanya terjamin bila keduanya jalan dalam **satu batch**: CodeIgniter menjalankan migration dalam satu batch urut versi dan me-rollback-nya terbalik, sehingga `kantor` di-drop dulu (baris kantor yang merujuk sentinel ikut hilang), baru sentinel dihapus. Di Dev/Production keduanya datang bersama dalam PR `[DBV-003][CR-010]` dan tidak dijalankan sebelum disetujui, jadi selalu satu batch.
- Bila `CreateKantor` sudah jalan lebih dulu (mis. DB lokal/scratch yang menjalankan branch ini sebelum tahap CR-010), `MigrationRunner::latest()` menjalankan `100200` di **batch baru** walaupun versinya lebih kecil. `migrate:rollback` berikutnya menghapus sentinel selagi `kantor` masih ada. `down()` sentinel menolak (FK 1451, pesan "hapus rujukannya dulu") selama ada baris kantor yang merujuk sentinel, dan berhasil bila tidak ada. Untuk menghindarinya, DB seperti itu me-rollback batch DBV-003 dulu (tabelnya masih kosong) sebelum `migrate` tahap CR-010, sehingga keempat migration jatuh dalam satu batch.
- Alternatif versi di atas `100300` ditolak: dalam satu batch (kasus Dev/Production), sentinel justru dihapus sebelum `kantor` di-drop, sehingga rollback gagal setiap kali ada kantor berkode LAIN-LAIN.

Alasan ditunda ke CR-010: bila baris sentinel ada sebelum engine mengenalnya, 2 test generik yang sudah disetujui gagal — `MasterGenericTcTest::testParentMustExistAndBeActiveAndCodeIsImmutable` (memakai induk `9999` sebagai "tidak ada") dan `testFourLevelCascadeOptions` (options provinsi memuat `99`, options kelurahan di bawah `9999999` memuat `9999999999`). Engine dan Config baru boleh diubah setelah CR-009 merge. Dengan fitur sentinel engine (dikeluarkan dari options dan lingkup urutan, tidak bisa diubah/dihapus, tidak bisa menjadi induk) kedua test itu lolos tanpa diubah. Selama migration sentinel belum ada, `kantor` berkode LAIN-LAIN ditolak FK (Bagian 2.4).

### 2.6 Perilaku aplikasi yang bergantung pada skema (bahan CR-010)

Diisi saat CR-010 diimplementasikan. Rencana ringkas (SPEC DBV-003 Bagian 7):
- **Hari libur** (`HariLiburController` + `HariLiburService`): validasi `valid_date[Y-m-d]` (koneksi `strictOn=false` akan menyimpan tanggal tidak valid sebagai `0000-00-00`), `tgl_akhir >= tgl_mulai`, `id_jenis_libur` wajib & aktif, `nama_libur` ≤ 100 karakter, `keterangan` ≤ 65.535 byte. Overlap inklusif terhadap **semua** status dalam transaksi dengan lock tulis serial. Error 1062 (`uq_hari_libur_tgl_mulai`) → 422 `tgl_mulai`; CHECK 3819/4025 → 422 `tgl_akhir`. Daftar LEFT JOIN `jenis_libur` (baris tanpa jenis tetap tampil), urut `tgl_mulai DESC`, filter `?tahun=`, default menyembunyikan status 10. `tanggalLibur()` hanya status 1 — satu-satunya sumber tanggal libur untuk presensi/tukin/uang makan/cuti/konket/LKH Fase 5. `updated_by` diisi saat tambah dan ubah.
- **Engine generik** untuk `jenis-libur`, `bidang-kursem`, `instansi-kursem`, `kantor` (soft delete 10, UNIQUE nama → 422, options UL_ALL).
- **Sentinel** (engine): dikeluarkan dari options dan lingkup urutan; `PUT`/`PATCH status`/`PATCH order`/`DELETE` → 422; tidak boleh menjadi induk entri baru; tetap bisa dibaca lewat `GET {kode}`.
- **`KantorHooks`**: `*_lain` wajib bila level itu sentinel dan dipaksa NULL bila bukan; sentinel berjenjang (level di bawah sentinel wajib sentinel); konsistensi rantai untuk pasangan non-sentinel; `kode_pos` 5 digit (usulan #12: salah satu dari `kelurahan.kd_pos` bila terisi).

## 3. Deviasi dari legacy & nilai [I]

| # | Item | Legacy [K] | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Aksi FK `hari_libur` → `jenis_libur` | `ON DELETE SET NULL ON UPDATE CASCADE` (:1320) | `RESTRICT/RESTRICT`, nama legacy | Aplikasi tidak pernah hard delete (status 10). SET NULL diam-diam membuat libur tanpa jenis (lalu hilang dari daftar legacy yang INNER JOIN). Hapus fisik manual harus anak → induk. MariaDB dapat menghilangkan klausa RESTRICT di `SHOW CREATE TABLE` (nilai default); verifikasi lewat `REFERENTIAL_CONSTRAINTS` |
| 2 | Aksi FK `kantor` → wilayah | Tidak diketahui (ERD tidak menyimpan aksi) | `RESTRICT/RESTRICT`, nama ERD | Sama dengan #1 dan wilayah Batch 1. Konsekuensi: rollback DBV-001 langsung ditolak selama `kantor` ada (Bagian 2.4) |
| 3 | `hari_libur.status` | Tidak ada; hapus = DELETE | TINYINT 1/2/10 DEFAULT 1 | G-01 Keputusan #5. Hanya status 1 dihitung sebagai libur (F2); status 2 = belum berlaku/rencana. Konsumen Fase 5 **wajib** memakai `tanggalLibur()` (legacy membaca tanpa filter status di > 20 lokasi) |
| 4 | CHECK rentang | Hanya datepicker klien (`holiday/form.php:93-94, 108-109`); server tidak mengecek | `chk_hari_libur_rentang` | CHECK pertama di repo. Data legacy dengan `tgl_akhir < tgl_mulai` akan menggagalkan impor (audit 6.3) |
| 5 | UNIQUE `tgl_mulai` + Paket A | Tidak ada; cek overlap hanya di kode dan hanya terhadap baris yang ada (hapus = fisik) | `uq_hari_libur_tgl_mulai`, berlaku untuk status 1/2/10 | Konsisten dengan aturan UNIQUE yang mencakup status 2/10 (DBV-001 #2, DBV-002 D5). Libur yang dihapus (10) harus dipulihkan dulu sebelum tanggalnya dipakai lagi. Overlap tetap dicek service |
| 6 | Tanpa UNIQUE nama `hari_libur` | Tidak ada | Tetap tanpa UNIQUE nama | **Deviasi eksplisit dari aturan UNIQUE nama master** (F4): nama libur berulang tiap tahun ("Tahun Baru Masehi") |
| 7 | `hari_libur.id_jenis_libur` NULL | NULL (:1310) | NULL di DB, **wajib** di aplikasi (F5) | Baris impor tanpa jenis tetap valid di DB; butuh keputusan pemetaan saat impor (6.3 #2) |
| 8 | UNIQUE nama `jenis_libur`, kursem, `kantor` | Tidak ada (DDL kursem hanya PK; `validate_param_kantor` tidak mengecek duplikat) | `uq_jenis_libur_nama`, `uq_bidang_kursem_nama`, `uq_instansi_kursem_nama`, `uq_kantor_nama` (global), termasuk status 2/10 | Aturan UNIQUE nama master (DBV-001 #2). `utf8mb4_unicode_ci` tidak membedakan huruf besar/kecil maupun aksen. Nama yang pernah dihapus (10) tidak bisa dibuat ulang, harus dipulihkan. **Audit duplikat wajib sebelum impor** |
| 9 | `order` kursem | Tidak ada; urut nama (`L_seminar.php:49-50`) | TINYINT NOT NULL DEFAULT 1 | G-01 #5 (semua master wajib `order`). Impor mengisi urut nama |
| 10 | Status 10 & COMMENT | Kursem `'1: Aktif, 2: Tidak Aktif'` (:248, :1438); kantor hanya 1; `jenis_libur` tidak diketahui | COMMENT `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` | Hapus = soft delete 10 (DBV-001 keputusan b) |
| 11 | Sentinel LAIN-LAIN di wilayah | Ada di tabel wilayah legacy [I kuat] | 4 baris di-seed migration, `order` 0, dilindungi engine (tahap CR-010) | F8. Impor wilayah legacy harus melewati 4 PK ini (6.3 #5). Options wilayah untuk semua master lain ikut mengeluarkan sentinel |
| 12 | AUTO_INCREMENT awal, COMMENT kolom audit | `AUTO_INCREMENT=186/14/18`; audit tanpa COMMENT | Tidak ditulis / COMMENT ditambahkan | Preseden DBV-001/002 |
| 13 | Hapus | Hard DELETE (`L_presensi.php:20477`, `Lm_umum.php:1751`) | Soft delete status 10, bisa dipulihkan | FK RESTRICT menjadi lapis kedua |
| 14 | Hak baca hari libur | Role 1/4/5/8 (`Presensi.php:1015`) | Sama dengan legacy (F6) | **Menyimpang** dari `02-MasterData.md:5` dan Matriks Modul G ("seluruh endpoint master data role 1 only"); Matriks tidak memuat endpoint ini. Diikuti keputusan user |
| 15 | G-TC untuk `hari_libur` | — | G-TC #1 (keunikan nama) dan #4 (re-order) tidak berlaku | Unik = `tgl_mulai`, urutan = `tgl_mulai DESC` (tanpa `order`). Diganti test khusus hari libur di CR-010 |
| 16 | Isi kolom audit | Kode legacy tidak menulis `created_at`/`updated_at` (default DB, jam server); kantor `created_by` saat tambah, `updated_by` saat ubah | Ditulis aplikasi (UTC). Pola engine: tabel ber-`created_by` (kantor) mengisi `created_by` saat insert dan `updated_by` saat update; tabel tanpa `created_by` (`jenis_libur`, `hari_libur`) mengisi `updated_by` saat insert dan update | Sama dengan legacy `sp_holiday` dan DBV-002 E1 |

### 3.1 Daftar [I] yang menunggu dump produksi

Butuh `mysqldump --no-data` untuk tabel di bawah **plus** data (atau hasil query audit Bagian 6.3):
- `jenis_libur`: seluruh DDL (panjang nama, tipe `order`/`status`, kolom audit, index) dan isi datanya (apakah hanya 1 = Libur Nasional, 2 = Cuti Bersama, atau ada kategori lain — subjudul `Presensi.php:1027` "Daftar libur dan kegiatan pegawai" mengisyaratkan kemungkinan kategori ketiga).
- `kantor`: tipe setiap kolom, urutan kolom, keberadaan `created_at`/`updated_at`, index, dan aksi keempat FK.
- Keberadaan, nama, status, dan `order` 4 baris sentinel di tabel wilayah produksi.
- DDL tabel wilayah produksi (sisa G-01 8.3).
- `bidang_kursem`, `instansi_kursem`, `hari_libur`: skema [K]; yang perlu hanya datanya.

Nilai [I] lain: `order` kursem TINYINT (#9, D3) dan lingkup UNIQUE `nama_kantor` global (keputusan user F8; alternatif `(id_kelurahan, nama_kantor)`).

## 4. Keputusan yang diminta dari DB Validator (DBV-003)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2.1-2.4 + 3 migration yang ada di branch ini (`100000`, `100100`, `100300`) untuk Dev. Migration sentinel `100200` belum termasuk (belum ada, lihat #10 dan #14) | Setujui. Sebelum dipakai di server, jalankan sekali `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4 (Bagian 6.4). Dijalankan di Dev hanya setelah PR `[DBV-003][CR-010]` (termasuk `100200`) disetujui dan di-merge, supaya keempatnya satu batch (Bagian 2.5) | ⏳ |
| 2 | FK RESTRICT/RESTRICT dengan nama legacy (`hari_libur` dan `kantor`), bukan SET NULL/CASCADE legacy | Setujui (Bagian 3 #1-#2) | ⏳ |
| 3 | CHECK `chk_hari_libur_rentang`, CHECK pertama di repo | Setujui + verifikasi MariaDB 10.4 (error 4025, `SHOW CREATE TABLE`, `information_schema.CHECK_CONSTRAINTS`) | ⏳ |
| 4 | UNIQUE `tgl_mulai` + Paket A (overlap dicek terhadap semua status; libur terhapus dipulihkan dulu sebelum tanggalnya dipakai) | Setujui (keputusan user F2/F3) | ⏳ |
| 5 | Tanpa UNIQUE nama `hari_libur` (deviasi eksplisit) | Setujui (F4) | ⏳ |
| 6 | `id_jenis_libur` NULL di DB, wajib di aplikasi | Pertahankan [K] (F5) | ⏳ |
| 7 | `hari_libur.status` [V2] dengan semantik hanya status 1 dihitung | Setujui | ⏳ |
| 8 | DDL `jenis_libur` [I] (VARCHAR(255), `order` TINYINT, audit Batch 1, UNIQUE nama) | Setujui sementara; cocokkan dengan dump (3.1) | ⏳ |
| 9 | DDL `kantor` [I] (tipe Bagian 2.4, UNIQUE nama global) | Setujui sementara; cocokkan dengan dump; audit data 6.3 | ⏳ |
| 10 | Rancangan 4 baris sentinel LAIN-LAIN (`order` 0, status 1) di wilayah v2 (Bagian 2.5), dilindungi engine; impor wilayah melewati 4 PK ini | Setujui rancangannya (F8). File migration `100200` di-review dan diverifikasi saat diajukan di tahap CR-010 (6.4 h) | ⏳ |
| 11 | Kursem: `order` TINYINT [V2], tanpa `*_by`, UNIQUE nama, PK TINYINT maks 127 | Setujui (F9) | ⏳ |
| 12 | `kode_pos` kantor wajib salah satu dari `kelurahan.kd_pos` bila daftarnya terisi (usulan riset, di luar F8 yang hanya "5 digit") | Ya, di `KantorHooks` | ⏳ |
| 13 | Perubahan `Batch1LegacySchemaTest` (test DBV-001) untuk melepas/memasang dependen wilayah di `setUp()`/`tearDown()` | Setujui — assertion DBV-001 tidak berubah (diff hanya penambahan). Konsekuensi di environment nyata: rollback DBV-001 mensyaratkan DBV-003 di-rollback lebih dulu | ⏳ |
| 14 | Migration sentinel `100200` dipisah ke tahap CR-010 (D5), sehingga `CreateKantor` masuk lebih dulu tanpa baris sentinel; kantor berkode LAIN-LAIN baru bisa disimpan setelah `100200` ada | Setujui. Alasan: 2 test generik yang sudah disetujui gagal bila sentinel ada sebelum engine mengenalnya (Bagian 2.5). Dampak: tidak ada, karena `kantor` belum punya endpoint dan belum diimpor sebelum CR-010. Syarat: keempat migration dijalankan di Dev dalam satu batch (#1) | ⏳ |

## 5. Status keputusan G-01 terkait

Rujukan balik di G-01 ditambahkan di tahap CR-010 (G-01 tidak diubah di branch ini).

| G-01 | Isi | Sebelumnya | Setelah DBV-003 |
|---|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status` | ✅ YA (23-09-2026) | Dijalankan: `jenis_libur.order` ternyata kolom legacy [K kolom]; `hari_libur.status` ditambahkan (tanpa `order`, urut tanggal); kursem ditambah `order` |
| Bagian 3 | Kursem "tanpa definisi kolom"; `kantor` "berbeda dari ERD"; `jenis_libur` tanpa `order` di seed; `hari_libur` tanpa `status` | Menunggu DDL | DDL kursem & `hari_libur` ditemukan; kolom `kantor` dari kode + FK ERD; `order` `jenis_libur` ada di kode legacy; `hari_libur.status` ditambahkan |
| Bagian 7 #1 | Options & rantai induk | Sebagian ditangani DBV-002 | Sentinel dikeluarkan dari options wilayah (tahap CR-010) |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon). Database scratch `simpeg_v2_s_dbv003x7` (DBPrefix kosong, `strictOn=false`) untuk siklus `spark migrate`; database test `simpeg_v2_t_dbv003x7` (DBPrefix `t_`, `strictOn=true`) untuk PHPUnit (`migrate:refresh` otomatis). Database dev `simpeg_v2` tidak disentuh. **Belum diverifikasi di MariaDB 10.4.**

Catatan lingkungan: `vendor/` harus sesuai `composer.lock` (`composer install`). Tanpa paket `ezyang/htmlpurifier` (dipakai `HtmlSanitizer` sejak DBV-002), 10 test FAQ/master generik gagal dengan `Class "HTMLPurifier_Config" not found`. Kegagalan ini tidak terkait skema DBV-003.

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| `php spark migrate --all` (scratch kosong) | 16 migration (3 `CodeIgniter\Queue` + 13 `App`, termasuk 3 migration DBV-003) jalan dalam satu batch; 25 tabel (termasuk `migrations`) |
| `php spark migrate:rollback` (batch tunggal di atas) | Seluruh rantai turun bersih: `CreateKantor` → `CreateKursem` → `CreateHariLibur` → `CreateFaq` → `AlterBatch1KeSkemaLegacy` → … Sisa hanya tabel `migrations` (0 baris), 0 CHECK |
| `migrate --all` dengan DBV-003 sebagai batch sendiri (batch 1 = 13 migration `main`, batch 2 = 3 migration DBV-003) → `migrate:rollback` → `migrate --all` | Rollback hanya men-drop 5 tabel DBV-003: 20 tabel tersisa (`provinsi`, `faq_topic`, dan tabel lain tetap), 0 CHECK tersisa, batch 1 utuh (13 baris). Migrate ulang sukses: 25 tabel, 1 CHECK, batch 2 = 3 baris. `SHOW CREATE TABLE` kelima tabel sesuai Bagian 2 |
| `phpunit --no-coverage tests/MasterData/LiburKantorKursemSchemaTest.php` | `OK (9 tests, 436 assertions)` |
| `phpunit --no-coverage tests/MasterData/Batch1LegacySchemaTest.php` (setelah adaptasi) | `OK (5 tests, 52 assertions)`. Sebelum adaptasi: 4 error (1833 `Cannot change column 'id_provinsi': used in a foreign key constraint 'fk_id_provinsi_kantor_to_prov'`, lalu DB test tertinggal setengah rollback) |
| `phpunit --no-coverage tests/MasterData` | `OK (75 tests, 2943 assertions)` — termasuk `FaqSchemaTest`, `FaqTest`, `MasterGenericTcTest`, `RbacMasterEndpointsTest` tanpa perubahan |
| `phpunit --no-coverage` seluruh `tests/`, dijalankan per direktori berurutan | 282 test: `MasterData` `OK (75 tests, 2943 assertions)`, `Auth` `OK (130 tests, 747 assertions)`, `database` `Tests: 39, Assertions: 148, Failures: 1`, `feature` `OK (14 tests, 117 assertions)`, `session` `OK (1 test, 1 assertion)`, `unit` `OK (23 tests, 99 assertions)`. Satu kegagalan di `database` adalah masalah timing di luar DBV-003: `JwtServiceTest::testRefreshTokenIsStoredAsHashNotPlaintext` membandingkan dengan `time() + 604800`, dan hasilnya bergeser 1 detik. Test itu lolos saat diulang (`OK (1 test, 5 assertions)`) |
| `phpstan analyse --memory-limit=1G` (level 5) pada 5 file PHP baru/diubah | `[OK] No errors` |
| `php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php` pada 5 file PHP baru/diubah | `Found 0 of 5 files that can be fixed` |

Isi `LiburKantorKursemSchemaTest`: kolom, tipe, dan nullable persis Bagian 2 lewat `information_schema`; collation `utf8mb4_unicode_ci` per tabel dan per kolom string; collation 4 kolom kode kantor = collation PK wilayah; InnoDB; row format Dynamic; PRIMARY, 5 UNIQUE, KEY FK; 5 nama FK + kolom induk (`REFERENCED_COLUMN_NAME`, termasuk `kantor.id_kabupaten` → `kabupaten_kota.id_kabupaten_kota`) + `UPDATE_RULE`/`DELETE_RULE` = RESTRICT; CHECK lewat `CHECK_CONSTRAINTS` (disaring schema + nama, clause dinormalkan) dan `SHOW CREATE TABLE`; default dan COMMENT status/order/audit; kursem tanpa `*_by`, `hari_libur` tanpa `order`/`created_by`. Uji perilaku dengan kode error: CHECK menolak `tgl_akhir < tgl_mulai` pada INSERT dan UPDATE (3819/4025) dan menerima tanggal sama; UNIQUE `tgl_mulai` menolak tanggal yang sama dengan baris status 10 (1062); nama libur sama di tahun berbeda diterima; `id_jenis_libur` NULL diterima, id tak dikenal ditolak (1452); hard delete dan ubah PK `jenis_libur` yang dirujuk ditolak (1451); UNIQUE nama 4 master menolak variasi huruf besar dan aksen (1062); PK TINYINT menerima 127 dan menolak 128 (1264); kode wilayah tak dikenal di tiap level kantor ditolak (1452); rantai wilayah tidak konsisten diterima DB; hapus dan ubah PK provinsi/kelurahan yang hanya dirujuk kantor ditolak (1451) lalu berhasil setelah rujukan dihapus; `down()` tiap migration hanya men-drop tabelnya dan `up()` mengembalikan skema persis; `up()` yang gagal di tengah (tabel penghalang, error 1050) men-drop hanya tabel yang dibuat run itu lalu bisa diulang.

### 6.3 Catatan migrasi data untuk Mapping

Salinan lokal `hari_libur` dan kedua tabel kursem berisi 0 baris; `jenis_libur`, `kantor`, dan tabel wilayah tidak ada. Semua butir di bawah hanya bisa diperiksa di data produksi.

1. **`stripslashes`**: legacy menyimpan teks dengan `addslashes` lalu di-escape lagi oleh query builder, sehingga data berisi `\'`/`\"` literal. Terdampak: `hari_libur.nama_libur`, `keterangan` (`L_presensi.php:20558-20560`) dan semua kolom teks `kantor` (`Lm_umum.php:1841-1856`). Tanpa `stripslashes`, backslash tampil di v2 dan mengacaukan pengecekan UNIQUE.
2. **Audit `hari_libur` sebelum impor**: `tgl_akhir < tgl_mulai` (menggagalkan CHECK); `tgl_mulai` ganda (menggagalkan UNIQUE); pasangan rentang overlap (aturan overlap v2 menolak data baru, data lama perlu dirapikan); `id_jenis_libur IS NULL` (butuh keputusan pemetaan karena aplikasi mewajibkan); `tgl_mulai`/`tgl_akhir` bernilai `0000-00-00`.
3. **Duplikat nama** (perbandingan `utf8mb4_unicode_ci`, setelah `stripslashes` dan trim) di `jenis_libur`, `bidang_kursem`, `instansi_kursem`, dan `kantor.nama_kantor` (global). Duplikat harus dirapikan dulu, karena impor akan gagal.
4. **Kantor**: rantai wilayah tidak konsisten (kabupaten bukan anak provinsi, dst.); kode wilayah yang tidak ada di tabel wilayah v2 (menggagalkan FK); `kode_pos` bukan 5 digit (input bebas legacy; kolom CHAR(5) dengan `strictOn=false` memotong diam-diam — **wajib diaudit sebelum impor**); panjang `telp`/`faks` > 50; `order` di luar 1..99 atau ganda (normalkan 1..n); `*_lain` terisi padahal kode bukan sentinel (v2 memaksanya NULL).
5. **Wilayah**: impor tabel wilayah legacy **melewati** 4 PK sentinel yang sudah di-seed migration `100200` (atau membandingkannya). Cek keberadaan, nama, dan status sentinel produksi. Impor `kantor` berkode LAIN-LAIN baru bisa dijalankan setelah `100200` ada (tahap CR-010).
6. **ID legacy** dipakai apa adanya. PK TINYINT (`jenis_libur`, kursem): pastikan semua ID ≤ 127. `AUTO_INCREMENT` naik otomatis setelah impor ID eksplisit.
7. **`order`**: kursem diisi urut nama; `jenis_libur` disalin; kantor dinormalkan 1..n.
8. **`created_by` / `updated_by` legacy** berisi `user.id` akun legacy (`Lm_umum.php:1862, 1865`, `L_presensi.php:20561`), sedangkan v2 berisi `id_pengguna`. Perlu dipetakan bila ID akun tidak dipertahankan.
9. **Waktu**: default DB legacy (jam server) vs v2 UTC, mengikuti keputusan zona waktu A-01.
10. **Status**: kantor legacy hanya 1 (hapus fisik) — salin 1; `hari_libur` tanpa status → diisi 1; kursem dan `jenis_libur` 1/2 salin langsung.
11. **Collation**: tabel legacy `hari_libur` dan kursem sudah `utf8mb4_unicode_ci`, sama dengan v2.

### 6.4 Permintaan verifikasi MariaDB 10.4 (eksplisit, kepada DB Validator)

Mohon dijalankan sekali di MariaDB 10.4 (lingkungan server) sebelum approval, dan hasilnya dicatat di PR:

- [ ] (a) `php spark migrate --all` → `php spark migrate:rollback` → `php spark migrate --all` sukses.
- [ ] (b) `SHOW CREATE TABLE hari_libur` memuat `CONSTRAINT chk_hari_libur_rentang CHECK`.
- [ ] (c) `INSERT` dengan `tgl_akhir < tgl_mulai` ditolak (error 4025); tanggal sama diterima.
- [ ] (d) `SELECT * FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_NAME = 'chk_hari_libur_rentang'` mengembalikan 1 baris.
- [ ] (e) 4 FK `kantor` → wilayah dan FK `hari_libur` → `jenis_libur` terbentuk dengan `UPDATE_RULE`/`DELETE_RULE` RESTRICT di `information_schema.REFERENTIAL_CONSTRAINTS`.
- [ ] (f) UNIQUE nama 1.020 byte terbentuk (row format DYNAMIC).
- [ ] (g) `DATETIME DEFAULT CURRENT_TIMESTAMP` dan `ON UPDATE CURRENT_TIMESTAMP` diterima.
- [ ] (h) Migration sentinel `2026-09-25-100200` up/down. **Belum bisa dijalankan**: file-nya belum ada di branch ini dan diajukan di tahap CR-010 (Bagian 4 #14). Saat itu, uji juga rollback satu batch bersama `CreateKantor` (Bagian 2.5).
- [ ] (i) Bila memungkinkan, jalankan `vendor/bin/phpunit --no-coverage tests/MasterData/LiburKantorKursemSchemaTest.php` di MariaDB (test sudah ditulis portabel: kode error CHECK 3819/4025, normalisasi `tinyint(4)`/`int(11)`, CHECK_CLAUSE tanpa/dengan kurung).

**Pemulihan bila `up()` gagal di tengah** (DDL MySQL/MariaDB ter-commit per statement, migration yang gagal tidak tercatat di tabel `migrations`):
- `CreateHariLibur` dan `CreateKursem` otomatis men-drop tabel yang sempat dibuat **pada run itu** (urutan terbalik, masih kosong) lalu melempar ulang error, sehingga setelah penyebabnya diperbaiki cukup jalankan ulang `php spark migrate` (diuji `LiburKantorKursemSchemaTest::testFailedUpDropsOnlyTablesCreatedInThatRun`). Tabel yang sudah ada sebelum run tidak disentuh.
- `CreateKantor` hanya satu statement CREATE: bila gagal (mis. error 3780 karena skema wilayah belum DBV-001), tidak ada tabel yang tertinggal.
- Bila pembersihan otomatis ikut gagal (mis. koneksi putus), pulihkan manual — **jangan `migrate:rollback` batch**: migration yang gagal tidak tercatat, sehingga rollback justru membatalkan batch terakhir yang tercatat. Drop tabel DBV-003 kosong yang tersisa dengan urutan `kantor`, `hari_libur`, `jenis_libur`, `instansi_kursem`, `bidang_kursem`, lalu `php spark migrate`. Catat kejadian di kartu DBV-003.
- **Rollback DBV-001** (`AlterBatch1KeSkemaLegacy::down()`) ditolak MySQL (error 1833/3780) selama tabel `kantor` ada. Rollback normal per batch aman (DBV-003 lebih dulu); bila DBV-001 perlu di-rollback sendiri, rollback DBV-003 dulu.
- **DB lokal/scratch yang sudah menjalankan 3 migration DBV-003 di tahap ini** (mis. `simpeg_v2_s_dbv003x7`): sebelum `migrate` tahap CR-010, rollback batch DBV-003 dulu (tabel masih kosong), supaya `100200` dan `100300` jatuh dalam satu batch. Bila tidak, `100200` jalan di batch baru dan rollback batch itu menolak selama ada kantor yang merujuk sentinel (Bagian 2.5).
