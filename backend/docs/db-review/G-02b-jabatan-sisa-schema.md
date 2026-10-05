# DB Validator Review — G-02b Master Jabatan sisa (skema legacy)

**Key review:** `DBV-018` (review DB Validator, skema) + `CR-032` (review kode) — satu pull request, judul `[DBV-018][CR-032] …`, branch `dbv-018/g02-sisa-jabatan`. Branch ini **bertumpuk** di atas `dbv-008/g02-jabatan-unit-satker` (PR DBV-008/CR-026): PR dibuka setelah PR DBV-008 di-merge ke `main`, lalu branch ini disinkronkan dengan `main`. Merge hanya setelah **kedua** review setuju; DB Validator hanya me-review/approve, merge dilakukan **user (reviewer CR)** (aturan PR berisi CR + DBV, `AGENTS.md` bagian 2).

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR (DBV-018)** dan review kode (CR-032). Dokumen, migration, dan kode sudah melewati **pra-review internal sisi CR** (bukan review DB Validator). Migration `2026-09-30-100100_CreateMasterJabatanSisa.php` dan `2026-09-30-100200_AddFkJabatanJenjangJf.php` **jangan dijalankan di Dev/Production** sebelum disetujui. G-02 tetap **IN_PROGRESS**: DoD "peta formasi" butuh halaman khusus (Bagian 4 #9, Bagian 7).

**Rujukan:** `G-02-jabatan-unit-satker-schema.md` (DBV-008; pola dokumen, Bagian 1.4, 6.5, 7), `02-MasterData.md` G-02, kartu Trello QASMTASK-034 (DoD G-02), `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 1 ("Copy langsung"), Matriks Role x Endpoint Modul G (`hr/master/c_jabatan/*`, role 1), **D1** = dump struktur produksi lengkap `simpeg01_struktur_lengkap_20261001.sql` (HeidiSQL, MySQL 8.0.21, tanpa data, termasuk trigger; berkas di luar repo), kode legacy baseline `9545385` (`application/controllers/hr/master/C_jabatan.php`, `application/libraries/hr/master/Lm_jabatan.php`, `application/views/hr/master/jabatan/**`, `application/controllers/hr/Employee.php` menu Data Master, `application/controllers/hr/services/Local.php`, `application/controllers/simapi/v1/A_dm.php`), keputusan proyek (DB v2 terpisah + impor; skema ikut legacy [K]; deviasi [V2] standar).

**Label sumber:**
- **[K]** terkonfirmasi — DDL D1 (`D1:baris`) atau kode legacy (`berkas:baris`, baseline `9545385`).
- **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3).
- **[I]** dugaan — tidak ada nilai skema [I] di dokumen ini; [I] hanya untuk nama contoh di fixture test.

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-30-100100_CreateMasterJabatanSisa.php` | 8 tabel: `jenjang_jf`, `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `periode_struktur_jabatan`, `struktur_jabatan`, `peta_jabatan`, `jabatan_koordinasi` (SQL mentah, sadar prefix; `down()` anak → induk; `up()` yang gagal di tengah membersihkan tabel yang dibuat pada run itu) |
| `app/Database/Migrations/2026-09-30-100200_AddFkJabatanJenjangJf.php` | FK legacy `fk_id_jenjang_jf_jab_to_jenjang_jf` (`jabatan.id_jenjang_jf` → `jenjang_jf`), RESTRICT; `down()` hanya melepas constraint (KEY DBV-008 tetap) |
| `tests/MasterData/JabatanSisaSchemaTest.php` | skema hasil kedua migration dibandingkan dengan Bagian 2 lewat `information_schema`, constraint DB, tanpa baris seed, rollback, `up()` gagal di tengah |
| `tests/MasterData/JabatanUnitSatkerSchemaTest.php` | test DBV-008 disesuaikan: `down()` G-02 dijalankan setelah kedua migration DBV-018 di-down (tabel DBV-018 merujuk `unit`/`satker`/`jabatan`), FK G-02 dibandingkan hanya di antara keenam tabel, FK `jenjang_jf` kini wajib ada |
| `app/Config/MasterData.php` (blok `DBV-018`) | 4 entri master: `rumpun-jabatan`, `subrumpun-jabatan`, `jabatan-akademik`, `periode-struktur-jabatan` (Bagian 2.9) |
| `app/Controllers/Api/MasterData/JabatanController.php` | mendaftarkan 4 key baru |
| `app/Libraries/MasterData/PeriodeStrukturJabatanHooks.php` | nama periode = tahun 4 digit 2000–3000 (Bagian 2.9) |
| `tests/_support/Database/Seeds/MasterDataSeeder.php`, `tests/_support/MasterDataTestTrait.php` (blok `DBV-018`) | seed & fixture G-TC generik keempat master; 8 baris `jenjang_jf` (FK jabatan) |
| `tests/MasterData/JabatanSisaTest.php` | perilaku khusus CR-032 lewat HTTP (Bagian 2.9) |
| `tests/MasterData/JabatanUnitSatkerTest.php` | `testJenjangJfColumnIsNotManaged` memakai jenjang seed (FK kini ada) |
| `frontend/src/features/master-data/__tests__/MasterDataView.g02b.spec.ts` | keempat master di halaman Master Data generik (menu ⋮, urutan per lingkup, tanpa urutan untuk jabatan akademik & periode) dan form jabatan akademik/sub rumpun. Halaman generik tidak diubah (meta-driven) |
| `app/Controllers/Api/MasterData/README.md`, `docs/progress/02-MasterData.md`, `docs/db-review/G-02-jabatan-unit-satker-schema.md` | dokumentasi master baru dan rujukan silang |

## 1. Latar belakang & keputusan

DBV-008 (PR G-02 bagian pertama) membuat 6 tabel dan menunda 8 tabel jabatan lain karena belum ada DDL (G-02 Bagian 2.7, 7). Dump struktur produksi lengkap D1 (01-10-2026) memuat DDL kedelapannya, sehingga semuanya diajukan sebagai **CREATE [K]** (keputusan proyek: DB v2 terpisah + impor; in-place batal).

| Tabel | DDL D1 | AUTO_INCREMENT D1 | Admin UI legacy (menu Data Master, `Employee.php`) | Di v2 (CR-032) |
|---|---|---|---|---|
| `jenjang_jf` | D1:1787-1796 | 9 | **tidak ada** (dibaca `rwy/L_ak.php`, peta, `jabatan.id_jenjang_jf`) | tabel + FK dari `jabatan`; tanpa CRUD |
| `rumpun_jabatan` | D1:6678-6688 | 4 | `c_jabatan/rumpun` | **engine generik** `rumpun-jabatan` |
| `subrumpun_jabatan` | D1:7165-7178 | 88 | `c_jabatan/subrumpun` | **engine generik** `subrumpun-jabatan` |
| `jabatan_akademik` | D1:1495-1505 | 43 | `c_jabatan/jabaka` | **engine generik** `jabatan-akademik` |
| `periode_struktur_jabatan` | D1:3880-3888 | 5 | `c_jabatan/periode` | **engine generik** `periode-struktur-jabatan` |
| `struktur_jabatan` | D1:7087-7107 | 1227 | `c_jabatan/struktur` | tabel saja; CRUD halaman khusus (Bagian 4 #10) |
| `peta_jabatan` | D1:3893-3912 | 1940 | `c_jabatan/peta_jab` (DoD "peta formasi") | tabel saja; CRUD halaman khusus (Bagian 4 #9) |
| `jabatan_koordinasi` | D1:1510-1545 | 462 | `c_jabatan/list_koord` | tabel saja; CRUD halaman khusus (Bagian 4 #11) |

Trigger: D1 tidak memuat trigger untuk kedelapan tabel ini (trigger G-02 hanya di `jabatan` dan `satker`, G-02 Bagian 1.4).

Perilaku legacy yang memengaruhi skema (`hr/master/c_jabatan`, semua aksi `userAuth(['1'])`):
- **Hapus keras** rumpun (`Lm_jabatan.php:2631-2662`), sub rumpun (:2862-2893), peta (:3132-3174), jabatan akademik (:3400-3431), periode (:2183), struktur (:1936). Hanya `jabatan_koordinasi` yang soft delete (status 2, :501-536).
- **Cek duplikat** hanya terhadap status 1: rumpun (:2687, :2700), sub rumpun per rumpun (:2923, :2937), jabatan akademik (:3460, :3473); periode terhadap semua status (:2232, :2251); struktur per (periode, jabatan, atasan) tanpa filter status (:2003, :2022); peta per (status 1, satker, jabatan, **kebutuhan**[, atasan]) (:3218, :3243).
- **Kolom denormalisasi** `struktur_jabatan.periode_struktur_jabatan/jabatan/jabatan_atasan` diisi dari tabel induk saat simpan (`set_param_struktur` :2039-2071).
- **Atasan jabatan koordinasi** dipilih dari `struktur_jabatan` periode aktif terbaru (es1/es2/es3) atau jabatan koordinasi jenis 1 di satker yang sama, digabung dalam satu dropdown `struk_<id>`/`koord_<id>` (`form_koord` :293-408, `set_param_koord` :565-602).
- **`addslashes`** pada kolom teks saat simpan (pola G-02 6.5 #6).
- **Konsumen di luar admin:** `struktur_jabatan` + `periode_struktur_jabatan` dibaca API `simapi/v1/A_dm.php:265-274` (periode aktif terbaru); `subrumpun_jabatan` lewat `hr/services/Local.php:309` (`list_subrumpun_jabatan`); rumpun/sub rumpun/jabatan akademik/periode dipakai riwayat jabatan (`rwy/L_jabatan.php`, B-07).

### 1.1 Keputusan yang sudah final (tidak dibuka ulang)

| # | Isi | Diterapkan |
|---|---|---|
| G2 | (DBV-001/002/005) Status 1/2/10, hapus = soft delete, UNIQUE nama termasuk status 2/10 dan case-insensitive lewat collation, `utf8mb4_unicode_ci`, FK RESTRICT/RESTRICT dengan nama legacy, AUTO_INCREMENT awal tidak ditulis, kolom audit diisi aplikasi (UTC, `id_pengguna`), tipe PK ikut legacy, ID legacy dipakai apa adanya saat impor | semua |
| P1 | DB v2 terpisah + impor (in-place batal); skema ikut DDL [K]; migration di `main` tidak diedit | migration baru, FK `jenjang_jf` lewat migration terpisah (bukan mengedit DBV-008) |
| ISSUE-012 | options master UL_ALL | 4 master engine |
| ISSUE-007 | layout form ikut redesign (MIG-001b) | tidak ada halaman/form FE baru di CR-032 |
| AGENTS.md §1 | aksi baris lewat menu ⋮ `RowActionsMenu` | halaman generik (sudah ⋮), dikunci `MasterDataView.g02b.spec.ts` |

### 1.2 Usulan (diimplementasikan sesuai usulan, ⏳ menunggu keputusan — Bagian 4)

| # | Usulan |
|---|---|
| U1 | 8 tabel [K] D1 + deviasi [V2] standar (Bagian 4 #1–#8) |
| U2 | FK `jabatan → jenjang_jf` lewat migration terpisah (Bagian 4 #2) |
| U3 | CRUD engine generik untuk 4 master yang punya UI admin legacy dan cocok engine; `peta_jabatan`, `struktur_jabatan`, `jabatan_koordinasi` ke halaman khusus; `jenjang_jf` tanpa CRUD (Bagian 4 #9–#12) |

### 1.3 Konflik yang dicatat

| # | Konflik | Sikap |
|---|---|---|
| 1 | DoD QASMTASK-034 "peta formasi" | skema dibuat sekarang; CRUD peta butuh halaman khusus (tanpa kolom nama, keunikan legacy memakai `kebutuhan`, kolom "B" bezetting dari pegawai B-01) → G-02 tetap IN_PROGRESS (Bagian 4 #9) |
| 2 | Rencana awal (todo J4.02) menyebut key `DBV-008b` | key final **DBV-018** (DBV-010+ untuk pekerjaan DBV baru) |
| 3 | Tabel DBV-018 merujuk `unit`/`satker`/`jabatan` (DBV-008) | `down()` migration DBV-008 tidak bisa dijalankan sendiri selama tabel DBV-018 ada (sama dengan FK masuk mana pun, mis. B-01 nanti). `migrate:rollback` tetap benar (batch dibatalkan anak dulu). Test DBV-008 disesuaikan (Bagian 6.2) |
| 4 | `struktur_jabatan.jabatan` VARCHAR(100) [K] vs `jabatan.jabatan` VARCHAR(250) [K] | diikuti apa adanya; salinan nama yang lebih panjang dari 100 karakter diaudit saat impor (6.5 #4) dan saat halaman struktur dibuat |

## 2. Skema hasil DBV-018

Berlaku untuk kedelapan tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; kolom string mewarisi collation tabel. `struktur_jabatan` legacy `utf8mb4_0900_ai_ci` (D1:7107, kolom `periode_struktur_jabatan` D1:7092 eksplisit 0900) → `utf8mb4_unicode_ci` [V2, G2].
- Nama index/constraint **tanpa** prefix tabel.
- `status TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus'` — lihat per tabel untuk asal kolomnya.
- Kolom audit **mengikuti kolom legacy per tabel** (ada/tidaknya `created_by`; `jenjang_jf` tanpa `*_by`), dengan COMMENT [V2] `created_by` `'id_pengguna pembuat'` dan `updated_by` `'id_pengguna yang terakhir mengubah'` (preseden FAQ DBV-002 dan DBV-008). Tanpa FK pada `*_by`.
- Nilai `AUTO_INCREMENT` awal tidak ditulis [V2]; counter D1 di Bagian 1 (disalin saat impor, 6.5 #7).
- Tanpa CHECK pada `status` (konsisten DBV-001..008).

### 2.1 jenjang_jf — [K] D1:1787-1796

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenjang_jf` | TINYINT NOT NULL AUTO_INCREMENT | [K] D1:1788; cocok `jabatan.id_jenjang_jf` TINYINT D1:1453 |
| `kategori_jf` | ENUM('Ahli','Terampil') NOT NULL DEFAULT 'Ahli' | [K] D1:1789 (ENUM dipertahankan: nilai teks bisnis, bukan status) |
| `jenjang_jf` | VARCHAR(50) NOT NULL | [K] D1:1790 |
| `extra_nama_jab` | VARCHAR(50) NOT NULL | [K] D1:1791 (akhiran nama jabatan JF) |
| `order` | TINYINT NOT NULL DEFAULT 1 | [K] D1:1792 (level per kategori, `rwy/L_ak.php:319-385`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | **[V2]** (legacy tanpa status; Bagian 4 #3) |
| `created_at`, `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP / DATETIME NULL ON UPDATE | [K] D1:1793-1794 (tanpa `*_by`) |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_jenjang_jf_nama (kategori_jf, jenjang_jf)` [V2]. FK masuk: `fk_id_jenjang_jf_jab_to_jenjang_jf` dari `jabatan` (migration `100200`, RESTRICT [V2]; legacy SET NULL/CASCADE D1:1469).

### 2.2 rumpun_jabatan — [K] D1:6678-6688

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_rumpun_jabatan` | TINYINT NOT NULL AUTO_INCREMENT | [K] D1:6679 |
| `rumpun_jabatan` | VARCHAR(50) NOT NULL | [K] D1:6680 |
| `order` | TINYINT NOT NULL DEFAULT 0 | [K] D1:6681 (urutan global; form legacy 1–100) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | legacy `ENUM('1','2')` D1:6682 → **TINYINT 1/2/10 [V2]** (Bagian 4 #4) |
| `created_at`, `created_by`, `updated_at`, `updated_by` | pola audit | [K] D1:6683-6686 |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_rumpun_jabatan_nama (rumpun_jabatan)` [V2].

### 2.3 subrumpun_jabatan — [K] D1:7165-7178

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_subrumpun_jabatan` | TINYINT NOT NULL AUTO_INCREMENT | [K] D1:7166 (counter D1 = 88, batas TINYINT 127 — Bagian 4 #13) |
| `id_rumpun_jabatan` | TINYINT NULL | [K] D1:7167; wajib di aplikasi |
| `subrumpun_jabatan` | VARCHAR(255) NOT NULL | [K] D1:7168 |
| `order` | TINYINT NOT NULL DEFAULT 0 | [K] D1:7169 (urutan per rumpun) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | legacy `ENUM('1','2')` D1:7170 → **TINYINT [V2]** |
| `created_at`, `created_by`, `updated_at`, `updated_by` | pola audit | [K] D1:7171-7174 |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_subrumpun_jabatan_nama (id_rumpun_jabatan, subrumpun_jabatan)` [V2] (`id_rumpun_jabatan` NULL-able → baris ber-NULL hanya dijaga aplikasi; Bagian 4 #5); `KEY subrumpun_jabatan_ibfk_01` [K] D1:7176; **FK** `subrumpun_jabatan_ibfk_01` → `rumpun_jabatan` RESTRICT/RESTRICT (nama [K] D1:7177, aksi [V2]; legacy CASCADE/CASCADE).

### 2.4 jabatan_akademik — [K] D1:1495-1505

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jabatan_akademik` | INT NOT NULL AUTO_INCREMENT | [K] D1:1496 |
| `jabatan_akademik` | VARCHAR(255) NOT NULL | [K] D1:1497 |
| `is_atasan` | TINYINT NOT NULL DEFAULT 2 COMMENT '1: Ya, 2: Tidak' | [K] D1:1498 (COMMENT legacy sudah Bahasa Indonesia, dipertahankan) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] D1:1499 (COMMENT legacy '1: Aktif, 2: Tidak Aktif' → v2) |
| `created_at`, `created_by`, `updated_at`, `updated_by` | pola audit | [K] D1:1500-1503 |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_jabatan_akademik_nama` [V2]; **CHECK** `chk_jabatan_akademik_is_atasan` (`is_atasan IN (1, 2)`) [V2]. Tanpa `order` (legacy urut nama).

### 2.5 periode_struktur_jabatan — [K] D1:3880-3888

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_periode_struktur_jabatan` | INT NOT NULL AUTO_INCREMENT | [K] D1:3881 |
| `periode_struktur_jabatan` | VARCHAR(45) NOT NULL | [K] D1:3882; isi = tahun (form legacy 2000–3000) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] D1:3883 |
| `created_at`, `updated_at`, `updated_by` | pola audit (tanpa `created_by`) | [K] D1:3884-3886 |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_periode_struktur_jabatan_nama` [V2] (legacy cek semua status). Tanpa `order`.

### 2.6 struktur_jabatan — [K] D1:7087-7107

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_struktur_jabatan` | INT NOT NULL AUTO_INCREMENT | [K] D1:7088 |
| `id_periode_struktur_jabatan`, `id_jabatan`, `id_jabatan_atasan` | INT NULL | [K] D1:7089-7091 (NULL-able mengikuti FK SET NULL legacy; wajib di form legacy) |
| `periode_struktur_jabatan` | VARCHAR(45) NOT NULL | [K] D1:7092 (salinan nama periode) |
| `jabatan` | VARCHAR(100) NOT NULL | [K] D1:7093 (salinan nama jabatan; 1.3 #4) |
| `jabatan_atasan` | VARCHAR(100) NULL | [K] D1:7094 |
| `level_struktur` | INT NOT NULL COMMENT '1: Menteri, 2: Es.I, 3: Es.II, 4: Es.III, 5: Es.IV' | [K] D1:7095 |
| `urutan` | INT NOT NULL DEFAULT 1 | [K] D1:7096 |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | **[V2]** (legacy tanpa status, hapus keras; Bagian 4 #3) |
| `created_at`, `updated_at`, `updated_by` | pola audit | [K] D1:7097-7099 |

Kunci: `PRIMARY` [K]; **UNIQUE** `uq_struktur_jabatan (id_periode_struktur_jabatan, id_jabatan, id_jabatan_atasan)` [V2] (aturan legacy :2003, :2022; baris ber-NULL hanya dijaga aplikasi); 3 KEY [K] D1:7101-7103; **3 FK** RESTRICT (nama [K] D1:7104-7106, aksi [V2]; legacy SET NULL/CASCADE) ke `jabatan` ×2 dan `periode_struktur_jabatan`; **CHECK** `chk_struktur_jabatan_level_struktur` (`BETWEEN 1 AND 5`) [V2].

### 2.7 peta_jabatan — [K] D1:3893-3912

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_peta_jabatan` | INT NOT NULL AUTO_INCREMENT | [K] D1:3894 |
| `id_satker` | INT NOT NULL | [K] D1:3895 |
| `id_jabatan` | INT NOT NULL | [K] D1:3896 |
| `id_jabatan_at` | INT NULL | [K] D1:3897 (jabatan atasan) |
| `kebutuhan` | INT NOT NULL DEFAULT 0 | [K] D1:3898 (formasi "K"; form legacy 1–100) |
| `urutan` | INT NOT NULL DEFAULT 1 | [K] D1:3899 |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] D1:3900 (legacy tanpa COMMENT) |
| `created_at`, `created_by`, `updated_at`, `updated_by` | pola audit | [K] D1:3901-3904 |

Kunci: `PRIMARY` [K]; 3 KEY [K] D1:3906-3908 (`fk_peta_jabatan_02` legacy `USING BTREE` = bawaan); **3 FK** RESTRICT (nama [K] D1:3909-3911; legacy 01/03 CASCADE/CASCADE, 02 SET NULL/CASCADE); **CHECK** `chk_peta_jabatan_kebutuhan` (`kebutuhan >= 0`) [V2]. **Tanpa UNIQUE** (Bagian 4 #6).

### 2.8 jabatan_koordinasi — [K] D1:1510-1545

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jabatan_koordinasi` | INT NOT NULL AUTO_INCREMENT | [K] D1:1511 |
| `old_id_jabatan` | INT NULL | [K] D1:1512 (jejak konversi lama; tidak dipakai kode) |
| `id_unit`, `id_satker` | INT NULL | [K] D1:1513-1514 (wajib di form legacy) |
| `id_atasan_es_1`, `id_atasan_es_2`, `id_atasan_es_3` | INT NULL | [K] D1:1515-1517 (→ `jabatan`) |
| `id_atasan_es_3_koord` | INT NULL | [K] D1:1518 (→ diri sendiri, atasan koordinator) |
| `old_id_atasan_es_3` | INT NULL | [K] D1:1519 |
| `jenis` | TINYINT NOT NULL DEFAULT 1 COMMENT '1: Koordinator, 2: Sub Koordinator' | [K] D1:1520 |
| `jabatan` | VARCHAR(255) NOT NULL | [K] D1:1521 |
| `kelas_jabatan` | TINYINT NULL | [K] D1:1522 (tanpa FK di legacy; dipertahankan, Bagian 4 #7) |
| `umur_pensiun` | TINYINT NULL | [K] D1:1523 |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] D1:1524 (legacy 1/2, hapus = 2) |
| `id_rmj` | INT NULL | [K] D1:1525 (tanpa FK) |
| `nip` | VARCHAR(30) NULL | [K] D1:1526 (tanpa FK; `pegawai` baru ada di B-01) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] D1:1527 |
| `updated_at` | DATETIME **NOT NULL** DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [K] D1:1528 (berbeda dari tabel lain, diikuti) |
| `updated_by` | INT NULL | [K] D1:1529 |

Kunci: `PRIMARY` [K]; 7 KEY [K] D1:1531-1537; **7 FK** RESTRICT/RESTRICT (nama [K] D1:1538-1544; legacy SET NULL/CASCADE, `fk_old_id_jabatan_jabkoor_to_jab` CASCADE/CASCADE) ke `jabatan` ×4, `jabatan_koordinasi` (diri sendiri), `satker`, `unit`; **CHECK** `chk_jabatan_koordinasi_jenis` (`jenis IN (1, 2)`) [V2]. **Tanpa UNIQUE** (Bagian 4 #6).

### 2.9 Perilaku aplikasi (CR-032)

Empat master dengan UI admin legacy yang cocok dengan engine master generik (punya kolom nama, keunikan berbasis nama) dikelola lewat endpoint master generik dari entri `Config\MasterData` blok `DBV-018`, controller `JabatanController`. CRUD + `master/meta` = role 1; `{key}/options` = semua role login (ISSUE-012), tanpa token 401. Halaman FE = halaman Master Data generik (meta-driven, aksi baris lewat menu ⋮ `RowActionsMenu` — AGENTS.md §1), tidak ada komponen FE baru.

| Key (`master/{key}`) | Nama (panjang, label) | Induk | Kolom tambahan & aturan | Opsi engine |
|---|---|---|---|---|
| `rumpun-jabatan` | `rumpun_jabatan` (≤50, "Rumpun Jabatan") | — | — | `orderColumnType 'tinyint'`, `idMaxLength 3`, audit `created_by` (pola FAQ) |
| `subrumpun-jabatan` | `subrumpun_jabatan` (≤255, "Sub Rumpun Jabatan") | `id_rumpun_jabatan` → `rumpun-jabatan` (wajib aktif) | — | `statusChain`, `orderColumnType 'tinyint'`, `idMaxLength 3`, audit `created_by` |
| `jabatan-akademik` | `jabatan_akademik` (≤255) | — | `is_atasan` "Jabatan Atasan" select **wajib** 1 Ya / 2 Tidak (label & radio legacy `akademik/form.php:39-50`) | `hasOrder false`, audit `created_by` |
| `periode-struktur-jabatan` | `periode_struktur_jabatan` (≤45, "Periode Struktur") | — | nama = tahun 4 digit 2000–3000 (`PeriodeStrukturJabatanHooks`, 422 "Periode Struktur harus tahun 4 digit antara 2000 dan 3000.") | `hasOrder false`, `hooks` |

- **Kolom audit legacy.** Rumpun, sub rumpun, jabatan akademik: tambah mengisi `created_by` dan membiarkan `updated_by` NULL, ubah mengisi `updated_by` (`sp_rumpun` :2714-2736, `sp_subrumpun` :2951-2974, `sp_jabaka` :3487-3509). Periode: `updated_by` saat tambah & ubah (tanpa `created_by`, `set_param_periode` :2263-2273).
- **Sub rumpun.** Nama unik per rumpun; tambah/pindah ke rumpun Tidak Aktif/Dihapus/tidak ada → 422 `id_rumpun_jabatan`; dropdown `subrumpun-jabatan/options?parent={id_rumpun_jabatan}` hanya sub rumpun aktif yang rumpunnya aktif (`statusChain`; legacy `list_subrumpun_jabatan` hanya menyaring status baris sendiri — pola U3).
- **Periode.** Hook hanya memeriksa saat nama ditulis; periode impor lama yang tidak berbentuk tahun tetap bisa dinonaktifkan/diubah kolom lain. Legacy hanya membatasi lewat mask form.
- **`jabatan.id_jenjang_jf`** tetap tidak dikelola API (`hiddenColumns` DBV-008); kini dijaga FK RESTRICT. Dropdown/CRUD `jenjang_jf` tidak ada (legacy tanpa UI); dikelola lewat impor/migration data bila berubah (Bagian 4 #12).
- **Tidak didaftarkan di engine** (tidak ada di registry maupun `master/meta`): `jenjang-jf`, `peta-jabatan`, `struktur-jabatan`, `jabatan-koordinasi` (Bagian 4 #9–#12).
- **Fixture test** (`MasterDataSeeder::seedG02b`, fixture blok `DBV-018`): 8 jenjang JF, 3 rumpun, 4 sub rumpun, 3 jabatan akademik, 2 periode (2021, 2024). Nama contoh [I]; ID kecil.

## 3. Deviasi dari legacy

| # | Item | Legacy | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | Status | ENUM('1','2') `rumpun_jabatan`/`subrumpun_jabatan`; tanpa status `jenjang_jf`/`struktur_jabatan`; TINYINT 1/2 lainnya; hapus keras (kecuali koordinasi) | TINYINT NOT NULL DEFAULT 1, 1/2/10, COMMENT v2 di kedelapan tabel; hapus = soft delete | G2. Impor: ENUM '1'/'2' → 1/2; tabel tanpa status → 1 |
| 2 | UNIQUE (6 index) | Tidak ada UNIQUE selain PK; cek duplikat aplikasi hanya status 1 (kecuali periode & struktur) | `uq_jenjang_jf_nama`, `uq_rumpun_jabatan_nama`, `uq_subrumpun_jabatan_nama`, `uq_jabatan_akademik_nama`, `uq_periode_struktur_jabatan_nama`, `uq_struktur_jabatan` | G2. **Audit duplikat wajib sebelum impor** (6.5 #1) |
| 3 | Aksi FK (15 FK termasuk `jabatan → jenjang_jf`) | CASCADE / SET NULL / ON UPDATE CASCADE | RESTRICT/RESTRICT, nama legacy | G2; CASCADE legacy (peta 01/03, sub rumpun, `old_id_jabatan`) ikut menghapus baris anak saat hard delete — v2 tidak hard delete |
| 4 | 4 CHECK | Tidak ada | `is_atasan` 1/2, `level_struktur` 1..5, `jenis` 1/2, `kebutuhan` ≥ 0 | Domain form legacy di lapis DB; impor dengan nilai di luar domain gagal → normalkan (6.5 #3) |
| 5 | Collation `struktur_jabatan` | `utf8mb4_0900_ai_ci` | `utf8mb4_unicode_ci` | G2; perbandingan nama sedikit berbeda (aksen/ekspansi) — tidak berdampak pada kolom ID |
| 6 | AUTO_INCREMENT awal | D1: 9 / 4 / 88 / 43 / 5 / 1227 / 1940 / 462 | Tidak ditulis | Counter disalin saat impor (6.5 #7) |
| 7 | COMMENT | COMMENT status legacy campur (`'1: Active, …'`, `'1: Aktif, 2: Tidak Aktif'`, tanpa COMMENT) | COMMENT status v2 seragam + COMMENT `created_by`/`updated_by` | Preseden DBV-005/008. COMMENT non-status legacy (`is_atasan`, `jenis`, `level_struktur`) dipertahankan |

## 4. Keputusan yang diminta dari DB Validator (DBV-018)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Lingkup | 8 tabel [K] D1 sebagai CREATE (Bagian 2) di migration baru `2026-09-30-100100` (timestamp sebelum draf B-01/B-02 `2026-09-30-12xxxx`, agar FK B-01 ke `rumpun_jabatan`/`jabatan_koordinasi` bisa dibuat) | ⏳ |
| 2 | FK `jabatan → jenjang_jf` | Migration terpisah `2026-09-30-100200` (ALTER ADD CONSTRAINT nama legacy, RESTRICT); migration DBV-008 tidak diedit lagi. Impor wajib mengaudit `jabatan.id_jenjang_jf` yatim (G-02 6.5 #2) | ⏳ |
| 3 | Kolom `status` [V2] di `jenjang_jf` dan `struktur_jabatan` (legacy tanpa status) | Tambah (TINYINT 1/2/10, impor = 1) demi pola G2 dan soft delete saat halaman struktur dibuat. Alternatif: ikut D1 tanpa status | ⏳ |
| 4 | ENUM('1','2') status rumpun/sub rumpun | → TINYINT 1/2/10 (preseden `gol_pppk` DBV-004) | ⏳ |
| 5 | 6 UNIQUE [V2] | Bagian 3 #2; `uq_struktur_jabatan` dan `uq_subrumpun_jabatan_nama` tidak menahan baris ber-NULL (kolom lingkupnya NULL-able: `id_periode_struktur_jabatan`/`id_jabatan`/`id_jabatan_atasan` dan `id_rumpun_jabatan`; UNIQUE menganggap setiap NULL berbeda) — keunikan baris itu hanya dijaga aplikasi; audit duplikat sebelum impor (6.5 #1) untuk kedua index memakai GROUP BY yang menyatukan NULL dalam satu kelompok (catatan C-1 review DB Validator 05-10-2026) | ⏳ |
| 6 | UNIQUE `peta_jabatan` & `jabatan_koordinasi` | **Tidak dibuat sekarang.** Peta: keunikan legacy memakai `kebutuhan` (jabatan yang sama boleh berulang dengan kebutuhan beda) — aturan v2 diputuskan bersama halaman peta. Koordinasi: legacy tanpa cek duplikat. Ditambah lewat ALTER setelah audit (6.5 #1) bila diperlukan | ⏳ |
| 7 | `jabatan_koordinasi.kelas_jabatan` tanpa FK | Ikut D1 (tanpa FK); validasi di aplikasi saat halaman koordinasi dibuat. Alternatif: FK [V2] ke `kelas_jabatan` setelah audit | ⏳ |
| 8 | 4 CHECK [V2] | Bagian 3 #4 | ⏳ |
| 9 | **CRUD `peta_jabatan` (DoD "peta formasi")** | Tidak lewat engine generik (tanpa kolom nama; satu entri = satker + jabatan + atasan + kebutuhan; form berjenjang ganda; kolom "B" dari pegawai B-01). Diusulkan CR baru: service + endpoint + halaman khusus (pola hari libur G-08), dikerjakan setelah MIG-001b (ISSUE-007) dan memakai `RowActionsMenu`. G-02 tetap IN_PROGRESS | ⏳ (butuh keputusan user: jadwal & key CR) |
| 10 | CRUD `struktur_jabatan` | Halaman khusus bersama struktur organisasi (B-19): kolom nama denormalisasi diisi server, atasan per periode. Sampai itu, data hanya dari impor | ⏳ |
| 11 | CRUD `jabatan_koordinasi` | Halaman khusus (B-07/PLT-PLH): dropdown atasan legacy diturunkan dari `struktur_jabatan` periode aktif + koordinator satker yang sama (`form_koord`), tidak bisa direplikasi dropdown ref generik (2.200 jabatan) | ⏳ |
| 12 | `jenjang_jf` tanpa CRUD | Legacy tanpa UI admin; data dari impor. Dropdown jenjang JF untuk form jabatan dibuat bila `id_jenjang_jf` mulai dikelola (Fase 3 B-12) | ⏳ |
| 13 | PK TINYINT `rumpun_jabatan`/`subrumpun_jabatan` | Ikut D1 (TINYINT signed, maks 127). Counter `subrumpun_jabatan` D1 = 88 → sisa ±39 ID; engine menolak tambah saat penuh (1264 → 422). Pelebaran ke SMALLINT = keputusan terpisah bila dibutuhkan | ⏳ |
| 14 | Konsumen `simapi/v1/A_dm.php` (struktur + periode) | Dicatat untuk rencana pengalihan konsumen (J3); tidak memengaruhi skema | ⏳ (catatan) |

Approval tanpa catatan per poin akan dicatat mengikuti kolom **Usulan** (preseden DBV-001/002/005). Koreksi setelah approval dilakukan lewat migration ALTER baru.

Keputusan kode yang diminta dari reviewer CR-032:

| # | Keputusan | Diimplementasikan | Alternatif |
|---|---|---|---|
| C1 | Lingkup CRUD | 4 master engine generik; peta/struktur/koordinasi ditunda ke halaman khusus | Perluasan engine (master tanpa kolom nama) — lebih besar dari kebutuhan, berisiko untuk 20+ master yang sudah ada |
| C2 | Validasi tahun periode | Hook server (422) | Hanya hint di form (legacy hanya mask) |
| C3 | Label | Legacy: "Rumpun Jabatan", "Sub Rumpun Jabatan", "Jabatan Akademik", "Jabatan Atasan", "Periode Struktur" | — |

## 5. Status keputusan G-01 terkait

| G-01 | Isi | Dengan DBV-018 (⏳) |
|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status` | `rumpun`/`subrumpun` [K] punya `order`; `jabatan_akademik`, `periode_struktur_jabatan` tanpa `order` (legacy urut nama/tahun, `hasOrder false`); tabel non-engine mengikuti D1 |
| Bagian 3 | Daftar tabel tanpa DDL | Kedelapan tabel jabatan keluar dari daftar bila disetujui. Dokumen G-01 tidak diubah di PR ini |

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon, `sql_mode` STRICT_TRANS_TABLES, row format default DYNAMIC). Database test per shard `simpeg_v2_t_dbv018_s<i>` (DBPrefix `t_`, dibuat & di-drop dengan nama persis oleh skrip gate). Database scratch `simpeg_v2_scr_dbv018` (koneksi default tanpa prefix; `.env` minimal di worktree, tidak di-commit; `SELECT DATABASE()` dicek sebelum `spark`) untuk siklus `migrate` → `migrate:rollback` → `migrate`, di-drop setelahnya. Verifikasi MariaDB 10.4 diminta dari DB Validator (6.3).

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| Siklus di DB scratch `simpeg_v2_scr_dbv018` (nama database dibuktikan lewat tabel penanda yang terbaca `php spark db:table --show` sebelum `migrate`; di-drop setelahnya) | `migrate --all` (26 migration, termasuk `100100` dan `100200`) → `migrate:rollback` (semua tabel aplikasi hilang, tersisa tabel `migrations`) → `migrate --all`: kedelapan tabel kembali; `TABLE_CONSTRAINTS` kedelapan tabel 8 PRIMARY, 6 UNIQUE, 4 CHECK, 14 FOREIGN KEY + FK `fk_id_jenjang_jf_jab_to_jenjang_jf` di `jabatan`; seluruh FK RESTRICT/RESTRICT |
| `phpunit tests/MasterData/JabatanSisaSchemaTest.php` | `OK (6 tests, 246 assertions)` — kolom & tipe persis (termasuk ENUM `kategori_jf`, `updated_at` NOT NULL `jabatan_koordinasi`), collation, index (nama KEY legacy), 15 FK RESTRICT termasuk FK ke diri sendiri, 4 CHECK, default & COMMENT, tanpa baris; DB menolak duplikat (termasuk status 10), ENUM di luar daftar, `is_atasan` 3, `level_struktur` 0/6, `jenis` 3, `kebutuhan` −1, rujukan yatim, hapus induk yang dirujuk; `down()` kedua migration lalu `up()` mengembalikan skema yang sama (KEY DBV-008 di `jabatan` tetap); `up()` gagal di tengah (penghalang `peta_jabatan`) men-drop hanya tabel run itu |
| `phpunit tests/MasterData/JabatanSisaTest.php` + `JabatanUnitSatkerSchemaTest.php` (disesuaikan) | `OK (7 tests, 169 assertions)` + `OK (6 tests, 255 assertions)` |
| PHPStan level 5, PHP-CS-Fixer | `[OK] No errors`; `Found 0 of 275` |
| Frontend (`npm run check`: ESLint, vue-tsc, Vitest, build) | Vitest `33 passed` file / `337 passed` test (termasuk `MasterDataView.g02b.spec.ts`, 6 test); build lolos |
| PHPUnit penuh (gate) | **belum dijalankan ulang** — antrean gate dibatasi untuk mengurangi beban MySQL bersama; wajib dijalankan sebelum PR dibuka |

### 6.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator) — WAJIB sebelum approval

1. `php spark migrate` → `php spark migrate:rollback` → `php spark migrate` (kedua migration DBV-018 masuk batch tersendiri; rollback melepas FK `jenjang_jf` lalu men-drop kedelapan tabel). Bersihkan database test lebih dulu bila sebelumnya dipakai branch dengan migration lain.
2. `vendor/bin/phpunit --no-coverage tests/MasterData/JabatanSisaSchemaTest.php tests/MasterData/JabatanUnitSatkerSchemaTest.php`.
3. CHECK ditegakkan (MariaDB 4025) dan terdaftar di `TABLE_CONSTRAINTS`: `is_atasan` = 3, `level_struktur` = 6, `jenis` = 3, `kebutuhan` = −1.
4. FK RESTRICT lewat `REFERENTIAL_CONSTRAINTS` (MariaDB bisa menyembunyikan RESTRICT di `SHOW CREATE TABLE`), termasuk FK ke diri sendiri `fk_id_atasan_es_3_koord_jabkoor_to_self`.
5. ENUM `kategori_jf` menolak nilai di luar `'Ahli','Terampil'` di mode strict.
6. Opsional: `JabatanSisaTest.php`, `MasterGenericTcTest.php`, `RbacMasterEndpointsTest.php`.

### 6.4 Pemulihan bila `up()` gagal di tengah

Pola DBV-008 (G-02 6.6): `up()` men-drop tabel DBV-018 yang sempat dibuat **pada run itu** lalu melempar ulang error; jalankan ulang `php spark migrate` setelah penyebabnya diperbaiki (diuji `JabatanSisaSchemaTest::testFailedUpDropsOnlyTablesCreatedInThatRun`). Bila pembersihan otomatis gagal, drop manual dengan urutan `jabatan_koordinasi`, `peta_jabatan`, `struktur_jabatan`, `periode_struktur_jabatan`, `jabatan_akademik`, `subrumpun_jabatan`, `rumpun_jabatan`, `jenjang_jf` — **jangan `migrate:rollback`** batch (migration yang gagal tidak tercatat). Bila migration `100200` yang gagal, tabel sudah lengkap: perbaiki data `jabatan.id_jenjang_jf` yatim lalu `php spark migrate`.

### 6.5 Audit sebelum impor (SQL baca-saja di salinan data legacy)

Ditambahkan ke runbook impor (DBV-011) bersama butir G-02 6.5. Setiap sesi diawali `SET time_zone = '+00:00';`.

1. **Duplikat** per lingkup UNIQUE (perbandingan `utf8mb4_unicode_ci`, setelah trim & `stripslashes`, **termasuk semua status**). GROUP BY menyatukan NULL dalam satu kelompok (berbeda dengan UNIQUE), jadi duplikat ber-NULL di lingkup `uq_subrumpun_jabatan_nama` (`id_rumpun_jabatan`) dan `uq_struktur_jabatan` (ketiga kolom ID) ikut terdeteksi dan ditangani seperti duplikat lain, walau index tidak akan menolaknya saat impor (Bagian 4 #5):
   ```sql
   SELECT kategori_jf, TRIM(jenjang_jf) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_jenjang_jf) ids
     FROM jenjang_jf GROUP BY kategori_jf, TRIM(jenjang_jf) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT TRIM(rumpun_jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_rumpun_jabatan) ids
     FROM rumpun_jabatan GROUP BY TRIM(rumpun_jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT id_rumpun_jabatan, TRIM(subrumpun_jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_subrumpun_jabatan) ids
     FROM subrumpun_jabatan GROUP BY id_rumpun_jabatan, TRIM(subrumpun_jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT TRIM(jabatan_akademik) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_jabatan_akademik) ids
     FROM jabatan_akademik GROUP BY TRIM(jabatan_akademik) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   SELECT TRIM(periode_struktur_jabatan) AS nama, COUNT(*) n, GROUP_CONCAT(id_periode_struktur_jabatan) ids
     FROM periode_struktur_jabatan GROUP BY TRIM(periode_struktur_jabatan) HAVING n > 1;
   SELECT id_periode_struktur_jabatan, id_jabatan, id_jabatan_atasan, COUNT(*) n, GROUP_CONCAT(id_struktur_jabatan) ids
     FROM struktur_jabatan GROUP BY id_periode_struktur_jabatan, id_jabatan, id_jabatan_atasan HAVING n > 1;
   -- Informasi untuk Bagian 4 #6 (tidak memblok impor):
   SELECT id_satker, id_jabatan, id_jabatan_at, COUNT(*) n, GROUP_CONCAT(CONCAT(id_peta_jabatan, ':', kebutuhan, ':', status)) isi
     FROM peta_jabatan GROUP BY id_satker, id_jabatan, id_jabatan_at HAVING n > 1;
   SELECT id_satker, TRIM(jabatan) COLLATE utf8mb4_unicode_ci AS nama, COUNT(*) n, GROUP_CONCAT(id_jabatan_koordinasi) ids
     FROM jabatan_koordinasi GROUP BY id_satker, TRIM(jabatan) COLLATE utf8mb4_unicode_ci HAVING n > 1;
   ```
2. **NULL/yatim** (FK + wajib di aplikasi):
   ```sql
   SELECT id_subrumpun_jabatan FROM subrumpun_jabatan s WHERE id_rumpun_jabatan IS NULL
       OR NOT EXISTS (SELECT 1 FROM rumpun_jabatan r WHERE r.id_rumpun_jabatan = s.id_rumpun_jabatan);
   SELECT id_struktur_jabatan FROM struktur_jabatan s
    WHERE (id_periode_struktur_jabatan IS NOT NULL AND NOT EXISTS (SELECT 1 FROM periode_struktur_jabatan p WHERE p.id_periode_struktur_jabatan = s.id_periode_struktur_jabatan))
       OR (id_jabatan IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = s.id_jabatan))
       OR (id_jabatan_atasan IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = s.id_jabatan_atasan));
   SELECT id_peta_jabatan FROM peta_jabatan p
    WHERE NOT EXISTS (SELECT 1 FROM satker s WHERE s.id_satker = p.id_satker)
       OR NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = p.id_jabatan)
       OR (id_jabatan_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = p.id_jabatan_at));
   SELECT id_jabatan_koordinasi FROM jabatan_koordinasi k
    WHERE (id_unit IS NOT NULL AND NOT EXISTS (SELECT 1 FROM unit u WHERE u.id_unit = k.id_unit))
       OR (id_satker IS NOT NULL AND NOT EXISTS (SELECT 1 FROM satker s WHERE s.id_satker = k.id_satker))
       OR (id_atasan_es_3_koord IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jabatan_koordinasi x WHERE x.id_jabatan_koordinasi = k.id_atasan_es_3_koord))
       OR EXISTS (SELECT 1 FROM (SELECT k.id_atasan_es_1 v UNION ALL SELECT k.id_atasan_es_2 UNION ALL SELECT k.id_atasan_es_3 UNION ALL SELECT k.old_id_jabatan) a
                   WHERE a.v IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jabatan j WHERE j.id_jabatan = a.v));
   SELECT id_jabatan, id_jenjang_jf FROM jabatan j
    WHERE id_jenjang_jf IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jenjang_jf x WHERE x.id_jenjang_jf = j.id_jenjang_jf);
   ```
3. **Nilai di luar domain CHECK/aplikasi**:
   ```sql
   SELECT id_jabatan_akademik, is_atasan FROM jabatan_akademik WHERE is_atasan NOT IN (1, 2);
   SELECT id_struktur_jabatan, level_struktur FROM struktur_jabatan WHERE level_struktur NOT BETWEEN 1 AND 5;
   SELECT id_jabatan_koordinasi, jenis FROM jabatan_koordinasi WHERE jenis NOT IN (1, 2);
   SELECT id_peta_jabatan, kebutuhan FROM peta_jabatan WHERE kebutuhan < 0;
   SELECT id_periode_struktur_jabatan, periode_struktur_jabatan FROM periode_struktur_jabatan
    WHERE periode_struktur_jabatan NOT REGEXP '^[0-9]{4}$'; -- hanya aturan aplikasi (hook), tidak memblok impor
   SELECT 'jabatan_koordinasi' t, COUNT(*) FROM jabatan_koordinasi WHERE status NOT IN (1, 2)
   UNION ALL SELECT 'peta_jabatan', COUNT(*) FROM peta_jabatan WHERE status NOT IN (1, 2, 10)
   UNION ALL SELECT 'jabatan_akademik', COUNT(*) FROM jabatan_akademik WHERE status NOT IN (1, 2, 10);
   ```
4. **Panjang salinan nama** `struktur_jabatan` (1.3 #4): `SELECT id_struktur_jabatan FROM struktur_jabatan WHERE CHAR_LENGTH(jabatan) > 100 OR CHAR_LENGTH(jabatan_atasan) > 100;` (strict mode menolak; legacy bisa menyimpan terpotong).
5. **Pemetaan status:** `rumpun_jabatan`/`subrumpun_jabatan` ENUM '1'/'2' → TINYINT 1/2; `jenjang_jf`/`struktur_jabatan` tanpa status → 1.
6. **`stripslashes`** kolom teks yang di-`addslashes` legacy (`rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`, `periode_struktur_jabatan`, `jabatan_koordinasi.jabatan`), pola G-02 6.5 #6.
7. **Counter AUTO_INCREMENT:** setelah impor, bila counter legacy (`SHOW TABLE STATUS` saat cutover; acuan D1 Bagian 1) lebih besar dari `MAX(id)+1`, `ALTER TABLE <tabel> AUTO_INCREMENT = <counter legacy>`.
8. **Urutan:** `rumpun_jabatan.order` global dan `subrumpun_jabatan.order` per rumpun dinomori ulang 1..n (default legacy 0, nilai ganda) — batas TINYINT 127.
9. **Audit & zona waktu:** `created_by`/`updated_by` legacy = `user.id` akun legacy → petakan ke `id_pengguna`; `created_at`/`updated_at` jam server → konversi mengikuti keputusan zona waktu global (DBV-011 R1–R4).
10. **Urutan impor:** induk dulu (`unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `kelas_jabatan`, `jenjang_jf`, `jabatan`, lalu tabel DBV-018); `jabatan_koordinasi` dua langkah (baris tanpa `id_atasan_es_3_koord`, lalu sisanya) atau dengan urutan ID karena FK ke diri sendiri.

## 7. Tindak lanjut

- **CR halaman peta jabatan** (DoD "peta formasi", Bagian 4 #9): service + endpoint + halaman khusus, menu ⋮ `RowActionsMenu` (Edit → Aktifkan/Nonaktifkan → Hapus `danger`, `ConfirmDialog`), kolom "B" setelah B-01. Key CR menunggu user.
- **Aturan keunikan `peta_jabatan`** (catatan C-2 review DB Validator 05-10-2026; Bagian 4 #6): diputuskan **sebelum** halaman peta formasi dibuat (Fase 3), termasuk apakah `kebutuhan` ikut lingkup keunikan seperti cek legacy. Bahan: blok informasi `peta_jabatan` di audit 6.5 #1. Bila diputuskan perlu UNIQUE, ditambah lewat migration ALTER baru setelah audit (migration DBV-018 tidak diedit).
- **Struktur jabatan & jabatan koordinasi** (Bagian 4 #10, #11): bersama B-19 / B-07.
- **DBV-012 (B-01/B-02):** FK `pegawai_mutasi_jabatan`/`riwayat_mutasi_jabatan` ke `rumpun_jabatan`/`jabatan_koordinasi` (nama [K] D1, draf `dbv-012/fk-ditahan`) kini punya tabel induk.
