# DB Validator Review — G-02 Master Jabatan, Unit & Satker (skema legacy)

**Key review:** `DBV-008` (review DB Validator, skema) + `CR-026` (review kode) — satu pull request, judul `[DBV-008][CR-026] …`, branch `dbv-008/g02-jabatan-unit-satker`. Merge hanya setelah **kedua** review setuju. DB Validator hanya me-review/approve; merge dilakukan **user (reviewer CR)** (aturan PR berisi CR + DBV, `AGENTS.md` bagian 2).

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR (DBV-008)** dan review kode (CR-026). Dokumen, migration, dan kode sudah melewati **pra-review internal sisi CR** (bukan review DB Validator). Migration `2026-09-30-100000_CreateMasterJabatanUnitSatker.php` **jangan dijalankan di Dev/Production** sebelum disetujui. G-02 tetap **IN_PROGRESS**: unit ini mengerjakan 6 tabel; `peta_jabatan` (DoD "peta formasi") dan tabel jabatan lain yang tidak punya DDL ditunda ke unit lanjutan (Bagian 7).

**Rujukan:** `02-MasterData.md` G-02 & G-TC, kartu Trello QASMTASK-034 (DoD G-02), `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 1 ("Copy langsung"), Matriks Role x Endpoint Modul G (`hr/master/c_jabatan/*`, role 1), DDL produksi `simpeg_prod.sql` (`group_jabatan` :1294-1303, `jabatan` :1447-1472, `dm_ak_jf` :457-469; HeidiSQL, MySQL 8.0.21), ERD `simpeg01.erd` (nama entity & FK, tanpa kolom), salinan lokal `simpeg_prod_duplikat` (`SHOW CREATE TABLE unit/satker/kelas_jabatan`), kode legacy (`application/libraries/hr/master/Lm_jabatan.php`, `application/controllers/hr/master/C_jabatan.php`, `application/views/hr/master/jabatan/**`, `application/controllers/hr/services/Local.php`, `L_user.php`, `L_employee.php`, `Lsl_gaji.php`), keputusan user 29-09-2026 (ISSUE-012, ISSUE-007, DBV-009 sesudah DBV-008), `G-01-master-schema.md` (Keputusan #5, #8, Bagian 3), preseden `G-06-diklat-hukdis-konket-tanda-jasa-schema.md` (DBV-005, disetujui 29-09-2026).

**Label sumber:**
- **[K]** terkonfirmasi — DDL `simpeg_prod.sql` (nomor baris), ERD `simpeg01.erd`, atau kode legacy (`berkas:baris`).
- **[L]** (label baru, diusulkan di Bagian 4 #1) — `SHOW CREATE TABLE` salinan lokal `simpeg_prod_duplikat`. Asal-usul salinan belum pasti, tetapi tipenya dicek silang dengan [K] (Bagian 1). Dokumen G-04/G-05 memasukkan `SHOW CREATE TABLE` DB lokal ke [K]; di G-02 dipisahkan eksplisit karena tiga tabel sepenuhnya bergantung padanya.
- **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3).
- **[I]** dugaan (tidak ada DDL; wajib dicocokkan dengan dump struktur produksi).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-30-100000_CreateMasterJabatanUnitSatker.php` | 6 tabel: `unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `kelas_jabatan`, `jabatan` (SQL mentah, sadar prefix tabel; `down()` men-drop anak → induk; `up()` yang gagal di tengah membersihkan tabel yang dibuat pada run itu) |
| `tests/MasterData/JabatanUnitSatkerSchemaTest.php` | skema hasil migration dibandingkan dengan Bagian 2 lewat `information_schema`, constraint DB (UNIQUE termasuk lingkup NULL, FK RESTRICT, CHECK, NOT NULL), tanpa baris seed, rollback, `up()` gagal di tengah |
| `app/Config/MasterData.php` (blok `DBV-008`) | 6 entri master + konstanta `DBV008_*` (Bagian 2.9) |
| `app/Controllers/Api/MasterData/JabatanController.php` | controller grup (`02-MasterData.md` G-02 "Files touched"), hanya mendaftarkan key master; route & RBAC dibangkitkan dari config |
| `app/Libraries/MasterData/SubGroupJabatanHooks.php` | tolak pindah group untuk sub group yang sudah dirujuk jabatan (Bagian 4 #13) |
| `app/Libraries/MasterData/MasterDefinition.php`, `MasterRegistry.php`, `MasterService.php`, `app/Controllers/Api/MasterData/BaseMasterController.php` | perluasan engine **CR-026**: opsi `codeAsName` + `idRange` untuk `kelas_jabatan` (Bagian 2.9, Bagian 4 #10) |
| `tests/_support/Database/Seeds/MasterDataSeeder.php`, `tests/_support/MasterDataTestTrait.php` (blok `DBV-008`) | seed G-02 dan fixture G-TC generik (termasuk kunci fixture opsional `update` untuk master yang namanya tidak bisa diubah) |
| `tests/MasterData/JabatanUnitSatkerTest.php` | perilaku khusus G-02 lewat HTTP (12 test, Bagian 2.9) |
| `tests/MasterData/MasterGenericTcTest.php`, `RbacMasterEndpointsTest.php` | loop generik memakai fixture `update` bila ada; `PATCH order` master tanpa kolom `order` = 422 (lolos filter role) |
| `tests/unit/Libraries/MasterRegistryTest.php` | konfigurasi `codeAsName`/`idRange` yang sah & yang ditolak |
| `frontend/src/features/master-data/{types.ts, schemas/master.schema.ts, components/MasterFormDialog.vue}` | meta `code_as_name`/`id_range`: input nama tidak dirender untuk master kode = nama, input kode berlabel nama master dan divalidasi rentang; pilihan ref ke master kode = nama tidak menampilkan kode dua kali. Layout form tidak diubah (ISSUE-007) |
| `frontend/src/features/master-data/__tests__/MasterDataView.g02.spec.ts` | keenam master di halaman Master Data generik (menu ⋮, item urutan per lingkup, tanpa urutan untuk jabatan/kelas) dan form satker/kelas/jabatan |
| `app/Controllers/Api/MasterData/README.md`, `docs/progress/02-MasterData.md` | dokumentasi opsi engine, master G-02, dan progres |

## 1. Latar belakang & keputusan

Sumber per tabel (salinan lain dibandingkan: `simpeg01` identik dengan dump untuk `group_jabatan`/`jabatan`/`dm_ak_jf`; `simpegdev_local` berisi versi seed lama dengan PK VARCHAR, kolom `bup`/`order`, dan status ENUM — **tidak dipakai**, bertentangan dengan [K]):

| Tabel | DDL | Keterangan |
|---|---|---|
| `group_jabatan` | [K] `simpeg_prod.sql:1294-1303` (AUTO_INCREMENT=7) | ERD: 10 FK masuk |
| `jabatan` | [K] `simpeg_prod.sql:1447-1472` (AUTO_INCREMENT=2203; 5 FK :1467-1471, semuanya ON DELETE SET NULL ON UPDATE CASCADE) | FK ke `jenjang_jf` dan `sub_group_jabatan` yang tabelnya tidak ada di dump → dump diimpor dengan FOREIGN_KEY_CHECKS=0 |
| `unit` | [L] 11 kolom | tidak ada di dump (dump berhenti di `jabatan`, urut alfabet) |
| `satker` | [L] 14 kolom, FK `fk_id_unit_satker_to_unit` (SET NULL/CASCADE) | nama FK juga ada di ERD |
| `kelas_jabatan` | [L] PK `kelas_jabatan` TINYINT (bukan AUTO_INCREMENT), `tukin` INT NOT NULL | tipe PK cocok dengan `jabatan.kelas_jabatan` TINYINT + FK [K] :1453/:1471 |
| `sub_group_jabatan` | **tidak ada** di salinan mana pun | [I] (Bagian 2.4); tipe PK dari kolom anak [K] `jabatan.id_sub_group_jabatan` INT :1450 dan `d_riwayat_cuti.id_sub_group_jabatan` INT :710 |

Bukti silang [K] untuk [L]: `d_user.id_unit/id_satker` INT (:774-775) dan `d_lkh.id_unit/id_satker` INT (:675-676) → PK INT; salinan nama `d_lkh.unit/satker` VARCHAR(150) (:680-681) → sama dengan [L] 150; salinan `pegawai_mutasi_jabatan.group_jabatan` VARCHAR(45) di salinan lokal = [K] :1296 (pola salinan nama terbukti), sehingga `pegawai_mutasi_jabatan.sub_group_jabatan` VARCHAR(100) menjadi dasar panjang [I] sub group.

Asal-usul [L]: `simpeg_prod_duplikat` berisi 1 unit, 1 satker, dan 4 kelas jabatan (`created_at` 2026-09-01 14:28) — data dev fitur slip gaji. DDL-nya berkolom legacy lengkap, persis kolom yang ditulis `Lm_jabatan.php` (`set_param_unit` :1407, `set_param_satker` :1743), sehingga dipakai sebagai dasar skema dengan label [L].

Perilaku legacy yang memengaruhi skema [K] (`hr/master/c_jabatan`, semua aksi `userAuth(['1'])`):
- **Hapus keras** untuk semua master G-02 (mis. jabatan `Lm_jabatan.php:167`, unit :1337, satker :1669, group :726, sub group :938, kelas :1146). Hanya `jabatan_koordinasi` yang soft delete (status 2, :514-518).
- **Ubah/hapus hanya baris status 1**: `C_jabatan.php` membaca baris dengan `status NOT IN ('2','10')` (:88, :393, :545, :697, :1153, :1305). Daftar hanya menampilkan status 1.
- **Cek duplikat** hanya terhadap status 1: unit (:1381, :1394), satker per unit (:1718, :1731), group (:766, :779), sub group per group (:983, :997), kelas saat tambah (:1179-1193). Cek duplikat **jabatan dikomentari** (:207-237) → data produksi bisa berisi jabatan ganda.
- **Bug legacy yang tidak ditiru v2:** form unit mengirim `is_upt=2` untuk "TIDAK" (`unit/form.php:44`) padahal konsumen memakai `== '1'`; hapus kelas memanggil `delete_sub_group` (`C_jabatan.php:758`); `delete_akjf` menghapus tabel `unit` dengan `$id` tak terdefinisi (`Lm_jabatan.php:2432-2434`); `edit_periode` memanggil `update_satker` (`C_jabatan.php:1013`); form satker memberi label "Tembusan KPPN" pada `lokasi_kppn` (`satker/form.php:90`).
- **`addslashes`** pada kolom teks saat simpan (mis. `set_param_sub_group` :1011-1012, `set_param_unit` :1412) → data produksi bisa berisi `\'`/`\"` literal (audit 6.5 #2).

### 1.1 Keputusan yang sudah final (tidak dibuka ulang)

| # | Isi | Diterapkan |
|---|---|---|
| G2 | (DBV-001/002/005, disetujui) Status 1/2/10, hapus = soft delete, UNIQUE nama termasuk status 2/10 dan case-insensitive lewat collation, `utf8mb4_unicode_ci` per tabel, FK `ON DELETE RESTRICT ON UPDATE RESTRICT` dengan nama legacy, AUTO_INCREMENT awal tidak ditulis, kolom audit diisi aplikasi (UTC, `id_pengguna`), tipe PK ikut legacy, ID legacy dipakai apa adanya saat impor | semua |
| K1 | PK `pegawai` = `nip`; tabel pegawai baru ada di B-01 | 2.9 (soft delete satker) |
| ISSUE-012 | options master tetap untuk semua role login (UL_ALL) | 2.9 |
| ISSUE-007 | layout form ikut redesign user (MIG-001b) — jangan ubah FormField/layout form | FE hanya logika (`v-if`, label) |
| DBV-009 | ALTER `pengguna.id_unit/id_satker` VARCHAR(10) → INT + FK dilakukan **setelah** DBV-008 di main | di luar lingkup (Bagian 5) |

### 1.2 Usulan (diimplementasikan sesuai usulan, ⏳ menunggu keputusan DB Validator — Bagian 4)

| # | Usulan |
|---|---|
| U1 | Label [L] untuk `unit`, `satker`, `kelas_jabatan` sebagai dasar skema (Bagian 4 #1) |
| U2 | Lingkup: 6 tabel sekarang; `peta_jabatan`, `dm_ak_jf`, `jenjang_jf`, dan tabel tanpa DDL ke unit lanjutan (Bagian 4 #2, #14) |
| U3 | `sub_group_jabatan` [I] minimal + FK dari `jabatan` (Opsi A, Bagian 4 #3) |
| U4 | Kolom `jabatan.id_jenjang_jf` + KEY legacy tetap ada, FK ditunda (Opsi B, Bagian 4 #4) |
| U5 | 5 CHECK [V2] `chk_…` (Bagian 4 #8, #9, #10) |
| U6 | Kelas jabatan dikelola engine generik lewat opsi CR baru `codeAsName` + `idRange` (KJ-1, Bagian 4 #10) |

### 1.3 Konflik yang dicatat

| # | Konflik | Sikap |
|---|---|---|
| 1 | G-01 Keputusan #5 "semua master wajib `order` + `status`" vs `jabatan` [K] dan `kelas_jabatan` [L] tanpa `order` | `jabatan` & `kelas_jabatan` tanpa `order` (Bagian 4 #7): ±2.200 jabatan tanpa semantik urutan (legacy urut kelas/id), kelas = urutan alami nomor. `sub_group_jabatan` [I] diberi `order` [V2] |
| 2 | DoD QASMTASK-034 menyebut `peta_jabatan` | ditunda (Bagian 4 #2, Bagian 7); G-02 tetap IN_PROGRESS |
| 3 | Rencana riset DBV-008 (30-09-2026) memasukkan `dm_ak_jf` [K] ke migration ini | **ditunda** (Bagian 4 #14): FK `dm_ak_jf → pangkat` membuat `down()` migration DBV-004 gagal dijalankan sendiri selama `dm_ak_jf` ada (test rollback G-04/G-05 ikut patah), padahal tabelnya tanpa API/UI sampai B-12 |
| 4 | `Bahan Baku SIMPEG/simpeg_v2_local_seed_legacy.sql:404, :431` melabeli `unit`/`satker` sebagai [K] | salah label: DDL itu tidak ada di dump produksi; file tidak diubah (bukan sumber kebenaran) |
| 5 | Dokumen DBV-011 (branch `dbv-011/…`, runbook :471) dan `02-MasterData.md` bagian engine menyebut `offset_zona_menit` (nama kolom seed lama) | kolom legacy adalah `satker.zonasi` [L]; catatan koreksi untuk penulis DBV-011 (branch itu tidak diubah di sini) |
| 6 | `simpegdev_local` punya `unit`/`satker`/`jabatan`/`jenjang_jf` dengan PK VARCHAR dan status ENUM | rekonstruksi seed lama, bertentangan dengan [K] → hanya pembanding |

## 2. Skema hasil DBV-008

Berlaku untuk keenam tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; kolom string mewarisi collation tabel (legacy menulis collation per kolom, hasilnya sama).
- Nama index/constraint **tanpa** prefix tabel (DBPrefix hanya untuk nama tabel, mis. `t_` di DB test).
- `status TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus'` — tipe & default [K]/[L]; COMMENT [V2] juga untuk tabel [K] (legacy `'1: Active, 2: Inactive, 10: Dihapus'` bermakna sama; preseden `diklat` DBV-005). `group_jabatan.status` tetap `TINYINT(1)` [K].
- Kolom audit pola [K] `group_jabatan`/`jabatan` (sama di ketiga tabel [L]): `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL COMMENT 'id_pengguna yang terakhir mengubah'` (COMMENT [V2]). Tanpa `created_by` dan tanpa FK (preseden DBV-001). Engine (`auditColumns` = `created_at/updated_at/updated_by`) mengisi `created_at` + `updated_at` + `updated_by` saat tambah dan `updated_at` + `updated_by` saat ubah; saudara yang hanya bergeser urutannya tidak di-stamp.
- Nilai `AUTO_INCREMENT` awal tidak ditulis [V2] (legacy `group_jabatan`=7, `jabatan`=2203). Legacy menghapus keras, jadi counter disalin saat impor (6.5 #9).
- UNIQUE nama berlaku juga untuk baris status 2/10, tidak peka huruf besar/kecil maupun aksen [V2, G2]. Key terpanjang `uq_jabatan_nama` = 4 + 4 + 250 × 4 = **1.008 byte** (batas InnoDB 3.072, row format DYNAMIC).
- Tanpa CHECK pada `status` (konsisten dengan DBV-001..005; nilai dijaga engine).

### 2.1 unit — [L] `simpeg_prod_duplikat`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_unit` | INT NOT NULL AUTO_INCREMENT | [L]; tipe [K] `d_user.id_unit` INT :774 |
| `unit` | VARCHAR(150) NOT NULL | [L]; [K] salinan `d_lkh.unit` VARCHAR(150) :680 |
| `is_upt` | TINYINT(1) NOT NULL DEFAULT 0 | [L]; 1 = UPT, 0 = bukan (konsumen `User.php:470-479`, `Ib.php:402`, `Tb.php:403` memakai `== '1'`) |
| `alamat_pdf_header` | TINYTEXT NULL | [L] (kop dokumen PDF) |
| `tembusan_kppn` | VARCHAR(256) NULL | [L] |
| `lokasi_kppn` | VARCHAR(50) NULL | [L] |
| `order` | INT NOT NULL DEFAULT 1 | [L]; urutan global (form legacy "Urutan", mask 1–100) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [L]; COMMENT [V2] |
| `created_at`, `updated_at`, `updated_by` | pola audit di atas | [L] |

Kunci: `PRIMARY (id_unit)` [L]; **UNIQUE** `uq_unit_nama (unit)` [V2]; **CHECK** `chk_unit_is_upt` (`is_upt IN (0, 1)`) [V2].

### 2.2 satker — [L] `simpeg_prod_duplikat`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_satker` | INT NOT NULL AUTO_INCREMENT | [L]; [K] `d_user.id_satker` INT :775 |
| `id_unit` | INT NULL | [L] (NULL-able mengikuti FK legacy SET NULL); wajib di aplikasi |
| `satker` | VARCHAR(150) NOT NULL | [L]; [K] `d_lkh.satker` :681 |
| `is_upt` | TINYINT(1) NOT NULL DEFAULT 0 | [L] |
| `alamat_pdf_header` | TEXT NULL | [L] (unit TINYTEXT, satker TEXT — beda di salinan, diikuti apa adanya) |
| `tembusan_kppn` | VARCHAR(256) NULL | [L] |
| `lokasi_kppn` | VARCHAR(50) NULL | [L] |
| `logo_uns` | VARCHAR(256) NULL | [L]; legacy unggah JPG (form 0,08 MB `satker/form.php:98`, server 838.860 byte `Lm_jabatan.php:1478`); disimpan, belum dikelola v2 |
| `zonasi` | INT NOT NULL DEFAULT 0, COMMENT `'offset jam presensi dari WIB dalam menit: 0 WIB, 60 WITA, 120 WIT'` | [L]; COMMENT [V2] (`simpegdev_local` punya COMMENT serupa). Form legacy "WIB + N menit", mask 0–120 (`satker/form.php:48-58, :131-135`) |
| `order` | SMALLINT NOT NULL DEFAULT 1 | [L]; urutan per unit |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [L] |
| `created_at`, `updated_at`, `updated_by` | pola audit | [L] |

Kunci: `PRIMARY (id_satker)` [L]; **UNIQUE** `uq_satker_nama (id_unit, satker)` [V2]; `KEY fk_id_unit_satker_to_unit (id_unit)` [L]; **FK** `fk_id_unit_satker_to_unit` → `unit(id_unit)` ON DELETE RESTRICT ON UPDATE RESTRICT (nama [L]/ERD, aksi [V2]; legacy SET NULL/CASCADE); **CHECK** `chk_satker_is_upt` (0/1) dan `chk_satker_zonasi` (`zonasi BETWEEN 0 AND 120`) [V2].

### 2.3 group_jabatan — `simpeg_prod.sql:1294-1303` [K]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_group_jabatan` | INT NOT NULL AUTO_INCREMENT | [K] :1295 |
| `group_jabatan` | VARCHAR(45) NOT NULL | [K] :1296 |
| `status` | TINYINT(1) NOT NULL DEFAULT 1, COMMENT v2 | [K] :1297; COMMENT [V2] |
| `order` | TINYINT NOT NULL DEFAULT 1 | [K] :1298 (urutan kolom legacy: `status` sebelum `order`) |
| `created_at`, `updated_at`, `updated_by` | pola audit | [K] :1299-1301 |

Kunci: `PRIMARY (id_group_jabatan)` [K]; **UNIQUE** `uq_group_jabatan_nama (group_jabatan)` [V2].

### 2.4 sub_group_jabatan — [I] (ERD entity + FK; kode `Lm_jabatan.php:938-1018`, form `sub_group/form.php`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_sub_group_jabatan` | INT NOT NULL AUTO_INCREMENT | [I]; tipe dari kolom anak [K] :1450, :710 |
| `id_group_jabatan` | INT NULL | [I] kolom dari kode (form & `set_param_sub_group` :1011); NULL-able seperti kolom FK lain di keluarga ini; wajib di aplikasi |
| `sub_group_jabatan` | VARCHAR(100) NOT NULL | [I]; panjang dari salinan [L] `pegawai_mutasi_jabatan.sub_group_jabatan` VARCHAR(100) |
| `need_satker` | TINYINT NOT NULL DEFAULT 1, COMMENT `'1: Ya, 2: Tidak (jabatan dipilih per satuan kerja di riwayat jabatan)'` | kolom & nilai [K] kode (`:973`, `:1015` → `'2'` atau `'1'`; form radio YA=1/TIDAK=2); tipe [I] |
| `order` | TINYINT NOT NULL DEFAULT 1 | **[V2]** (legacy tanpa `order`: dropdown group 1 urut id, lainnya urut nama — `Local.php:294-307`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [I] (kode membaca `status`) |
| `created_at`, `updated_at`, `updated_by` | pola audit | [I] (meniru `group_jabatan` [K]; kode hanya menulis `updated_by`) |

Kunci: `PRIMARY` [I]; **UNIQUE** `uq_sub_group_jabatan_nama (id_group_jabatan, sub_group_jabatan)` [V2]; `KEY` + **FK** `fk_id_group_jabatan_sgj_to_gj` → `group_jabatan` RESTRICT/RESTRICT (nama ERD [K], aksi [V2]); **CHECK** `chk_sub_group_jabatan_need_satker` (`need_satker IN (1, 2)`) [V2].

### 2.5 kelas_jabatan — [L] `simpeg_prod_duplikat`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `kelas_jabatan` | TINYINT NOT NULL (PK alami, **bukan** AUTO_INCREMENT) | [L]; tipe cocok FK [K] `jabatan.kelas_jabatan` TINYINT :1453/:1471 |
| `tukin` | INT NOT NULL | [L] (nominal tunjangan kinerja per bulan) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [L] |
| `created_at`, `updated_at`, `updated_by` | pola audit | [L] |

Kunci: `PRIMARY (kelas_jabatan)` [L]; **CHECK** `chk_kelas_jabatan_kelas_jabatan` (`kelas_jabatan BETWEEN 1 AND 20`) [V2] — mask form legacy 1–20 (`kelas/form.php:73-74`). Tanpa kolom nama dan tanpa `order`.

### 2.6 jabatan — `simpeg_prod.sql:1447-1472` [K]

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jabatan` | INT NOT NULL AUTO_INCREMENT | [K] :1448 |
| `id_group_jabatan` | INT NULL | [K] :1449; wajib di aplikasi |
| `id_sub_group_jabatan` | INT NULL | [K] :1450; wajib di aplikasi |
| `id_satker` | INT NULL | [K] :1451; opsional (JF/pelaksana tanpa satker) |
| `id_jenjang_jf` | TINYINT NULL | [K] :1452; disimpan, tidak dikelola (Bagian 4 #4) |
| `kelas_jabatan` | TINYINT NULL | [K] :1453; opsional |
| `jabatan` | VARCHAR(250) NOT NULL | [K] :1454 |
| `umur_pensiun` | INT NULL | [K] :1455; aplikasi 50–80 (mask form legacy `jabatan/form.php:164-168`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] :1456; COMMENT [V2] |
| `created_at`, `updated_at`, `updated_by` | pola audit | [K] :1457-1459 |

Kunci: `PRIMARY (id_jabatan)` [K]; **UNIQUE** `uq_jabatan_nama (id_sub_group_jabatan, id_satker, jabatan)` [V2]; `KEY jabatan (jabatan)` [K] :1461; KEY FK legacy [K] :1462-1466 (`fk_id_group_jabatan_jabatan_to_gj`, `fk_id_sub_group_jabatan_jabatan_to_sgj`, `fk_id_satker_jabatan_to_satker`, `fk_kelas_jabatan_jabatan_to_kelasjabatan`, `fk_id_jenjang_jf_jab_to_jenjang_jf`); **4 FK** RESTRICT/RESTRICT (nama [K] :1467-1471, aksi [V2]) ke `group_jabatan`, `sub_group_jabatan`, `satker`, `kelas_jabatan`. FK `fk_id_jenjang_jf_jab_to_jenjang_jf` **tidak dibuat** (Bagian 4 #4).

UNIQUE tidak menegakkan baris ber-`id_satker` NULL (MySQL/MariaDB menganggap NULL berbeda): keunikan nama jabatan tanpa satker per sub group hanya dijaga aplikasi (engine `WHERE id_satker IS NULL` di dalam named lock tabel), dibuktikan `JabatanUnitSatkerSchemaTest` (DB menerima dua baris NULL bernama sama) dan `JabatanUnitSatkerTest` (aplikasi menolaknya). Hal yang sama untuk `satker.id_unit` NULL.

### 2.7 Tabel yang tidak dibuat di DBV-008

- `dm_ak_jf` [K] `simpeg_prod.sql:457-469` (PK komposit `id_jabatan` + `id_pangkat`, FK CASCADE ke `jabatan` & `pangkat`) — ditunda ke B-12 (AK JF) (Bagian 4 #14, 1.3 #3).
- `jenjang_jf` (tanpa DDL; FK dari `jabatan`) — ditunda (Bagian 4 #4, Bagian 7).
- `peta_jabatan`, `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `struktur_jabatan`, `periode_struktur_jabatan`, `jabatan_koordinasi` (tanpa DDL, ISSUE-003) — riset [I] di Bagian 7.

### 2.8 Baris yang di-hard-code kode legacy (didokumentasikan, TIDAK dikunci — pola B6 G-06)

| Tabel | ID | Arti di kode legacy |
|---|---|---|
| `unit` | 6, 7 (± 85 cabang kode), 8, 16, 19 | unit khusus di laporan/presensi/layanan |
| `satker` | 37, 38, 39, 41, 49, 50, 171 | satker khusus (mis. `Siasn.php:3835`, `L_employee.php:6222`) |
| `group_jabatan` | 1 Struktural, 2 JFT, 3 JFU/pelaksana, 4 Kabinet (`Local.php:363-367` "JFT / JF / KABINET"), 5 (`L_user.php:1125`) | percabangan dropdown jabatan per satker dan aturan riwayat |
| `sub_group_jabatan` | 1, 2, 3, 4, 5, 12, 14, 32, 34, 54, 55, 57 Tenaga Ahli, 58 PTT, 60 Staf Khusus | mis. `list_sgj_pj` mengecualikan 57/58/60 (`Local.php:4475-4491`) |

Impor wajib memakai ID legacy apa adanya dan menyalin counter AUTO_INCREMENT (6.5 #9). Tanpa seed di migration (`JabatanUnitSatkerSchemaTest::testMigrationCreatesNoRows`). Konsekuensi tanpa kunci: admin bisa mengganti nama/menonaktifkan baris-baris ini; dampaknya ke B-07/Fase 4–7 ditinjau saat modul itu dikerjakan.

### 2.9 Perilaku aplikasi (CR-026)

Keenam tabel dikelola lewat endpoint master generik (`app/Controllers/Api/MasterData/README.md`) dari entri `Config\MasterData` blok `DBV-008`, controller `JabatanController`. CRUD + `master/meta` = role 1; `{key}/options` = semua role login (ISSUE-012), tanpa token 401.

| Key (`master/{key}`) | Nama (panjang, label) | Induk | Kolom tambahan & aturan | Opsi engine |
|---|---|---|---|---|
| `unit` | `unit` (≤150, "Unit Kerja") | — | `is_upt` "UPT" boolean 1/0; `alamat_pdf_header` textarea ≤255 byte; `tembusan_kppn` ≤256; `lokasi_kppn` ≤50 | `orderColumnType 'int'` |
| `satker` | `satker` (≤150, "Satuan Kerja") | `id_unit` → `unit` (wajib aktif) | `zonasi` "Zonasi Presensi (menit)" int **wajib** 0–120 (hint WIB/WITA/WIT); `is_upt`; `alamat_pdf_header` ≤65.535 byte; `tembusan_kppn`; `lokasi_kppn` | `statusChain`, `hiddenColumns ['logo_uns']`, `orderColumnType 'smallint'` |
| `group-jabatan` | `group_jabatan` (≤45) | — | — | `orderColumnType 'tinyint'` |
| `sub-group-jabatan` | `sub_group_jabatan` (≤100) | `id_group_jabatan` → `group-jabatan` | `need_satker` "Butuh Satuan Kerja" select **wajib** 1 Ya / 2 Tidak | `statusChain`, `hooks SubGroupJabatanHooks`, `orderColumnType 'tinyint'` |
| `kelas-jabatan` | = kode (`kelas_jabatan`, "Kelas Jabatan") | — | `tukin` "Tunjangan Kinerja" int **wajib** 0–2.147.483.647 | `codeAsName`, `idRange [1, 20]`, `idMaxLength 2`, `hasOrder false` |
| `jabatan` | `jabatan` (≤250) | — | `id_group_jabatan` ref **wajib**; `id_sub_group_jabatan` ref **wajib**, `dependsOn id_group_jabatan`; `id_satker` ref opsional; `kelas_jabatan` ref opsional; `umur_pensiun` int opsional 50–80 | `uniqueScope ['id_sub_group_jabatan','id_satker']`, `filters` (4 ref), `hiddenColumns ['id_jenjang_jf']`, `hasOrder false` |

Semua entri: `auditColumns ['created_at','updated_at','updated_by']`, urutan mode `shift` (master ber-`order`), `publicOptions` bawaan (UL_ALL).

- **Dropdown.** `unit/options` → `satker/options?parent={id_unit}` (satker aktif yang unitnya aktif); `group-jabatan/options` → `sub-group-jabatan/options?parent={id_group_jabatan}` (sub group aktif yang group-nya aktif); `jabatan/options?id_sub_group_jabatan=&id_satker=` (setara `list_jabatan_sub_satker` legacy; juga `?id_group_jabatan=`, `?kelas_jabatan=`; urut nama — legacy urut `kelas_jabatan DESC, id ASC`, lihat catatan B-07); `kelas-jabatan/options` (`id` = `nama` = nomor kelas, urut numerik). Nilai filter yang bukan bentuk kode → 422. Tanpa parameter `restrict` (usulan Bagian 8).
- **Satker.** Nama unik per unit (nama sama di unit lain boleh); tambah/pindah ke unit Tidak Aktif/Dihapus/tidak ada → 422 `id_unit`. `zonasi` kosong/`null`/negatif/>120/desimal/teks → 422 `zonasi` (CHECK DB tidak pernah tercapai). `logo_uns` tidak ada di meta/respons dan tidak bisa ditulis (nilai DB dipertahankan).
- **Sub group.** Nama unik per group; `need_satker` selain 1/2 atau kosong → 422. **Pindah group** sub group yang sudah dirujuk jabatan (termasuk jabatan Tidak Aktif/Dihapus) → 422 `id_group_jabatan` "Sub group jabatan ini sudah dipakai N jabatan; pindah group tidak diizinkan. Buat sub group baru di group tujuan." tanpa tulis/audit [V2]; tanpa rujukan → boleh (ditaruh di akhir urutan group baru). Alasan: `jabatan` menyimpan `id_group_jabatan` sendiri [K], sehingga memindah sub group membuat group jabatan-jabatannya basi.
- **Kelas jabatan (CR-026).** Nomor kelas = kode = nama. Tambah: `kelas_jabatan` wajib bilangan bulat 1–20 **tanpa nol di depan** (`0`, `21`, `07`, `7a`, `-1`, `1.5` → 422); nomor yang sudah ada → 422 "Kode 7 sudah dipakai.". Ubah: hanya `tukin`/status; nomor lain di payload → 422 "Kelas Jabatan adalah kode entri dan tidak dapat diubah." (nomor yang sama boleh ikut terkirim — form edit mengirimnya). `GET kelas-jabatan/07` → 404 (bukan alias 7). `PATCH …/order` → 422.
- **Jabatan.** Group wajib; sub group wajib dan harus di bawah group yang dipilih (422 "Sub Group Jabatan … tidak berada di bawah Group Jabatan yang dipilih."), juga saat hanya group yang diubah; group/sub group/satker/kelas wajib ada, bentuk kanonik, dan aktif — hanya diperiksa bila nilainya berubah (jabatan lama yang merujuk entri non-aktif tetap bisa diubah kolom lain, E6). Nama unik per (sub group, satker) termasuk satker kosong. `umur_pensiun` di luar 50–80 → 422; kosong = NULL. `id_jenjang_jf` tidak ada di meta/respons dan tidak bisa ditulis.
- **Soft delete (G-TC #2, DoD "nonaktifkan satker yang masih punya pegawai").** Hapus unit/satker/sub group/kelas yang masih dirujuk = status 10; rujukan tetap utuh, entri hilang dari dropdown, bisa dipulihkan; hard delete ditolak FK RESTRICT (1451). Tabel pegawai baru ada di B-01: B-01 menambah FK `pegawai_mutasi_jabatan.id_satker` → `satker` RESTRICT + test regresi "satker yang punya pegawai".
- **Zonasi presensi (catatan Fase 5).** Legacy membaca `pegawai_mutasi_jabatan.id_satker` → `satker.zonasi` **tanpa filter status** lalu `$dtNow->modify("+{$zonasi} minutes")` (`Local.php:1761-1777`, `:1909-1923`, `L_user.php:2058-2067`). Fase 5 wajib membaca `satker.zonasi` langsung (termasuk satker Tidak Aktif/Dihapus), bukan dari options.
- **Tukin (catatan Fase 5).** `L_employee.php:4470, 5159, 6478, 11591` LEFT JOIN `kelas_jabatan` tanpa filter status; `Lsl_gaji.php:217-232` (fitur 2026-09) memakai `status=1`. Legacy tidak konsisten; diputuskan di Fase 5, bukan G-02.
- **Frontend.** Halaman generik `/master/:entity` dibangun dari `master/meta`: kolom Urutan dan item "Naikkan/Turunkan urutan" di menu ⋮ hanya untuk master ber-`order` (unit & group global, satker per unit, sub group per group; jabatan & kelas tidak pernah); filter 4 ref untuk jabatan; form satker dengan dropdown unit berjenjang dan input angka `zonasi` + hint; form jabatan dengan sub group terkunci sampai group dipilih; form kelas jabatan hanya punya input nomor kelas (berlabel "Kelas Jabatan", validasi 1–20) saat tambah dan tanpa input nama; pilihan ref kelas menampilkan nomor sekali. Dikunci `MasterDataView.g02.spec.ts`. Layout form tidak diubah (ISSUE-007).
- **Fixture test** (`MasterDataSeeder::seedG02`, fixture blok `DBV-008`): 2 unit, 3 satker (satu UPT zonasi 60), 4 group, 5 sub group, kelas 7/9/11/13 dengan tukin [L] 5.079.200 / 6.335.750 / 8.757.600 / 10.936.000, 4 jabatan. Nama contoh [I]; ID kecil, bukan ID hard-coded 2.8.

Perluasan engine CR-026 (generik, bawaan = perilaku lama; README engine "Opsi definisi tambahan (CR-026)"):
- `MasterDefinition`: properti `codeAsName` & `idRange` (+ `fromConfig`, meta `code_as_name`/`id_range`); `columns()` tidak mendaftarkan kolom PK = nama dua kali; kode kanonik `idRange` = `/^[1-9][0-9]*\z/`; konstruktor menolak `idRange` dengan min < 1 atau min > max.
- `MasterRegistry`: `codeAsName` hanya untuk kode manual dengan `nameField` = `primaryKey`; `nameField` = `primaryKey` tanpa `codeAsName` ditolak; `idRange` hanya untuk kode manual tanpa `idDigits`.
- `BaseMasterController::rules()`: rule kode rentang; master `codeAsName` tidak memasang rule nama saat tambah (key sama dengan kode) dan memasang `if_exist|permit_empty` saat ubah agar nilai terkirim diteruskan ke service.
- `MasterService`: tambah memakai kode sebagai nama; ubah menolak nama ≠ kode; `assertNameUnique()` dilewati untuk `codeAsName` (keunikan = cek kode + PRIMARY KEY).
- Test generik: kunci fixture opsional `update` (payload PUT pengganti "ubah nama") di `MasterGenericTcTest::testAuditLogIsRecordedForCreateUpdateDeleteWithActor` dan `RbacMasterEndpointsTest::testSuperAdminPassesFilterOnEveryCrudEndpoint`; `PATCH order` master tanpa `order` di test RBAC = 422 (tetap membuktikan filter role lolos).

Batas yang diketahui:
1. **Balapan lintas tabel.** Tambah jabatan dan pindah group sub group yang berjalan persis bersamaan memakai named lock tabel yang berbeda (`jabatan` vs `sub_group_jabatan`), sehingga keduanya bisa lolos cek masing-masing dan jabatan baru merujuk group lama. Kelas masalah ini sama dengan cek rujukan engine lain (induk/ref tidak dikunci saat dibaca); tulis master hanya oleh Super Admin. Audit 6.5 #4 (konsistensi group jabatan vs group sub group-nya) menangkap sisanya.
2. **Label pilihan satker di form jabatan** tidak berjenjang per unit (kolom `id_unit` bukan kolom `jabatan`), jadi nama satker yang sama di dua unit tampil dua kali dengan kode berbeda ("Bagian Umum (12)", "Bagian Umum (45)"). Perbaikan UX (dropdown bantu unit seperti legacy) → setelah MIG-001b.
3. **Tabel daftar FE generik** hanya menampilkan urutan, kode, nama, induk, status (sama dengan G-04..G-06); kolom tambahan terlihat di form Edit.

Keputusan kode yang diminta dari reviewer CR-026:

| # | Keputusan | Diimplementasikan | Alternatif |
|---|---|---|---|
| C1 | Kelas jabatan lewat engine generik | Opsi `codeAsName` + `idRange` (KJ-1) | KJ-2: service + halaman khusus (setelah MIG-001b); tanpa engine, `jabatan.kelas_jabatan` tidak bisa menjadi field `ref` |
| C2 | Pindah group sub group | Ditolak bila sudah dirujuk jabatan (hook) | Izinkan + perbarui `jabatan.id_group_jabatan` sekaligus (menulis tabel lain dari hook master) |
| C3 | `zonasi` wajib di form | Ya (kosong akan menjadi NULL → 1048 → 500; engine belum punya opsi `default` field) | Opsi engine `default` (sama dengan catatan C1 G-06) |
| C4 | Label | Legacy: "Unit Kerja", "Satuan Kerja", "Group Jabatan", "Sub Group Jabatan", "Kelas Jabatan", "Jabatan", "UPT", "Alamat PDF Header", "Tembusan KPPN", "Umur Pensiun"; v2: "Zonasi Presensi (menit)", "Butuh Satuan Kerja", "Tunjangan Kinerja", "Lokasi KPPN" (legacy salah label) | — |

Catatan modul B/Fase lanjut (bukan pekerjaan master):
- B-07 (riwayat jabatan): dropdown jabatan legacy mengabaikan satker untuk group 2/3/4 dan urut `kelas_jabatan DESC, id ASC` (`Local.php:348-377`); `need_satker` dipakai riwayat jabatan (`Local.php:560, 818, 1326`, `rwy/L_jabatan.php:179, 601`) dan tidak ditegakkan di master; `list_sgj_pj` mengecualikan sub group 57/58/60.
- FK masuk (ERD) yang tipenya wajib cocok nanti (B-01/B-07/Fase 4–7): `pegawai_mutasi_jabatan`/`riwayat_mutasi_jabatan` (group, sub group, unit, satker, jabatan, atasan es1–4), `pegawai_plt`/`plh`, `jabatan_plt`, `pa_layanan`, `news`, `konv_ak`, `riwayat_lckh*`, `peta_jabatan`, `dm_ak_jf`, `pegawai_ak`, `riwayat_ak(_siasn)`. Tipe final: `id_unit`/`id_satker`/`id_jabatan`/`id_group_jabatan`/`id_sub_group_jabatan` **INT signed**; `kelas_jabatan` & `id_jenjang_jf` **TINYINT signed**.
- DBV-009: `pengguna.id_unit/id_satker` VARCHAR(10) (`2026-09-17-000001_CreatePengguna.php:30-31`) vs legacy `d_user` INT [K :774-775]. Yang perlu disesuaikan saat itu: `AuthSeeder` & test Auth memakai `'S01'`/`'S02'` (mis. `SessionRevocationTest.php:102`), claims JWT `id_unit/id_satker`, dan scoping role 3 di `UserService` yang membandingkan string.

## 3. Deviasi dari legacy & nilai [I]/[L]

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Status 10 & COMMENT | Hapus keras semua master G-02; COMMENT `group_jabatan`/`jabatan` `'1: Active, 2: Inactive, 10: Deleted'` | Status 1/2/10 di keenam tabel, hapus = soft delete, COMMENT v2 | Aturan DBV-001/002/005 (G2). Tidak ada hard delete dari aplikasi, jadi FK RESTRICT tidak terpicu dari aplikasi |
| 2 | UNIQUE nama (5 index) | Tidak ada UNIQUE selain PK; cek duplikat hanya status 1; cek jabatan dikomentari | 5 UNIQUE termasuk status 2/10, case-insensitive | **Audit duplikat wajib sebelum impor** (6.5 #1), terutama `jabatan` (cek dikomentari) dan baris status 2 legacy |
| 3 | Lingkup UNIQUE jabatan | — | `(id_sub_group_jabatan, id_satker, jabatan)`; baris ber-`id_satker` NULL hanya dijaga aplikasi | JF/pelaksana tanpa satker; nama jabatan fungsional sama boleh di sub group berbeda |
| 4 | Aksi FK | `jabatan` 5 FK SET NULL/CASCADE [K]; `satker → unit` SET NULL/CASCADE [L] | RESTRICT/RESTRICT, nama legacy | G2; CASCADE/SET NULL hanya berefek pada SQL manual. MariaDB bisa menyembunyikan RESTRICT di `SHOW CREATE TABLE` → diverifikasi lewat `REFERENTIAL_CONSTRAINTS` |
| 5 | FK `jabatan → jenjang_jf` | Ada [K] :1468 | Tidak dibuat; kolom + KEY tetap | Tabel `jenjang_jf` tanpa DDL, tanpa CRUD legacy, di luar DoD (Bagian 4 #4) |
| 6 | 5 CHECK | Tidak ada | `chk_unit_is_upt`, `chk_satker_is_upt`, `chk_satker_zonasi`, `chk_sub_group_jabatan_need_satker`, `chk_kelas_jabatan_kelas_jabatan` | Menegakkan domain form legacy di lapis DB (MySQL 3819 / MariaDB 4025); aplikasi menolak lebih dulu (422). Impor dengan nilai di luar rentang gagal → normalkan (6.5 #3) |
| 7 | `is_upt` unit | Form mengirim `2` untuk "TIDAK" (`unit/form.php:44`), list menganggap ≠0 = YES | 1/0 + CHECK | Konsumen legacy memakai `== '1'`; impor memetakan 2 → 0 |
| 8 | `sub_group_jabatan` | Tanpa DDL | Tabel [I] minimal + FK dari `jabatan` + `order` [V2] | Masuk DoD CRUD; kolom lengkap dari form/validasi legacy; `order` diisi saat impor (6.5 #5) |
| 9 | `order` jabatan & kelas | Tidak ada di [K]/[L] | Tidak ditambah (`hasOrder false`) | G-01 #5 dikecualikan (1.3 #1) |
| 10 | Kelas jabatan | Edit hanya `tukin` (:1200-1211) | Sama: nomor kelas tidak bisa diubah | Nomor = PK alami yang dirujuk jabatan |
| 11 | `need_satker` tipe | Tanpa DDL | TINYINT (tanpa lebar tampilan) | Nilai 1/2 bukan boolean; [I] |
| 12 | AUTO_INCREMENT awal | `group_jabatan`=7, `jabatan`=2203 | Tidak ditulis | Preseden G2; counter disalin saat impor (6.5 #9) |
| 13 | Kolom FK NULL-able | `satker.id_unit`, `jabatan.id_group_jabatan/id_sub_group_jabatan/id_satker/kelas_jabatan` NULL [K]/[L] | Tetap NULL-able (Salin langsung); wajib di aplikasi kecuali satker/kelas jabatan | Audit NULL/yatim sebelum impor (6.5 #2) |

Nilai **[I]/[L]** yang tersisa ada di Bagian 6.4.

## 4. Keputusan yang diminta dari DB Validator (DBV-008)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Label [L] untuk `unit`, `satker`, `kelas_jabatan` | Terima [L] sebagai dasar skema (bukti silang [K] Bagian 1); cocokkan dengan dump penuh (6.4); koreksi lewat ALTER baru | ⏳ |
| 2 | Lingkup DBV-008 | 6 tabel sekarang; `peta_jabatan` + 6 tabel tanpa DDL + `jenjang_jf` ke unit lanjutan (key DBV berikutnya); G-02 tetap IN_PROGRESS. Peta tidak cocok engine (tanpa kolom nama, keunikan memakai `kebutuhan`) dan butuh halaman khusus → setelah MIG-001b (ISSUE-007) | ⏳ |
| 3 | FK `jabatan → sub_group_jabatan` | **Opsi A (diimplementasikan):** tabel `sub_group_jabatan` [I] minimal (2.4) + FK. Opsi B: KEY saja, FK ditunda | ⏳ |
| 4 | `jenjang_jf` | **Opsi B (diimplementasikan):** kolom `jabatan.id_jenjang_jf` TINYINT + KEY legacy, FK + tabel ditunda, kolom tidak dikelola engine (`hiddenColumns`). Opsi A: tabel [I] + engine (urutan manual per `kategori_jf`) — nilai `kategori_jf` belum diketahui, tanpa CRUD legacy, di luar DoD | ⏳ |
| 5 | Aksi FK | RESTRICT/RESTRICT dengan nama legacy (deviasi dari SET NULL/CASCADE) | ⏳ |
| 6 | 5 UNIQUE [V2] | `uq_unit_nama`, `uq_satker_nama (id_unit, satker)`, `uq_group_jabatan_nama`, `uq_sub_group_jabatan_nama (id_group_jabatan, sub_group_jabatan)`, `uq_jabatan_nama (id_sub_group_jabatan, id_satker, jabatan)` (1.008 byte); baris NULL hanya dijaga aplikasi; audit duplikat wajib sebelum impor (6.5 #1) | ⏳ |
| 7 | Kolom `order` vs G-01 #5 | `order` TINYINT [V2] di `sub_group_jabatan` (per group); `jabatan` & `kelas_jabatan` tanpa `order` | ⏳ |
| 8 | `satker.zonasi` | INT NOT NULL DEFAULT 0 [L]; wajib di form; 0–120 menit; CHECK `chk_satker_zonasi` [V2]; audit nilai di luar rentang sebelum impor | ⏳ |
| 9 | `is_upt` unit & satker | TINYINT(1) NOT NULL DEFAULT 0 [L] + CHECK IN (0,1) [V2]; impor memetakan `unit.is_upt=2` → 0 | ⏳ |
| 10 | `kelas_jabatan` | PK alami TINYINT [L] + CHECK 1–20 [V2]; dikelola engine lewat opsi CR-026 `codeAsName` + `idRange` (KJ-1, C1) | ⏳ |
| 11 | `satker.logo_uns` | Disimpan, tidak dikelola/diekspos sampai ada fitur unggah (pola `faq_topic.icon` D6) | ⏳ |
| 12 | `statusChain` | Dropdown `satker` (unit aktif) dan `sub-group-jabatan` (group aktif) memakai rantai status [V2] (pola U3); legacy hanya menyaring status baris sendiri | ⏳ |
| 13 | Pindah group sub group | Ditolak bila sudah dirujuk jabatan (`SubGroupJabatanHooks`, 422) [V2] | ⏳ |
| 14 | `dm_ak_jf` [K] | **Diubah dari rencana riset: ditunda ke B-12 (AK JF)** — FK `dm_ak_jf → pangkat` membuat `down()` migration DBV-004 tidak bisa dijalankan sendiri (test rollback G-04/G-05 patah), tabel tanpa API/UI, CRUD legacy rusak (`delete_akjf`). Alternatif: buat sekarang (DDL [K], FK RESTRICT) + sesuaikan `PangkatPendidikanSchemaTest` | ⏳ |
| 15 | Kolom FK NULL-able | Tetap NULL-able ikut [K]/[L] (Salin langsung); wajib di aplikasi; audit NULL/yatim sebelum impor (6.5 #2) | ⏳ |
| 16 | COMMENT | COMMENT status v2 di keenam tabel termasuk [K] (preseden `diklat` DBV-005); COMMENT `updated_by`, `zonasi`, `need_satker` [V2]. Rencana riset semula mempertahankan COMMENT legacy di tabel [K] — diganti demi konsistensi | ⏳ |
| 17 | ID hard-coded & counter | Tidak dikunci (B6); impor membawa ID legacy + menyalin AUTO_INCREMENT (6.5 #9) | ⏳ |
| 18 | Hubungan dengan DBV-009 | ALTER `pengguna.id_unit/id_satker` → INT + FK RESTRICT hanya setelah DBV-008 di main | ⏳ (keputusan user final; dicatat) |
| 19 | Options `satker` opt-in setara `restrict` untuk role 3 | **Tidak diimplementasikan** — usulan, belum diputuskan user (Bagian 8) | ⏳ (butuh keputusan user) |

Approval tanpa catatan per poin akan dicatat mengikuti kolom **Usulan** (preseden DBV-001/002/005). Koreksi setelah approval dilakukan lewat migration ALTER baru, bukan mengedit migration ini.

## 5. Status keputusan G-01 terkait

| G-01 | Isi | Sebelumnya | Dengan DBV-008 (⏳) |
|---|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status` | ✅ YA (23-09-2026) | Dijalankan untuk unit/satker/group/sub group; `jabatan` & `kelas_jabatan` dikecualikan (Bagian 4 #7) |
| Keputusan #8 | Tipe `pengguna.id_unit/id_satker` | BELUM DIPUTUSKAN | Tipe induk kini INT (unit/satker [L]); ALTER di DBV-009 setelah DBV-008 di main |
| Bagian 3 | Daftar tabel tanpa DDL | menunggu dump (ISSUE-003) | `unit`, `satker`, `kelas_jabatan` [L] dan `sub_group_jabatan` [I] keluar dari daftar bila disetujui; `peta_jabatan`, `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `struktur_jabatan`, `periode_struktur_jabatan`, `jabatan_koordinasi`, `jenjang_jf` tetap. Dokumen G-01 tidak diubah di PR ini |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon, `sql_mode` STRICT_TRANS_TABLES, row format default DYNAMIC). Database test per shard `simpeg_v2_t_dbv08…` (DBPrefix `t_`, `strictOn=true`; migration dijalankan PHPUnit lewat `migrate:refresh`, dibuat & di-drop dengan nama persis). Database scratch `simpeg_v2_s_dbv08c1` (koneksi default, tanpa prefix; nama database diatur di `.env` salinan worktree, lalu `php spark migrate:status` dipastikan membaca database kosong itu sebelum `migrate` dijalankan) untuk siklus `migrate` → `migrate:rollback` → `migrate`, di-drop setelahnya. Verifikasi MariaDB 10.4 diminta dari DB Validator (6.3).

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| `php spark migrate --all` (DB scratch, tanpa migration ini) lalu `php spark migrate --all` | state `main` di batch 1 (23 migration); `CreateMasterJabatanUnitSatker` masuk **batch 2 tersendiri** (24 baris) |
| `php spark migrate:rollback` | hanya batch 2 yang dibatalkan: keenam tabel G-02 hilang; `provinsi`, `pangkat`, `diklat`, `pengguna` tetap ada; `migrations` kembali 23 baris, batch maks 1 |
| `php spark migrate --all` (ulang) | keenam tabel dibuat lagi, batch 2 (24 baris), 0 baris data |
| `information_schema` DB scratch | `TABLE_CONSTRAINTS` keenam tabel: **6 PRIMARY, 5 UNIQUE, 6 FOREIGN KEY, 5 CHECK** (nama `chk_…`) sesuai Bagian 2; `REFERENTIAL_CONSTRAINTS` keenam FK UPDATE/DELETE = RESTRICT; `SHOW CREATE TABLE` di bawah |
| `phpunit tests/MasterData/JabatanUnitSatkerSchemaTest.php` | `OK (6 tests, 250 assertions)` — kolom & tipe persis (termasuk `tinyint(1)` `is_upt`/`group_jabatan.status`, `tinytext`/`text` alamat, `smallint` order satker), collation `utf8mb4_unicode_ci` per tabel & kolom, InnoDB, index persis (termasuk KEY legacy `jabatan` dan `fk_id_jenjang_jf_jab_to_jenjang_jf`), 6 FK + kolom induk + RESTRICT/RESTRICT, tanpa FK `id_jenjang_jf`, 5 CHECK; default & COMMENT; PK `kelas_jabatan` tanpa AUTO_INCREMENT & tanpa default; migration tidak menulis baris; DB menolak nama ganda beda kapitalisasi (termasuk terhadap baris status 10), `is_upt` 2, `zonasi` −1/121, `need_satker` 0/3, kelas 0/21/ganda, `tukin` kosong, rujukan yatim (unit/group/sub group/satker/kelas), nama jabatan NULL, hapus & ganti PK induk yang masih dirujuk (1451); DB **menerima** dua baris ber-induk NULL bernama sama (lingkup NULL hanya aplikasi); `down()` lalu `up()` mengembalikan skema yang sama tanpa menyentuh `provinsi`/`faq_topic`/`pangkat`/`diklat`/`pengguna`; `up()` yang gagal di tengah (penghalang `kelas_jabatan`) men-drop hanya tabel yang dibuat run itu lalu bisa diulang |
| `phpunit tests/MasterData/JabatanUnitSatkerTest.php` (CR-026) | `OK (12 tests, 404 assertions)` — Bagian 2.9 |
| `phpunit` `MasterGenericTcTest`, `RbacMasterEndpointsTest`, `MasterConfigSchemaTest`, `tests/unit/Libraries/MasterRegistryTest` | `OK` — G-TC #1–#6 generik mencakup keenam key G-02 (fixture blok `DBV-008`); `orderColumnType`/`columnType`/boolean cocok dengan DDL; `group-jabatan` ikut uji batas urutan TINYINT 127; registry menerima `codeAsName`/`idRange` kelas jabatan dan menolak 7 konfigurasi salah |
| `vitest run src/features/master-data` | `16 passed` file / `145 passed` test (termasuk `MasterDataView.g02.spec.ts`, 10 test) |
| Quality gate penuh (`fastcheck.py`, langkah = `./check.sh`: PHPStan level 5, PHP-CS-Fixer, PHPUnit 2 shard, ESLint, vue-tsc, Vitest, build) | **lolos** (30-09-2026, basis `origin/main` `89ea98d`): PHPStan `[OK] No errors`; PHP-CS-Fixer `Found 0 of 244`; PHPUnit `OK (290 tests, 10272 assertions)` + `OK (264 tests, 8669 assertions)` = 554 test; Vitest `32 passed` file / `331 passed` test; build lolos. Wajib dijalankan ulang setelah rebase sebelum merge |

`SHOW CREATE TABLE` di DB scratch (MySQL 8.0.30):

```sql
CREATE TABLE `unit` (
  `id_unit` int NOT NULL AUTO_INCREMENT,
  `unit` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_upt` tinyint(1) NOT NULL DEFAULT '0',
  `alamat_pdf_header` tinytext COLLATE utf8mb4_unicode_ci,
  `tembusan_kppn` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lokasi_kppn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order` int NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_unit`),
  UNIQUE KEY `uq_unit_nama` (`unit`),
  CONSTRAINT `chk_unit_is_upt` CHECK ((`is_upt` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `satker` (
  `id_satker` int NOT NULL AUTO_INCREMENT,
  `id_unit` int DEFAULT NULL,
  `satker` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_upt` tinyint(1) NOT NULL DEFAULT '0',
  `alamat_pdf_header` text COLLATE utf8mb4_unicode_ci,
  `tembusan_kppn` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lokasi_kppn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_uns` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zonasi` int NOT NULL DEFAULT '0' COMMENT 'offset jam presensi dari WIB dalam menit: 0 WIB, 60 WITA, 120 WIT',
  `order` smallint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_satker`),
  UNIQUE KEY `uq_satker_nama` (`id_unit`,`satker`),
  KEY `fk_id_unit_satker_to_unit` (`id_unit`),
  CONSTRAINT `fk_id_unit_satker_to_unit` FOREIGN KEY (`id_unit`) REFERENCES `unit` (`id_unit`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_satker_is_upt` CHECK ((`is_upt` in (0,1))),
  CONSTRAINT `chk_satker_zonasi` CHECK ((`zonasi` between 0 and 120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `group_jabatan` (
  `id_group_jabatan` int NOT NULL AUTO_INCREMENT,
  `group_jabatan` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `order` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_group_jabatan`),
  UNIQUE KEY `uq_group_jabatan_nama` (`group_jabatan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `sub_group_jabatan` (
  `id_sub_group_jabatan` int NOT NULL AUTO_INCREMENT,
  `id_group_jabatan` int DEFAULT NULL,
  `sub_group_jabatan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `need_satker` tinyint NOT NULL DEFAULT '1' COMMENT '1: Ya, 2: Tidak (jabatan dipilih per satuan kerja di riwayat jabatan)',
  `order` tinyint NOT NULL DEFAULT '1',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_sub_group_jabatan`),
  UNIQUE KEY `uq_sub_group_jabatan_nama` (`id_group_jabatan`,`sub_group_jabatan`),
  KEY `fk_id_group_jabatan_sgj_to_gj` (`id_group_jabatan`),
  CONSTRAINT `fk_id_group_jabatan_sgj_to_gj` FOREIGN KEY (`id_group_jabatan`) REFERENCES `group_jabatan` (`id_group_jabatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_sub_group_jabatan_need_satker` CHECK ((`need_satker` in (1,2)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `kelas_jabatan` (
  `kelas_jabatan` tinyint NOT NULL,
  `tukin` int NOT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`kelas_jabatan`),
  CONSTRAINT `chk_kelas_jabatan_kelas_jabatan` CHECK ((`kelas_jabatan` between 1 and 20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

CREATE TABLE `jabatan` (
  `id_jabatan` int NOT NULL AUTO_INCREMENT,
  `id_group_jabatan` int DEFAULT NULL,
  `id_sub_group_jabatan` int DEFAULT NULL,
  `id_satker` int DEFAULT NULL,
  `id_jenjang_jf` tinyint DEFAULT NULL,
  `kelas_jabatan` tinyint DEFAULT NULL,
  `jabatan` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `umur_pensiun` int DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_jabatan`),
  UNIQUE KEY `uq_jabatan_nama` (`id_sub_group_jabatan`,`id_satker`,`jabatan`),
  KEY `jabatan` (`jabatan`),
  KEY `fk_id_group_jabatan_jabatan_to_gj` (`id_group_jabatan`),
  KEY `fk_id_sub_group_jabatan_jabatan_to_sgj` (`id_sub_group_jabatan`),
  KEY `fk_id_satker_jabatan_to_satker` (`id_satker`),
  KEY `fk_kelas_jabatan_jabatan_to_kelasjabatan` (`kelas_jabatan`),
  KEY `fk_id_jenjang_jf_jab_to_jenjang_jf` (`id_jenjang_jf`),
  CONSTRAINT `fk_id_group_jabatan_jabatan_to_gj` FOREIGN KEY (`id_group_jabatan`) REFERENCES `group_jabatan` (`id_group_jabatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_id_satker_jabatan_to_satker` FOREIGN KEY (`id_satker`) REFERENCES `satker` (`id_satker`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_id_sub_group_jabatan_jabatan_to_sgj` FOREIGN KEY (`id_sub_group_jabatan`) REFERENCES `sub_group_jabatan` (`id_sub_group_jabatan`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_kelas_jabatan_jabatan_to_kelasjabatan` FOREIGN KEY (`kelas_jabatan`) REFERENCES `kelas_jabatan` (`kelas_jabatan`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 6.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator) — WAJIB sebelum approval

Mohon hasil berikut dilaporkan di PR (poin mana yang lolos/gagal):

1. `php spark migrate` → `php spark migrate:rollback` → `php spark migrate` (migration ini masuk batch tersendiri; rollback hanya membatalkan batch ini). Bersihkan database test lebih dulu bila sebelumnya dipakai branch dengan migration lebih baru (catatan lingkungan DBV-005).
2. `vendor/bin/phpunit --no-coverage tests/MasterData/JabatanUnitSatkerSchemaTest.php` di database test MariaDB (helper test menormalkan lebar tampilan `int(11)`/`tinyint(4)`/`smallint(6)` dan default `'NULL'`/`current_timestamp()` MariaDB; `tinyint(1)` tetap dibandingkan persis).
3. CHECK ditegakkan (MariaDB 4025) dan terdaftar di `information_schema.TABLE_CONSTRAINTS` dengan `CONSTRAINT_TYPE = 'CHECK'`: `is_upt` = 2, `zonasi` = 121, `need_satker` = 3, `kelas_jabatan` = 21.
4. FK RESTRICT dicek lewat `information_schema.REFERENTIAL_CONSTRAINTS` (MariaDB bisa menyembunyikan klausa RESTRICT di `SHOW CREATE TABLE`); hapus induk yang dirujuk ditolak 1451.
5. `COLUMN_TYPE` `tinyint(1)` pada `is_upt` dan `group_jabatan.status`, `tinytext` `unit.alamat_pdf_header`, `smallint` `satker.order`, `kelas_jabatan` tanpa AUTO_INCREMENT.
6. UNIQUE 1.008 byte `uq_jabatan_nama` diterima (row format DYNAMIC).
7. Opsional (perilaku aplikasi di atas MariaDB): `vendor/bin/phpunit --no-coverage tests/MasterData/JabatanUnitSatkerTest.php` dan `MasterGenericTcTest.php` + `RbacMasterEndpointsTest.php`.

### 6.4 Nilai [I]/[L] yang menunggu dump struktur produksi (`mysqldump --no-data` penuh simpeg01)

1. `SHOW CREATE TABLE unit`, `satker`, `kelas_jabatan` produksi: pastikan sama dengan [L] (tipe/panjang, `alamat_pdf_header` TINYTEXT vs TEXT, `order` INT vs SMALLINT, `zonasi`, `logo_uns`, aksi FK `satker → unit`), counter AUTO_INCREMENT.
2. `SHOW CREATE TABLE sub_group_jabatan`: tipe PK, panjang nama (100 [I]), `need_satker` (tipe, default), keberadaan `order`/`created_at`, aksi FK `fk_id_group_jabatan_sgj_to_gj`, nullability `id_group_jabatan`.
3. `jenjang_jf` dan tabel Bagian 7 (untuk unit lanjutan).
4. Tipe kolom anak yang merujuk tabel G-02 (daftar FK masuk di 2.9 "Catatan modul B").
5. Data: 6.5.

Bila dump berbeda: koreksi lewat migration ALTER baru selagi tabel masih kosong (migration ini tidak diedit setelah disetujui).

### 6.5 Audit sebelum impor (SQL baca-saja di salinan data legacy)

Memenuhi janji `DBV-011` (runbook, "UNIQUE dan butir auditnya ditambahkan saat DBV-006..008"): butir di bawah ditambahkan ke runbook impor saat DBV-011 dan DBV-008 sama-sama disetujui. Setiap sesi diawali `SET time_zone = '+00:00';` (DBV-011 R4). Salinan lokal hanya berisi data dev (1 unit, 1 satker, 4 kelas), jadi semua butir hanya bermakna di data produksi.

1. **Duplikat** (perbandingan `utf8mb4_unicode_ci`, setelah trim & `stripslashes` #6, **termasuk status 2/10**) per lingkup UNIQUE:
   ```sql
   SELECT TRIM(unit) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_unit ORDER BY id_unit) ids, GROUP_CONCAT(status) st
     FROM unit GROUP BY TRIM(unit) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT id_unit, TRIM(satker) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_satker) ids
     FROM satker GROUP BY id_unit, TRIM(satker) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT TRIM(group_jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_group_jabatan) ids
     FROM group_jabatan GROUP BY TRIM(group_jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT id_group_jabatan, TRIM(sub_group_jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_sub_group_jabatan) ids
     FROM sub_group_jabatan GROUP BY id_group_jabatan, TRIM(sub_group_jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   -- GROUP BY menyatukan NULL, sama dengan aturan aplikasi untuk jabatan tanpa satker.
   SELECT id_sub_group_jabatan, id_satker, TRIM(jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_jabatan) ids
     FROM jabatan GROUP BY id_sub_group_jabatan, id_satker, TRIM(jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   ```
   Cek duplikat jabatan legacy dikomentari (Bagian 1) → hasil `jabatan` diperkirakan tidak kosong; rapikan bersama admin (gabungkan dan arahkan ulang rujukan riwayat) sebelum impor.
2. **NULL/yatim** (FK + wajib di aplikasi):
   ```sql
   SELECT id_satker FROM satker s WHERE id_unit IS NULL OR NOT EXISTS (SELECT 1 FROM unit u WHERE u.id_unit = s.id_unit);
   SELECT id_sub_group_jabatan FROM sub_group_jabatan s WHERE id_group_jabatan IS NULL OR NOT EXISTS (SELECT 1 FROM group_jabatan g WHERE g.id_group_jabatan = s.id_group_jabatan);
   SELECT id_jabatan FROM jabatan j WHERE id_group_jabatan IS NULL OR id_sub_group_jabatan IS NULL
       OR NOT EXISTS (SELECT 1 FROM group_jabatan g WHERE g.id_group_jabatan = j.id_group_jabatan)
       OR NOT EXISTS (SELECT 1 FROM sub_group_jabatan s WHERE s.id_sub_group_jabatan = j.id_sub_group_jabatan)
       OR (id_satker IS NOT NULL AND NOT EXISTS (SELECT 1 FROM satker s WHERE s.id_satker = j.id_satker))
       OR (kelas_jabatan IS NOT NULL AND NOT EXISTS (SELECT 1 FROM kelas_jabatan k WHERE k.kelas_jabatan = j.kelas_jabatan));
   SELECT DISTINCT id_jenjang_jf FROM jabatan WHERE id_jenjang_jf IS NOT NULL; -- dicocokkan dengan jenjang_jf saat tabel itu dibuat
   ```
3. **Nilai di luar domain CHECK/aplikasi**:
   ```sql
   SELECT id_satker, zonasi FROM satker WHERE zonasi NOT BETWEEN 0 AND 120;
   SELECT 'unit' t, id_unit id, is_upt FROM unit WHERE is_upt NOT IN (0, 1)          -- is_upt 2 (bug form) → 0
   UNION ALL SELECT 'satker', id_satker, is_upt FROM satker WHERE is_upt NOT IN (0, 1);
   SELECT id_sub_group_jabatan, need_satker FROM sub_group_jabatan WHERE need_satker NOT IN (1, 2) OR need_satker IS NULL;
   SELECT kelas_jabatan FROM kelas_jabatan WHERE kelas_jabatan NOT BETWEEN 1 AND 20;
   SELECT id_jabatan, umur_pensiun FROM jabatan WHERE umur_pensiun IS NOT NULL AND umur_pensiun NOT BETWEEN 50 AND 80; -- hanya aturan aplikasi
   SELECT 'unit' t, COUNT(*) FROM unit WHERE status NOT IN (1, 2, 10) UNION ALL SELECT 'satker', COUNT(*) FROM satker WHERE status NOT IN (1, 2, 10)
   UNION ALL SELECT 'jabatan', COUNT(*) FROM jabatan WHERE status NOT IN (1, 2, 10);
   ```
4. **Konsistensi group jabatan** (alasan hook #13): jabatan yang group-nya bukan group sub group-nya.
   ```sql
   SELECT j.id_jabatan, j.id_group_jabatan, s.id_group_jabatan AS group_sub
     FROM jabatan j JOIN sub_group_jabatan s ON s.id_sub_group_jabatan = j.id_sub_group_jabatan
    WHERE NOT (j.id_group_jabatan <=> s.id_group_jabatan);
   ```
5. **Urutan**: `unit.order` global, `satker.order` per unit, `group_jabatan.order` global dinomori ulang 1..n (nilai 0/ganda/di luar tipe); `sub_group_jabatan.order` [V2] diisi: group 1 urut `id_sub_group_jabatan`, group lain urut nama (sama dengan dropdown legacy `Local.php:294-307`). Batas TINYINT 127 per group.
6. **`stripslashes`** kolom teks yang di-`addslashes` legacy (`unit`, `satker`, `alamat_pdf_header`, `tembusan_kppn`, `lokasi_kppn`, `sub_group_jabatan`, `group_jabatan`, `jabatan`): audit pola `\'`, `\"`, `\\` per baris sebelum memutuskan (pola G-06 6.5 #2).
7. **Baris hard-coded 2.8** ada dengan ID tersebut dan namanya sesuai arti di kode legacy.
8. **Audit & zona waktu**: `updated_by` legacy = `user.id` akun legacy → petakan ke `id_pengguna`; `created_at`/`updated_at` jam server → konversi mengikuti keputusan zona waktu global (DBV-011 R1–R4).
9. **Counter AUTO_INCREMENT**: impor dengan ID eksplisit hanya menaikkan counter ke `MAX(id)+1`. Legacy menghapus keras semua master G-02, jadi setelah impor `unit`, `satker`, `group_jabatan` (legacy 7), `sub_group_jabatan`, dan `jabatan` (legacy 2203), bila counter legacy (`SHOW TABLE STATUS` / dump 6.4) lebih besar dari `MAX(id)+1`, jalankan `ALTER TABLE <tabel> AUTO_INCREMENT = <counter legacy>`. `kelas_jabatan` tanpa AUTO_INCREMENT.
10. **Salinan nama di riwayat** (`pegawai_mutasi_jabatan.group_jabatan/sub_group_jabatan/unit/satker/jabatan`, `d_lkh.unit/satker`) disalin apa adanya, jangan di-join ulang ke master.

### 6.6 Pemulihan bila `up()` gagal di tengah

DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`. `up()` otomatis men-drop tabel G-02 yang sempat dibuat **pada run itu** (urutan terbalik, masih kosong) lalu melempar ulang error, sehingga setelah penyebabnya diperbaiki cukup jalankan ulang `php spark migrate` (diuji `JabatanUnitSatkerSchemaTest::testFailedUpDropsOnlyTablesCreatedInThatRun`). Tabel yang sudah ada sebelum run tidak disentuh. Bila pembersihan otomatis ikut gagal (mis. koneksi putus), pulihkan manual — **jangan `migrate:rollback` batch**: migration ini tidak tercatat, sehingga rollback justru membatalkan batch terakhir yang tercatat. Drop tabel G-02 kosong yang tersisa dengan urutan `jabatan`, `kelas_jabatan`, `sub_group_jabatan`, `group_jabatan`, `satker`, `unit`, lalu `php spark migrate`. Catat kejadian di kartu DBV-008.

## 7. Tabel ditunda — hasil riset [I] untuk unit lanjutan

Semua tanpa DDL (ISSUE-003). Tiap tabel akan diajukan dengan key DBV berikutnya setelah dump tersedia atau keputusan [I] diambil; G-02 tetap IN_PROGRESS sampai `peta_jabatan` selesai.

| Tabel | Kolom dari kode legacy [I] | FK (ERD) | Konsumen / catatan |
|---|---|---|---|
| `peta_jabatan` | `id_peta_jabatan` PK, `id_satker`, `id_jabatan`, `id_jabatan_at` (atasan), `kebutuhan` 1–100, `urutan` 1–100, `status` 1/2, `created_by`, `updated_by` (`Lm_jabatan.php:3262-3284`); unik (status 1, satker, jabatan, kebutuhan[, jabatan_at]) :3176-3260; hapus keras :3151 | `fk_peta_jabatan_01/02` → jabatan, `fk_peta_jabatan_03` → satker | DoD G-02 "peta formasi"; kolom "B" (bezetting) dihitung dari pegawai → butuh B-01; form berjenjang ganda (jabatan + atasan) → halaman khusus setelah MIG-001b |
| `rumpun_jabatan` | PK TINYINT (salinan [L] `pegawai_mutasi_jabatan.id_rumpun_jabatan`), `rumpun_jabatan` VARCHAR(50), `order`, `status`, `created_by`, `updated_by` | FK masuk `pegawai_mutasi_jabatan_ibfk_02`, `riwayat_mutasi_jabatan_ibfk_02`, `subrumpun_jabatan_ibfk_01` | B-07 |
| `subrumpun_jabatan` | PK, `id_rumpun_jabatan`, `subrumpun_jabatan` (salinan pmj TEXT → panjang belum pasti), `order`, `status`, `*_by` | → rumpun | `list_subrumpun_jabatan`, B-07 |
| `jabatan_koordinasi` | `id_unit`, `id_satker`, `jenis` 1/2, `jabatan`, `id_atasan_es_1/2/3` → jabatan, `id_atasan_es_3_koord` (self-FK), `kelas_jabatan`, `umur_pensiun`, `status` (hapus = 2, :514-518), `updated_by`; ERD juga `old_id_jabatan` (tidak dipakai kode) | ke jabatan, self | B-07 (PLT/PLH/koordinator) |
| `struktur_jabatan` / `periode_struktur_jabatan` | periode, jabatan, atasan, level, urutan; unik (periode, jabatan, atasan) :1985-2008; kolom denormalisasi `jabatan`, `jabatan_atasan`, `periode_struktur_jabatan` (:2039-2071); periode unik nama semua status (:2229, :2247) | ke jabatan, periode | struktur organisasi (B-19) — dikerjakan bersama task itu |
| `jabatan_akademik` | `jabatan_akademik`, `is_atasan`, `status`, `*_by`; unik nama status 1 | — | tanpa konsumen di luar master → usul cukup disalin saat impor |
| `jenjang_jf` | `id_jenjang_jf`, `jenjang_jf`, `extra_nama_jab`, `kategori_jf`, `order` (level per kategori, `rwy/L_ak.php:319-385`, `function_helper.php:2118`) | FK masuk `fk_id_jenjang_jf_jab_to_jenjang_jf` | tambah FK lewat ALTER setelah audit yatim (6.5 #2) |
| `dm_ak_jf` | DDL [K] :457-469 | → jabatan, pangkat (CASCADE di legacy) | B-12 (1.3 #3, Bagian 4 #14) |

## 8. Usulan tindak lanjut — options `satker` untuk Admin Satker (belum diputuskan user)

Rinciannya di `docs/progress/02-MasterData.md` bagian "Usulan tindak lanjut G-02". Ringkas: legacy `list_satker` punya parameter opt-in `restrict` (hanya dikirim dropdown "Satuan Kerja" form PLT/PLH) yang, untuk pengguna role 3 ber-satker, mengembalikan satker miliknya saja; tanpa parameter semua satker aktif per unit untuk semua role. DBV-008 **tidak** mengimplementasikannya (options tetap UL_ALL, ISSUE-012). Bila user menyetujui, parameter opt-in ditambahkan di unit CR terpisah (butuh `id_satker` INT di `pengguna`, jadi setelah DBV-009); jangan dijadikan pembatasan tanpa syarat untuk role 3.
