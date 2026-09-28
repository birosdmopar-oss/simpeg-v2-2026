# DB Validator Review — G-04 Kenaikan Pangkat & G-05 Pendidikan (skema legacy)

**Key review:** `DBV-004` (review DB Validator, skema) + `CR-011` (review kode) — satu pull request, judul `[DBV-004][CR-011] …`, branch `dbv-004/g04-g05-pangkat-pendidikan`. DB Validator hanya me-review/approve; **merge dilakukan user** setelah kedua review menyatakan setuju. Branch berbasis `main` yang sudah memuat perluasan engine master CR-009 (Gelombang 1); timestamp migration `2026-09-25-110000`/`110100` tetap (sesudah DBV-003, sebelum DBV-005). Isi: bagian skema DBV-004 (2 migration + schema test + dokumen ini) dan bagian kode CR-011 (definisi master di engine generik, 2 controller grup, 1 hook, test fitur, satu penyesuaian form FE).

**Status:** ⏳ **MENUNGGU APPROVAL DB VALIDATOR (DBV-004) DAN REVIEW KODE (CR-011)**. Migration `2026-09-25-110000_CreateMasterKenaikanPangkat.php` dan `2026-09-25-110100_CreateMasterPendidikan.php` **JANGAN dijalankan di Dev/Production sebelum disetujui**. Approval final menunggu dump struktur produksi untuk nilai [I] (Bagian 7).

**Rujukan:** `02-MasterData.md` G-04/G-05 + G-TC, Tech Spec §2.3 G-04 ("DB Impact: pangkat, jenis_kp, gol_pppk") & G-05 ("jenjang_pendidikan, bidang_pendidikan, jurusan_pendidikan"), `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 1 ("sama / Copy langsung"; "jurusan depend ke bidang"), Matriks Role x Endpoint Modul G (`hr/master/c_kp/*`, `hr/master/c_pendidikan/*` = role 1), DDL produksi `simpeg_prod.sql:257-265` dan `:1277-1289` (HeidiSQL, MySQL 8.0.21), ERD legacy `simpeg01.erd` (nama FK), `SHOW CREATE TABLE` DB lokal `simpeg01` & `simpeg_prod_duplikat` (read-only), kode legacy (`application/controllers/hr/master/C_kp.php`, `C_pendidikan.php`, `application/libraries/hr/master/Lm_kp.php`, `Lm_pendidikan.php`, `application/controllers/hr/services/Local.php`, `application/libraries/hr/rwy/L_kp.php`, `L_pendidikan.php`, `application/views/hr/master/kp/**`, `application/views/hr/master/pendidikan/**`), `G-01-master-schema.md` (Keputusan #5/#6, Bagian 8 / DBV-001), `G-10-faq-schema.md` (pola pilot DBV-002), keputusan proyek 25-09-2026.

**Label sumber:** **[K]** terkonfirmasi — DDL `simpeg_prod.sql` (nomor baris), ERD `simpeg01.erd`, `SHOW CREATE TABLE` DB lokal legacy, atau kode legacy (`berkas:baris`); **[V2]** tambahan/ubahan v2 yang disengaja (deviasi dari legacy — alasan di Bagian 3); **[I]** dugaan (tidak ada DDL, wajib dicocokkan dengan dump struktur produksi). Nilai dari DB lokal `simpegdev_local` ditulis [I] karena asal-usulnya belum pasti (tabel `pangkat` di sana terbukti tiruan: tanpa kolom `gol`/`ruang` yang ditulis kode).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-09-25-110000_CreateMasterKenaikanPangkat.php` | 3 tabel G-04: `pangkat`, `jenis_kp`, `gol_pppk` (SQL mentah, sadar prefix tabel; `down()` men-drop ketiganya) |
| `app/Database/Migrations/2026-09-25-110100_CreateMasterPendidikan.php` | 3 tabel G-05: `jenjang_pendidikan`, `bidang_pendidikan`, `jurusan_pendidikan` (`down()` men-drop anak → induk) |
| `tests/MasterData/PangkatPendidikanSchemaTest.php` | skema hasil kedua migration dibandingkan dengan Bagian 2 lewat `information_schema`, constraint DB, rollback & kegagalan `up()` per migration |
| `app/Config/MasterData.php` (blok DBV-004) | CR-011: definisi 6 master di engine generik — nama, field, mode urutan, filter, keunikan singkatan, kolom tersembunyi, rantai status (Bagian 2.8) |
| `app/Controllers/Api/MasterData/KpController.php`, `PendidikanController.php` | CR-011: controller grup G-04 / G-05; route & RBAC dibangkitkan dari config (CRUD role 1, dropdown semua role yang login) |
| `app/Libraries/MasterData/JurusanPendidikanHooks.php` | CR-011: jurusan wajib punya minimal satu flag jenjang |
| `tests/MasterData/KenaikanPangkatTest.php`, `PendidikanTest.php` | CR-011: test fitur per master (Bagian 6.2) |
| `tests/_support/MasterDataTestTrait.php`, `tests/_support/Database/Seeds/MasterDataSeeder.php` (blok DBV-004) | fixture G-TC generik dan data uji dengan ID legacy bermakna (6.4) |
| `tests/MasterData/MasterGenericTcTest.php`, `RbacMasterEndpointsTest.php` | penyesuaian test generik bersama: tabel tanpa `created_at` dan kolom nama pendek (Bagian 6.2) |
| `frontend/src/features/master-data/components/MasterFormDialog.vue` (+ spec) | select opsional (`row_jurusan`) bisa dikosongkan lagi |

## 1. Latar belakang & keputusan

G-04 dan G-05 sebelumnya belum ditulis (G-01 Bagian 3: seed lokal tanpa `order`, `gol_pppk` dengan kolom `nama_gol_pppk` yang tidak cocok dengan Legacy Audit). Hasil penelusuran: **hanya 2 dari 6 tabel yang DDL-nya ada di dump produksi** — `bidang_pendidikan` (`simpeg_prod.sql:257-265`) dan `gol_pppk` (`:1277-1289`) [K]; `SHOW CREATE TABLE` di `simpeg01` dan `simpeg_prod_duplikat` identik dengan dump. `pangkat`, `jenis_kp`, `jenjang_pendidikan`, `jurusan_pendidikan` tidak ada di dump (terpotong secara abjad di `jabatan`, CREATE terakhir `:1447`) maupun di `simpeg01`/`simpeg_prod_duplikat`; DB lokal `production` (95 tabel) adalah aplikasi lain dan tidak memuat tabel grup ini. Untuk keempat tabel itu **nama kolom [K]** diambil dari kode legacy yang menulis/membacanya (CI3 error bila kolom tidak ada), sedangkan **tipe, panjang, dan default [I]**. Prinsip sama dengan DBV-001/002: nama tabel & kolom sama dengan legacy (Mapping Prinsip #1, ADR-023), tipe PK ikut legacy (termasuk signed), status `1`/`2`/`10`, hapus = soft delete, UNIQUE nama, `utf8mb4_unicode_ci`.

Perilaku legacy yang memengaruhi skema [K]:
- Kelola master hanya UserLevel 1 (`C_kp.php:13`, `C_pendidikan.php`); **hapus = hard DELETE** (`MY_Model::delete`, `Lm_kp.php:134, 368, 581`, `Lm_pendidikan.php:134, …`). Edit/hapus ditolak bila status 2/10 (`C_kp.php:88, 140, 240, 292`; `C_pendidikan.php:88, 140, 240, 292, 392, 444`), sehingga baris Tidak Aktif tidak bisa diaktifkan lagi lewat UI.
- **Keunikan hanya dicek aplikasi**, dengan filter berbeda-beda: pangkat pada (`cpns`, `gol`, `ruang`, `gol_ruang`) untuk `status!='10'` (`Lm_kp.php:193, 213`); jenis KP nama untuk `status!='10'` (`:408, 425`); golongan PPPK nama global tanpa filter status (`:625, 642`); jenjang dan bidang nama untuk `status='1'` (`Lm_pendidikan.php:178, 191` dan `:376, 389`); **jurusan tanpa cek keunikan sama sekali** (`:569-590`).
- **`pangkat.order` adalah level pangkat**, bukan sekadar urutan tampil: "KP berikutnya = order + 1" (`L_ak_20230729.php:68-69`, `L_employee.php:2434-2441`, `:2701-2705` `$constPangkat[$row['order']]`), perbandingan level (`L_ak.php:381-390` `gr_level <= current_lv_pang`), `pang.order AS current_lv_pang` (`helpers/function_helper.php:2120`, `L_user.php:612`).
- **Label & kunci dropdown pangkat = `gol_ruang`** (`views/hr/employee/rwy/kp/form.php:100`, `L_employee.php:11422`); peta SIASN mencari pangkat lewat `gol_ruang` (`Siasn.php:2390, 2535`, awalan `"CPNS "` dari `:2465`). Dropdown pangkat difilter `cpns` menurut jenis KP (`L_kp.php:56, 67`: `id_jenis_kp=='1' ? '1' : '2'`).
- **`gol_pppk.status` ENUM('1','2')** (`:1283`). Diuji di MySQL 8.0.30 lokal (TEMPORARY table di DB scratch): menulis `'10'` dengan strict mode mati menyimpan string kosong (warning 1265), dengan strict mode hidup error.
- Flag jenjang `D_I`..`S_3` di `jurusan_pendidikan` bernilai **1/0** (`Lm_pendidikan.php:599-605`, filter `='1'` di `Local.php:83, 109`), minimal satu dicentang (`:584-587`). `pangkat.cpns` = **1 CPNS / 2 PNS** (kode tipe; `views/hr/master/kp/form.php:40-43`, `Local.php:180-184`).
- **Dropdown berjenjang lewat `jenjang_pendidikan.row_jurusan`**: nilainya adalah nama kolom flag yang disisipkan langsung ke SQL (`Local.php:83, 109`, `L_pendidikan.php:67, 77, 98, 108`); jenjang 1–3 (SD/SLTP/SLTA) tanpa bidang/jurusan. Dropdown jurusan legacy tidak memfilter status jurusan/bidang (`Local.php:83, 87`, `L_pendidikan.php:77, 108`) — bug legacy.
- Kolom yang disimpan dengan `addslashes` (lihat 6.3 #2); `gol_pppk.uang_makan` tidak bisa diubah lewat UI legacy (`sp_golpppk`, `Lm_kp.php:656-675`) tetapi dibaca presensi PPPK (`L_presensi.php:4013-4021`, `:7825-7832`).
- Bug admin Golongan PPPK: menu tambah memanggil form & insert **pangkat** (`C_kp.php:353, 367-383`, `formUrl` `c_kp/add` di `:380`), dan menu hapus meng-hard-delete **pangkat** dengan id yang sama (`C_kp.php:444, 453`) — FK legacy `fk_dm_ak_jf_ibfk_02` ON DELETE CASCADE (`simpeg_prod.sql:468`) ikut menghapus baris `dm_ak_jf` pangkat itu. Bug form ubah jurusan: checkbox `S_2` diisi dari nilai `S_1` (`views/hr/master/pendidikan/jurusan/form.php:87`). Dampak data: 6.3 #7–#9.

### 1.1 Keputusan user & proyek (final, 25-09-2026)

| # | Keputusan | Diterapkan |
|---|---|---|
| P1 | `pangkat`: kolom nama engine (nameField) dan UNIQUE = `gol_ruang`, tetap teks bebas seperti legacy (tidak diturunkan server) | `uq_pangkat_nama (gol_ruang)`; `nameField` `gol_ruang` (CR-011) |
| P2 | `pangkat.order` = level pangkat; **mode order manual** (tidak digeser saat tambah/pindah, tidak dinomori ulang saat hapus) — fitur engine CR-009 | COMMENT kolom; `orderMode: manual` + `orderColumnType: tinyint` (CR-011) |
| P3 | `UNIQUE(cpns, order)` **DITUNDA** sampai data produksi terlihat (6.3 #5) | tidak dibuat; dibuktikan schema test |
| P4 | `gol_pppk`: DDL [K]; `status` ENUM('1','2') → TINYINT 1/2/10 (deviasi wajib); UNIQUE nama | migration |
| P5 | `gol_pppk.uang_makan` tetap DOUBLE [K], **bisa diedit Super Admin** (K5 c(ii)); sumber tarif ikut legacy (PPPK dari `gol_pppk`, PNS dari `web_config`) | skema [K]; field `uang_makan` wajib 0..10.000.000 (CR-011, C3 di 2.8) |
| P6 | `jenjang_pendidikan`: UNIQUE nama + UNIQUE singkatan (kode legacy membandingkan string singkatan); `row_jurusan` dipilih dari daftar tetap; `bobot_ipasn` disimpan tetapi disembunyikan | migration (+ CHECK D5); `uniqueFields`, select `row_jurusan`, `hiddenColumns` (CR-011) |
| P7 | `jurusan_pendidikan`: UNIQUE(`id_bidang_pendidikan`, `jurusan_pendidikan`) + audit duplikat; FK RESTRICT dengan nama legacy; dropdown mengikuti rantai status bidang → jurusan (pola FAQ U3) | migration; `statusChain` (CR-011) |
| P8 | `bidang_pendidikan` & `jurusan_pendidikan` ditambah `order` [V2]; impor diisi urut nama ASC | migration |
| P9 | PK TINYINT **signed** untuk `pangkat`, `jenis_kp`, `gol_pppk`, `bidang_pendidikan` | migration |
| P10 | Flag `D_I`..`S_3` bernilai 1/0 dan `pangkat.cpns` 1/2 — nilai legacy (menjawab ISSUE-015 untuk grup ini) | migration |
| P11 | `jenjang_jf` **tidak** termasuk DBV-004 (dirujuk `jabatan`, pindah ke DBV-008) | — |
| P12 | Impor wajib memakai ID legacy apa adanya. Belum ada fitur kunci baris; ID yang di-hard-code didokumentasikan (6.4 + fixture test CR-011) dan ditinjau lagi di Fase 3 | 6.4 |
| P13 | Hapus = soft delete status 10; status 1/2/10; UNIQUE berlaku juga untuk status 2/10; `utf8mb4_unicode_ci`; setiap PR DBV meminta verifikasi MariaDB 10.4 eksplisit (Bagian 8) | migration |

### 1.2 Keputusan usulan (diimplementasikan sesuai usulan, menunggu approval DBV — Bagian 4)

| # | Usulan |
|---|---|
| D1 | Kolom audit 4 tabel tanpa DDL (`pangkat`, `jenis_kp`, `jenjang_pendidikan`, `jurusan_pendidikan`) = `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` + `updated_by INT NULL`, **tanpa** `created_*` — pola (a) `bidang_pendidikan`/`diklat` [K], diterapkan sebagai [I]. Untuk `jenjang_pendidikan`/`jurusan_pendidikan` didukung satu library legacy dengan `bidang_pendidikan` (`Lm_pendidikan.php`); untuk `pangkat`/`jenis_kp` **tidak ada bukti library** — saudara satu library `Lm_kp.php`, `gol_pppk` [K], justru pola (b) dengan `created_*`. Dipilih demi keseragaman grup (Bagian 3 #8, 4 #8) |
| D2 | Panjang kolom teks [I] = kolom snapshot [K] di `simpeg_prod_duplikat.pegawai_kp`: `pangkat` 50, `gol`/`ruang`/`gol_ruang` 10, `jenis_kp` 100 — nilai master selalu muat saat disalin ke riwayat |
| D3 | PK `jenjang_pendidikan` dan `jurusan_pendidikan` INT signed [I] (ID jurusan ≥ 1185 [K], jadi bukan TINYINT); tipe FK anak Fase 3 wajib sama |
| D4 | `order`: `pangkat`/`jenis_kp`/`jenjang_pendidikan` TINYINT NOT NULL DEFAULT 1 [I], analog master legacy [K] `agama` :178, `diklat` :447, `group_jabatan` :1298 (semuanya `tinyint`); bukti [I] yang berlawanan: `simpegdev_local.pangkat`/`jenjang_pendidikan` dan seed inferensi `simpeg_v2_local_seed_legacy.sql` memakai `int`; `bidang_pendidikan`/`jurusan_pendidikan` INT NOT NULL DEFAULT 1 [V2, preseden `faq_article.order`]; `gol_pppk` TINYINT NOT NULL DEFAULT 0 [K] |
| D5 | CHECK `chk_jenjang_pendidikan_row_jurusan`: NULL atau persis salah satu `D_I, D_II, D_III, D_IV, S_1, S_2, S_3` lewat perbandingan biner `CAST(row_jurusan AS BINARY)` — peka huruf dan spasi di akhir ikut dihitung (`utf8mb4_bin` bersifat PAD SPACE sehingga `'S_1 '` akan lolos) [V2] — nilai ini menentukan kolom flag yang dibaca |
| D6 | Tanpa CHECK untuk `status`, `cpns`, flag `D_I`..`S_3` (konsisten DBV-001/002; divalidasi aplikasi) |
| D7 | Flag `D_I`..`S_3` TINYINT NOT NULL DEFAULT 0 + COMMENT [I] |
| D8 | `jurusan_pendidikan`: KEY `fk_id_bidang_pendidikan_jpend_to_bpend` tetap dibuat walau UNIQUE sudah diawali kolom yang sama [I]: legacy jurusan tanpa UNIQUE, sehingga KEY bernama FK kemungkinan ada di produksi. Di dump, 9 dari 31 FK tanpa KEY senama — semuanya karena kolom FK sudah diawali index lain (mis. `fk_dm_ak_jf_ibfk_01` oleh PRIMARY `dm_ak_jf` :465-467, juga `faq_rate`, `faq_related_article`, `dh_online`, `fb_token`), yaitu situasi yang sama dengan UNIQUE jurusan. Dipertahankan agar sebanding dengan produksi (preseden FAQ `faq_article`) |
| D9 | FK `ON DELETE RESTRICT ON UPDATE RESTRICT` (aksi legacy tidak diketahui — ERD tanpa aksi FK; seed inferensi memakai CASCADE) |
| D10 | Tanpa KEY `order`/`status` tambahan (tidak ada bukti index legacy; tabel kecil) dan tanpa seed data di migration (ID datang dari impor; fixture test memakai ID yang sama) |
| D11 | COMMENT status v2 `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` di 6 tabel (legacy bidang `'1: Active, 2: Inactive, 10: Deleted'`, gol_pppk `'1: Aktif, 2: Tidak Aktif'`); COMMENT `created_by`/`updated_by` [V2, preseden]; COMMENT semantik level di `pangkat.order` [V2] |
| D12 | `pangkat.cpns` TINYINT NOT NULL DEFAULT 2 COMMENT `'1: CPNS, 2: PNS'` [I] (`simpegdev_local` `varchar(5) DEFAULT '2'`) |
| D13 | `jenjang_pendidikan.bobot_ipasn` INT NULL DEFAULT 25 [I, `simpegdev_local`] — tetap ada di DB, tidak diekspos API/FE. Default untuk jenjang yang dibuat lewat v2 diajukan ulang di Bagian 4 #16 (usulan `DEFAULT NULL`) |
| D14 | Nilai AUTO_INCREMENT awal tidak ditulis (legacy bidang 100, gol_pppk 19; preseden DBV-001/002) |

## 2. Skema hasil DBV-004

Berlaku untuk keenam tabel:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, termasuk seluruh kolom string (legacy [K] juga `utf8mb4_unicode_ci`).
- Nama constraint/index **tanpa** prefix tabel (DBPrefix hanya berlaku untuk nama tabel, mis. `t_` di DB test).
- Semua PK **signed** (tanpa UNSIGNED) dan AUTO_INCREMENT; nilai AUTO_INCREMENT awal tidak ditulis (D14).
- Status: `TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus'` [V2 COMMENT, D11].
- Kolom audit pola D1 (5 tabel selain `gol_pppk`): `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, `updated_by INT NULL COMMENT 'id_pengguna yang terakhir mengubah'`. `gol_pppk` memakai kolom audit DDL [K]. Tanpa FK kolom audit (preseden DBV-001).
- Tidak ada FK ke/dari tabel di luar grup: tabel perujuk (riwayat KP/pendidikan/KGB/mutasi jabatan, `dm_ak_jf`) dibuat di Fase 3 / DBV-008 (Bagian 2.9).

### 2.1 pangkat — tanpa DDL legacy (kolom dari kode `Lm_kp.php:226-241`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_pangkat` | TINYINT NOT NULL AUTO_INCREMENT | tipe [K]: `dm_ak_jf.id_pangkat tinyint` + FK `fk_dm_ak_jf_ibfk_02` → `pangkat` (`simpeg_prod.sql:459, 468`), `pegawai_kp.id_pangkat tinyint` (simpeg_prod_duplikat); AUTO_INCREMENT [K] (form tanpa id, `insert_id` `Lm_kp.php:53`) |
| `pangkat` | VARCHAR(50) NOT NULL | kolom [K] `Lm_kp.php:231`, wajib `:169`; panjang [I] D2 (`pegawai_kp.pangkat varchar(50)`, snapshot `L_kp.php:560`) |
| `gol` | VARCHAR(10) NOT NULL | kolom [K] `:232`, form I–IV (`views/hr/master/kp/form.php:62-67`); panjang [I] D2 |
| `ruang` | VARCHAR(10) NOT NULL | kolom [K] `:233`, form a–e (`form.php:74-80`); panjang [I] D2 |
| `gol_ruang` | VARCHAR(10) NOT NULL | kolom [K] `:234`, teks bebas wajib (`form.php:87`); panjang [I] D2 (`pegawai_kp.gol_ruang varchar(10)`); "CPNS III/b" tepat 10 karakter |
| `cpns` | TINYINT NOT NULL DEFAULT 2, COMMENT `'1: CPNS, 2: PNS'` | kolom & nilai [K] `:229`, `form.php:40-43`, `Local.php:180-184`, `L_kp.php:56`; tipe/default [I] D12 |
| `order` | TINYINT NOT NULL DEFAULT 1, COMMENT `'level pangkat (KP berikutnya = order + 1), bukan sekadar urutan tampil'` | kolom [K] `:230` (wajib `:165`), form 1–100 (`form.php:110-114`); semantik level [K] (Bagian 1); tipe/default [I] D4 (analog `agama`/`diklat`/`group_jabatan` [K]; `simpegdev_local` `int`); COMMENT [V2] |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | kolom [K] `:236`, tambah = 1 (`C_kp.php:47`); nilai 1/2/10 [K] (`views/hr/master/kp/list.php:76-82`, `Lm_kp.php:13`); tipe [I] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [I] D1 |
| `updated_by` | INT NULL, COMMENT | kolom [K] `:238`; tipe [I]; COMMENT [V2] |

Kunci: `PRIMARY (id_pangkat)`; **UNIQUE** `uq_pangkat_nama (gol_ruang)` [V2, P1]. **Tidak ada** `UNIQUE(cpns, order)` (P3) maupun KEY `order` (D10).

### 2.2 jenis_kp — tanpa DDL legacy (kolom dari kode `Lm_kp.php:438-449`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenis_kp` | TINYINT NOT NULL AUTO_INCREMENT | tipe [K]: FK ERD `fk_id_jenis_kp_peg_kp_to_jenis_kp` (`pegawai_kp` → `jenis_kp`, `simpeg01.erd`; FK mewajibkan tipe & tanda sama) + `pegawai_kp.id_jenis_kp tinyint` (simpeg_prod_duplikat — salinan ini sendiri tanpa FK itu, 2.9); AUTO_INCREMENT [K] `Lm_kp.php:287` |
| `jenis_kp` | VARCHAR(100) NOT NULL | kolom [K] `:442`, wajib `:399`; panjang [I] D2 (`pegawai_kp.jenis_kp varchar(100)`, snapshot `L_kp.php:552`) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] `:441`, wajib `:395`, form 1–100 (`views/hr/master/kp/jenis/form.php:69-73`); tipe [I] D4 (analog `agama`/`diklat`/`group_jabatan` [K]; seed inferensi `int`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | kolom [K] `:444`, tambah = 1 (`C_kp.php:199`), daftar `status!='10'` (`Lm_kp.php:247`); tipe [I] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [I] D1 |
| `updated_by` | INT NULL, COMMENT | kolom [K] `:446`; tipe [I] |

Kunci: `PRIMARY (id_jenis_kp)`; **UNIQUE** `uq_jenis_kp_nama (jenis_kp)` [V2].

### 2.3 gol_pppk — `simpeg_prod.sql:1277-1289`

| Kolom (urut legacy) | Tipe | Sumber |
|---|---|---|
| `id_gol_pppk` | TINYINT NOT NULL AUTO_INCREMENT | [K] :1278 |
| `gol_pppk` | VARCHAR(10) NOT NULL | [K] :1279 |
| `uang_makan` | DOUBLE NOT NULL DEFAULT 0 | [K] :1280 — dapat diedit Super Admin (P5) |
| `order` | TINYINT NOT NULL DEFAULT 0 | [K] :1281 |
| `keterangan` | TINYTEXT NULL | [K] :1282 |
| `status` | **TINYINT** NOT NULL DEFAULT 1, COMMENT v2 | **[V2] deviasi wajib** (legacy [K] :1283 `enum('1','2') … NOT NULL DEFAULT '1'`) |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | [K] :1284 |
| `updated_at` | DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP | [K] :1285 |
| `created_by` | INT NULL, COMMENT `'id_pengguna pembuat'` | [K] :1286; COMMENT [V2] |
| `updated_by` | INT NULL, COMMENT | [K] :1287; COMMENT [V2] |

Kunci: `PRIMARY (id_gol_pppk)` [K] :1288; **UNIQUE** `uq_gol_pppk_nama (gol_pppk)` [V2] (legacy unik global di aplikasi, `Lm_kp.php:625, 642`; tarif uang makan PPPK dicocokkan per nama, `L_presensi.php:7825-7832`). Legacy `AUTO_INCREMENT=19` tidak ditulis (D14).

### 2.4 jenjang_pendidikan — tanpa DDL legacy (kolom dari kode `Lm_pendidikan.php:204-213`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jenjang_pendidikan` | INT NOT NULL AUTO_INCREMENT | tipe [I] D3 (`simpegdev_local` `int AUTO_INCREMENT`); AUTO_INCREMENT [K] `insert_id` `Lm_pendidikan.php:53`; ID 1–3 dan > 3 bermakna (6.4) |
| `jenjang_pendidikan_singkat` | VARCHAR(50) NOT NULL | kolom [K] `:208`; panjang [I] (`simpegdev_local` varchar(50)) |
| `jenjang_pendidikan` | VARCHAR(100) NOT NULL | kolom [K] `:207`; panjang [I] (`simpegdev_local` varchar(100)) |
| `row_jurusan` | VARCHAR(10) NULL DEFAULT NULL, COMMENT `'kolom flag jenjang di jurusan_pendidikan (D_I..S_3); NULL = jenjang tanpa jurusan'` | kolom [K] dibaca `Local.php:83, 109`, `L_pendidikan.php:67, 77, 98, 108` (tidak ada di form legacy); tipe [I]; CHECK [V2] D5 |
| `bobot_ipasn` | INT NULL DEFAULT 25, COMMENT `'skor kualifikasi pendidikan IP ASN'` | kolom [K] `L_user.php:878-884` (dipakai bila > 0); tipe/default [I] D13; tidak diekspos API/FE (P6) |
| `order` | TINYINT NOT NULL DEFAULT 1 | kolom [K] `:209`, form 1–20 (`views/hr/master/pendidikan/jenjang/form.php:61-64`); tipe [I] D4 (analog `agama`/`diklat`/`group_jabatan` [K]; `simpegdev_local` `int DEFAULT '1'` nullable) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] tambah = 1 (`C_pendidikan.php:47`), daftar/dropdown `status='1'` (`Lm_pendidikan.php:13`); tipe [I] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [I] D1 |
| `updated_by` | INT NULL, COMMENT | kolom [K] `:210`; tipe [I] |

Kunci: `PRIMARY (id_jenjang_pendidikan)`; **UNIQUE** `uq_jenjang_pendidikan_nama (jenjang_pendidikan)` [V2]; **UNIQUE** `uq_jenjang_pendidikan_singkat (jenjang_pendidikan_singkat)` [V2, P6]; **CHECK** `chk_jenjang_pendidikan_row_jurusan`: `row_jurusan IS NULL OR CAST(row_jurusan AS BINARY) IN ('D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3')` [V2, D5].

### 2.5 bidang_pendidikan — `simpeg_prod.sql:257-265`

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_bidang_pendidikan` | TINYINT NOT NULL AUTO_INCREMENT | [K] :258 (legacy `AUTO_INCREMENT=100` → tinggal ID 100..127, 28 ID) |
| `bidang_pendidikan` | VARCHAR(100) NOT NULL | [K] :259 |
| `bidang_pendidikan_english` | VARCHAR(100) NULL DEFAULT NULL | [K] :260 |
| `order` | INT NOT NULL DEFAULT 1 | **[V2]** P8/D4 (legacy tanpa `order`, urut `bidang_pendidikan ASC`: `Lm_pendidikan.php:219`, `L_employee.php:11419`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | tipe & default [K] :261; COMMENT [V2] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [K] :262 |
| `updated_by` | INT NULL, COMMENT | [K] :263; COMMENT [V2] |

Kunci: `PRIMARY (id_bidang_pendidikan)` [K] :264; **UNIQUE** `uq_bidang_pendidikan_nama (bidang_pendidikan)` [V2].

### 2.6 jurusan_pendidikan — tanpa DDL legacy (kolom dari kode `Lm_pendidikan.php:592-607`)

| Kolom (urut) | Tipe | Sumber |
|---|---|---|
| `id_jurusan_pendidikan` | INT NOT NULL AUTO_INCREMENT | tipe [I] D3; AUTO_INCREMENT [K] `Lm_pendidikan.php:468`; ID ≥ 1185 [K] (`L_pendidikan.php:829`) → bukan TINYINT |
| `id_bidang_pendidikan` | TINYINT NOT NULL | kolom [K] `:595`, wajib `:576`; tipe [K] = PK induk `simpeg_prod.sql:258` (FK wajib tipe & tanda sama) |
| `jurusan_pendidikan` | VARCHAR(255) NOT NULL | kolom [K] `:596`, wajib `:580`; panjang [I] |
| `jurusan_pendidikan_english` | VARCHAR(255) NULL DEFAULT NULL | kolom [K] `:597`; panjang [I] |
| `gelar` | VARCHAR(50) NULL DEFAULT NULL | kolom [K] `:598`; panjang [I] |
| `D_I`, `D_II`, `D_III`, `D_IV`, `S_1`, `S_2`, `S_3` | TINYINT NOT NULL DEFAULT 0, COMMENT `'1: tersedia untuk jenjang D.I, 0: tidak'` (dst.) | kolom & nilai 1/0 [K] `:599-605`, filter `='1'` (`Local.php:83, 109`), daftar (`views/hr/master/pendidikan/jurusan/list.php:72-78`); minimal satu (`:584-587`); tipe/default [I] D7 |
| `order` | INT NOT NULL DEFAULT 1 | **[V2]** P8/D4 (legacy urut nama: `Lm_pendidikan.php:424`, `Local.php:83`) |
| `status` | TINYINT NOT NULL DEFAULT 1, COMMENT v2 | [K] tambah = 1 (`C_pendidikan.php:351`), daftar `status='1'` (`Lm_pendidikan.php:423`); tipe [I] |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | [I] D1 |
| `updated_by` | INT NULL, COMMENT | kolom [K] `:606`; tipe [I] |

Kunci: `PRIMARY (id_jurusan_pendidikan)`; **UNIQUE** `uq_jurusan_pendidikan_nama (id_bidang_pendidikan, jurusan_pendidikan)` [V2, P7]; `KEY fk_id_bidang_pendidikan_jpend_to_bpend (id_bidang_pendidikan)` [I, D8]; FK `fk_id_bidang_pendidikan_jpend_to_bpend` → `bidang_pendidikan (id_bidang_pendidikan)` **ON DELETE RESTRICT ON UPDATE RESTRICT** — nama [K] ERD, aksi [V2] D9. Panjang key UNIQUE terbesar 1 + 255 × 4 = 1.021 byte (di bawah batas 3.072 byte InnoDB, row format DYNAMIC).

### 2.7 Ringkasan constraint

| Tabel | PRIMARY | UNIQUE | KEY | FK | CHECK |
|---|---|---|---|---|---|
| pangkat | `id_pangkat` | `uq_pangkat_nama (gol_ruang)` | – | – | – |
| jenis_kp | `id_jenis_kp` | `uq_jenis_kp_nama (jenis_kp)` | – | – | – |
| gol_pppk | `id_gol_pppk` | `uq_gol_pppk_nama (gol_pppk)` | – | – | – |
| jenjang_pendidikan | `id_jenjang_pendidikan` | `uq_jenjang_pendidikan_nama`, `uq_jenjang_pendidikan_singkat` | – | – | `chk_jenjang_pendidikan_row_jurusan` |
| bidang_pendidikan | `id_bidang_pendidikan` | `uq_bidang_pendidikan_nama` | – | – (induk) | – |
| jurusan_pendidikan | `id_jurusan_pendidikan` | `uq_jurusan_pendidikan_nama (id_bidang_pendidikan, jurusan_pendidikan)` | `fk_id_bidang_pendidikan_jpend_to_bpend` | `fk_id_bidang_pendidikan_jpend_to_bpend` → bidang, RESTRICT/RESTRICT | – |

### 2.8 Perilaku aplikasi yang bergantung pada skema (CR-011)

Definisi: blok DBV-004 di `app/Config/MasterData.php`. Rincian endpoint: `app/Controllers/Api/MasterData/README.md` (tabel master G-04/G-05). Butir C2–C6 adalah keputusan implementasi untuk review kode CR-011 (bukan pertanyaan DBV).

- **Kelola master (role 1)** lewat engine master generik: `pangkat`, `jenis-kp`, `gol-pppk` (`KpController`) dan `jenjang-pendidikan`, `bidang-pendidikan`, `jurusan-pendidikan` (`PendidikanController`). Hapus = status 10, pulihkan lewat ubah status; aplikasi tidak pernah hard delete, jadi FK RESTRICT tidak terpicu dari aplikasi. Dropdown (options) terbuka untuk semua role yang login karena dipakai form riwayat Fase 3; options hanya membaca kode, nama, dan induk (`uang_makan`/`bobot_ipasn` tidak ikut).
- **`pangkat` urutan = level (P2):** `orderMode: manual`, `orderColumnType: tinyint` — `order` 1..127 (batas TINYINT signed; form legacy 1..100) disimpan apa adanya; tambah, ubah, PATCH order, hapus, dan pulihkan tidak menggeser atau menomori ulang pangkat lain dan tidak menulis audit untuk pangkat lain. Nilai `order` sama antara CPNS dan PNS diterima (P3). **C6:** tambah tanpa `order` = nilai terbesar seluruh pangkat **termasuk yang dihapus** + 1 (level pangkat terhapus tetap dipegang dan kembali saat dipulihkan, jadi pemulihan tidak menghasilkan dua pangkat berlevel sama; tanpa lingkup `cpns`, karena level legacy global: `L_employee.php:2434-2441`, daftar legacy `order ASC` tanpa pemisahan `cpns`); legacy mewajibkan `order` di form. G-TC #4 tidak berlaku untuk `pangkat` (Bagian 3.1 #1); lima master lain tetap mode geser.
- **`pangkat` nama = `gol_ruang` (P1):** nameField "Gol./Ruang", teks bebas maks. 10 karakter (tidak diturunkan dari `cpns`/`gol`/`ruang`); duplikat beda kapitalisasi → 422, termasuk terhadap baris Tidak Aktif/Dihapus (dengan saran aktifkan/pulihkan). `cpns` (label legacy "Jenis Pangkat") pilihan 1 CPNS / 2 PNS, `gol` I–IV, `ruang` a–e — pilihan form legacy, peka huruf; `pangkat` wajib maks. 50. Dropdown & daftar admin bisa difilter `?cpns=1|2` (opsi `filters`), sesuai dropdown pangkat per jenis KP legacy (`L_kp.php:56, 67`); nilai lain → 422.
- **`gol_pppk`:** soft delete menyimpan 10 dan bisa dipulihkan (regresi bug ENUM). **C3:** `uang_makan` field desimal **wajib** dengan batas 0..10.000.000 — kolom `DOUBLE NOT NULL`, jadi nilai kosong akan menjadi NULL (error 1048 → 500), dan angka raksasa menjadi INF (error SQL); batas atas adalah pengaman aplikasi, bukan aturan legacy. `keterangan` maks. 255 byte (TINYTEXT). Kolom audit pola FAQ E1: tambah mengisi `created_at`/`created_by` dan juga `updated_at` (Bagian 3 #12), `updated_by` terisi saat ubah.
- **`jenjang_pendidikan`:** `uniqueFields: ['jenjang_pendidikan_singkat']` → singkatan ganda (case-insensitive, termasuk baris Tidak Aktif/Dihapus) 422 pada field singkatan, termasuk balapan yang baru ditolak `uq_jenjang_pendidikan_singkat` (1062). `row_jurusan` = select dari 7 kode (`D_I`..`S_3`, peka huruf) — kosong (termasuk spasi saja atau `false`) disimpan NULL, bukan string kosong yang ditolak CHECK (3819 → 500); CHECK D5 menjadi lapis kedua. `hiddenColumns: ['bobot_ipasn']` → tidak ada di form, meta, daftar, detail, maupun hasil tulis; nilai yang dikirim diabaikan dan nilai DB tidak berubah; jenjang baru memakai default kolom (Bagian 4 #16). **C5:** label dropdown jenjang = nama jenjang (nameField); legacy memakai singkatan (`L_employee.php:11418-11422`) — engine belum punya opsi label dropdown terpisah, dicatat untuk Fase 3.
- **`jurusan_pendidikan`:** flag `D_I`..`S_3` bertipe boolean (1/0, JSON `true`/`false` diterima, tidak dikirim saat tambah = 0). `JurusanPendidikanHooks`: minimal satu flag 1 → 422 `D_I` "Pilih minimal satu jenjang pendidikan." (legacy `Lm_pendidikan.php:584-587`). Saat ubah, flag terkini dibaca ulang dengan `SELECT … FOR UPDATE` di transaksi tulis (bukan snapshot yang dibaca sebelum transaksi), sehingga dua ubah bersamaan yang mematikan flag berbeda berjalan berurutan dan yang kedua ditolak. **C2:** diperiksa saat tambah dan saat ubah yang menyentuh flag; ubah yang tidak menyentuh flag (nama, bidang, status) tidak diperiksa ulang — pola E6 engine — sehingga baris impor yang ketujuh flag-nya 0 tetap bisa dirapikan (6.3 #12); legacy memeriksa setiap simpan form. Nama unik per bidang (P7); bidang wajib ada dan aktif saat tambah/pindah (E6); pindah bidang menaruh jurusan di akhir urutan bidang baru dan merapatkan bidang lama.
- **Dropdown berjenjang (G-05 DoD "bidang → jurusan"), C4:** `bidang-pendidikan/options` → `jurusan-pendidikan/options?parent={id_bidang_pendidikan}` dengan `statusChain`: jurusan hanya tampil bila jurusan dan bidangnya status 1; status jurusan tidak diubah saat bidang dinonaktifkan/dihapus dan jurusan muncul lagi saat bidang dipulihkan — memperbaiki bug legacy `Local.php:83, 87`, `L_pendidikan.php:77, 108`. **Dropdown per jenjang** (legacy: bidang per jenjang, jurusan per bidang + flag menurut `row_jurusan`, `Local.php:83, 109`) **belum dibuat di CR-011**; dikerjakan bersama riwayat pendidikan Fase 3 lewat service khusus dengan daftar kolom flag tetap + `MasterService::whereActiveChain()` — `row_jurusan` tidak akan disisipkan mentah ke SQL seperti legacy.
- **Input kosong & tidak valid (engine, berlaku untuk keenam master):** nilai yang dianggap kosong oleh rule `permit_empty` (tidak dikirim, `""`, spasi saja, `false`) tidak pernah menjadi `order` 0 — tambah = paling akhir / MAX+1, ubah = urutan tidak berubah; service juga menolak urutan < 1 (lapis kedua). Kolom opsional berisi spasi saja/`false` disimpan NULL. Nilai array/objek JSON di kolom mana pun → 422 "`<Label>` tidak valid." (sebelumnya lolos `permit_empty` lalu 500).
- **Kapasitas PK AUTO_INCREMENT (P9, Bagian 3 #9):** bila counter sudah di batas tipe PK (TINYINT 127), InnoDB mengulang nilai maksimum sehingga INSERT gagal 1062 pada PRIMARY; engine menerjemahkannya menjadi 422 "Kode `<Master>` sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database." (cek nama/kode ganda tetap didahulukan), bukan 500.
- **Kolom audit (D1):** 5 tabel tanpa `created_*` mengisi `updated_at`/`updated_by` saat tambah maupun ubah (perilaku Batch 1). Saudara yang hanya bergeser urutannya tidak di-stamp (`updated_at = updated_at`, sehingga `ON UPDATE CURRENT_TIMESTAMP` tidak terpicu). Waktu ditulis aplikasi dalam UTC.
- **Form FE:** halaman Master Data generik memuat keenam master dari meta (filter `cpns` dari meta `filters`; urutan pangkat berlabel "Urutan (nilai tetap)" tanpa panah geser). Satu penyesuaian: select opsional (`row_jurusan`) bisa dikosongkan lagi (sebelumnya pilihan kosong hanya untuk field ref).

### 2.9 Konsekuensi untuk tabel anak Fase 3 / DBV-008

Kolom FK di tabel anak wajib bertipe persis sama dengan PK master: `id_pangkat`, `id_jenis_kp`, `id_gol_pppk`, `id_bidang_pendidikan` = **TINYINT signed**; `id_jenjang_pendidikan`, `id_jurusan_pendidikan` = **INT signed** [I]. Nama FK anak memakai nama ERD `simpeg01.erd`: 12 FK ke `pangkat` (antara lain `fk_id_pangkat_peg_kp_to_pangkat`, `fk_id_pangkat_rwy_kp_to_pangkat`, `fk_dm_ak_jf_ibfk_02`), `fk_id_jenis_kp_{pcpns,peg_kp,ppns,rwy_kp}_…`, `pegawai_kgb_ibfk_01`/`pegawai_mutasi_jabatan_ibfk_01`/`riwayat_kgb_ibfk_01`/`riwayat_mutasi_jabatan_ibfk_01` (→ `gol_pppk`), `fk_id_jenjang_pendidikan_{pegpend_to_jpend,rpend_to_jpend,rwyib_to_jp,twytb_to_jenjp}`, `fk_id_bidang_pendidikan_{pegpend,rpend}_to_bpend`, `fk_id_jurusan_pendidikan_{pegpend_to_jupend,rpend_jupend}`. Kolom snapshot teks di riwayat (`pangkat`, `gol`, `ruang`, `gol_ruang`, `jenis_kp`, `gol_pppk`, `jenjang_pendidikan_singkat`, `bidang_pendidikan`, `jurusan_pendidikan`) minimal sepanjang kolom master. Keberadaan FK itu di produksi belum pasti: salinan lokal `simpeg_prod_duplikat.pegawai_kp` hanya punya `fk_nip_pegkp_to_pegawai` (tanpa FK ke `pangkat`/`jenis_kp` walau ERD mencantumkannya) dan `pegawai_mutasi_jabatan` tanpa FK ke `gol_pppk` → audit orphan wajib sebelum FK Fase 3 dibuat (6.3 #10).

## 3. Deviasi dari legacy & nilai [I]

| # | Item | Legacy [K] | v2 | Alasan / konsekuensi |
|---|---|---|---|---|
| 1 | `gol_pppk.status` | `enum('1','2') … NOT NULL DEFAULT '1'` (:1283) | TINYINT NOT NULL DEFAULT 1, 1/2/10 (P4) | Soft delete v2 menulis 10. Uji di MySQL 8.0.30 (TEMPORARY table): ENUM('1','2') ← '10' dengan strict mati = string kosong (warning 1265), strict hidup = error. Koneksi aplikasi `strictOn=false` (`Config/Database.php:44`) akan merusak data diam-diam. Impor: `CAST(status AS CHAR)` (6.3 #3) |
| 2 | UNIQUE nama | Tidak ada UNIQUE selain PK; keunikan hanya di aplikasi dengan filter berbeda (Bagian 1) | 7 UNIQUE (Bagian 2.7), berlaku juga untuk status 2/10, case-insensitive lewat collation | Aturan sama dengan DBV-001 #2. Konsekuensi: nama yang pernah dihapus (10) tidak bisa dibuat ulang, harus dipulihkan; **audit duplikat data legacy wajib sebelum impor** (6.3 #4) — terutama jurusan (legacy tanpa cek sama sekali) dan jenjang/bidang (legacy hanya mengecek baris status 1) |
| 3 | Kunci unik `pangkat` | Aplikasi: (`cpns`, `gol`, `ruang`, `gol_ruang`) untuk `status!='10'` (`Lm_kp.php:193, 213`) | UNIQUE `gol_ruang` saja (P1) | `gol_ruang` adalah label dropdown dan kunci peta SIASN (`Siasn.php:2390, 2535`, awalan "CPNS "), sehingga harus unik agar peta tidak ambigu. Baris produksi dengan `gol_ruang` sama tetapi `cpns`/`gol`/`ruang` berbeda akan gagal impor → audit (6.3 #4, #9) |
| 4 | `pangkat.order` | Level pangkat, tanpa cek unik/rentang di server (form klien 1–100) | Mode order manual (P2); UNIQUE(cpns, order) ditunda (P3) | Engine generik mode geser menggeser/menomori ulang `order`; untuk pangkat itu merusak level, jadi `pangkat` memakai mode urutan manual CR-009 (Bagian 2.8). Keputusan UNIQUE(cpns, order) menunggu peta nilai `order` per `cpns` di produksi (6.3 #5) |
| 5 | `order` bidang & jurusan | Tidak ada; urut nama ASC | INT NOT NULL DEFAULT 1 [V2] (P8), diisi urut nama saat impor | Keputusan #5 G-01. Tipe INT (preseden `faq_article.order`), bukan TINYINT, karena jurusan bisa ribuan baris |
| 6 | Aksi FK jurusan → bidang | Tidak diketahui (ERD tanpa aksi FK; seed inferensi CASCADE) | RESTRICT/RESTRICT, nama legacy (D9) | Sama dengan DBV-001/002: aplikasi tidak pernah hard delete; RESTRICT lapis kedua. Hapus fisik manual harus anak → induk. MySQL 8 menampilkan `ON DELETE RESTRICT ON UPDATE RESTRICT` di `SHOW CREATE TABLE`; MariaDB bisa menghilangkannya (nilai default) — verifikasi lewat `REFERENTIAL_CONSTRAINTS` (schema test) |
| 7 | CHECK `row_jurusan` | Tidak ada; nilai disisipkan mentah ke SQL (`Local.php:83`) | CHECK peka huruf (D5) | Nilai di luar 7 nama kolom membuat query dropdown gagal/salah. Perbandingan biner (`CAST(… AS BINARY)`) karena `utf8mb4_unicode_ci` menganggap 's_1' = 'S_1' dan `utf8mb4_bin` bersifat PAD SPACE (MySQL 8 dan MariaDB 10.4) sehingga 'S_1 ' = 'S_1'; nilai berspasi di akhir akan lolos CHECK tetapi tidak cocok dengan daftar kolom flag tetap di service Fase 3. String kosong ditolak (harus NULL). CHECK ditegakkan MySQL ≥ 8.0.16 dan MariaDB ≥ 10.2 |
| 8 | Kolom audit 4 tabel tanpa DDL | Tidak diketahui. Dua pola legacy untuk master yang kodenya hanya menulis `updated_by`: (a) `updated_at NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE` tanpa `created_at` — `bidang_pendidikan` :262, `diklat` :449; (b) `created_at` + `updated_at NULL ON UPDATE` — `agama`, `group_jabatan`, `jabatan` (dan DBV-001) | Pola (a) (D1) [I] | Bukti legacy terbelah, dan "kode hanya menulis `updated_by`" tidak menentukan pola: `agama` (`Lm_umum.php:200-207`) dan `group_jabatan` (`Lm_jabatan.php:792-800`) juga hanya menulis `updated_by` tetapi berpola (b). Untuk `jenjang`/`jurusan`, (a) didukung satu library dengan `bidang_pendidikan` (`Lm_pendidikan.php`). Untuk `pangkat`/`jenis_kp` **tidak ada bukti library**: saudara satu library `Lm_kp.php`, `gol_pppk` [K] :1277-1289, justru pola (b) + `created_*`. (a) dipilih hanya demi keseragaman grup [I]. Bila dump produksi menunjukkan (b), `created_at` perlu migration ALTER lanjutan setelah merge (migration di `main` tidak diedit). Engine mendukung tabel tanpa `created_at` (createdField kosong). Cocokkan dengan dump |
| 9 | Tipe PK | `bidang` TINYINT [K]; `pangkat`/`jenis_kp` TINYINT [K dari kolom FK]; jenjang/jurusan tidak diketahui | Sama [K]; jenjang/jurusan INT signed [I] (D3) | TINYINT signed maks. 127: `bidang_pendidikan` tinggal ID 100..127 (AUTO_INCREMENT prod 100), `gol_pppk` tinggal ID 19..127 (prod 19). Risiko kapasitas diterima (P9); memperlebar berdampak ke tipe FK semua tabel anak. Saat kapasitas habis, tambah lewat aplikasi → 422 dengan penjelasan (Bagian 2.8), bukan 500 |
| 10 | COMMENT & AUTO_INCREMENT awal | COMMENT status legacy berbeda/tanpa 10; `AUTO_INCREMENT=100/19` | COMMENT v2 (D11); AUTO_INCREMENT awal tidak ditulis (D14) | Preseden DBV-001/002; impor ID eksplisit menaikkan counter otomatis |
| 11 | Hapus | Hard DELETE (`MY_Model::delete`), termasuk bug hapus Golongan PPPK yang menghapus pangkat | Soft delete status 10 (P13) | Baris legacy yang pernah dihapus sudah hilang fisik; status 10 hanya muncul dari aplikasi v2 |
| 12 | `gol_pppk.updated_at` saat tambah | NULL sampai baris diubah (DDL [K] :1285 `DEFAULT NULL ON UPDATE`) | Diisi jam tambah oleh engine (CI4 mengisi kolom waktu ubah saat insert) | Sama dengan G-10 #9 (FAQ, sudah disetujui). Penanda "belum pernah diubah" tetap ada: `updated_by` NULL sampai diubah. Diajukan di Bagian 4 #14 |

Nilai **[I]** yang tersisa ada di Bagian 7. Nilai lain [K] atau keputusan user/proyek di Bagian 1.1.

### 3.1 Konflik dokumen yang diputuskan mengikuti keputusan proyek

| # | Dokumen | Isi dokumen | Yang dipakai | Tindak lanjut |
|---|---|---|---|---|
| 1 | `02-MasterData.md` G-TC #4 | Re-ordering: ubah urutan 1 entitas, entitas lain ter-shift | `pangkat.order` mode manual (P2), tanpa geser | G-TC #4 dikecualikan untuk `pangkat`: test generik re-ordering tetap memakai master mode geser, perilaku pangkat dibuktikan `KenaikanPangkatTest::testPangkatManualOrderNeverShiftsOthers`. Mohon DBV mencatatnya sebagai pengecualian yang disetujui (Bagian 4 #5) |
| 2 | Kode legacy (`Lm_kp.php:193, 213`) | Keunikan pangkat = (`cpns`, `gol`, `ruang`, `gol_ruang`) untuk `status!='10'` | UNIQUE `gol_ruang` (P1), didukung lookup `gol_ruang` di `Siasn.php:2390, 2535` [K] | Bagian 3 #3; audit 6.3 #4 |
| 3 | Kode legacy (`Lm_pendidikan.php:178, 191, 376, 389, 569-590`) | Jurusan tanpa cek unik; jenjang/bidang unik hanya di antara status 1 | UNIQUE termasuk status 2/10 (P6, P7, P13) | Duplikat produksi wajib dirapikan sebelum impor (6.3 #4) |
| 4 | `05-Presensi.md` D-09 | Tarif uang makan dari `web_config` | Legacy [K] + K5 c(ii): tarif PPPK dari `gol_pppk.uang_makan` (`L_presensi.php:4013-4021`), PNS dari `web_config` | Dicatat untuk modul Presensi (Fase 5) |
| 5 | `02-MasterData.md` "Pola berulang" | Status `'1'` Aktif / `'0'` Non-aktif | Status 1/2/10 (keputusan DBV-001) | Tidak ada perubahan |

## 4. Keputusan yang diminta dari DB Validator (DBV-004)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema Bagian 2 + 2 migration (`2026-09-25-110000_CreateMasterKenaikanPangkat`, `2026-09-25-110100_CreateMasterPendidikan`) untuk dijalankan di Dev | Setujui. Sebelum dipakai di server MariaDB, jalankan sekali `migrate` → `migrate:rollback` → `migrate` di sana (Bagian 8) | ⏳ |
| 2 | `gol_pppk.status` TINYINT 1/2/10 menggantikan ENUM('1','2') (P4) | Setujui (deviasi wajib, Bagian 3 #1) | ⏳ |
| 3 | 7 UNIQUE nama, termasuk baris status 2/10, case-insensitive lewat collation | Setujui; audit duplikat wajib sebelum impor (6.3 #4) | ⏳ |
| 4 | `pangkat` UNIQUE(`gol_ruang`) + nameField `gol_ruang` (P1) | Setujui (keputusan proyek, Bagian 3 #3); audit `gol_ruang` ganda | ⏳ |
| 5 | `pangkat.order` = level, mode manual; G-TC #4 dikecualikan untuk pangkat; UNIQUE(cpns, order) ditunda (P2/P3) | Setujui; putuskan UNIQUE(cpns, order) setelah data produksi (6.3 #5) | ⏳ |
| 6 | FK jurusan → bidang `RESTRICT/RESTRICT` dengan nama legacy (D9) | Setujui (Bagian 3 #6) | ⏳ |
| 7 | `order` [V2] bidang/jurusan INT NOT NULL DEFAULT 1, impor diisi urut nama (P8/D4) | Setujui | ⏳ |
| 8 | Kolom audit D1 (`updated_at` NOT NULL + `updated_by`, tanpa `created_*`) untuk 4 tabel tanpa DDL. Perhatian: untuk `pangkat`/`jenis_kp` pola ini tanpa bukti library — saudara satu library `gol_pppk` berpola `created_at` + `updated_at` NULL (Bagian 3 #8) | Setujui sementara; cocokkan dengan dump struktur (Bagian 7). Bila produksi berpola `created_at`, diperlukan migration ALTER lanjutan | ⏳ |
| 9 | Tipe [I]: PK INT jenjang/jurusan (D3), panjang teks (D2), `order` TINYINT (D4), `cpns` (D12), flag (D7), `bobot_ipasn` (D13) | Setujui sementara; cocokkan dengan dump struktur (Bagian 7) | ⏳ |
| 10 | CHECK `chk_jenjang_pendidikan_row_jurusan` biner: peka huruf dan spasi di akhir (D5) | Setujui (Bagian 3 #7) | ⏳ |
| 11 | Tanpa CHECK `status`/`cpns`/flag (D6) | Setujui (konsisten DBV-001/002) | ⏳ |
| 12 | `bobot_ipasn` disimpan tetapi tidak diekspos API/FE (P6/D13) | Setujui | ⏳ |
| 13 | KEY FK jurusan dipertahankan walau berlebih (D8); tanpa KEY `order`/`status` (D10) | Setujui | ⏳ |
| 14 | Status 10 + COMMENT v2 (D11); kolom audit diisi aplikasi (`id_pengguna`, UTC), termasuk `gol_pppk.updated_at` yang ikut terisi saat tambah (Bagian 3 #12) | Setujui (sama dengan G-10 #9) | ⏳ |
| 15 | ID bermakna (6.4) dan butir audit data (6.3) sebagai prasyarat impor | Setujui | ⏳ |
| 16 | Default `jenjang_pendidikan.bobot_ipasn` untuk jenjang yang dibuat lewat v2. Kolom disembunyikan (P6) sehingga admin tidak bisa melihat/mengoreksinya, sedangkan DDL sekarang `DEFAULT 25` [I, `simpegdev_local`, asal-usul belum pasti] dan `L_user.php:878-884` memakai nilai > 0 sebagai skor kualifikasi pendidikan IP ASN — jenjang baru dari v2 diam-diam mendapat skor 25 | Ubah ke `DEFAULT NULL` (tanpa skor sampai diisi lewat impor/DBA); baris impor tetap menyalin nilai legacy. Bila disetujui, migration `2026-09-25-110100`, schema test, dan D13 disesuaikan sebelum merge; bila ditolak, tetap 25 | ⏳ |

## 5. Status keputusan G-01 terkait

| G-01 | Isi | Sebelumnya | Setelah DBV-004 |
|---|---|---|---|
| Keputusan #5 | Semua master wajib `order` + `status` (termasuk `jenis_kp`, `gol_pppk`, `bidang`/`jurusan_pendidikan`) | ✅ YA (23-09-2026) | Dijalankan: `order` `pangkat`, `jenis_kp`, `jenjang_pendidikan` ternyata ada di kode legacy [K]; `gol_pppk.order` ada di DDL [K]; `bidang_pendidikan`/`jurusan_pendidikan` ditambah [V2] (⏳ DBV-004 #7). Pengecualian: `pangkat.order` mode manual (⏳ DBV-004 #5) |
| Keputusan #6 | `jenjang_jf` ikut dimigrasi di G-01? | BELUM DIPUTUSKAN | `jenjang_jf` tidak terkait pendidikan; dirujuk `jabatan` (`fk_id_jenjang_jf_jab_to_jenjang_jf` [K ERD]) → dipindah ke DBV-008 (P11) |
| Bagian 3 | `gol_pppk` seed `nama_gol_pppk` vs Legacy Audit "nominal uang makan" | Menunggu DDL | Terjawab DDL [K] `simpeg_prod.sql:1277-1289`: kolom `gol_pppk` dan `uang_makan` |
| Bagian 3 | Master tanpa `order` di seed: `jenis_kp`, `gol_pppk`, `bidang`/`jurusan_pendidikan` | Menunggu DDL | Lihat baris Keputusan #5 di atas |
| Bagian 7 #1 | Induk non-aktif tidak menurunkan status ke anak; `options()` tidak menyaring rantai | Belum diputuskan | Untuk jurusan: dropdown memakai rantai status bidang → jurusan (P7, CR-011 dengan fitur rantai status CR-009) |

Penunjuk di G-01 ("→ lihat `G-04-G-05-pangkat-pendidikan-schema.md` (DBV-004)") tidak ditambahkan di branch ini agar branch DBV-003/004/005 yang dikerjakan paralel tidak saling konflik di G-01; ditambahkan setelah ketiganya di-merge, tanpa mengubah riwayat.

## 6. Verifikasi developer (sebelum review DB Validator)

### 6.1 Lingkungan

MySQL 8.0.30 lokal (Laragon), `sql_mode` server `STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`. Database scratch khusus grup ini (default group, tanpa prefix; DB dibuat `utf8mb4_unicode_ci`) untuk siklus `migrate` → `migrate:rollback` → `migrate`, dan database test khusus grup ini (DBPrefix `t_`, `strictOn=true`) — migration dijalankan otomatis oleh PHPUnit (`migrate:refresh`). Database dev `simpeg_v2` **tidak** dijalankan migration ini. **Belum diverifikasi di MariaDB 10.4** (Bagian 8).

### 6.2 Hasil

| Perintah | Hasil |
|---|---|
| `php spark migrate --all` (DB scratch; migration `main` sudah di batch 1) | Kedua migration DBV-004 jalan di batch 2; 26 tabel |
| `SHOW CREATE TABLE` 6 tabel | Sama dengan Bagian 2: PK signed (`tinyint`/`int`), `double NOT NULL DEFAULT '0'`, `updated_at … DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, 7 UNIQUE, `KEY fk_id_bidang_pendidikan_jpend_to_bpend`, FK `… ON DELETE RESTRICT ON UPDATE RESTRICT`, CHECK ditampilkan MySQL sebagai `(((row_jurusan is null) or ((row_jurusan collate utf8mb4_bin) in (_utf8mb4'D_I', …, _utf8mb4'S_3'))))` |
| `php spark migrate:rollback` | Hanya batch 2: `CreateMasterPendidikan` lalu `CreateMasterKenaikanPangkat`; 20 tabel `main` (Batch 1, FAQ, auth, queue) utuh |
| `php spark migrate --all` (ulang) | Batch 2 lagi; `SHOW CREATE TABLE` keenam tabel identik dengan run pertama |
| `phpunit --no-coverage tests/MasterData/PangkatPendidikanSchemaTest.php` | `OK (8 tests, 344 assertions)` (setelah CHECK biner dan kasus `'S_1 '`) |
| Ulang siklus di DB scratch setelah CHECK biner: `migrate:rollback` (hanya batch 2, 26 → 20 tabel) → `migrate --all` | `SHOW CREATE TABLE` keenam tabel identik antara dua run; CHECK tampil sebagai `cast(row_jurusan as char charset binary) in (…)`; `INSERT` `row_jurusan = 'S_1 '` ditolak 3819 |
| `phpunit --no-coverage tests/MasterData` (regresi: Batch1LegacySchemaTest, FaqSchemaTest, FaqTest, MasterGenericTcTest, RbacMasterEndpointsTest + test baru) | `OK (74 tests, 2849 assertions)`, 22:20 — 66 test lama tetap lolos tanpa perubahan, ditambah 8 test baru |
| `php-cs-fixer fix --dry-run --diff` (3 file baru) | `Found 0 of 3 files that can be fixed` |
| `phpstan analyse --memory-limit=1G` (3 file baru, level 5) | `[OK] No errors` |

Isi `PangkatPendidikanSchemaTest` (8 test): kolom & tipe persis Bagian 2 lewat `information_schema` (urutan, tanda signed, NULL), collation `utf8mb4_unicode_ci` per tabel & kolom string, InnoDB, seluruh index (tanpa KEY lain), satu-satunya FK dari/ke keenam tabel + kolom induk + `UPDATE_RULE`/`DELETE_RULE` = RESTRICT, satu-satunya CHECK; default & COMMENT `status`/`order`/`cpns`/kolom audit/flag/`row_jurusan`/`bobot_ipasn`/`uang_makan`, tanpa `created_*` di 5 tabel D1; `gol_pppk.status` menyimpan 10 dan 2; constraint DB menolak nama ganda beda kapitalisasi (termasuk terhadap baris status 10) di ketujuh UNIQUE, menerima nama & `order` sama CPNS/PNS (bukti tidak ada UNIQUE(cpns, order)) dan jurusan bernama sama di bidang lain; FK menolak orphan, hard delete bidang yang masih punya jurusan, dan ubah PK bidang yang dirujuk; CHECK menerima NULL dan ketujuh kode, menolak 'X', 's_1', string kosong, 'S_1 ' (spasi di akhir), dan UPDATE ke 'S1' / 'S_1  '; flag default 0; PK TINYINT signed menolak ID 128; `down()`/`up()` per migration hanya menyentuh tabelnya sendiri dan mengembalikan skema yang sama; `up()` yang gagal di tengah (penghalang `gol_pppk` / `jurusan_pendidikan`) men-drop hanya tabel yang dibuat run itu, tabel penghalang tidak tersentuh, lalu `up()` bisa diulang.

Bagian kode CR-011 (database test grup ini, `strictOn=true`):

| Perintah | Hasil |
|---|---|
| `phpunit --no-coverage tests/MasterData/KenaikanPangkatTest.php tests/MasterData/PendidikanTest.php` | `KenaikanPangkatTest` `OK (12 tests, 186 assertions)`, `PendidikanTest` `OK (10 tests, 239 assertions)` |
| `./check.sh` (root: PHPStan level 5, PHP-CS-Fixer, seluruh PHPUnit backend, ESLint, vue-tsc, Vitest, build) | `SEMUA CHECK LOLOS`: PHPStan `[OK] No errors`, PHP-CS-Fixer `Found 0 of 201 files`, PHPUnit `OK (394 tests, 12201 assertions)`, Vitest `21 passed (21)` / `211 passed (211)`, build selesai |
| Mutation check (38 mutasi: 18 awal + 20 perbaikan review; tiap mutasi dibatalkan lagi dengan `git checkout`) | 38 dari 38 tertangkap (test terkait gagal), rincian di bawah |

Isi test CR-011: `KenaikanPangkatTest` (12 test) — `order` kosong (spasi, `false`) tidak pernah menjadi level 0 dan array → 422, MAX+1 menghitung pangkat terhapus, PK TINYINT habis → 422, pencarian nama pangkat, `gol`/`ruang` wajib, `keterangan` spasi = NULL dan array → 422; urutan pangkat mode manual (tambah/PUT/PATCH/hapus/pulihkan tidak menggeser pangkat lain, tanpa audit untuk pangkat lain, tambah tanpa `order` = MAX+1 seluruh pangkat), batas 127 termasuk MAX+1 otomatis, `gol_ruang` unik termasuk terhadap baris dihapus, pilihan `cpns`/`gol`/`ruang` peka huruf, panjang `pangkat`/`gol_ruang`, filter `?cpns=` di dropdown & daftar (nilai tidak sah 422, parameter lain diabaikan, cache ikut ter-invalidate, terbuka untuk pegawai), jenis KP tetap mode geser, `gol_pppk` menyimpan status 10 lalu bisa dipulihkan, `uang_makan` wajib 0..10.000.000 (desimal, notasi `1e9` dan angka raksasa ditolak, role 3 → 403), `keterangan` 255 byte, kolom audit pola D1 dan pola FAQ; `PendidikanTest` (10 test) — wajib/panjang maksimal singkatan, nama Inggris, gelar, dan `extraSearch` ketiga master; `row_jurusan` spasi/tab/`false` = NULL (tambah & mengosongkan), array → 422; flag jurusan terkini dibaca dengan kunci baris; PK bidang TINYINT habis → 422; singkatan jenjang unik 422 pada field singkatan (termasuk baris Tidak Aktif/Dihapus dan balapan 1062), `row_jurusan` hanya dari 7 kode (kosong = NULL, bisa dikosongkan lagi), `bobot_ipasn` tidak ada di respons/meta/options mana pun dan nilainya tidak berubah, minimal satu flag jurusan (C2), nama jurusan unik per bidang + pindah bidang, dropdown jurusan ikut rantai status bidang (termasuk cache & role pegawai), bidang wajib aktif saat tambah/pindah, kolom audit tanpa `created_*` dan saudara yang hanya bergeser tidak di-stamp. Keenam master juga terdaftar di fixture G-TC generik, sehingga ikut `MasterGenericTcTest` (CRUD, duplikat, status, urutan, audit log, balapan, kolom audit) dan `RbacMasterEndpointsTest` (CRUD hanya role 1, dropdown semua role). Dua test bersama disesuaikan: `testLegacyAuditColumnsAreFilledWithActor` membaca kolom waktu yang memang ada di tabel (`created_at`, atau `updated_at` untuk tabel pola D1) dan `testSuperAdminPassesFilterOnEveryCrudEndpoint` memotong nama uji ke panjang kolom nama (`gol_ruang`/`gol_pppk` VARCHAR(10)).

Mutation check CR-011:

| # | Mutasi | Test yang gagal |
|---|---|---|
| 1 | `orderMode: manual` pangkat dihapus | `testPangkatManualOrderNeverShiftsOthers` |
| 2 | `orderColumnType: tinyint` pangkat dihapus | `testPangkatOrderIsBoundedByTinyint` (order 128 → 422 generik tanpa `errors.order`) |
| 3 | `filters: ['cpns']` dihapus | `testPangkatOptionsAndListFilterByCpns` |
| 4 | `min`/`max` `uang_makan` dihapus | `testGolPppkUangMakanIsEditableAndValidated` |
| 5 | `required` `uang_makan` dihapus | `testGolPppkUangMakanIsEditableAndValidated` |
| 6 | `uniqueFields` jenjang dihapus | `testJenjangSingkatanIsUniqueOnItsField` (1062 lolos sebagai error) |
| 7 | `hiddenColumns: ['bobot_ipasn']` dihapus | `testJenjangBobotIpasnIsNeverExposedOrChanged` |
| 8 | `row_jurusan` select → teks bebas | `testJenjangRowJurusanIsChosenFromFixedList` (CHECK 3819 lolos sebagai error) |
| 9 | `hooks` jurusan dihapus | `testJurusanRequiresAtLeastOneFlag` |
| 10 | Hook memeriksa juga ubah yang tidak menyentuh flag (C2 dibuang) | `testJurusanRequiresAtLeastOneFlag` |
| 11 | Hook: syarat flag dibalik | `testJurusanRequiresAtLeastOneFlag` |
| 12 | `statusChain` jurusan `false` | `testJurusanOptionsFollowBidangStatusChain` |
| 13 | `gol-pppk` dihapus dari `KpController::$entities` | `RbacMasterEndpointsTest::testSuperAdminPassesFilterOnEveryCrudEndpoint` (404) |
| 14 | FE: `:allow-empty` field non-ref dihapus | `MasterFormDialog.spec.ts` (select opsional) |
| 15 | `maxBytes` `keterangan` dihapus | `testGolPppkKeteranganIsLimitedTo255Bytes` |
| 16 | Flag `S_1` bukan boolean | `testJurusanRequiresAtLeastOneFlag` |
| 17 | Audit `gol_pppk` pola D1, bukan pola FAQ | `KenaikanPangkatTest::testAuditColumns` |
| 18 | nameField pangkat = `pangkat` | `testPangkatGolRuangIsUniqueAndFieldsValidated` |
| 19 | CHECK `row_jurusan` kembali ke `COLLATE utf8mb4_bin` (PAD SPACE) | `PangkatPendidikanSchemaTest::testConstraintsAreEnforced` (`'S_1 '` diterima) |
| 20 | `requestedOrder()` lama (hanya `null`/`''` dianggap kosong) | `testPangkatEmptyOrderNeverStoresZero` (`order` ' ' → 422, bukan MAX+1) |
| 21 | Batas bawah urutan < 1 di `requestedOrder()` dihapus | `testPangkatEmptyOrderNeverStoresZero` (service menyimpan level 0) |
| 22 | `MasterField::normalize()` tanpa trim-dulu dan tanpa `false` | `testJenjangRowJurusanIsChosenFromFixedList` (CHECK 3819 lolos), `testGolPppkKeteranganIsLimitedTo255Bytes` (`''` bukan NULL) |
| 23 | Penolakan array/objek JSON di `BaseMasterController::input()` dihapus | `testGolPppkKeteranganIsLimitedTo255Bytes` (ErrorException → 500) |
| 24 | MAX+1 mode manual kembali mengabaikan entri terhapus | `testPangkatAutoOrderCountsDeletedLevels` |
| 25 | 1062 PRIMARY tidak diterjemahkan (PK habis) | `testPangkatFullTinyintKeyGives422`, `PendidikanTest::testBidangFullTinyintKeyGives422` |
| 26 | Hook jurusan memakai snapshot `$existing` (tanpa kunci baris) | `testJurusanRequiresAtLeastOneFlag` |
| 27–28 | `required` `gol` / `ruang` dihapus | `testPangkatGolRuangIsUniqueAndFieldsValidated` |
| 29–30 | `required` / `max_length[50]` singkatan jenjang dihapus | `testPendidikanFieldRulesAndExtraSearch` |
| 31–33 | `max_length` `bidang_pendidikan_english` (100) / `jurusan_pendidikan_english` (255) / `gelar` (50) dihapus | `testPendidikanFieldRulesAndExtraSearch` |
| 34 | `extraSearch` pangkat dihapus | `testPangkatGolRuangIsUniqueAndFieldsValidated` |
| 35–37 | `extraSearch` jenjang / bidang / jurusan dihapus | `testPendidikanFieldRulesAndExtraSearch` |
| 38 | FE: `:allow-empty` field non-ref dihapus (ulang) | `MasterFormDialog.spec.ts`: 2 test gagal — pilihan kosong aktif dan alur edit mengosongkan `row_jurusan` (sebelumnya hanya 1) |

### 6.3 Catatan migrasi data untuk Mapping (audit sebelum impor)

Salinan lokal hanya memuat `bidang_pendidikan` dan `gol_pppk` (skema); isi keenam master produksi belum terlihat, jadi semua butir di bawah diperiksa di data produksi.

1. **ID legacy disalin apa adanya** (keenam tabel, P12); counter AUTO_INCREMENT naik otomatis. ID yang di-hard-code kode legacy: 6.4.
2. **`stripslashes`** pada kolom yang disimpan dengan `addslashes`: `pangkat` (`Lm_kp.php:229-236`), `jenis_kp` (`:441-444`), `gol_pppk` (`:660-663`), `jenjang_pendidikan` (`Lm_pendidikan.php:207-209`), `bidang_pendidikan` (`:405-406`), `jurusan_pendidikan` (`:595-597`; `gelar` tidak di-escape, `:598`). Dilakukan sebelum audit duplikat.
3. **`gol_pppk.status` ENUM → TINYINT**: salin `CAST(status AS CHAR)` ('1'/'2'). Nilai `''` (indeks ENUM 0, data rusak) ditetapkan manual bersama pemilik proses.
4. **Audit duplikat** dalam `utf8mb4_unicode_ci` (setelah `stripslashes` + trim), **semua status**: `pangkat.gol_ruang`, `jenis_kp`, `gol_pppk`, `jenjang_pendidikan` (nama **dan** singkatan), `bidang_pendidikan`, `jurusan_pendidikan` per (`id_bidang_pendidikan`, nama). Legacy hanya mengecek di aplikasi dengan filter berbeda (Bagian 3.1 #2–#3). Duplikat dirapikan dulu; impor akan gagal.
5. **`pangkat.order` per `cpns`**: petakan nilai (sama antara CPNS/PNS? ganda dalam satu `cpns`? 0?) sebagai dasar keputusan UNIQUE(cpns, order). **Jangan dinormalkan** — nilainya level pangkat.
6. **`order` `jenis_kp`, `gol_pppk`, `jenjang_pendidikan`**: legacy hanya validasi klien (form 1–100, jenjang 1–20; server hanya cek tidak kosong) → normalkan 1..n (urut `order`, id). `bidang_pendidikan`: `order` = urutan nama ASC; `jurusan_pendidikan`: per bidang, urutan nama ASC.
7. **Bug `S_2` di form ubah jurusan** (`views/hr/master/pendidikan/jurusan/form.php:87`: checkbox `S_2` diisi dari nilai `S_1`): setiap simpan ubah jurusan lewat UI menyalin `S_1` ke `S_2` kecuali admin mengoreksi. Jurusan yang pernah diubah tidak bisa dibedakan dari data asli (`set_param_jurusan` mengisi `updated_by` saat tambah maupun ubah, `Lm_pendidikan.php:463, 507, 606`), jadi daftar jurusan dengan `S_2` = `S_1` ditinjau bersama pemilik proses sebelum impor.
8. **Bug hapus Golongan PPPK** (`C_kp.php:444, 453` meng-hard-delete `pangkat` ber-id sama): cek lubang ID `pangkat` yang sama dengan ID `gol_pppk`, orphan `id_pangkat` di tabel anak, dan baris `dm_ak_jf` yang hilang (FK legacy `fk_dm_ak_jf_ibfk_02` ON DELETE CASCADE, `simpeg_prod.sql:468`).
9. **Bug tambah Golongan PPPK** (`C_kp.php:353, 367-383`: form & insert pangkat): cari baris `pangkat` yang `gol_ruang`-nya di luar 25 kunci standar ("CPNS I/a".."CPNS III/b", "I/a".."IV/e", `L_employee.php:2447`, `Siasn.php:2465`).
10. **Orphan**: legacy hard delete dan salinan lokal tanpa FK (`pegawai_kp`, `pegawai_mutasi_jabatan`) → audit orphan semua kolom id master di tabel anak sebelum FK Fase 3 (Bagian 2.9).
11. **`row_jurusan`**: nilai harus persis salah satu dari 7 kode (CHECK biner: peka huruf dan spasi di akhir — trim dulu; string kosong → NULL). Tentukan nilai untuk baris "Dokter"/"Apoteker" bila ada (kode legacy menyebut singkatan itu, `L_employee.php:1440`).
12. **Jurusan dengan ketujuh flag 0** (hanya mungkin lewat SQL manual; ubah bersamaan lewat aplikasi diserialkan kunci baris, Bagian 2.8): v2 tetap mengizinkan ubah kolom lain (nama, bidang, status), tetapi ubah yang menyentuh flag wajib menyisakan minimal satu flag 1 (C2, Bagian 2.8), dan jurusan itu tidak akan muncul di dropdown per jenjang → rapikan saat impor.
13. **`bobot_ipasn`, `uang_makan`**: salin apa adanya (NULL/0 = tanpa skor/tarif).
14. **`created_by`/`updated_by` legacy** berisi `user.id` akun legacy → petakan ke `id_pengguna` bila ID akun tidak dipertahankan (sama dengan G-10 6.3 #7). `updated_at`/`created_at` legacy jam server DB → konversi mengikuti keputusan zona waktu global (A-01).
15. **Status**: legacy hard delete sehingga tidak ada baris status 10 hasil hapus; status 2 hanya mungkin di `pangkat`/`jenis_kp`/`gol_pppk` (form pendidikan tidak punya pilihan status). Salin langsung **bila** tipe kolom produksi TINYINT; bila ENUM (seperti `gol_pppk` :1283; `simpegdev_local.pangkat`/`jenjang_pendidikan` juga `enum('0','1')` [I]), salin `CAST(status AS CHAR)` seperti #3 — salin langsung ENUM ke TINYINT memakai indeks ENUM dan menggeser nilai diam-diam.
16. **Kapasitas PK TINYINT**: `bidang_pendidikan` maks. ID 127 (AUTO_INCREMENT produksi 100), `gol_pppk` maks. 127 (produksi 19), `pangkat`/`jenis_kp` maks. 127. Setelah habis, tambah lewat aplikasi → 422 "Kode … sudah mencapai batas maksimal tipe kolom …" (Bagian 2.8); perlu keputusan pelebaran tipe (dengan tipe FK tabel anak) sebelum itu terjadi.
17. **Collation**: tabel legacy `bidang_pendidikan`/`gol_pppk` sudah `utf8mb4_unicode_ci`; empat tabel lain belum diketahui — cek di dump.

### 6.4 ID dan nilai yang di-hard-code kode legacy (impor wajib ID apa adanya; belum dikunci — P12)

| Master | ID / nilai | Makna | Bukti |
|---|---|---|---|
| `jenis_kp` | 1 | Pengangkatan CPNS (dropdown pangkat difilter `cpns=1`) | `L_employee.php:8855`, `L_kp.php:56, 67`, `Siasn.php:2459-2462`, `L_presensi.php:7064` |
| `jenis_kp` | 2 | Pengangkatan PNS; periode KP berikutnya 3 tahun (lainnya 4) | `L_employee.php:8860, 1515`, `siasn_sync/Rw_gol.php:98` |
| `jenis_kp` | 3 | Reguler (default sinkron SIASN) | `Siasn.php:2457-2458` |
| `jenis_kp` | 5 | Penyesuaian Ijazah | `L_employee.php:8867` |
| `jenis_kp` | 6 | Nama [I]; dikecualikan bersama id 2 dari sinkron golongan SIASN (jenis KP yang tidak mengubah golongan) | `siasn_sync/Rw_gol.php:98` |
| `jenis_kp` | semua | peta `siasn_simpeg.JENIS_KP_ID.id_jenis_kp` | `Siasn.php:2412-2414` |
| `pangkat` | semua | peta `siasn_simpeg.GOLONGAN_ID.id_pangkat`; kunci `gol_ruang` (25 kunci: "CPNS I/a".."CPNS III/b", "I/a".."IV/e"); level `order` | `Siasn.php:7748, 2390, 2535`, `Rw_skp.php:553`, `L_employee.php:2434-2441, 2447` |
| `jenjang_pendidikan` | 1, 2, 3 | SD/SLTP/SLTA: tanpa bidang/jurusan, memakai NEM | `L_pendidikan.php:62, 93, 833-834`, `A_employee.php:356, 795`, `Rw_pendidikan.php:150-151` |
| `jenjang_pendidikan` | > 3 | pilihan Tugas Belajar / Izin Belajar | `L_tb.php:71`, `L_ib.php:68` |
| `jenjang_pendidikan` | singkatan | "SD", "SLTP", "SLTA", "D.I", "D.II", "D.III", "D.IV", "S.1", "S.2", "Dokter", "Apoteker", "S.3" dibandingkan sebagai string | `L_employee.php:1440, 2449-2450` |
| `jenjang_pendidikan` | semua | peta `siasn_simpeg.TK_PENDIDIKAN.id_jenjang_pendidikan` | `Rw_pendidikan.php:36-46` |
| `bidang_pendidikan` | 98 | "Lainnya" → isian `bidang_pendidikan_lain`; default sinkron SIASN | `L_pendidikan.php:75, 822`, `A_employee.php:793`, `Rw_pendidikan.php:150` |
| `jurusan_pendidikan` | 1185 | "Lainnya" → isian `jurusan_pendidikan_lain`; default sinkron SIASN | `L_pendidikan.php:829`, `A_employee.php:355, 794`, `Rw_pendidikan.php:151` |
| `gol_pppk` | 7, 9, 10, 11, 12 | pilihan golongan pada approval cuti | `L_cuti.php:4545` (varian lama dikomentari `:4154`, `:4189`) |
| `gol_pppk` | nama | tarif uang makan PPPK dicocokkan dengan `gol_ruang` pegawai PPPK | `L_presensi.php:7825-7832, 8785` (per id `:4013-4021`) |

Konsekuensi selama belum ada fitur kunci baris: admin role 1 bisa menonaktifkan/menghapus (status 10) baris di atas atau mengganti namanya, yang akan mengubah perilaku modul lain di Fase 3–6. Ditinjau lagi di Fase 3 (P12).

**Pemulihan bila `up()` gagal di tengah** (DDL MySQL ter-commit per statement; migration yang gagal tidak tercatat di tabel `migrations`): `up()` masing-masing migration otomatis men-drop tabel grupnya yang sempat dibuat **pada run itu** (urutan terbalik, masih kosong) lalu melempar ulang error, sehingga setelah penyebabnya diperbaiki cukup jalankan ulang `php spark migrate` (diuji `PangkatPendidikanSchemaTest::testFailed*UpDropsOnlyTablesCreatedInThatRun`). Tabel yang sudah ada sebelum run tidak disentuh. Bila hanya `CreateMasterPendidikan` yang gagal, `CreateMasterKenaikanPangkat` sudah tercatat; `migrate` ulang hanya menjalankan migration pendidikan (batch berikutnya), sehingga kembali ke kondisi sebelum DBV-004 butuh dua kali `migrate:rollback`. Bila pembersihan otomatis ikut gagal (mis. koneksi putus), pulihkan manual — **jangan `migrate:rollback`** untuk migration yang tidak tercatat, karena rollback membatalkan batch terakhir yang tercatat (mis. `CreateFaq` atau `CreateMasterKenaikanPangkat`). Drop tabel kosong yang tersisa: pendidikan `jurusan_pendidikan`, `bidang_pendidikan`, `jenjang_pendidikan`; kenaikan pangkat `gol_pppk`, `jenis_kp`, `pangkat`; lalu `php spark migrate`. Catat kejadian di kartu DBV-004.

## 7. Nilai [I] yang menunggu dump struktur produksi

- `pangkat`: tipe/panjang `pangkat`, `gol`, `ruang`, `gol_ruang`, `cpns` (+ default), `order` (+ default), tipe `status` (TINYINT atau ENUM? — 6.3 #15), keberadaan & bentuk `updated_at`/`created_at`, index lain; isi: jumlah baris, `order` per baris, baris CPNS, ID.
- `jenis_kp`: semua tipe selain PK (termasuk `status`: TINYINT atau ENUM?); kolom audit; nama baris (arti id 4 dan 6).
- `jenjang_pendidikan`: tipe PK (INT?), panjang singkatan/nama, tipe `row_jurusan`, `bobot_ipasn` (+ default), `order`, tipe `status` (TINYINT atau ENUM?), kolom audit, collation; baris "Dokter"/"Apoteker" dan `row_jurusan`-nya.
- `jurusan_pendidikan`: tipe PK, panjang nama/english/gelar, tipe & default flag, tipe `status` (TINYINT atau ENUM?), kolom audit, aksi FK legacy, index, collation; jumlah duplikat nama per bidang.
- `bidang_pendidikan`, `gol_pppk`: skema [K]; hanya isi baris (`gol_pppk` ID 1–18) dan keberadaan FK ERD `*_ibfk_01` → `gol_pppk` di produksi.
- Tabel anak (Fase 3): tipe kolom id master dan keberadaan FK di produksi (`pegawai_kp`, `riwayat_kp`, `pegawai_pendidikan`, `riwayat_pendidikan`, `pegawai_kgb`, `riwayat_kgb`, `pegawai_mutasi_jabatan`, `riwayat_mutasi_jabatan`, `riwayat_tb`, `riwayat_ib`, `pegawai_cpns`, `pegawai_pns`, `pegawai_hukdis`, `riwayat_hukdis`, `pegawai_ak`, `riwayat_ak`, `konv_ak`).

**Permintaan data** (masuk permintaan gabungan ke pemegang akses `simpeg01`): `mysqldump --no-data` penuh; isi keenam master; duplikat nama dalam `utf8mb4_unicode_ci` (6.3 #4); orphan id master di tabel anak (6.3 #8, #10); peta `pangkat.order` × `cpns` (6.3 #5); `gol_ruang` di luar 25 kunci standar (6.3 #9); jurusan dengan `S_2` = `S_1` (6.3 #7).

## 8. Permintaan verifikasi MariaDB 10.4 (eksplisit)

DBV-001 dan DBV-002 disetujui tanpa laporan verifikasi MariaDB terpisah. Untuk DBV-004 mohon DB Validator menjalankan dan melaporkan di kartu/PR DBV-004:

1. Di database kosong MariaDB 10.4 (collation database `utf8mb4_unicode_ci`): `php spark migrate --all` → `php spark migrate:rollback` → `php spark migrate --all`; ketiganya tanpa error dan rollback hanya membatalkan batch DBV-004.
2. `SHOW CREATE TABLE` keenam tabel, dibandingkan dengan Bagian 2 (MariaDB menulis `tinyint(4)`/`int(11)` dan `current_timestamp()`; itu setara). MySQL 8.0.30 menulis klausa CHECK sebagai `cast(row_jurusan as char charset binary) in (…)`.
3. **CHECK** `chk_jenjang_pendidikan_row_jurusan` dengan `CAST(row_jurusan AS BINARY)`: `INSERT` dengan `row_jurusan` NULL dan `'S_1'` diterima; `'s_1'`, `'S_1 '` (spasi di akhir), `'X'`, dan `''` ditolak (juga dengan `sql_mode` non-strict). Cek `information_schema.TABLE_CONSTRAINTS` memuat baris `CONSTRAINT_TYPE = 'CHECK'` untuk constraint ini, dan `information_schema.CHECK_CONSTRAINTS` menampilkan klausa yang sama.
4. **FK** `fk_id_bidang_pendidikan_jpend_to_bpend` antara kolom TINYINT signed berhasil dibuat; `information_schema.REFERENTIAL_CONSTRAINTS` menunjukkan `UPDATE_RULE`/`DELETE_RULE` = `RESTRICT` (MariaDB bisa tidak menampilkan klausa RESTRICT di `SHOW CREATE TABLE`).
5. `uang_makan DOUBLE NOT NULL DEFAULT 0` dan `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` diterima apa adanya.
6. UNIQUE `uq_jurusan_pendidikan_nama` 1.021 byte diterima (row format DYNAMIC, default MariaDB 10.4; periksa `innodb_default_row_format`).
7. Bila memungkinkan, jalankan `vendor/bin/phpunit --no-coverage tests/MasterData/PangkatPendidikanSchemaTest.php` dengan database test MariaDB (helper test sudah menormalkan `tinyint(4)`, `current_timestamp()`, dan default `NULL` versi MariaDB).
8. Laporkan versi persis server, `sql_mode`, `character_set_server`/`collation_server`.
9. Bila memungkinkan, jalankan juga test fitur CR-011 `tests/MasterData/KenaikanPangkatTest.php` dan `tests/MasterData/PendidikanTest.php` dengan database test MariaDB (antara lain: `DOUBLE` `uang_makan` 41000.5, pesan 422 dari UNIQUE singkatan saat balapan 1062, dan `updated_at` saudara yang bergeser tidak berubah).
10. **PK TINYINT habis**: di database test, sisipkan `bidang_pendidikan` ber-ID 127 lalu tambah bidang lewat API (atau jalankan `PendidikanTest::testBidangFullTinyintKeyGives422`). MySQL 8.0.30 memberi 1062 pada PRIMARY (diterjemahkan 422). Laporkan kode error MariaDB 10.4 untuk kasus ini: bila bukan 1062 (mis. 167/1264 "Out of range"), respons bisa berbeda (1264 → 422 generik CR-007; kode lain → 500) dan penerjemahan perlu disesuaikan.
