# DB Validator Review — B-01/B-02 Pegawai, snapshot, riwayat & lampiran (skema legacy)

> **DRAF REVISI D1 (01-10-2026).** Ditulis ulang dari dump struktur produksi lengkap `simpeg01` 01-10-2026 (D1),
> menggantikan draf 30-09-2026 yang disusun dari stub lokal [L], ERD, dan kode legacy [I]. Satu dokumen gabungan untuk
> dua key review: B-01 (DBV-012) dan B-02 (DBV-013). Dikerjakan sebagai persiapan Fase 3 (pengecualian urutan fase
> disetujui user 01-10-2026).

**Key review:** `DBV-012` (B-01, Trello QASMTASK-043) dan `DBV-013` (B-02, QASMTASK-044). **Belum ada PR dan belum
di-push.** Draf ada di branch lokal `dbv-012/b01-b02-pegawai-riwayat-draf` (sudah di-merge dengan `origin/main`
01-10-2026). Migration yang **ditahan** (FK masuk `pengguna`/`faq_rate`, FK ke tabel G-02) tidak ikut branch ini;
drafnya di branch lokal `dbv-012/fk-ditahan` (Bagian 7). Usulan alur: `[CR]` + `[DBV]` (PR; DB Validator
review/approve, reviewer CR yang merge), karena PR juga mengubah test yang sudah ada di `main` (`AGENTS.md` bagian 2).
Urutan merge yang direncanakan: DBV-008 (G-02, PR #17) ↔ DBV-012 → DBV-013 → migration FK yang ditahan.

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR.** Semua pemeriksaan yang dicatat di sini adalah **pra-review internal
sisi CR**; belum ada butir yang direview atau disetujui DB Validator. Migration B-01/B-02 **JANGAN** dijalankan di
Dev/Production sebelum disetujui DBV-012/DBV-013.

**Rujukan:** `03-Kepegawaian.md` B-01 (:13-22), B-02 (:24-32), B-03/B-04 (DoD `pegawai_hist`), B-18 (lampiran);
`Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 2 & 3; ADR-006 / `app/Models/BaseSnapshotModel.php`; dokumen DBV di `main`
(`A-01` #4, D-7, D-8, D-12; `G-04-G-05` ; `G-06`; `G-10` D2); D1 = `simpeg01_struktur_lengkap_20261001.sql` (Bahan Baku
lokal, **tidak** di repo; rujukan `D1:baris`).

## 0. Ringkasan

| Hal | Isi |
|---|---|
| Tabel | 17 tabel B-01 (`pegawai`, `pegawai_hist`, `pegawai_foto`, 14 snapshot termasuk `pegawai_ak_siasn`) + 26 tabel B-02 [K] D1 + lookup `jenis_rwy` [V2] = **44 tabel** |
| Label | 43 tabel naik ke **[K] D1** (kolom, tipe, NULL, default, ON UPDATE, AUTO_INCREMENT, COMMENT, urutan kolom, PRIMARY/UNIQUE/KEY, nama FK disalin persis); tidak ada lagi kolom [I]/[L] |
| FK D1 | 165 FK: 96 dipasang di CREATE, 14 snapshot → riwayat di `131000`, 45 ke tabel G-02 **ditahan** sampai PR #17 merge, 10 ke `jabatan_koordinasi`/`rumpun_jabatan` (belum ada di v2) **menunggu**; + 1 FK [V2] `document_attachment` → `jenis_rwy` |
| Deviasi [V2] | Aksi FK RESTRICT/RESTRICT (semua); 31 CHECK domain; 6 kolom NIP VARCHAR(50) → VARCHAR(30); 8 kolom FK TINYINT → INT mengikuti PK master di `main`; trigger legacy tidak dibawa; collation per kolom/AUTO_INCREMENT/ROW_FORMAT tidak ditulis (tanpa efek) |
| Koreksi draf 30-09 | 569 selisih per kolom/index/FK draf vs D1 (Lampiran A), termasuk klaim "status riwayat VARCHAR(5)" yang keliru: status riwayat produksi sudah `TINYINT NOT NULL DEFAULT 0` |
| Keputusan diminta | Bagian 8 |

## 1. Latar belakang, sumber & cakupan

### 1.1 Keputusan proyek yang berlaku (tidak dibuka ulang)

| # | Isi | Dampak ke B-01/B-02 |
|---|---|---|
| K1 | PK `pegawai` = `nip` VARCHAR(30) `utf8mb4_unicode_ci`. Ganti NIP (B-06) = satu transaksi: salin baris → arahkan ulang tabel anak → hapus baris lama | Semua FK ke `pegawai` RESTRICT; kolom NIP ber-FK VARCHAR(30) (Bagian 2.3); registry NIP (Bagian 4.3) |
| K2 | Akun non-pegawai `pengguna.nip` NULL (DBV-010, di `main`) | FK `pengguna.nip` → `pegawai.nip` nullable, **ditahan** (Bagian 7) |
| DB terpisah | DB v2 terpisah dari legacy + impor (rencana in-place batal) | Skema v2 boleh menambah CHECK/RESTRICT; data legacy dinormalkan saat impor (Bagian 6); trigger legacy tidak dibawa (Bagian 5) |
| G2 | Collation `utf8mb4_unicode_ci` per tabel, FK `ON DELETE RESTRICT ON UPDATE RESTRICT` dengan nama legacy, AUTO_INCREMENT awal tidak ditulis, kolom audit diisi aplikasi (UTC, `id_pengguna`) | Seluruh tabel |
| Status | Master 1/2/10; status riwayat **0/1/2/10 sesuai produksi** (LKH + 3 Revisi) | CHECK Bagian 2.2 |
| K3 | Migration yang sudah di `main` tidak diedit; koreksi lewat migration baru + DBV | Tipe PK master yang berbeda dengan D1 tidak diubah di sini (Bagian 2.4, keputusan 8 #4) |
| ADR-006 | `BaseSnapshotModel::syncToActiveSnapshot()` meng-upsert satu baris per `nip` di tabel snapshot, hanya di approval final | Snapshot ber-PK `nip` [K]; aturan pemilihan baris = Bagian 5 |
| DBV-010 / A-01 D-12 | `pengguna.id_pengguna` INT UNSIGNED, `*_by` legacy INT signed | `*_by` tanpa FK |

### 1.2 Label sumber

| Label | Arti |
|---|---|
| **[K]** | DDL produksi D1 (`SHOW CREATE TABLE` seluruh schema `simpeg01`, 01-10-2026), dirujuk `D1:baris` |
| **[V2]** | Keputusan v2; setiap deviasi dari [K] dicatat per butir (Bagian 2, 3) |

Label lama draf 30-09 ([L] `simpeg_prod_duplikat`, [I] kode CI3, [K-erd] ERD, [K-m] tabel `d_*`, [E] E-Talenta) **tidak
dipakai lagi**: semua kolom kini bersumber D1. Hasil pencocokan ulang: `pegawai`, `pegawai_kp`, `pegawai_mutasi_jabatan`
(52 kolom), `pegawai_pns`, `pegawai_foto` yang di draf berlabel [L] ternyata **cocok** dengan D1; seluruh 56 nama FK di
branch `dbv-012/fk-ditahan` ada di D1 dengan kolom dan induk yang sama.

### 1.3 Cakupan

**DBV-012 (B-01):** `pegawai`, `pegawai_hist`, `pegawai_foto`, dan 14 snapshot satu-baris-per-`nip`: `pegawai_kp`,
`pegawai_cpns`, `pegawai_pns`, `pegawai_kgb`, `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_hukdis`, `pegawai_ak`,
**`pegawai_ak_siasn`** (baru, keputusan 8 #6), `pegawai_keluarga`, `pegawai_alamat`, `pegawai_alamat_kantor`,
`pegawai_tanda_jasa`, `pegawai_mutasi_jabatan`.

**DBV-013 (B-02):** `riwayat_mutasi_jabatan`, `pegawai_plt`, `pegawai_plh`, `riwayat_kp`, `riwayat_kgb`,
`riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `bkn_periode_ekinper`, `riwayat_skp`, `riwayat_skp_periodik`,
`riwayat_lckh`, `absen_ijin`, `riwayat_hukdis`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `ignore_konv_ak`,
`riwayat_keluarga`, `detail_anak`, `riwayat_alamat`, `riwayat_karpeg`, `riwayat_kariskarsu`, `riwayat_tanda_jasa`,
`riwayat_organisasi`, `document_attachment`, lookup `jenis_rwy` [V2], dan 14 FK snapshot → riwayat (`131000`).

**Di luar cakupan:**

| Tabel | Alasan / pemilik |
|---|---|
| `riwayat_cuti`, `riwayat_cuti_notif_kt`, `riwayat_tb`, `riwayat_tb_perpan`, `riwayat_ib`, `riwayat_pmk`, `riwayat_pmk_req`, `pegawai_sisa_cuti`, `petugas_layanan` | Fase 4 (C-01) |
| `dh_online`, `gaji_pegawai` | Fase 5 (D-01); kolom NIP-nya wajib masuk registry B-06 saat dibuat |
| `skl` | Tier 7 |
| `riwayat_ak_unsur`, `riwayat_ak_spmk`, `riwayat_ak_bk`, `riwayat_ak_skkp`, `riwayat_layanan`, `riwayat_mutasi_req`, `riwayat_konket_beta`, `riwayat_skp_periodik_temp`, `pegawai_test`, `riwayat_jabatan`, `pegawai_jabatan`, `jabatan_plt` | Tidak dipakai jalur aktif kode legacy (temuan draf 30-09, tetap berlaku) |
| `double_riwayat_kp`, `siasn_upload_arsip` | Tabel kerja sinkron SIASN; pemilik = integrasi SIASN (lap D3), dicatat untuk runbook impor |
| `aa_lkh`, `queue_aa_lkh` | Auto-approval LKH lewat cron; pemilik belum ditetapkan (keputusan 8 #13) |
| `simapi_ch`, `simapi_update`, `simapi_jd` | Antrean API simapi yang diisi trigger (Bagian 5); konsumen J3 |
| `d_*`, `da_deleted`, tabel backup `*_YYYYMMDD`, `riwayat_lckh_2019..2022` | Arsip hapus berbasis trigger / salinan historis; tidak diimpor |
| `jabatan_koordinasi`, `rumpun_jabatan` | Master yang dirujuk 10 FK B-01/B-02; belum ada di v2 maupun PR #17 (keputusan 8 #12) |

### 1.4 Koreksi klaim draf 30-09

1. **Status riwayat bukan VARCHAR.** Draf menyebut stub [L] `status VARCHAR(5) DEFAULT '1'` dan mengusulkan TINYINT
   sebagai deviasi. D1: semua `riwayat_*` sudah `status TINYINT NOT NULL DEFAULT '0'` (0 Waiting / 1 Approved /
   2 Rejected / 10 Deleted), cocok dengan v2 — **bukan deviasi**. Pengecualian [K]: `riwayat_pendidikan.status` INT NULL
   DEFAULT 0 (D1:6143), `riwayat_lckh.status` INT NOT NULL (D1:5473, 3 = Revisi), `riwayat_hukdis` COMMENT "1: Active,
   10: Deleted" dengan DEFAULT 0 (D1:4873), `riwayat_ak_siasn`/`riwayat_skp_periodik` DEFAULT 1, Plt/Plh DEFAULT 1.
2. **Blok workflow** (`show_ua_*`, `show_notif`, `updated_at`) di produksi NOT NULL DEFAULT 0 / NOT NULL (draf: NULL);
   tipe TINYINT vs INT berbeda per tabel dan kini mengikuti D1 persis (pertanyaan draf 4.1 #3 gugur).
3. **Snapshot tidak ditulis PHP, melainkan trigger** (D1 memuat badan trigger): aturan pemilihan baris kini diketahui
   (Bagian 5). Dugaan draf "kolom snapshot = kolom riwayat yang dibaca kode" keliru di 11 dari 13 snapshot (Lampiran A).
4. **`konv_ak` ber-PK `nip`** (D1:2057-2088), bukan PK surrogate; **`riwayat_ak_siasn`** ber-PK BIGINT dengan UNIQUE
   `id_rw_siasn` (D1:4174-4212). Usulan draf "UNIQUE ditunda sampai audit" untuk kedua tabel gugur — UNIQUE/PK [K]
   langsung dipakai.
5. **Lebar/tipe yang salah duga**, mis. `pegawai_hist.glr_awal` 12 / `glr_akhir` 24 / `email` 256, `riwayat_lckh.nama`
   256 (draf 150), `riwayat_karpeg`/`riwayat_kariskarsu` `file_1..5` VARCHAR(225) NOT NULL, `riwayat_skp_periodik` (banyak
   tipe), `pegawai_pendidikan.nem`/`ipk` DECIMAL; daftar lengkap di Lampiran A.
6. **Kolom SIASN** (`id_rw_siasn`, `siasn_*`) ada di banyak tabel produksi; kini ikut [K].
7. **Catatan verifikasi lama** yang menyebut uji FK masuk `120200` "pada DB pengembangan lokal berisi 14 akun ber-NIP"
   dikoreksi: uji fail-closed `120200` dilakukan di **DB scratch** bernama eksplisit dengan akun ber-NIP tiruan, bukan di
   DB pengembangan (lihat Bagian 9).

## 2. Konvensi bersama & deviasi [V2]

### 2.1 Sumber DDL

Setiap `CREATE TABLE` di migration `120000`..`130900` adalah DDL D1 per tabel dengan transformasi mekanis berikut
(tanpa efek pada hasil `information_schema` kecuali butir yang ditandai [V2]):

1. `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` per kolom dihapus — sama dengan default tabel (`ENGINE=InnoDB
   DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`). Seluruh 43 tabel D1 cakupan ini sudah `utf8mb4_unicode_ci`
   (tidak ada konversi collation untuk B-01/B-02).
2. `AUTO_INCREMENT=<n>` tabel tidak ditulis (G2); counter disalin saat impor (Bagian 6). `ROW_FORMAT=DYNAMIC` tidak
   ditulis (default InnoDB MySQL 8 / MariaDB 10.4).
3. Nama tabel di DDL ditulis `{{nama}}` dan diganti nama ber-prefix saat migrate (DBPrefix test `t_`).
4. FK disusun ulang dengan aksi [V2] (2.2) dan dipisah menurut tujuan (Bagian 4).

### 2.2 Deviasi [V2] bersama

| # | Deviasi | D1 | v2 | Alasan |
|---|---|---|---|---|
| V1 | Aksi FK | `CASCADE/CASCADE` (FK `nip`, FK snapshot → riwayat), `SET NULL/CASCADE` (FK master) | `RESTRICT/RESTRICT` semua | K1 (ganti NIP lewat B-06, bukan cascade); riwayat & master dihapus lunak (status 10), jadi hapus keras yang memutus relasi adalah bug yang harus ditolak DB |
| V2 | CHECK domain | tidak ada CHECK | 31 CHECK (daftar 2.2.1) | Menjaga domain status/flag yang dibaca aplikasi; nilai di luar domain dinormalkan saat impor (Bagian 6) |
| V3 | Trigger | trigger pengisi snapshot, notifikasi, antrean simapi, lintas schema, dan arsip hapus (Bagian 5) | tidak ada trigger | DB v2 terpisah; aturan dipindah ke aplikasi (Bagian 5) |
| V4 | Kolom NIP ber-FK VARCHAR(50) | `pegawai_ak`, `pegawai_ak_siasn`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `ignore_konv_ak` `.nip` VARCHAR(50) | VARCHAR(30) | = PK `pegawai.nip` (K1, registry B-06 seragam). Tanpa kehilangan data: FK legacy (CASCADE) sudah memaksa nilainya = `pegawai.nip` VARCHAR(30); tetap diaudit `MAX(CHAR_LENGTH(nip))` (Bagian 6) |
| V5 | Kolom FK ke 4 master | `id_jenjang_pendidikan`, `id_jenis_hukdis`, `id_tingkat_hukdis`, `id_tanda_jasa` TINYINT (8 kolom, 2.4) | INT | PK master di `main` (G-05/G-06) INT, sedangkan D1 TINYINT; FK mensyaratkan tipe sama. Penyelarasan master ↔ D1 = keputusan 8 #4 |
| V6 | FK baru | — | `fk_id_riwayat_da_to_jenis_rwy` (`document_attachment.id_riwayat` → `jenis_rwy`) | Lookup `jenis_rwy` [V2] menggantikan konstanta PHP `ARSIP_RWY` (DoD B-02) |

#### 2.2.1 Daftar CHECK [V2]

| Tabel | CHECK | Domain | Dasar |
|---|---|---|---|
| `pegawai` | `chk_pegawai_status`, `chk_pegawai_flag_update`, `chk_pegawai_jenis_kelamin` | `status` 1/2/10; `flag_update` 0..3; `jenis_kelamin` 1/2 | COMMENT [K] D1:2514, :2529-2530 |
| `pegawai_hist` | `chk_pegawai_hist_status`, `chk_pegawai_hist_flag_update`, `chk_pegawai_hist_jenis_kelamin` | sama dengan `pegawai` (default [K] `flag_update` 0) | D1:2956, :2972-2973 |
| `pegawai_plt`, `pegawai_plh` | `chk_<tabel>_status` | 1/2/10 | COMMENT [K] 1/2 (D1:3433, :3382) + 10 soft delete v2 |
| `riwayat_mutasi_jabatan`, `riwayat_kp`, `riwayat_kgb`, `riwayat_pendidikan`, `riwayat_diklat`, `riwayat_seminar`, `riwayat_skp`, `riwayat_hukdis`, `riwayat_keluarga`, `riwayat_tanda_jasa`, `riwayat_organisasi` | `chk_<tabel>_status` | 0/1/2/3/10 | COMMENT [K] 0/1/2/10 + 3 "Diproses" yang ditawarkan form admin legacy tabel-tabel ini (temuan draf 30-09, keputusan 8 #2) |
| `riwayat_alamat`, `riwayat_ak`, `riwayat_ak_siasn`, `konv_ak`, `riwayat_skp_periodik`, `riwayat_karpeg`, `riwayat_kariskarsu` | `chk_<tabel>_status` | 0/1/2/10 | COMMENT [K] / penulis legacy |
| `riwayat_lckh` | `chk_riwayat_lckh_status` | 0/1/2/3/10 | COMMENT [K] D1:5473 (3 = Revisi) + 10 soft delete v2 |
| `absen_ijin` | `chk_absen_ijin_status`, `chk_absen_ijin_affect_tukin`, `chk_absen_ijin_jenis_dinas` | `status` W/V/X/10; `affect_tukin` 1/2; `jenis_dinas` 0/1 (NULL boleh) | COMMENT [K] (D1:33-69) |
| `jenis_rwy` [V2] | `chk_jenis_rwy_status` | 1/2/10 | konvensi master |

CHECK mengizinkan NULL, jadi `riwayat_pendidikan.status` INT NULL [K] tetap menerima NULL. Tidak ada CHECK pada snapshot,
`detail_anak`, `document_attachment`, `bkn_periode_ekinper`, `ignore_konv_ak`, `pegawai_foto`.

### 2.3 NIP

Semua kolom NIP yang ber-FK ke `pegawai(nip)` VARCHAR(30) `utf8mb4_unicode_ci` NOT NULL, kecuali `riwayat_lckh.nip_atasan`
(NULL [K]). Nama kolom lampiran `NIP` (huruf besar) dipertahankan [K]. Kolom NIP tanpa FK [K]: `pegawai.nip_lama`,
`pegawai_hist.nip_lama`, `riwayat_skp.nip_penilai`/`nip_atasan_penilai` (VARCHAR(18)), `riwayat_skp_periodik.nip`/
`pegawai_atasan_nip` (VARCHAR(50), data API BKN). Registry lengkap: Bagian 4.3.

### 2.4 Tipe kolom FK vs PK master di `main`

| Master | PK D1 | PK `main` | Kolom anak (D1 TINYINT → v2 INT) |
|---|---|---|---|
| `jenjang_pendidikan` | TINYINT | INT | `pegawai_pendidikan.id_jenjang_pendidikan`, `riwayat_pendidikan.id_jenjang_pendidikan` |
| `jenis_hukdis` | TINYINT | INT | `pegawai_hukdis.id_jenis_hukdis`, `riwayat_hukdis.id_jenis_hukdis` |
| `tingkat_hukdis` | TINYINT | INT | `pegawai_hukdis.id_tingkat_hukdis`, `riwayat_hukdis.id_tingkat_hukdis` |
| `tanda_jasa` | TINYINT | INT | `pegawai_tanda_jasa.id_tanda_jasa`, `riwayat_tanda_jasa.id_tanda_jasa` |

Master lain yang dirujuk (`agama`, `jenis_pegawai`, `jenis_status`, `provinsi`, `kabupaten_kota`, `kecamatan`, `kelurahan`,
`pangkat`, `jenis_kp`, `gol_pppk`, `bidang_pendidikan`, `jurusan_pendidikan`, `diklat`) bertipe sama dengan D1. Ini temuan
J1.02 untuk dokumen G-04/G-05/G-06 yang sudah disetujui (tipe PK [I] INT); koreksi master hanya lewat migration baru + DBV
(K3), bukan di PR ini.

### 2.5 Kolom audit & COMMENT

Kolom audit (`created_at`, `updated_at`, `updated_by`, `approved_by`, `created_by`) mengikuti D1 per tabel; aplikasi v2
mengisinya (UTC, `id_pengguna`), default DB hanya cadangan. COMMENT kolom disalin persis dari D1 (bahasa Inggris/typo
legacy seperti "Watinting" dipertahankan) supaya skema = produksi; arti nilai v2 didokumentasikan di sini dan di kode
aplikasi (keputusan 8 #16).

## 3. Skema per tabel

Setiap subbagian merujuk baris D1, jumlah kolom, index, penempatan FK, CHECK, dan deviasi khusus tabel. DDL lengkap ada di
migration; ekspektasi test di `tests/_support/Kepegawaian/SkemaD1.php`. "Selisih draf" = jumlah butir beda draf 30-09
vs D1 (rinci di Lampiran A).

### 3.1 B-01 — pegawai, pengajuan, foto (`120000`)

#### 3.1.1 `pegawai` — [K] D1:2497-2550

- Migration `120000`; 40 kolom; PK `nip`; 6 KEY.
- FK: di CREATE 5 (`fk_id_agama_peg_to_agama`, `fk_id_jenis_pegawai_peg_to_jenis_pegawai`, `fk_id_jenis_status_peg_to_jenis_status`, `fk_id_kabupaten_kota_lahir_peg_to_kab_kota`, `fk_id_provinsi_lahir_peg_to_prov`). Aksi D1 SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_pegawai_status`, `chk_pegawai_flag_update`, `chk_pegawai_jenis_kelamin` (2.2.1).
- Selisih draf 30-09 → D1: 2 butir. Draf [L] `simpeg_prod_duplikat` cocok dengan D1; label naik [K]. COMMENT kini [K] ("10: Deleted", "0: No Update, …"), bukan COMMENT v2.

#### 3.1.2 `pegawai_hist` — [K] D1:2938-3003

- Migration `120000`; 48 kolom; PK `id_pegawai_hist`; 9 KEY.
- FK: di CREATE 6 (`fk_peg_hist_ibfk_01`, `fk_peg_hist_ibfk_02`, `fk_peg_hist_ibfk_03`, `fk_peg_hist_ibfk_04`, `fk_peg_hist_ibfk_05`, `fk_peg_hist_ibfk_06`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_pegawai_hist_status`, `chk_pegawai_hist_flag_update`, `chk_pegawai_hist_jenis_kelamin` (2.2.1).
- Selisih draf 30-09 → D1: 18 butir. Draf [I] keliru di 7 kolom: `glr_awal` VARCHAR(12), `glr_akhir` VARCHAR(24), `email` VARCHAR(256), `reason_note` TINYTEXT, `flag_update` DEFAULT 0, `show_ua_*` NOT NULL DEFAULT 0 (D1). Akibatnya CHECK `flag_update` menjadi 0..3 (draf 1/2/3) dan default pengajuan baru = 0; service B-03/B-04 wajib menulis 1 (Diajukan) eksplisit. KEY [K] `fk_peg_hist_ibfk_01..06`, `flag_update`, `status`, `show_notif`.

#### 3.1.3 `pegawai_foto` — [K] D1:2924-2933

- Migration `120000`; 4 kolom; PK `id_pegawai_foto`; 2 KEY.
- FK: di CREATE 1 (`fk_nip_pegfoto_to_peg`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 2 butir. Cocok; tambahan KEY [K] `foto`. Diisi trigger `pegawai_afIns/afUpd` (Bagian 5.2).

### 3.2 B-01 — snapshot `pegawai_*` (`120100`)

#### 3.2.1 `pegawai_kp` — [K] D1:3131-3162

- Migration `120100`; 22 kolom; PK `nip`; 3 KEY.
- FK: di CREATE 3 (`fk_id_jenis_kp_peg_kp_to_jenis_kp`, `fk_id_pangkat_peg_kp_to_pangkat`, `fk_nip_pegkp_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_kp_peg_kp_to_rwy_kp`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 1 butir. Cocok dengan draf [L]; label naik [K].

#### 3.2.2 `pegawai_cpns` — [K] D1:2861-2892

- Migration `120100`; 22 kolom; PK `nip`; 3 KEY.
- FK: di CREATE 3 (`fk_id_jenis_kp_pcpns_to_jenis_kp`, `fk_id_pangkat_pcpns_to_pangkat`, `fk_nip_pcpns_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_kp_pcpns_to_rwy_kp`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 2 butir. `id_riwayat_kp` NOT NULL [K] (draf NULL).

#### 3.2.3 `pegawai_pns` — [K] D1:3540-3571

- Migration `120100`; 22 kolom; PK `nip`; 3 KEY.
- FK: di CREATE 3 (`fk_id_jenis_kp_ppns_to_jenis_kp`, `fk_id_pangkat_ppns_to_pangkat`, `fk_nip_ppns_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_kp_ppns_to_rwy_kp`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 1 butir. Cocok dengan bentuk `pegawai_kp`.

#### 3.2.4 `pegawai_kgb` — [K] D1:3107-3126

- Migration `120100`; 12 kolom; PK `nip`; 2 KEY.
- FK: di CREATE 2 (`fk_nip_pegkgb_to_pegawai`, `pegawai_kgb_ibfk_01`); ke riwayat di `131000` 1 (`fk_id_riwayat_kgb_pkgb_to_rkgb`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 3 butir. Tambahan [K] `gol_pppk`; `tgl_sk` NOT NULL [K].

#### 3.2.5 `pegawai_pendidikan` — [K] D1:3326-3358

- Migration `120100`; 21 kolom; PK `nip`; 4 KEY.
- FK: di CREATE 4 (`fk_id_bidang_pendidikan_pegpend_to_bpend`, `fk_id_jenjang_pendidikan_pegpend_to_jpend`, `fk_id_jurusan_pendidikan_pegpend_to_jupend`, `fk_nip_pegpend_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_pendidikan_pegpend_to_rpend`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `id_jenjang_pendidikan` (V4/V5).
- Selisih draf 30-09 → D1: 9 butir. Tambahan [K] `glr_awal`/`glr_akhir`; `nem` DECIMAL(5,2), `ipk` DECIMAL(3,2) [K] (draf DOUBLE); `id_jenjang_pendidikan` TINYINT D1 → INT [V2] (V5).

#### 3.2.6 `pegawai_diklat` — [K] D1:2897-2919

- Migration `120100`; 15 kolom; PK `nip`; 2 KEY.
- FK: di CREATE 2 (`fk_id_diklat_pegdiklat_to_diklat`, `fk_nip_pegdiklat_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_diklat_pegdiklat_to_rwydiklat`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 9 butir. Tambahan [K] `id_sub_group_jabatan`, `nama_diklat_lain`, `id_rw_siasn`; kolom draf `jenis_diklat` tidak ada di D1 (dibuang).

#### 3.2.7 `pegawai_hukdis` — [K] D1:3008-3039

- Migration `120100`; 20 kolom; PK `nip`; 4 KEY.
- FK: di CREATE 4 (`fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis`, `fk_id_pangkat_peg_hukdis_to_pangkat`, `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis`, `fk_nip_peghukdis_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `id_tingkat_hukdis`, `id_jenis_hukdis` (V4/V5).
- Selisih draf 30-09 → D1: 9 butir. `id_tingkat_hukdis`/`id_jenis_hukdis` TINYINT D1 → INT [V2] (V5); `tmtsk` NOT NULL [K].

#### 3.2.8 `pegawai_ak` — [K] D1:2673-2717

- Migration `120100`; 35 kolom; PK `nip`; 3 KEY.
- FK: di CREATE 2 (`fk_id_pangkat_cak_to_pangkat`, `fk_nip_cak_to_peg`); ke riwayat di `131000` 1 (`fk_id_riwayat_ak_cak_to_rak`); ke G-02 ditahan 1 (`fk_id_jabatan_cak_to_jab`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 13 butir. Tambahan 23 kolom [K]; `nip` VARCHAR(50) D1 → VARCHAR(30) [V2] (V4).

#### 3.2.9 `pegawai_ak_siasn` — [K] D1:2742-2779

- Migration `120100`; 28 kolom; PK `nip`; 3 KEY.
- FK: di CREATE 2 (`fk_id_pangkat_peg_ak_siasn_04`, `fk_nip_peg_ak_siasn_01`); ke riwayat di `131000` 1 (`fk_id_riwayat_ak_siasn_peg_ak_siasn_02`); ke G-02 ditahan 1 (`fk_id_jabatan_peg_ak_siasn_03`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 0 butir. **Baru di cakupan** (keputusan 8 #6): snapshot `riwayat_ak_siasn` yang diisi trigger; `nip` VARCHAR(50) → VARCHAR(30) [V2] (V4).

#### 3.2.10 `pegawai_keluarga` — [K] D1:3088-3102

- Migration `120100`; 9 kolom; PK `nip`; 1 KEY.
- FK: di CREATE 1 (`fk_nip_pegkeluarga_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 5 butir. `urutan_perkawinan` NOT NULL DEFAULT 1, `tgl_perkawinan`/`nama_pasangan` NOT NULL [K].

#### 3.2.11 `pegawai_alamat` — [K] D1:2784-2817

- Migration `120100`; 20 kolom; PK `nip`; 5 KEY.
- FK: di CREATE 5 (`fk_id_kabupaten_kota_pegalamat_kabkota`, `fk_id_kecamatan_pegalamat_kecamatan`, `fk_id_kelurahan_pegalamat_kelurahan`, `fk_id_provinsi_pegalamat_provinsi`, `fk_nip_pegalamat_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_alamat_pegalamat_riwalamat`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 8 butir. Tambahan [K] `alamat_utama`, `keterangan`; `kd_pos` CHAR(5) [K].

#### 3.2.12 `pegawai_alamat_kantor` — [K] D1:2822-2856

- Migration `120100`; 21 kolom; PK `nip`; 5 KEY.
- FK: di CREATE 5 (`pegawai_alamat_kantor_ibfk_1`, `pegawai_alamat_kantor_ibfk_2`, `pegawai_alamat_kantor_ibfk_3`, `pegawai_alamat_kantor_ibfk_4`, `pegawai_alamat_kantor_ibfk_6`); ke riwayat di `131000` 1 (`pegawai_alamat_kantor_ibfk_5`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 16 butir. Bentuk mengikuti D1 (FK `pegawai_alamat_kantor_ibfk_1..6`).

#### 3.2.13 `pegawai_tanda_jasa` — [K] D1:3669-3686

- Migration `120100`; 10 kolom; PK `nip`; 2 KEY.
- FK: di CREATE 2 (`fk_id_tanda_jasa_pegtj_to_tj`, `fk_nip_pegtj_to_pegawai`); ke riwayat di `131000` 1 (`fk_id_riwayat_tanda_jasa_ptj_to_rtj`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `id_tanda_jasa` (V4/V5).
- Selisih draf 30-09 → D1: 11 butir. `id_tanda_jasa` TINYINT D1 → INT [V2] (V5).

#### 3.2.14 `pegawai_mutasi_jabatan` — [K] D1:3192-3277

- Migration `120100`; 52 kolom; PK `nip`; 15 KEY.
- FK: di CREATE 2 (`fk_nip_pmj_to_pegawai`, `pegawai_mutasi_jabatan_ibfk_01`); ke riwayat di `131000` 1 (`fk_id_riwayat_mutasi_jabatan_pmj_to_rmj`); ke G-02 ditahan 9 (`fk_id_atasan_es_1_pmj_to_jabatan`, `fk_id_atasan_es_2_pmj_to_jabatan`, `fk_id_atasan_es_3_pmj_to_jabatan`, `fk_id_atasan_es_4_pmj_to_jabatan`, `fk_id_group_jabatan_pmj_to_gj`, `fk_id_jabatan_pmj_to_jabatan`, `fk_id_satker_pmj_to_satker`, `fk_id_sub_group_jabatan_pmj_to_sgj`, `fk_id_unit_pmj_to_unit`); ke tabel belum ada di v2 4 (`fk_id_atasan_es_3_koord_pmj_to_jabkoor`, `fk_id_atasan_es_4_koord_pmj_to_jabkoor`, `fk_id_jabatan_koord_pmj_to_jabkoor`, `pegawai_mutasi_jabatan_ibfk_02`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 2 butir. Cocok dengan draf [L] (52 kolom); label naik [K].

### 3.3 B-02 — jabatan, Plt/Plh, KP, KGB, pendidikan, diklat, seminar (`130100`..`130300`)

#### 3.3.1 `riwayat_mutasi_jabatan` — [K] D1:5713-5810

- Migration `130100`; 65 kolom; PK `id_riwayat_mutasi_jabatan`; 15 KEY.
- FK: di CREATE 2 (`fk_nip_rwymutasijabatan_to_pegawai`, `riwayat_mutasi_jabatan_ibfk_01`); ke G-02 ditahan 9 (`fk_id_atasan_es_1_rwymj_to_jabatan`, `fk_id_atasan_es_2_rwymj_to_jabatan`, `fk_id_atasan_es_3_rwymj_to_jabatan`, `fk_id_atasan_es_4_rwymj_to_jabatan`, `fk_id_group_jabatan_rwymj_to_gj`, `fk_id_jabatan_rwymj_to_jabatan`, `fk_id_satker_rwymj_to_satker`, `fk_id_sub_group_jabatan_rwymj_to_sgj`, `fk_id_unit_rwymj_to_unit`); ke tabel belum ada di v2 4 (`fk_id_atasan_es_3_koord_rmj_to_jabkoor`, `fk_id_atasan_es_4_koord_rmj_to_jabkoor`, `fk_id_jabatan_koord_rmj_to_jabkoor`, `riwayat_mutasi_jabatan_ibfk_02`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_mutasi_jabatan_status` (2.2.1).
- Selisih draf 30-09 → D1: 18 butir. Draf "stub [L] + [I]" diganti DDL D1 penuh (65 kolom, termasuk kolom SIASN).

#### 3.3.2 `pegawai_plt` — [K] D1:3414-3460

- Migration `130100`; 22 kolom; PK `id_pegawai_plt`; 11 KEY.
- FK: di CREATE 1 (`fk_nip_plt_plt_to_pegawai`); ke G-02 ditahan 9 (`fk_id_group_jabatan_plt_plt_to_gj`, `fk_id_group_jabatan_plt_to_gj`, `fk_id_jabatan_plt_to_jabatan`, `fk_id_satker_plt_plt_to_satker`, `fk_id_satker_plt_to_satker`, `fk_id_sub_group_jabatan_plt_plt_to_sgj`, `fk_id_sub_group_jabatan_plt_to_sgj`, `fk_id_unit_plt_plt_to_unit`, `fk_id_unit_plt_to_unit`); ke tabel belum ada di v2 1 (`fk_id_jabatan_koord_plt_to_jabkoord`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_pegawai_plt_status` (2.2.1).
- Selisih draf 30-09 → D1: 10 butir. Penugasan Plt (B-07), bukan snapshot. Status [K] TINYINT NOT NULL DEFAULT 1.

#### 3.3.3 `pegawai_plh` — [K] D1:3363-3409

- Migration `130100`; 22 kolom; PK `id_pegawai_plh`; 11 KEY.
- FK: di CREATE 1 (`fk_pegawai_plh_ibfk_01`); ke G-02 ditahan 9 (`fk_pegawai_plh_ibfk_02`, `fk_pegawai_plh_ibfk_04`, `fk_pegawai_plh_ibfk_05`, `fk_pegawai_plh_ibfk_06`, `fk_pegawai_plh_ibfk_07`, `fk_pegawai_plh_ibfk_08`, `fk_pegawai_plh_ibfk_09`, `fk_pegawai_plh_ibfk_10`, `fk_pegawai_plh_ibfk_11`); ke tabel belum ada di v2 1 (`fk_pegawai_plh_ibfk_03`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_pegawai_plh_status` (2.2.1).
- Selisih draf 30-09 → D1: 10 butir. Pemetaan `fk_pegawai_plh_ibfk_01..11` → kolom kini [K] (pertanyaan draf K2-6 gugur).

#### 3.3.4 `riwayat_kp` — [K] D1:5338-5381

- Migration `130200`; 35 kolom; PK `id_riwayat_kp`; 3 KEY.
- FK: di CREATE 3 (`fk_id_jenis_kp_rwy_kp_to_jenis_kp`, `fk_id_pangkat_rwy_kp_to_pangkat`, `fk_nip_rwykp_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_kp_status` (2.2.1).
- Selisih draf 30-09 → D1: 23 butir. Status [K] TINYINT NOT NULL DEFAULT 0 (bukan VARCHAR).

#### 3.3.5 `riwayat_kgb` — [K] D1:5277-5307

- Migration `130200`; 22 kolom; PK `id_riwayat_kgb`; 4 KEY.
- FK: di CREATE 2 (`fk_nip_rwykgb_to_pegawai`, `riwayat_kgb_ibfk_01`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_kgb_status` (2.2.1).
- Selisih draf 30-09 → D1: 19 butir. Tambahan kolom [K] PPPK/SIASN.

#### 3.3.6 `riwayat_pendidikan` — [K] D1:6119-6164

- Migration `130300`; 34 kolom; PK `id_riwayat_pendidikan`; 5 KEY.
- FK: di CREATE 4 (`fk_id_bidang_pendidikan_rpend_to_bpend`, `fk_id_jenjang_pendidikan_rpend_to_jpend`, `fk_id_jurusan_pendidikan_rpend_jupend`, `fk_nip_rwypendidikan_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_pendidikan_status` (2.2.1); tipe `id_jenjang_pendidikan` (V4/V5).
- Selisih draf 30-09 → D1: 24 butir. `status` [K] INT NULL DEFAULT 0 dan `show_notif` INT NULL (keputusan 8 #8); `id_jenjang_pendidikan` TINYINT → INT [V2] (V5).

#### 3.3.7 `riwayat_diklat` — [K] D1:4714-4755

- Migration `130300`; 33 kolom; PK `id_riwayat_diklat`; 4 KEY.
- FK: di CREATE 2 (`fk_id_diklat_rdiklat_to_diklat`, `fk_nip_rwydiklat_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_diklat_status` (2.2.1).
- Selisih draf 30-09 → D1: 25 butir.

#### 3.3.8 `riwayat_seminar` — [K] D1:6264-6292

- Migration `130300`; 24 kolom; PK `id_riwayat_seminar`; 1 KEY.
- FK: di CREATE 1 (`fk_nip_rwyseminar_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_seminar_status` (2.2.1).
- Selisih draf 30-09 → D1: 22 butir.

### 3.4 B-02 — SKP, LKH, konket, hukdis, angka kredit (`130400`..`130600`)

#### 3.4.1 `bkn_periode_ekinper` — [K] D1:270-284

- Migration `130400`; 11 kolom; PK `id`; 1 KEY.
- FK: tidak ada.
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 1 butir. Cocok [K].

#### 3.4.2 `riwayat_skp` — [K] D1:6324-6366

- Migration `130400`; 37 kolom; PK `id_riwayat_skp`; 2 KEY.
- FK: di CREATE 1 (`fk_nip_rwyskp_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_skp_status` (2.2.1).
- Selisih draf 30-09 → D1: 34 butir. `tahun` YEAR [K] (draf SMALLINT).

#### 3.4.3 `riwayat_skp_periodik` — [K] D1:6408-6446

- Migration `130400`; 35 kolom; PK `id_riwayat_skp_periodik`; 1 KEY.
- FK: tidak ada.
- Deviasi [V2] khusus: CHECK `chk_riwayat_skp_periodik_status` (2.2.1).
- Selisih draf 30-09 → D1: 33 butir. PK BIGINT [K]; banyak tipe draf salah duga (Lampiran A). Status DEFAULT 1 [K].

#### 3.4.4 `riwayat_lckh` — [K] D1:5455-5495

- Migration `130400`; 27 kolom; PK `id_riwayat_lckh`; 7 KEY.
- FK: di CREATE 2 (`fk_riwayat_lckh_ibfk_01`, `fk_riwayat_lckh_ibfk_02`); ke G-02 ditahan 2 (`fk_riwayat_lckh_ibfk_03`, `fk_riwayat_lckh_ibfk_04`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_lckh_status` (2.2.1).
- Selisih draf 30-09 → D1: 15 butir. `nama` VARCHAR(256) [K] (draf 150); `status` INT NOT NULL [K]; `nip_atasan` nullable FK.

#### 3.4.5 `absen_ijin` — [K] D1:33-69

- Migration `130500`; 28 kolom; PK `id`; 5 KEY.
- FK: di CREATE 1 (`fk_nip_abijin_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_absen_ijin_status`, `chk_absen_ijin_affect_tukin`, `chk_absen_ijin_jenis_dinas` (2.2.1).
- Selisih draf 30-09 → D1: 2 butir. Cocok [K] (sudah [K] di draf). `status` CHAR(2) W/V/X/10.

#### 3.4.6 `riwayat_hukdis` — [K] D1:4853-4893

- Migration `130600`; 30 kolom; PK `id_riwayat_hukdis`; 4 KEY.
- FK: di CREATE 4 (`fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis`, `fk_id_pangkat_rwyhukdis_to_pangkat`, `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis`, `fk_nip_rwyhukdis_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_hukdis_status` (2.2.1); tipe `id_tingkat_hukdis`, `id_jenis_hukdis` (V4/V5).
- Selisih draf 30-09 → D1: 12 butir. Blok workflow [K] ada di produksi; COMMENT status "1: Active, 10: Deleted", DEFAULT 0 (keputusan 8 #10); `id_tingkat_hukdis`/`id_jenis_hukdis` TINYINT → INT [V2] (V5).

#### 3.4.7 `riwayat_ak` — [K] D1:4034-4092

- Migration `130600`; 50 kolom; PK `id_riwayat_ak`; 3 KEY.
- FK: di CREATE 2 (`fk_id_pangkat_rak_to_pangkat`, `fk_nip_rak_to_peg`); ke G-02 ditahan 1 (`fk_id_jabatan_rak_to_jab`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_ak_status` (2.2.1); tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 38 butir. `nip` VARCHAR(50) → VARCHAR(30) [V2] (V4).

#### 3.4.8 `riwayat_ak_siasn` — [K] D1:4174-4212

- Migration `130600`; 29 kolom; PK `id_riwayat_ak_siasn`; UNIQUE `id_rw_siasn`; 3 KEY.
- FK: di CREATE 2 (`fk_id_pangkat_rw_ak_siasn_03`, `fk_nip_rw_ak_siasn_01`); ke G-02 ditahan 1 (`fk_id_jabatan_rw_ak_siasn_02`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_ak_siasn_status` (2.2.1); tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 22 butir. PK BIGINT, UNIQUE `id_rw_siasn` [K]; `nip` VARCHAR(50) → VARCHAR(30) [V2] (V4).

#### 3.4.9 `konv_ak` — [K] D1:2057-2088

- Migration `130600`; 20 kolom; PK `nip`; 4 KEY.
- FK: di CREATE 2 (`fk_id_pangkat_konvak_to_pangkat`, `fk_nip_konvak_to_pegawai`); ke G-02 ditahan 3 (`fk_id_jabatan_konvak_to_jabatan`, `fk_id_satker_konvak_to_satker`, `fk_id_unit_konvak_to_unit`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_konv_ak_status` (2.2.1); tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 16 butir. **PK `nip`** [K] (D1:2057-2088); `nip` VARCHAR(50) → VARCHAR(30) [V2] (V4).

#### 3.4.10 `ignore_konv_ak` — [K] D1:1346-1351

- Migration `130600`; 2 kolom; PK `nip`, `ignore_date`; 0 KEY.
- FK: di CREATE 1 (`fk_nip_ignorekonvak_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tipe `nip` (V4/V5).
- Selisih draf 30-09 → D1: 2 butir. `nip` VARCHAR(50) → VARCHAR(30) [V2] (V4); PK (`nip`, `ignore_date`) [K].

### 3.5 B-02 — keluarga, alamat, kartu, tanda jasa, organisasi, lampiran, `jenis_rwy` (`130000`, `130700`..`130900`)

#### 3.5.1 `riwayat_keluarga` — [K] D1:5247-5272

- Migration `130700`; 19 kolom; PK `id_riwayat_keluarga`; 3 KEY.
- FK: di CREATE 1 (`fk_nip_rwykeluarga_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_keluarga_status` (2.2.1).
- Selisih draf 30-09 → D1: 21 butir.

#### 3.5.2 `detail_anak` — [K] D1:373-386

- Migration `130700`; 9 kolom; PK `id_detail_anak`; 1 KEY.
- FK: di CREATE 1 (`fk_id_riwayat_keluarga_detanak_to_rwykeluarga`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 1 butir. Cocok [K]; FK ke `riwayat_keluarga` RESTRICT (D1 CASCADE).

#### 3.5.3 `riwayat_alamat` — [K] D1:4300-4350

- Migration `130700`; 35 kolom; PK `id_riwayat_alamat`; 8 KEY.
- FK: di CREATE 5 (`fk_id_kabupaten_kota_riwalamat_kabkota`, `fk_id_kecamatan_riwalamat_kecamatan`, `fk_id_kelurahan_riwalamat_kelurahan`, `fk_id_provinsi_riwalamat_provinsi`, `fk_nip_rwyalamat_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_alamat_status` (2.2.1).
- Selisih draf 30-09 → D1: 23 butir. Kolom `kd_pos` dan `KdPos` [K] keduanya ada.

#### 3.5.4 `riwayat_karpeg` — [K] D1:5122-5149

- Migration `130800`; 23 kolom; PK `id_riwayat_karpeg`; 1 KEY.
- FK: di CREATE 1 (`fk_nip_rkarpeg_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_karpeg_status` (2.2.1).
- Selisih draf 30-09 → D1: 26 butir. `file_1..5` VARCHAR(225) NOT NULL [K]; `rated` DEFAULT 2.

#### 3.5.5 `riwayat_kariskarsu` — [K] D1:5061-5088

- Migration `130800`; 23 kolom; PK `id_riwayat_kariskarsu`; 1 KEY.
- FK: di CREATE 1 (`fk_nip_rkariskarsu_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_kariskarsu_status` (2.2.1).
- Selisih draf 30-09 → D1: 22 butir. `file_1..5` VARCHAR(225) NOT NULL [K].

#### 3.5.6 `riwayat_tanda_jasa` — [K] D1:6507-6539

- Migration `130800`; 24 kolom; PK `id_riwayat_tanda_jasa`; 4 KEY.
- FK: di CREATE 2 (`fk_id_tanda_jasa_rtj_to_tj`, `fk_nip_rwytj_to_pegawai`). Aksi D1 CASCADE/CASCADE, SET NULL/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_tanda_jasa_status` (2.2.1); tipe `id_tanda_jasa` (V4/V5).
- Selisih draf 30-09 → D1: 22 butir. `id_tanda_jasa` TINYINT → INT [V2] (V5).

#### 3.5.7 `riwayat_organisasi` — [K] D1:6092-6114

- Migration `130800`; 18 kolom; PK `id_riwayat_organisasi`; 1 KEY.
- FK: di CREATE 1 (`fk_nip_rwyorganisasi_to_pegawai`). Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: CHECK `chk_riwayat_organisasi_status` (2.2.1).
- Selisih draf 30-09 → D1: 15 butir.

#### 3.5.8 `document_attachment` — [K] D1:498-520

- Migration `130900`; 17 kolom; PK `id_attachment`; 2 KEY.
- FK: di CREATE 1 (`fk_NIP_da_to_pegawai`); [V2] `fk_id_riwayat_da_to_jenis_rwy`. Aksi D1 CASCADE/CASCADE → RESTRICT/RESTRICT (V1).
- Deviasi [V2] khusus: tidak ada selain V1.
- Selisih draf 30-09 → D1: 2 butir. Cocok [K] (kolom `NIP` huruf besar); FK [V2] `fk_id_riwayat_da_to_jenis_rwy` (V6).

#### 3.5.9 `jenis_rwy` — [V2] (tidak ada di D1)

- Migration `130000`; lookup kode jenis lampiran `document_attachment.id_riwayat` (konstanta legacy `ARSIP_RWY`/
  `JENIS_RWY_ARSIP`), PK = kode legacy tanpa AUTO_INCREMENT, UNIQUE `uq_jenis_rwy_kode`/`uq_jenis_rwy_nama`, CHECK
  `chk_jenis_rwy_status`, seed 21 baris termasuk 0 "Belum Terhubung". Tidak berubah dari draf 30-09 (keputusan 8 #11).

## 4. Ringkasan FK & registry NIP

### 4.1 Penempatan FK

| Kelompok | Jumlah | Migration | Status |
|---|---|---|---|
| FK di CREATE (ke `pegawai`, master di `main`, antar tabel B-02) | 96 | `120000`..`130900` | ikut PR |
| FK [V2] `fk_id_riwayat_da_to_jenis_rwy` | 1 | `130900` | ikut PR |
| Snapshot → riwayat (RWY) | 14 | `131000_AddFkSnapshotKeRiwayat` (pra-cek orphan fail-closed) | ikut PR DBV-013 |
| Ke tabel G-02 (`unit`, `satker`, `jabatan`, `group_jabatan`, `sub_group_jabatan`) | 45 | `AddFkG02Pegawai` (11) + `AddFkG02Riwayat` (34), branch `dbv-012/fk-ditahan` | **ditahan** sampai PR #17 merge |
| Ke `jabatan_koordinasi` / `rumpun_jabatan` | 10 | konstanta `MENUNGGU_TABEL` / `FK_KOORD_RUMPUN` di branch yang sama | **menunggu** master baru |

Setiap FK yang dipasang belakangan sudah punya index dengan kolom terdepan = kolom FK di DDL D1 (dicek generator), jadi
migration penyusul cukup `ADD CONSTRAINT` tanpa index baru.

### 4.2 FK snapshot → riwayat (`131000`)

| FK (nama D1) | Snapshot.kolom | Riwayat | Aksi D1 → v2 |
|---|---|---|---|
| `fk_id_riwayat_ak_cak_to_rak` | `pegawai_ak.id_riwayat_ak` | `riwayat_ak` | SET NULL/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_ak_siasn_peg_ak_siasn_02` | `pegawai_ak_siasn.id_riwayat_ak_siasn` | `riwayat_ak_siasn` | SET NULL/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_alamat_pegalamat_riwalamat` | `pegawai_alamat.id_riwayat_alamat` | `riwayat_alamat` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat` | `pegawai_diklat.id_riwayat_diklat` | `riwayat_diklat` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` | `pegawai_hukdis.id_riwayat_hukdis` | `riwayat_hukdis` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga` | `pegawai_keluarga.id_riwayat_keluarga` | `riwayat_keluarga` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_kgb_pkgb_to_rkgb` | `pegawai_kgb.id_riwayat_kgb` | `riwayat_kgb` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_kp_pcpns_to_rwy_kp` | `pegawai_cpns.id_riwayat_kp` | `riwayat_kp` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_kp_peg_kp_to_rwy_kp` | `pegawai_kp.id_riwayat_kp` | `riwayat_kp` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_kp_ppns_to_rwy_kp` | `pegawai_pns.id_riwayat_kp` | `riwayat_kp` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj` | `pegawai_mutasi_jabatan.id_riwayat_mutasi_jabatan` | `riwayat_mutasi_jabatan` | SET NULL/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_pendidikan_pegpend_to_rpend` | `pegawai_pendidikan.id_riwayat_pendidikan` | `riwayat_pendidikan` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `fk_id_riwayat_tanda_jasa_ptj_to_rtj` | `pegawai_tanda_jasa.id_riwayat_tanda_jasa` | `riwayat_tanda_jasa` | CASCADE/CASCADE → RESTRICT/RESTRICT |
| `pegawai_alamat_kantor_ibfk_5` | `pegawai_alamat_kantor.id_riwayat_alamat` | `riwayat_alamat` | CASCADE/CASCADE → RESTRICT/RESTRICT |

`131000` fail-closed: sebelum ALTER apa pun mengecek kolom induk ada dan tidak ada `id_riwayat_*` yatim; FK yang sudah ada dilewati; ALTER yang gagal melepas FK yang dipasang pada run itu. Tabel riwayat yang dirujuk tidak bisa di-drop selama FK ini terpasang (3730).

### 4.3 Registry kolom NIP (landasan B-06)

`NipReferenceRegistryTest` mengunci: daftar FK ke `pegawai(nip)` = registry per versi migration (B-01: 16 FK, B-02: 24
FK), semuanya RESTRICT/RESTRICT, VARCHAR(30) unicode_ci, NOT NULL kecuali `riwayat_lckh.nip_atasan`; setiap kolom bernama
NIP di tabel aplikasi wajib tercatat sebagai FK atau di daftar NON_FK beserta alasannya. Tambahan revisi D1:
`fk_nip_peg_ak_siasn_01` (`pegawai_ak_siasn.nip`). `pengguna.nip` dan `faq_rate.nip` tetap NON_FK selama migration
`120200` ditahan (Bagian 7). Tabel Fase 4/5 (`riwayat_cuti` termasuk kolom atasan, `dh_online`, `gaji_pegawai`) wajib
didaftarkan saat dibuat.

## 5. Pemetaan trigger legacy → aturan v2

D1 memuat badan trigger. Pada tabel cakupan B-01/B-02 ada trigger pengisi snapshot (AFTER INSERT/UPDATE/DELETE pada
`riwayat_*`), penanda notifikasi (BEFORE UPDATE), antrean API simapi, tulis lintas schema e-kinerja, dan arsip hapus
(BEFORE DELETE → `d_*`/`da_deleted`). v2 memakai DB terpisah dan **tidak membawa trigger** (V3); perilaku yang masih
dibutuhkan menjadi aturan aplikasi. Tabel di bawah menjadi bahan B-03 (`pegawai_hist`) dan B-07..B-17 (riwayat), serta
dasar rekonsiliasi impor snapshot (Bagian 6).

### 5.1 Pengisi snapshot (ADR-006: v2 menyinkronkan hanya di approval final, termasuk saat baris aktif ditolak/dihapus)

| Snapshot | Trigger D1 | Aturan legacy (baris riwayat yang menjadi snapshot) | Aturan v2 (pemilik) |
|---|---|---|---|
| `pegawai_kp` | `riwayat_kp_afIns/afUpd/afDel` D1:9477-9668 | `status='1'` AND `id_jenis_kp != 6`, `ORDER BY tmtsk DESC LIMIT 1`; bila tidak ada → hapus snapshot. Catatan: `afIns` memilih ulang tanpa filter `id_jenis_kp != 6` (inkonsistensi legacy) | Pakai aturan `afUpd`/`afDel` (dengan pengecualian `id_jenis_kp = 6`) di semua jalur (B-08) |
| `pegawai_cpns` | idem | `status='1'` AND `id_jenis_kp = 1`, `ORDER BY tmtsk ASC LIMIT 1` | Sama (B-08) |
| `pegawai_pns` | idem | `status='1'` AND `id_jenis_kp = 2`, `ORDER BY tmtsk ASC LIMIT 1` | Sama (B-08) |
| `pegawai_kgb` | `riwayat_kgb_af*` D1:9340-9458 | `status='1'`, `ORDER BY tmtsk DESC`; `afIns` mengganti hanya bila `tmtsk > snapshot.tmtsk` | Pilih ulang `MAX(tmtsk)` status 1 (B-09) |
| `pegawai_pendidikan` | `riwayat_pendidikan_af*` D1:9989-10152 | `status='1'`, `ORDER BY jenjang_pendidikan.order DESC, tgl_lulus DESC` (jenjang tertinggi) | Sama (B-10); gelar `glr_awal/glr_akhir` ikut snapshot |
| `pegawai_diklat` | `riwayat_diklat_af*` D1:8851-9006 | `status='1'` AND `jenis_diklat = 1` (struktural), `ORDER BY tgl_sertifikat DESC`; `afIns` hanya bila lebih baru | Sama (B-11) |
| `pegawai_hukdis` | `riwayat_hukdis_af*` D1:9025-9150 | `status='1'`, `ORDER BY tmtsk DESC`; `afIns` hanya bila `tmtsk >` snapshot | Sama (B-14) |
| `pegawai_ak` | `riwayat_ak_af*` D1:8389-8474 | `status='1'`, `ORDER BY tgl_pak DESC, periode_akhir DESC, id_riwayat_ak DESC` | Sama (B-15/AK) |
| `pegawai_ak_siasn` | `riwayat_ak_siasn_af*` D1:8493-8577 | `status='1'`, `ORDER BY tgl_sk DESC` | Sama (sinkron SIASN) |
| `pegawai_keluarga` | `riwayat_keluarga_af*` D1:9205-9321 | `status='1'`, `ORDER BY tgl_perkawinan DESC`; `afIns` hanya bila lebih baru | Sama (B-15) |
| `pegawai_alamat` | `riwayat_alamat_af*` D1:8584-8811 | `status='1'` AND `alamat_utama = 1`, terbaru (`afDel`: `created_at DESC, id DESC`; `afUpd`: `id DESC`) | Satu urutan: `created_at DESC, id DESC` (B-16) |
| `pegawai_alamat_kantor` | idem | `status='1'` AND `jenis_alamat = 3`, terbaru | Sama (B-16) |
| `pegawai_tanda_jasa` | `riwayat_tanda_jasa_af*` D1:10335-10459 | `status='1'` AND `id_tanda_jasa IN (26, 27, 28)` (Satyalancana Karya Satya), `ORDER BY tgl_sertifikat DESC` | Sama; ID hard-code wajib ada di impor master (B-17) |
| `pegawai_mutasi_jabatan` | `riwayat_mutasi_jabatan_af*` D1:9725-9893 | `status='1'` AND `jenis_jabatan != 3`, `ORDER BY tmtsk DESC`; `afIns` mengganti bila `tmtsk >= snapshot.tmtsk` (lebih longgar dari `>` di modul lain) | Sama; seri `>=` dipertahankan agar mutasi bertanggal sama yang disetujui belakangan menang (B-07) |
| `pegawai_foto` | `pegawai_afIns/afUpd` D1:7893-8127 | Baris baru setiap `pegawai.foto` berubah dan belum ada pasangan (`nip`, `foto`) | Service foto B-01/B-05 menulis `pegawai_foto` di transaksi yang sama |

Aturan bersama v2: snapshot dihitung ulang (bukan sekadar diganti) ketika baris riwayat aktif ditolak (status 2) atau
dihapus lunak (status 10), sama dengan efek `afUpd`/`afDel` legacy; snapshot dihapus bila tidak ada lagi baris yang
memenuhi.

### 5.2 Trigger lain

| Trigger D1 | Perilaku legacy | Aturan v2 |
|---|---|---|
| `<tabel>_beUpd` pada `riwayat_kp`, `_kgb`, `_pendidikan`, `_diklat`, `_seminar`, `_skp`, `_hukdis`, `_keluarga`, `_alamat`, `_tanda_jasa`, `_organisasi`, `_mutasi_jabatan`, `_ak`, `_karpeg`, `_kariskarsu`, `_lckh` | Mengisi kolom notifikasi saat status berubah (contoh `riwayat_kp_beUpd` D1:9675-9680: status 0 → 1/2 ⇒ `show_notif = 1`, `notif_date = NOW()`) | Service approval mengisi `show_notif`/`notif_date` (UTC) di transaksi approval (B-TC notifikasi) |
| `pegawai_hist_beUpd` D1:8131-8140 | Penanda notifikasi pengajuan biodata | B-03/B-04 |
| `pegawai_afUpd` D1:7960-8127 | `pegawai.status` 1 → 2: `pengguna.status = 2` untuk `UserLevel = 2` | Service status pegawai menonaktifkan akun di transaksi yang sama (A-09) |
| `riwayat_lckh_beIns` D1:9696-9706 | `id_unit`/`id_satker` NULL → diisi dari `pegawai_mutasi_jabatan` | Service LKH (B-12) mengisi dari snapshot jabatan |
| Antrean simapi (`simapi_ch`/`simapi_update`) pada `pegawai`, riwayat, `detail_anak`, `pegawai_mutasi_jabatan` | Antrean perubahan untuk API simapi | Tidak dibawa; integrasi konsumen J3 (simapi) memakai event aplikasi/ view |
| Tulis lintas schema e-kinerja (`pegawai_kp_af*` D1:8146-8184) | Sinkron `dd_user` e-kinerja | Tidak dibawa; konsumen J3 |
| Arsip hapus: `absen_ijin_beDel` (D1:7638), `riwayat_lckh_beDel` (D1:9687), `document_attachment_beDel` (D1:7721) | `INSERT INTO d_konket/d_lkh/da_deleted SELECT *` sebelum hapus | Soft delete (status 10) + `audit_logs` before/after. `document_attachment` tidak punya kolom status → hapus keras B-18 wajib mencatat `audit_logs` lengkap (keputusan 8 #9) |

## 6. Interaksi impor (bahan runbook DBV-014, Tier 2–3)

1. **Urutan:** master (termasuk G-02 PR #17) → `pegawai` → `pegawai_hist` (hanya pengajuan pending `flag_update = 1`,
   Mapping Tier 3) → `pegawai_foto` → `riwayat_*`/`absen_ijin`/`document_attachment` → snapshot (disalin apa adanya dari
   legacy, bukan diturunkan ulang) → `131000` (pra-cek orphan) → FK G-02 → FK masuk `pengguna`/`faq_rate`.
2. **Audit NIP:** `MAX(CHAR_LENGTH(nip))` di keenam kolom D1 VARCHAR(50) (V4) dan semua kolom NIP lain harus ≤ 30;
   orphan `nip` per tabel = 0 (legacy CASCADE seharusnya menjamin).
3. **Normalisasi status riwayat:** hitung `SELECT status, COUNT(*) … GROUP BY status` per tabel. Nilai 0–3 dan 10
   dipertahankan bila masuk domain tabelnya (2.2.1); nilai 3 di tabel ber-domain 0/1/2/10 dan nilai lain dilaporkan dan
   dipetakan sesuai keputusan 8 #2. `riwayat_pendidikan.status` NULL dipertahankan (INT NULL [K]). `absen_ijin.status`
   selain W/V/X/10 (angka/`H` lama) → W/V/X. `pegawai.status`, `flag_update`, `jenis_kelamin` di luar domain CHECK
   dilaporkan sebelum impor.
4. **Nilai kosong/nol mode non-strict:** `''` pada kolom FK (wilayah, `nip_atasan`) → NULL; id G-02 = 0 → NULL; tanggal
   `0000-00-00` di kolom DATE NOT NULL dilaporkan (strict v2 menolak).
5. **Tipe V5:** kolom anak INT lebih lebar dari TINYINT D1 — tidak ada pemotongan.
6. **Snapshot vs aturan Bagian 5:** rekonsiliasi membandingkan snapshot impor dengan baris riwayat yang dipilih aturan
   5.1; selisih dilaporkan (bukan diperbaiki diam-diam). `id_riwayat_*` snapshot yatim → NULL atau riwayatnya diimpor
   dulu, karena `131000` fail-closed (`pegawai_cpns`/`pegawai_alamat`/`pegawai_alamat_kantor` `id_riwayat_*` NOT NULL
   [K]: yatim harus diselesaikan, tidak bisa di-NULL-kan).
7. **Counter AUTO_INCREMENT** D1 (struktur 01-10; nilai final diambil dari dump saat freeze) disalin setelah impor bila
   lebih besar dari `MAX(id)+1`:

   | Tabel | AUTO_INCREMENT D1 |
   |---|---|
   | `pegawai_hist` | 3330 |
   | `pegawai_foto` | 5670 |
   | `riwayat_mutasi_jabatan` | 41462 |
   | `pegawai_plt` | 180 |
   | `pegawai_plh` | 10 |
   | `riwayat_kp` | 167159 |
   | `riwayat_kgb` | 27724 |
   | `riwayat_pendidikan` | 71810 |
   | `riwayat_diklat` | 36285 |
   | `riwayat_seminar` | 18437 |
   | `riwayat_skp` | 26298 |
   | `riwayat_skp_periodik` | 74546 |
   | `riwayat_lckh` | 1608944 |
   | `absen_ijin` | 327402 |
   | `riwayat_hukdis` | 68 |
   | `riwayat_ak` | 1414 |
   | `riwayat_ak_siasn` | 283652 |
   | `riwayat_keluarga` | 3361 |
   | `detail_anak` | 6096 |
   | `riwayat_alamat` | 21098 |
   | `riwayat_karpeg` | 247 |
   | `riwayat_kariskarsu` | 338 |
   | `riwayat_tanda_jasa` | 7960 |
   | `riwayat_organisasi` | 1100 |
   | `document_attachment` | 170051 |

8. **Tidak diimpor:** `d_*`, `da_deleted`, antrean `simapi_*`, tabel backup; `pegawai_hist` non-pending.
9. **Waktu:** kolom `DEFAULT CURRENT_TIMESTAMP`/`ON UPDATE` legacy berisi WIB → konversi ke UTC (ISSUE-022, J1.07).
10. **ID hard-code** yang dipakai aturan v2 wajib ada di impor master: `tanda_jasa` 26/27/28, `jenis_kp` 1/2/6,
    `bidang_pendidikan` 98, `jurusan_pendidikan` 1185, `jenis_rwy` (kode lampiran).

## 7. Migration yang ditahan (tidak ikut PR B-01/B-02)

Disimpan di branch lokal `dbv-012/fk-ditahan` (di atas commit DBV-013, belum di-push, **tidak** untuk di-merge apa
adanya). Saat diaktifkan, timestamp ditetapkan ulang agar lebih besar dari migration yang sudah jalan di lingkungan target.

| Migration (timestamp draf) | Isi | Key | Prasyarat aktif |
|---|---|---|---|
| `AddFkPegawaiDiPenggunaFaqRate` (`120200`) | `fk_id_pegawai_pengguna_to_pegawai` (`pengguna.nip`, nullable), `fk_nip_faqrate_to_peg` (`faq_rate.nip`); RESTRICT; pra-cek orphan fail-closed | DBV-012 | `pegawai` terisi di lingkungan target; validasi NIP (422) di AccountProvisioner/Manajemen Akun; seed & test akun/rating menyisipkan `pegawai` |
| `AddFkG02Pegawai` (`129900`) | 11 FK snapshot → G-02 (`pegawai_mutasi_jabatan` 9, `pegawai_ak` 1, **`pegawai_ak_siasn` 1** — tambahan revisi D1) + 4 FK `MENUNGGU_TABEL` | DBV-012 | PR #17 (DBV-008) merge; 4 FK sisanya setelah `jabatan_koordinasi`/`rumpun_jabatan` ada |
| `AddFkG02Riwayat` (`139900`) | 34 FK riwayat/penugasan → G-02 + 6 FK `FK_KOORD_RUMPUN` | DBV-013 | Sama |

Daftar 54 FK G-02/koordinasi/rumpun di branch itu sama persis dengan D1 (nama, kolom, induk) kecuali tambahan
`fk_id_jabatan_peg_ak_siasn_03`; 2 FK masuk juga ada di D1. Uji pemasangan FK G-02 di atas tabel PR #17: Bagian 9.2.

## 8. Keputusan yang diminta dari DB Validator / user

| # | Pertanyaan | Usulan (pra-review CR) | Keputusan |
|---|---|---|---|
| 1 | Setujui skema [K] D1 + deviasi [V2] (Bagian 2-3) untuk B-01 (`120000`, `120100`) dan B-02 (`130000`..`131000`) | Setujui setelah verifikasi MariaDB 10.4 (9.4) | ⏳ |
| 2 | Domain CHECK status riwayat (2.2.1): 0/1/2/3/10 untuk 11 tabel yang form admin legacy-nya menulis 3, 0/1/2/10 untuk 7 tabel; LKH 0/1/2/3/10 | Setujui; putuskan bersama hitungan `status` produksi (Bagian 6 #3). Alternatif: tanpa CHECK (persis D1) | ⏳ |
| 3 | Semua FK RESTRICT/RESTRICT (V1), termasuk `detail_anak` dan `document_attachment` (D1 CASCADE) | Setujui (K1); service menghapus anak/lampiran eksplisit dalam satu transaksi | ⏳ |
| 4 | **Tipe PK master `main` vs D1** (2.4): `jenjang_pendidikan`, `jenis_hukdis`, `tingkat_hukdis`, `tanda_jasa` INT di `main`, TINYINT di D1 | (a, draf ini) kolom anak INT (V5), master tidak diubah; (b) migration ALTER master → TINYINT (DBV baru, K3) lalu kolom anak kembali [K] TINYINT. Usulan (a): lossless, tanpa mengubah tabel yang sudah disetujui | ⏳ |
| 5 | NIP VARCHAR(50) → VARCHAR(30) di 6 kolom (V4), termasuk `ignore_konv_ak.nip` | Setujui; audit panjang sebelum impor | ⏳ |
| 6 | `pegawai_ak_siasn` (D1:2742-2779, snapshot `riwayat_ak_siasn` yang diisi trigger) masuk B-01 | Setujui masuk (draf ini); alternatif tetap di luar cakupan dan tidak diimpor | ⏳ |
| 7 | Trigger legacy tidak dibawa; pemetaan Bagian 5 menjadi aturan aplikasi | Setujui; aturan 5.1 menjadi acuan B-03/B-07..B-17 dan rekonsiliasi impor | ⏳ |
| 8 | `riwayat_pendidikan.status` INT NULL [K] (beda dari riwayat lain TINYINT NOT NULL) | Pertahankan [K]; seragamkan lewat ALTER setelah audit NULL bila DBV menghendaki | ⏳ |
| 9 | `document_attachment` tanpa kolom status: hapus keras + `audit_logs` (pengganti `da_deleted`) | Setujui; alternatif tambah kolom status [V2] | ⏳ |
| 10 | `riwayat_hukdis.status` COMMENT [K] "1: Active, 10: Deleted", DEFAULT 0; CHECK 0/1/2/3/10 | Setujui; service B-14 menulis 1 eksplisit | ⏳ |
| 11 | Lookup `jenis_rwy` [V2] + FK `document_attachment.id_riwayat` (seed 21 baris termasuk 0) | Setujui; audit kode di luar seed (mis. 33) sebelum impor | ⏳ |
| 12 | FK G-02 (45) ditahan sampai PR #17 merge; 10 FK ke `jabatan_koordinasi`/`rumpun_jabatan` menunggu master baru (D1 punya kedua tabel) | Setujui; key review migration FK G-02 = DBV-012/013 yang sama atau key baru (putuskan user) | ⏳ |
| 13 | Pemilik `aa_lkh`/`queue_aa_lkh` | Diputuskan bersama B-12/Fase 5 | ⏳ |
| 14 | FK masuk `pengguna.nip`/`faq_rate.nip` (`120200`) tetap ditahan | Setujui (prasyarat Bagian 7) | ⏳ |
| 15 | Alur PR `[CR]` + `[DBV]`; test lama yang diubah: 3 test master (lepas migration B-01/B-02 sebelum `down()`), schema test B-02 digabung menjadi `RiwayatSchemaTest` | Setujui | ⏳ |
| 16 | COMMENT kolom = D1 persis (bahasa Inggris/typo legacy) | Setujui (skema = produksi) | ⏳ |

Koreksi setelah approval dilakukan lewat migration ALTER baru, bukan dengan mengedit migration yang sudah di `main`.

## 9. Verifikasi developer (pra-review internal sisi CR)

### 9.1 Lingkungan

MySQL 8.0.30 (Laragon lokal), PHP 8.4.2, CodeIgniter 4.7.4, PHPUnit 10.5. Worktree branch lokal
`dbv-012/b01-b02-pegawai-riwayat-draf` (merge `origin/main` 01-10-2026). Semua uji database memakai **DB scratch bernama
eksplisit** (dibuat lalu di-DROP dengan nama persis); `.env` minimal di worktree (tidak di-commit) menunjuk DB scratch,
dicek lewat `php spark db:table --show` sebelum setiap `spark migrate`. Tidak ada uji di DB pengembangan bersama, DB test
bersama, maupun DB legacy.

### 9.2 Hasil

| Perintah / uji | Hasil |
|---|---|
| Generator DDL dari D1 | 43 tabel; untuk setiap FK D1 ada index dengan kolom terdepan = kolom FK; tidak ada `CHARACTER SET`/`COLLATE` selain utf8mb4_unicode_ci; tidak ada alamat host di DDL/dokumen |
| Siklus Dev-like di DB scratch `simpeg_v2_scr_dbv012`: DB baru → `php spark migrate --all` dengan migration `main` saja (batch 1) → sisipkan 1 akun `pengguna` ber-NIP tiruan → `migrate --all` (batch 2) | ✅ batch 2 = 13 migration (`120000`..`131000`) tanpa error walau `pengguna` berisi NIP tanpa baris `pegawai`; total 80 tabel, 126 FK (111 FK B-01/B-02 = 96 CREATE + 1 [V2] + 14 RWY), 36 CHECK, `jenis_rwy` 21 baris; data akun utuh |
| `php spark migrate:rollback` | ✅ 13 migration batch 2 dibatalkan; 36 tabel `main` + 15 FK `main` + akun tetap; batch 1 tetap tercatat |
| `php spark migrate --all` ulang | ✅ identik dengan sebelum rollback (80 tabel, 126 FK, 36 CHECK, 21 baris `jenis_rwy`) |
| FK G-02 di atas PR #17: worktree sementara dari `origin/dbv-008/g02-jabatan-unit-satker` + migration revisi ini + `AddFkG02Pegawai`/`AddFkG02Riwayat` (branch `fk-ditahan`, diperbarui), DB scratch terpisah `simpeg_v2_scr_dbv012g` | ✅ `AddFkG02Pegawai` memasang 11 FK; `AddFkG02Riwayat` **gagal tertutup** (tabel `jabatan_koordinasi` tidak ada, 0 FK terpasang). Dengan tabel stub `jabatan_koordinasi` (PK INT [K]) dan `rumpun_jabatan` (PK TINYINT [K]) di DB scratch itu: 40 FK terpasang, semua RESTRICT/RESTRICT; tipe kolom anak D1 cocok dengan PK PR #17; `migrate:rollback` melepas 40 FK, `migrate` memasangnya lagi. DB scratch di-DROP, worktree sementara dihapus |
| Schema test terarah (`tests/database/Kepegawaian`, DB test scratch) | ✅ 18 test lolos (setelah perbaikan uji index `SnapshotRiwayatFkTest`: nama index D1 tidak selalu = nama FK) |
| Gate `fastcheck.py <worktree> dbv012r --shards 2` (PHPStan level 5, PHP-CS-Fixer, PHPUnit penuh, ESLint, vue-tsc, Vitest, build) | ⏳ dijalankan (hasil dicatat di commit DBV-013) |

Semua DB scratch (`simpeg_v2_scr_dbv012`, `simpeg_v2_scr_dbv012g`, DB test scratch, DB shard gate) di-DROP dengan nama persis
setelah selesai. `.env` worktree khusus uji, tidak di-commit.

### 9.3 Pemulihan bila `up()` gagal di tengah

DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di `migrations`. Setiap migration
Create men-drop tabel yang sempat dibuat **pada run itu** (urutan terbalik) lalu melempar ulang error; `131000` melepas FK
yang sempat dipasang. Bila pembersihan otomatis ikut gagal, drop manual (prefix sesuai lingkungan; hanya tabel yang belum
tercatat di `migrations`), **jangan `migrate:rollback` batch**:

- `131000`: `ALTER TABLE <snapshot> DROP FOREIGN KEY <nama>` untuk FK 4.2 yang tertinggal (index tetap).
- `130900`: `document_attachment`. `130800`: `riwayat_organisasi` → `riwayat_tanda_jasa` → `riwayat_kariskarsu` →
  `riwayat_karpeg`. `130700`: `riwayat_alamat` → `detail_anak` → `riwayat_keluarga`. `130600`: `ignore_konv_ak` →
  `konv_ak` → `riwayat_ak_siasn` → `riwayat_ak` → `riwayat_hukdis`. `130500`: `absen_ijin`. `130400`: `riwayat_lckh` →
  `riwayat_skp_periodik` → `riwayat_skp` → `bkn_periode_ekinper`. `130300`: `riwayat_seminar` → `riwayat_diklat` →
  `riwayat_pendidikan`. `130200`: `riwayat_kgb` → `riwayat_kp`. `130100`: `pegawai_plh` → `pegawai_plt` →
  `riwayat_mutasi_jabatan`. `130000`: `jenis_rwy` (setelah `document_attachment`).
- `120100`: 14 snapshot urutan terbalik (`pegawai_mutasi_jabatan` → … → `pegawai_kp`), setelah FK `131000` dilepas.
  `120000`: `pegawai_foto` → `pegawai_hist` → `pegawai` (setelah semua tabel yang merujuk `pegawai` di-drop).

### 9.4 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator) — WAJIB sebelum approval

1. `php spark migrate` → `migrate:rollback` → `migrate`: migration B-01/B-02 masuk batch tersendiri; rollback hanya batch
   itu.
2. `tests/database/Kepegawaian/*Test.php` di DB test MariaDB (helper menormalkan `int(11)`/`tinyint(4)`/`year(4)` dan
   default `'NULL'`/`current_timestamp()`; CHECK 4025).
3. CHECK terdaftar di `information_schema.TABLE_CONSTRAINTS` dan ditegakkan; FK RESTRICT dicek lewat
   `REFERENTIAL_CONSTRAINTS` (MariaDB bisa menyembunyikan klausa RESTRICT di `SHOW CREATE TABLE`).
4. Kolom `year`, `decimal(10,3)`, `bigint`, `mediumtext`, COMMENT berisi kutip, dan `DEFAULT 'Permohonan Pertama'`
   terbaca sama di `information_schema.COLUMNS`.
5. Seed `jenis_rwy` 21 baris (baris `id_jenis_rwy = 0` tersimpan).

## Lampiran A — Selisih draf 30-09 vs D1 (sudah diperbaiki di revisi ini)

Hasil pencocokan otomatis draf 30-09 (migration yang dijalankan di DB scratch) dengan D1 per kolom/index/FK. "prod" =
D1, "v2" = draf 30-09 (bukan skema revisi). Semua butir di bawah kini mengikuti D1 kecuali yang tercatat sebagai deviasi
[V2] di Bagian 2.

### A.1 B-01 (draf 30-09, migration `120000`, `120100`)

#### A `pegawai` — `D1:2497-2550` (40 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (5; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_agama_peg_to_agama`, `fk_id_jenis_pegawai_peg_to_jenis_pegawai`, `fk_id_jenis_status_peg_to_jenis_status`, `fk_id_kabupaten_kota_lahir_peg_to_kab_kota`, `fk_id_provinsi_lahir_peg_to_prov`
- CHECK [V2] (tidak ada di prod): `chk_pegawai_flag_update`, `chk_pegawai_jenis_kelamin`, `chk_pegawai_status`

#### A `pegawai_hist` — `D1:2938-3003` (48 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: glr_awal.type: prod='varchar(12)' v2='varchar(50)' (`D1:2948`)
- Kolom: glr_akhir.type: prod='varchar(24)' v2='varchar(50)' (`D1:2949`)
- Kolom: email.type: prod='varchar(256)' v2='varchar(150)' (`D1:2971`)
- Kolom: flag_update.default: prod='0' v2='1' (`D1:2973`)
- Kolom: reason_note.type: prod='tinytext' v2='mediumtext' (`D1:2981`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:2984`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:2984`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:2985`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:2985`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:2986`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:2986`)
- KEY (nama/isi): KEY fk_peg_hist_ibfk_01: prod=('nip',) v2=None
- KEY (nama/isi): KEY nip: prod=None v2=('nip',)
- KEY (nama/isi): KEY show_notif: prod=('show_notif',) v2=('show_notif', 'notif_date')
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (6; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_peg_hist_ibfk_01`, `fk_peg_hist_ibfk_02`, `fk_peg_hist_ibfk_03`, `fk_peg_hist_ibfk_04`, `fk_peg_hist_ibfk_05`, `fk_peg_hist_ibfk_06`
- CHECK [V2] (tidak ada di prod): `chk_pegawai_hist_flag_update`, `chk_pegawai_hist_jenis_kelamin`, `chk_pegawai_hist_status`

#### A `pegawai_foto` — `D1:2924-2933` (4 kolom, collation utf8mb4_unicode_ci)
- KEY (nama/isi): KEY foto: prod=('foto',) v2=None
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_pegfoto_to_peg`

#### A `pegawai_kp` — `D1:3131-3162` (22 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (4; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_kp_peg_kp_to_jenis_kp`, `fk_id_pangkat_peg_kp_to_pangkat`, `fk_id_riwayat_kp_peg_kp_to_rwy_kp`, `fk_nip_pegkp_to_pegawai`

#### A `pegawai_cpns` — `D1:2861-2892` (22 kolom, collation utf8mb4_unicode_ci)
- Kolom: id_riwayat_kp.null: prod='NO' v2='YES' (`D1:2863`)
- FK sama nama/kolom, aksi beda (4; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_kp_pcpns_to_jenis_kp`, `fk_id_pangkat_pcpns_to_pangkat`, `fk_id_riwayat_kp_pcpns_to_rwy_kp`, `fk_nip_pcpns_to_pegawai`

#### A `pegawai_pns` — `D1:3540-3571` (22 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (4; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_kp_ppns_to_jenis_kp`, `fk_id_pangkat_ppns_to_pangkat`, `fk_id_riwayat_kp_ppns_to_rwy_kp`, `fk_nip_ppns_to_pegawai`

#### A `pegawai_kgb` — `D1:3107-3126` (12 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `gol_pppk` (`D1:3114`)
- Kolom: tgl_sk.null: prod='NO' v2='YES' (`D1:3113`)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_riwayat_kgb_pkgb_to_rkgb`, `fk_nip_pegkgb_to_pegawai`, `pegawai_kgb_ibfk_01`

#### A `pegawai_pendidikan` — `D1:3326-3358` (21 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `glr_awal` (`D1:3344`), `glr_akhir` (`D1:3345`)
- Kolom: id_jenjang_pendidikan.type: prod='tinyint' v2='int' (`D1:3329`)
- Kolom: tgl_lulus.null: prod='NO' v2='YES' (`D1:3332`)
- Kolom: jenjang_pendidikan_singkat.type: prod='varchar(15)' v2='varchar(50)' (`D1:3336`)
- Kolom: bidang_pendidikan_lain.type: prod='varchar(100)' v2='varchar(255)' (`D1:3338`)
- Kolom: nem.type: prod='decimal(5,2)' v2='double' (`D1:3341`)
- Kolom: ipk.type: prod='decimal(3,2)' v2='double' (`D1:3342`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:3347`)
- FK sama nama/kolom, aksi beda (5; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_bidang_pendidikan_pegpend_to_bpend`, `fk_id_jenjang_pendidikan_pegpend_to_jpend`, `fk_id_jurusan_pendidikan_pegpend_to_jupend`, `fk_id_riwayat_pendidikan_pegpend_to_rpend`, `fk_nip_pegpend_to_pegawai`

#### A `pegawai_diklat` — `D1:2897-2919` (15 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `id_sub_group_jabatan` (`D1:2901`), `nama_diklat_lain` (`D1:2906`), `id_rw_siasn` (`D1:2911`)
- Kolom hanya di v2: `jenis_diklat`
- Urutan kolom berbeda dari prod
- Kolom: no_sertifikat.type: prod='varchar(255)' v2='varchar(100)' (`D1:2903`)
- Kolom: jumlah_jp.type: prod='int' v2='smallint' (`D1:2904`)
- Kolom: sub_group_jabatan.type: prod='varchar(255)' v2='varchar(100)' (`D1:2907`)
- Kolom: deskripsi.type: prod='tinytext' v2='text' (`D1:2908`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:2910`)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_diklat_pegdiklat_to_diklat`, `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat`, `fk_nip_pegdiklat_to_pegawai`

#### A `pegawai_hukdis` — `D1:3008-3039` (20 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: id_tingkat_hukdis.type: prod='tinyint' v2='int' (`D1:3011`)
- Kolom: id_jenis_hukdis.type: prod='tinyint' v2='int' (`D1:3012`)
- Kolom: tmtsk.null: prod='NO' v2='YES' (`D1:3014`)
- Kolom: masa_hukuman.type: prod='tinytext' v2='text' (`D1:3023`)
- Kolom: aturan_dilanggar.type: prod='tinytext' v2='text' (`D1:3025`)
- Kolom: alasan_hukuman.type: prod='tinytext' v2='text' (`D1:3026`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:3027`)
- FK sama nama/kolom, aksi beda (5; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis`, `fk_id_pangkat_peg_hukdis_to_pangkat`, `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis`, `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis`, `fk_nip_peghukdis_to_pegawai`

#### A `pegawai_ak` — `D1:2673-2717` (35 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `nama` (`D1:2679`), `no_induk_jf` (`D1:2680`), `ak_kum_kp` (`D1:2685`), `ak_kum_next_jen` (`D1:2686`), `ak_terakhir` (`D1:2687`), `pengajuan_ak` (`D1:2688`), `nama_pym` (`D1:2690`), `jab_pym` (`D1:2691`), `flag_instansi_ym` (`D1:2692`), `instansi_ym` (`D1:2693`), `jabatan` (`D1:2694`), `tmt_jab` (`D1:2695`), `mker_th_jab` (`D1:2696`), `mker_bl_jab` (`D1:2697`), `pangkat` (`D1:2698`), `gol_ruang` (`D1:2699`), `tmt_pang` (`D1:2700`), `mker_th_pang` (`D1:2701`), `mker_bl_pang` (`D1:2702`), `keterangan` (`D1:2703`), `created_at` (`D1:2705`), `created_by` (`D1:2706`), `updated_by` (`D1:2708`)
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:2674`)
- Kolom: jenis_ak.null: prod='NO' v2='YES' (`D1:2678`)
- Kolom: jenis_ak.default: prod='2' v2='<NULL>' (`D1:2678`)
- Kolom: no_hpak.type: prod='varchar(50)' v2='varchar(100)' (`D1:2681`)
- Kolom: nilai_ak.type: prod='double' v2='decimal(8,3)' (`D1:2689`)
- Kolom: nilai_ak.null: prod='NO' v2='YES' (`D1:2689`)
- Kolom: nilai_ak.default: prod='0' v2='<NULL>' (`D1:2689`)
- Kolom: file_pak.type: prod='varchar(256)' v2='varchar(255)' (`D1:2704`)
- Kolom: updated_at.null: prod='YES' v2='NO' (`D1:2707`)
- Kolom: updated_at.default: prod='<NULL>' v2='CURRENT_TIMESTAMP' (`D1:2707`)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_pangkat_cak_to_pangkat`, `fk_id_riwayat_ak_cak_to_rak`, `fk_nip_cak_to_peg`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (1): `fk_id_jabatan_cak_to_jab`

#### A `pegawai_keluarga` — `D1:3088-3102` (9 kolom, collation utf8mb4_unicode_ci)
- Kolom: urutan_perkawinan.null: prod='NO' v2='YES' (`D1:3091`)
- Kolom: urutan_perkawinan.default: prod='1' v2='<NULL>' (`D1:3091`)
- Kolom: tgl_perkawinan.null: prod='NO' v2='YES' (`D1:3092`)
- Kolom: nama_pasangan.null: prod='NO' v2='YES' (`D1:3094`)
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga`, `fk_nip_pegkeluarga_to_pegawai`

#### A `pegawai_alamat` — `D1:2784-2817` (20 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `alamat_utama` (`D1:2791`), `keterangan` (`D1:2803`)
- Urutan kolom berbeda dari prod
- Kolom: id_riwayat_alamat.null: prod='NO' v2='YES' (`D1:2786`)
- Kolom: jenis_alamat.null: prod='NO' v2='YES' (`D1:2792`)
- Kolom: jenis_alamat.default: prod='1' v2='<NULL>' (`D1:2792`)
- Kolom: kd_pos.type: prod='char(5)' v2='varchar(10)' (`D1:2801`)
- Kolom: alamat.type: prod='tinytext' v2='text' (`D1:2802`)
- FK sama nama/kolom, aksi beda (6; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_kabupaten_kota_pegalamat_kabkota`, `fk_id_kecamatan_pegalamat_kecamatan`, `fk_id_kelurahan_pegalamat_kelurahan`, `fk_id_provinsi_pegalamat_provinsi`, `fk_id_riwayat_alamat_pegalamat_riwalamat`, `fk_nip_pegalamat_to_pegawai`

#### A `pegawai_alamat_kantor` — `D1:2822-2856` (21 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `keterangan` (`D1:2843`)
- Kolom hanya di v2: `jenis_alamat`, `updated_at`
- Kolom: id_riwayat_alamat.null: prod='NO' v2='YES' (`D1:2824`)
- Kolom: kd_pos.type: prod='char(5)' v2='varchar(10)' (`D1:2837`)
- Kolom: alamat.type: prod='tinytext' v2='text' (`D1:2838`)
- KEY (nama/isi): KEY fk_id_kabupaten_kota_pegalamat_kabkota: prod=('id_kabupaten_kota',) v2=None
- KEY (nama/isi): KEY fk_id_kecamatan_pegalamat_kecamatan: prod=('id_kecamatan',) v2=None
- KEY (nama/isi): KEY fk_id_kelurahan_pegalamat_kelurahan: prod=('id_kelurahan',) v2=None
- KEY (nama/isi): KEY fk_id_provinsi_pegalamat_provinsi: prod=('id_provinsi',) v2=None
- KEY (nama/isi): KEY fk_id_riwayat_alamat_pegalamat_riwalamat: prod=('id_riwayat_alamat',) v2=None
- KEY (nama/isi): KEY pegawai_alamat_kantor_ibfk_1: prod=None v2=('id_kabupaten_kota',)
- KEY (nama/isi): KEY pegawai_alamat_kantor_ibfk_2: prod=None v2=('id_kecamatan',)
- KEY (nama/isi): KEY pegawai_alamat_kantor_ibfk_3: prod=None v2=('id_kelurahan',)
- KEY (nama/isi): KEY pegawai_alamat_kantor_ibfk_4: prod=None v2=('id_provinsi',)
- KEY (nama/isi): KEY pegawai_alamat_kantor_ibfk_5: prod=None v2=('id_riwayat_alamat',)
- FK sama nama/kolom, aksi beda (6; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `pegawai_alamat_kantor_ibfk_1`, `pegawai_alamat_kantor_ibfk_2`, `pegawai_alamat_kantor_ibfk_3`, `pegawai_alamat_kantor_ibfk_4`, `pegawai_alamat_kantor_ibfk_5`, `pegawai_alamat_kantor_ibfk_6`

#### A `pegawai_tanda_jasa` — `D1:3669-3686` (10 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `id_rw_siasn` (`D1:3678`)
- Kolom hanya di v2: `tanda_jasa_lain`
- Urutan kolom berbeda dari prod
- Kolom: id_tanda_jasa.type: prod='tinyint' v2='int' (`D1:3672`)
- Kolom: tgl_sertifikat.null: prod='NO' v2='YES' (`D1:3673`)
- Kolom: tanda_jasa.null: prod='NO' v2='YES' (`D1:3675`)
- Kolom: negara.type: prod='varchar(255)' v2='varchar(100)' (`D1:3676`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:3677`)
- Kolom: updated_at.null: prod='YES' v2='NO' (`D1:3679`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:3679`)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_riwayat_tanda_jasa_ptj_to_rtj`, `fk_id_tanda_jasa_pegtj_to_tj`, `fk_nip_pegtj_to_pegawai`

#### A `pegawai_mutasi_jabatan` — `D1:3192-3277` (52 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj`, `fk_nip_pmj_to_pegawai`, `pegawai_mutasi_jabatan_ibfk_01`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (13): `fk_id_atasan_es_1_pmj_to_jabatan`, `fk_id_atasan_es_2_pmj_to_jabatan`, `fk_id_atasan_es_3_koord_pmj_to_jabkoor`, `fk_id_atasan_es_3_pmj_to_jabatan`, `fk_id_atasan_es_4_koord_pmj_to_jabkoor`, `fk_id_atasan_es_4_pmj_to_jabatan`, `fk_id_group_jabatan_pmj_to_gj`, `fk_id_jabatan_koord_pmj_to_jabkoor`, `fk_id_jabatan_pmj_to_jabatan`, `fk_id_satker_pmj_to_satker`, `fk_id_sub_group_jabatan_pmj_to_sgj`, `fk_id_unit_pmj_to_unit`, `pegawai_mutasi_jabatan_ibfk_02`

### A.2 B-02 (draf 30-09, migration `130100`..`131000`)

#### A `riwayat_mutasi_jabatan` — `D1:5713-5810` (65 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: jenis_jabatan.null: prod='NO' v2='YES' (`D1:5730`)
- Kolom: jenis_jabatan.default: prod='1' v2='<NULL>' (`D1:5730`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:5770`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:5773`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:5774`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:5776`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:5776`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:5776`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:5777`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:5777`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:5777`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:5778`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5778`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5778`)
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwymutasijabatan_to_pegawai`, `riwayat_mutasi_jabatan_ibfk_01`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (13): `fk_id_atasan_es_1_rwymj_to_jabatan`, `fk_id_atasan_es_2_rwymj_to_jabatan`, `fk_id_atasan_es_3_koord_rmj_to_jabkoor`, `fk_id_atasan_es_3_rwymj_to_jabatan`, `fk_id_atasan_es_4_koord_rmj_to_jabkoor`, `fk_id_atasan_es_4_rwymj_to_jabatan`, `fk_id_group_jabatan_rwymj_to_gj`, `fk_id_jabatan_koord_rmj_to_jabkoor`, `fk_id_jabatan_rwymj_to_jabatan`, `fk_id_satker_rwymj_to_satker`, `fk_id_sub_group_jabatan_rwymj_to_sgj`, `fk_id_unit_rwymj_to_unit`, `riwayat_mutasi_jabatan_ibfk_02`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_mutasi_jabatan_status`

#### A `pegawai_plt` — `D1:3414-3460` (22 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `keterangan` (`D1:3431`)
- Urutan kolom berbeda dari prod
- Kolom: jenis_jabatan.type: prod='tinyint' v2='tinyint(1)' (`D1:3416`)
- Kolom: jenis_jabatan_koord.type: prod='tinyint' v2='tinyint(1)' (`D1:3417`)
- Kolom: tgl_mulai.null: prod='YES' v2='NO' (`D1:3418`)
- Kolom: tgl_akhir.null: prod='YES' v2='NO' (`D1:3419`)
- Kolom: updated_at.default: prod='<NULL>' v2='CURRENT_TIMESTAMP' (`D1:3435`)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_plt_plt_to_pegawai`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (10): `fk_id_group_jabatan_plt_plt_to_gj`, `fk_id_group_jabatan_plt_to_gj`, `fk_id_jabatan_koord_plt_to_jabkoord`, `fk_id_jabatan_plt_to_jabatan`, `fk_id_satker_plt_plt_to_satker`, `fk_id_satker_plt_to_satker`, `fk_id_sub_group_jabatan_plt_plt_to_sgj`, `fk_id_sub_group_jabatan_plt_to_sgj`, `fk_id_unit_plt_plt_to_unit`, `fk_id_unit_plt_to_unit`
- CHECK [V2] (tidak ada di prod): `chk_pegawai_plt_status`

#### A `pegawai_plh` — `D1:3363-3409` (22 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `keterangan` (`D1:3380`)
- Urutan kolom berbeda dari prod
- Kolom: jenis_jabatan.type: prod='tinyint' v2='tinyint(1)' (`D1:3365`)
- Kolom: jenis_jabatan_koord.type: prod='tinyint' v2='tinyint(1)' (`D1:3366`)
- Kolom: tgl_mulai.null: prod='YES' v2='NO' (`D1:3367`)
- Kolom: tgl_akhir.null: prod='YES' v2='NO' (`D1:3368`)
- Kolom: updated_at.default: prod='<NULL>' v2='CURRENT_TIMESTAMP' (`D1:3384`)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_pegawai_plh_ibfk_01`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (10): `fk_pegawai_plh_ibfk_02`, `fk_pegawai_plh_ibfk_03`, `fk_pegawai_plh_ibfk_04`, `fk_pegawai_plh_ibfk_05`, `fk_pegawai_plh_ibfk_06`, `fk_pegawai_plh_ibfk_07`, `fk_pegawai_plh_ibfk_08`, `fk_pegawai_plh_ibfk_09`, `fk_pegawai_plh_ibfk_10`, `fk_pegawai_plh_ibfk_11`
- CHECK [V2] (tidak ada di prod): `chk_pegawai_plh_status`

#### A `riwayat_kp` — `D1:5338-5381` (35 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `siasn_flag` (`D1:5360`), `siasn_error` (`D1:5361`), `siasn_lu` (`D1:5362`)
- Urutan kolom berbeda dari prod
- Kolom: tgl_sk.null: prod='YES' v2='NO' (`D1:5344`)
- Kolom: jenis_kp.null: prod='YES' v2='NO' (`D1:5350`)
- Kolom: gol.null: prod='YES' v2='NO' (`D1:5351`)
- Kolom: ruang.null: prod='YES' v2='NO' (`D1:5352`)
- Kolom: gol_ruang.null: prod='YES' v2='NO' (`D1:5353`)
- Kolom: pangkat.null: prod='YES' v2='NO' (`D1:5354`)
- Kolom: gaji_pokok.type: prod='double' v2='int' (`D1:5357`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:5365`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:5368`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:5369`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:5371`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:5371`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:5371`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:5372`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:5372`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:5372`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:5373`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5373`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5373`)
- FK sama nama/kolom, aksi beda (3; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_kp_rwy_kp_to_jenis_kp`, `fk_id_pangkat_rwy_kp_to_pangkat`, `fk_nip_rwykp_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_kp_status`

#### A `riwayat_kgb` — `D1:5277-5307` (22 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `gol_pppk` (`D1:5284`)
- Urutan kolom berbeda dari prod
- Kolom: tgl_sk.null: prod='NO' v2='YES' (`D1:5283`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:5291`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:5294`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:5295`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:5297`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:5297`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:5297`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:5298`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:5298`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:5298`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:5299`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5299`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5299`)
- KEY (nama/isi): KEY show_notif_notif_date: prod=('show_notif', 'notif_date') v2=None
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwykgb_to_pegawai`, `riwayat_kgb_ibfk_01`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_kgb_status`

#### A `riwayat_pendidikan` — `D1:6119-6164` (34 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `siasn_flag` (`D1:6140`), `siasn_error` (`D1:6141`), `siasn_lu` (`D1:6142`)
- Urutan kolom berbeda dari prod
- Kolom: id_jenjang_pendidikan.type: prod='tinyint' v2='int' (`D1:6122`)
- Kolom: tgl_lulus.null: prod='NO' v2='YES' (`D1:6125`)
- Kolom: jenjang_pendidikan_singkat.type: prod='varchar(15)' v2='varchar(50)' (`D1:6129`)
- Kolom: bidang_pendidikan_lain.type: prod='varchar(100)' v2='varchar(255)' (`D1:6131`)
- Kolom: nem.type: prod='decimal(5,2)' v2='double' (`D1:6134`)
- Kolom: ipk.type: prod='decimal(3,2)' v2='double' (`D1:6135`)
- Kolom: status.type: prod='int' v2='tinyint' (`D1:6143`)
- Kolom: status.null: prod='YES' v2='NO' (`D1:6143`)
- Kolom: created_at.null: prod='YES' v2='NO' (`D1:6144`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:6145`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:6148`)
- Kolom: show_notif.type: prod='int' v2='tinyint' (`D1:6149`)
- Kolom: show_notif.null: prod='YES' v2='NO' (`D1:6149`)
- Kolom: show_ua_upt.type: prod='int' v2='tinyint' (`D1:6151`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:6151`)
- Kolom: show_ua_deputi.type: prod='int' v2='tinyint' (`D1:6152`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:6152`)
- Kolom: show_ua_biro.type: prod='int' v2='tinyint' (`D1:6153`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:6153`)
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (4; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_bidang_pendidikan_rpend_to_bpend`, `fk_id_jenjang_pendidikan_rpend_to_jpend`, `fk_id_jurusan_pendidikan_rpend_jupend`, `fk_nip_rwypendidikan_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_pendidikan_status`

#### A `riwayat_diklat` — `D1:4714-4755` (33 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: jenis_diklat.null: prod='YES' v2='NO' (`D1:4721`)
- Kolom: tgl_sertifikat.null: prod='YES' v2='NO' (`D1:4723`)
- Kolom: no_sertifikat.type: prod='varchar(255)' v2='varchar(100)' (`D1:4726`)
- Kolom: jumlah_jp.type: prod='int' v2='smallint' (`D1:4727`)
- Kolom: sub_group_jabatan.type: prod='varchar(255)' v2='varchar(100)' (`D1:4730`)
- Kolom: deskripsi.type: prod='tinytext' v2='text' (`D1:4731`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:4733`)
- Kolom: siasn_error.type: prod='tinytext' v2='text' (`D1:4736`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:4739`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:4742`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:4743`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:4745`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:4745`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:4745`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:4746`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:4746`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:4746`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:4747`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:4747`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:4747`)
- KEY (nama/isi): KEY show_notif_notif_date: prod=('show_notif', 'notif_date') v2=None
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_diklat_rdiklat_to_diklat`, `fk_nip_rwydiklat_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_diklat_jenis_diklat`, `chk_riwayat_diklat_status`

#### A `riwayat_seminar` — `D1:6264-6292` (24 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: jenis_seminar.type: prod='tinyint(1)' v2='tinyint' (`D1:6267`)
- Kolom: bidang_seminar.type: prod='varchar(100)' v2='varchar(255)' (`D1:6268`)
- Kolom: nama_seminar.null: prod='NO' v2='YES' (`D1:6269`)
- Kolom: tgl_sertifikat.null: prod='YES' v2='NO' (`D1:6270`)
- Kolom: jumlah_jp.type: prod='int' v2='smallint' (`D1:6272`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:6274`)
- Kolom: siasn_error.type: prod='tinytext' v2='text' (`D1:6277`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:6280`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:6283`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:6284`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:6286`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:6286`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:6286`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:6287`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:6287`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:6287`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:6288`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:6288`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:6288`)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwyseminar_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_seminar_status`

#### A `bkn_periode_ekinper` — `D1:270-284` (11 kolom, collation utf8mb4_unicode_ci)
- Identik (kolom, tipe, NULL, default, PK, KEY, FK, collation).

#### A `riwayat_skp` — `D1:6324-6366` (37 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `gol_ruang_penilai` (`D1:6346`), `unor_penilai` (`D1:6347`), `status_penilai` (`D1:6348`)
- Urutan kolom berbeda dari prod
- Kolom: tahun.type: prod='year' v2='smallint' (`D1:6327`)
- Kolom: tgl_mulai.null: prod='YES' v2='NO' (`D1:6328`)
- Kolom: tgl_akhir.null: prod='YES' v2='NO' (`D1:6329`)
- Kolom: nip_penilai.type: prod='varchar(18)' v2='varchar(30)' (`D1:6330`)
- Kolom: nama_penilai.type: prod='varchar(150)' v2='varchar(255)' (`D1:6331`)
- Kolom: jabatan_penilai.type: prod='varchar(150)' v2='varchar(255)' (`D1:6332`)
- Kolom: nip_atasan_penilai.type: prod='varchar(18)' v2='varchar(30)' (`D1:6333`)
- Kolom: nama_atasan_penilai.type: prod='varchar(150)' v2='varchar(255)' (`D1:6334`)
- Kolom: jabatan_atasan_penilai.type: prod='varchar(150)' v2='varchar(255)' (`D1:6335`)
- Kolom: nilai_skp.type: prod='decimal(5,2)' v2='decimal(6,2)' (`D1:6336`)
- Kolom: nilai_skp_60_persen.type: prod='decimal(5,2)' v2='decimal(6,2)' (`D1:6337`)
- Kolom: nilai_perilaku.type: prod='decimal(5,2)' v2='decimal(6,2)' (`D1:6339`)
- Kolom: nilai_perilaku_40_persen.type: prod='decimal(5,2)' v2='decimal(6,2)' (`D1:6340`)
- Kolom: nilai_prestasi_kerja.type: prod='decimal(5,2)' v2='decimal(6,2)' (`D1:6342`)
- Kolom: keterangan.type: prod='mediumtext' v2='text' (`D1:6344`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:6345`)
- Kolom: siasn_error.type: prod='tinytext' v2='text' (`D1:6351`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:6354`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:6354`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:6357`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:6359`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:6359`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:6359`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:6360`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:6360`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:6360`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:6361`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:6361`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:6361`)
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwyskp_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_skp_status`

#### A `riwayat_skp_periodik` — `D1:6408-6446` (35 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `f_arsip_2` (`D1:6440`)
- Kolom hanya di v2: `keterangan`, `updated_by`
- Kolom: id_riwayat_skp_periodik.type: prod='bigint' v2='int' (`D1:6409`)
- Kolom: id_pns.type: prod='varchar(150)' v2='varchar(100)' (`D1:6410`)
- Kolom: id_pns.null: prod='NO' v2='YES' (`D1:6410`)
- Kolom: jenis.type: prod='int' v2='varchar(100)' (`D1:6414`)
- Kolom: jenis.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6414`)
- Kolom: tahun_skp.type: prod='year' v2='varchar(10)' (`D1:6415`)
- Kolom: tahun_skp.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6415`)
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:6416`)
- Kolom: nama.null: prod='NO' v2='YES' (`D1:6417`)
- Kolom: periode_awal_skp.type: prod='date' v2='varchar(30)' (`D1:6418`)
- Kolom: periode_awal_skp.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6418`)
- Kolom: periode_akhir_skp.type: prod='date' v2='varchar(30)' (`D1:6419`)
- Kolom: periode_akhir_skp.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6419`)
- Kolom: skp_jenis_jabatan.type: prod='int' v2='varchar(100)' (`D1:6424`)
- Kolom: skp_jenis_jabatan.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6424`)
- Kolom: is_skp_plt_plh_pjb.type: prod='int' v2='varchar(10)' (`D1:6425`)
- Kolom: is_skp_plt_plh_pjb.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6425`)
- Kolom: hasil_kerja.type: prod='varchar(50)' v2='varchar(100)' (`D1:6426`)
- Kolom: perilaku_kerja.type: prod='varchar(50)' v2='varchar(100)' (`D1:6427`)
- Kolom: hasil_akhir.type: prod='varchar(50)' v2='varchar(100)' (`D1:6428`)
- Kolom: pegawai_atasan_nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:6430`)
- Kolom: pegawai_atasan_golru.type: prod='varchar(10)' v2='varchar(20)' (`D1:6435`)
- Kolom: waktu_dinilai.type: prod='datetime' v2='varchar(30)' (`D1:6436`)
- Kolom: waktu_dinilai.coll: prod='' v2='utf8mb4_unicode_ci' (`D1:6436`)
- Kolom: golru.type: prod='varchar(10)' v2='varchar(20)' (`D1:6438`)
- Kolom: f_arsip_1.type: prod='varchar(512)' v2='varchar(255)' (`D1:6439`)
- Kolom: updated_at.default: prod='<NULL>' v2='CURRENT_TIMESTAMP' (`D1:6443`)
- KEY (nama/isi): KEY idx_riwayat_skp_periodik_nip: prod=None v2=('nip',)
- KEY (nama/isi): KEY idx_riwayat_skp_periodik_periode_nip: prod=None v2=('periode_id', 'nip')
- KEY (nama/isi): KEY nip: prod=('nip',) v2=None
- CHECK [V2] (tidak ada di prod): `chk_riwayat_skp_periodik_status`

#### A `riwayat_lckh` — `D1:5455-5495` (27 kolom, collation utf8mb4_unicode_ci)
- Kolom: nama.type: prod='varchar(256)' v2='varchar(150)' (`D1:5461`)
- Kolom: unit.type: prod='varchar(256)' v2='varchar(150)' (`D1:5462`)
- Kolom: satker.type: prod='varchar(256)' v2='varchar(150)' (`D1:5463`)
- KEY (nama/isi): KEY file_lckh: prod=('file_lckh',) v2=None
- KEY (nama/isi): KEY fk_nip_rwylkh_to_pegawai: prod=('nip',) v2=None
- KEY (nama/isi): KEY fk_riwayat_lckh_ibfk_01: prod=None v2=('nip',)
- KEY (nama/isi): KEY fk_riwayat_lckh_ibfk_02: prod=None v2=('nip_atasan',)
- KEY (nama/isi): KEY idx_riwayat_lckh_atasan_status: prod=None v2=('nip_atasan', 'status')
- KEY (nama/isi): KEY idx_riwayat_lckh_nip_tgl: prod=None v2=('nip', 'tgl_laporan')
- KEY (nama/isi): KEY nip_atasan_status: prod=('nip_atasan', 'status') v2=None
- KEY (nama/isi): KEY tgl_laporan: prod=('tgl_laporan',) v2=None
- KEY (nama/isi): KEY tgl_laporan_status: prod=('tgl_laporan', 'status') v2=None
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_riwayat_lckh_ibfk_01`, `fk_riwayat_lckh_ibfk_02`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (2): `fk_riwayat_lckh_ibfk_03`, `fk_riwayat_lckh_ibfk_04`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_lckh_status`

#### A `absen_ijin` — `D1:33-69` (28 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_abijin_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_absen_ijin_affect_tukin`, `chk_absen_ijin_jenis_dinas`, `chk_absen_ijin_status`

#### A `riwayat_hukdis` — `D1:4853-4893` (30 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `reason_note` (`D1:4878`), `show_notif` (`D1:4879`), `notif_date` (`D1:4880`), `show_ua_upt` (`D1:4881`), `show_ua_deputi` (`D1:4882`), `show_ua_biro` (`D1:4883`)
- Urutan kolom berbeda dari prod
- Kolom: id_tingkat_hukdis.type: prod='tinyint' v2='int' (`D1:4856`)
- Kolom: id_jenis_hukdis.type: prod='tinyint' v2='int' (`D1:4857`)
- Kolom: masa_hukuman.type: prod='tinytext' v2='text' (`D1:4868`)
- Kolom: aturan_dilanggar.type: prod='tinytext' v2='text' (`D1:4870`)
- Kolom: alasan_hukuman.type: prod='tinytext' v2='text' (`D1:4871`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:4872`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:4875`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:4875`)
- FK sama nama/kolom, aksi beda (4; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis`, `fk_id_pangkat_rwyhukdis_to_pangkat`, `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis`, `fk_nip_rwyhukdis_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_hukdis_status`

#### A `riwayat_ak` — `D1:4034-4092` (50 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `kredit_utama_baru` (`D1:4050`), `kredit_penunjang_baru` (`D1:4051`), `id_rw_siasn` (`D1:4068`), `siasn_flag` (`D1:4069`), `siasn_error` (`D1:4070`), `siasn_lu` (`D1:4071`)
- Kolom hanya di v2: `tgl_ak_terakhir`, `tgl_pengajuan_ak`, `file_dupak`, `file_pengantar`
- Urutan kolom berbeda dari prod
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:4038`)
- Kolom: nama.type: prod='varchar(150)' v2='varchar(255)' (`D1:4040`)
- Kolom: no_hpak.type: prod='varchar(50)' v2='varchar(100)' (`D1:4042`)
- Kolom: ak_kum_kp.type: prod='double' v2='decimal(8,3)' (`D1:4046`)
- Kolom: ak_kum_next_jen.type: prod='double' v2='decimal(8,3)' (`D1:4047`)
- Kolom: ak_terakhir.type: prod='double' v2='decimal(8,3)' (`D1:4048`)
- Kolom: ak_terakhir.null: prod='NO' v2='YES' (`D1:4048`)
- Kolom: ak_terakhir.default: prod='0' v2='<NULL>' (`D1:4048`)
- Kolom: pengajuan_ak.type: prod='double' v2='decimal(8,3)' (`D1:4049`)
- Kolom: pengajuan_ak.null: prod='NO' v2='YES' (`D1:4049`)
- Kolom: pengajuan_ak.default: prod='0' v2='<NULL>' (`D1:4049`)
- Kolom: nilai_ak.type: prod='double' v2='decimal(8,3)' (`D1:4052`)
- Kolom: nilai_ak.null: prod='NO' v2='YES' (`D1:4052`)
- Kolom: nilai_ak.default: prod='0' v2='<NULL>' (`D1:4052`)
- Kolom: nama_pym.type: prod='varchar(256)' v2='varchar(255)' (`D1:4053`)
- Kolom: jab_pym.type: prod='varchar(256)' v2='varchar(255)' (`D1:4054`)
- Kolom: flag_instansi_ym.null: prod='NO' v2='YES' (`D1:4055`)
- Kolom: flag_instansi_ym.default: prod='0' v2='<NULL>' (`D1:4055`)
- Kolom: instansi_ym.type: prod='varchar(256)' v2='varchar(255)' (`D1:4056`)
- Kolom: jabatan.type: prod='varchar(256)' v2='varchar(255)' (`D1:4057`)
- Kolom: pangkat.type: prod='varchar(256)' v2='varchar(50)' (`D1:4061`)
- Kolom: gol_ruang.type: prod='varchar(50)' v2='varchar(10)' (`D1:4062`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:4066`)
- Kolom: file_pak.type: prod='varchar(256)' v2='varchar(255)' (`D1:4067`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:4073`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:4076`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:4082`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:4082`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:4083`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:4083`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:4084`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:4084`)
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_pangkat_rak_to_pangkat`, `fk_nip_rak_to_peg`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (1): `fk_id_jabatan_rak_to_jab`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_ak_status`

#### A `riwayat_ak_siasn` — `D1:4174-4212` (29 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `f_pak` (`D1:4196`)
- Kolom: id_riwayat_ak_siasn.type: prod='bigint' v2='int' (`D1:4175`)
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:4180`)
- Kolom: tahun_mulai_penilaian.type: prod='year' v2='smallint' (`D1:4184`)
- Kolom: tahun_selesai_penilaian.type: prod='year' v2='smallint' (`D1:4186`)
- Kolom: kredit_utama_baru.type: prod='decimal(10,3)' v2='decimal(8,3)' (`D1:4187`)
- Kolom: kredit_penunjang_baru.type: prod='decimal(10,3)' v2='decimal(8,3)' (`D1:4188`)
- Kolom: kredit_baru_total.type: prod='decimal(10,3)' v2='decimal(8,3)' (`D1:4189`)
- Kolom: is_angka_kredit_pertama.type: prod='varchar(10)' v2='tinyint(1)' (`D1:4192`)
- Kolom: is_angka_kredit_pertama.coll: prod='utf8mb4_unicode_ci' v2='' (`D1:4192`)
- Kolom: is_integrasi.type: prod='varchar(10)' v2='tinyint(1)' (`D1:4193`)
- Kolom: is_integrasi.coll: prod='utf8mb4_unicode_ci' v2='' (`D1:4193`)
- Kolom: is_konversi.type: prod='varchar(10)' v2='tinyint(1)' (`D1:4194`)
- Kolom: is_konversi.coll: prod='utf8mb4_unicode_ci' v2='' (`D1:4194`)
- Kolom: sumber.type: prod='varchar(255)' v2='varchar(100)' (`D1:4197`)
- Kolom: is_pemenuhan_kp.type: prod='varchar(10)' v2='tinyint(1)' (`D1:4198`)
- Kolom: is_pemenuhan_kp.coll: prod='utf8mb4_unicode_ci' v2='' (`D1:4198`)
- Kolom: updated_at.default: prod='<NULL>' v2='CURRENT_TIMESTAMP' (`D1:4202`)
- UNIQUE: UNIQUE id_rw_siasn: prod=('id_rw_siasn',) v2=None
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_pangkat_rw_ak_siasn_03`, `fk_nip_rw_ak_siasn_01`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (1): `fk_id_jabatan_rw_ak_siasn_02`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_ak_siasn_status`

#### A `konv_ak` — `D1:2057-2088` (20 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di v2: `id`
- Urutan kolom berbeda dari prod
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:2058`)
- Kolom: pangkat.type: prod='varchar(255)' v2='varchar(50)' (`D1:2063`)
- Kolom: gol_ruang.type: prod='varchar(50)' v2='varchar(10)' (`D1:2064`)
- Kolom: no_pak.type: prod='varchar(255)' v2='varchar(100)' (`D1:2068`)
- Kolom: ak_terakhir.type: prod='double' v2='decimal(8,3)' (`D1:2070`)
- Kolom: ak_terakhir.null: prod='NO' v2='YES' (`D1:2070`)
- Kolom: ak_terakhir.default: prod='0' v2='<NULL>' (`D1:2070`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:2073`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:2076`)
- PK: PK prod=['nip'] v2=['id']
- KEY (nama/isi): KEY fk_nip_konvak_to_pegawai: prod=None v2=('nip',)
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_pangkat_konvak_to_pangkat`, `fk_nip_konvak_to_pegawai`
- FK hanya di prod, nama sama persis dengan draf branch `dbv-012/fk-ditahan` (3): `fk_id_jabatan_konvak_to_jabatan`, `fk_id_satker_konvak_to_satker`, `fk_id_unit_konvak_to_unit`
- CHECK [V2] (tidak ada di prod): `chk_konv_ak_status`

#### A `ignore_konv_ak` — `D1:1346-1351` (2 kolom, collation utf8mb4_unicode_ci)
- Kolom: nip.type: prod='varchar(50)' v2='varchar(30)' (`D1:1347`)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_ignorekonvak_to_pegawai`

#### A `riwayat_keluarga` — `D1:5247-5272` (19 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: urutan_perkawinan.null: prod='NO' v2='YES' (`D1:5250`)
- Kolom: urutan_perkawinan.default: prod='1' v2='<NULL>' (`D1:5250`)
- Kolom: tgl_perkawinan.null: prod='NO' v2='YES' (`D1:5251`)
- Kolom: nama_pasangan.null: prod='NO' v2='YES' (`D1:5253`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:5258`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:5261`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:5262`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:5264`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:5264`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:5264`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:5265`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:5265`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:5265`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:5266`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5266`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5266`)
- KEY (nama/isi): KEY show_notif_notif_date: prod=('show_notif', 'notif_date') v2=None
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwykeluarga_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_keluarga_status`

#### A `detail_anak` — `D1:373-386` (9 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_riwayat_keluarga_detanak_to_rwykeluarga`

#### A `riwayat_alamat` — `D1:4300-4350` (35 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: alamat_utama.null: prod='NO' v2='YES' (`D1:4307`)
- Kolom: alamat_utama.default: prod='2' v2='<NULL>' (`D1:4307`)
- Kolom: jenis_alamat.default: prod='1' v2='<NULL>' (`D1:4308`)
- Kolom: kd_pos.type: prod='varchar(50)' v2='varchar(10)' (`D1:4317`)
- Kolom: alamat.type: prod='tinytext' v2='text' (`D1:4318`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:4327`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:4330`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:4331`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:4333`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:4333`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:4333`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:4334`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:4334`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:4334`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:4335`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:4335`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:4335`)
- KEY (nama/isi): KEY alamat_utama_created_at: prod=('alamat_utama', 'created_at') v2=None
- KEY (nama/isi): KEY show_notif: prod=('show_notif', 'notif_date') v2=None
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (5; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_kabupaten_kota_riwalamat_kabkota`, `fk_id_kecamatan_riwalamat_kecamatan`, `fk_id_kelurahan_riwalamat_kelurahan`, `fk_id_provinsi_riwalamat_provinsi`, `fk_nip_rwyalamat_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_alamat_status`

#### A `riwayat_karpeg` — `D1:5122-5149` (23 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `show_ua_upt` (`D1:5142`), `show_ua_deputi` (`D1:5143`), `show_pl` (`D1:5145`)
- Urutan kolom berbeda dari prod
- Kolom: jenis_permohonan.type: prod='varchar(50)' v2='varchar(100)' (`D1:5125`)
- Kolom: jenis_permohonan.null: prod='NO' v2='YES' (`D1:5125`)
- Kolom: jenis_permohonan.default: prod='Permohonan Pertama' v2='<NULL>' (`D1:5125`)
- Kolom: file_surat_hilang.type: prod='varchar(225)' v2='varchar(255)' (`D1:5126`)
- Kolom: file_1.type: prod='varchar(225)' v2='varchar(255)' (`D1:5127`)
- Kolom: file_1.null: prod='NO' v2='YES' (`D1:5127`)
- Kolom: file_2.type: prod='varchar(225)' v2='varchar(255)' (`D1:5128`)
- Kolom: file_2.null: prod='NO' v2='YES' (`D1:5128`)
- Kolom: file_3.type: prod='varchar(225)' v2='varchar(255)' (`D1:5129`)
- Kolom: file_3.null: prod='NO' v2='YES' (`D1:5129`)
- Kolom: file_4.type: prod='varchar(225)' v2='varchar(255)' (`D1:5130`)
- Kolom: file_4.null: prod='NO' v2='YES' (`D1:5130`)
- Kolom: file_5.type: prod='varchar(225)' v2='varchar(255)' (`D1:5131`)
- Kolom: file_5.null: prod='NO' v2='YES' (`D1:5131`)
- Kolom: file_tanda_terima.type: prod='varchar(225)' v2='varchar(255)' (`D1:5132`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:5135`)
- Kolom: updated_at.extra: prod='' v2='on update CURRENT_TIMESTAMP' (`D1:5135`)
- Kolom: reason_note.type: prod='mediumtext' v2='text' (`D1:5138`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5144`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5144`)
- KEY (nama/isi): KEY fk_nip_rkarpeg_to_pegawai: prod=None v2=('nip',)
- KEY (nama/isi): KEY nip_status_rated_notif_date: prod=('nip', 'status', 'rated', 'notif_date') v2=None
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rkarpeg_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_karpeg_status`

#### A `riwayat_kariskarsu` — `D1:5061-5088` (23 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `show_ua_upt` (`D1:5081`), `show_ua_deputi` (`D1:5082`), `show_pl` (`D1:5084`)
- Urutan kolom berbeda dari prod
- Kolom: file_1.type: prod='varchar(225)' v2='varchar(255)' (`D1:5064`)
- Kolom: file_1.null: prod='NO' v2='YES' (`D1:5064`)
- Kolom: file_2.type: prod='varchar(225)' v2='varchar(255)' (`D1:5065`)
- Kolom: file_2.null: prod='NO' v2='YES' (`D1:5065`)
- Kolom: file_3.type: prod='varchar(225)' v2='varchar(255)' (`D1:5066`)
- Kolom: file_3.null: prod='NO' v2='YES' (`D1:5066`)
- Kolom: file_4.type: prod='varchar(225)' v2='varchar(255)' (`D1:5067`)
- Kolom: file_4.null: prod='NO' v2='YES' (`D1:5067`)
- Kolom: file_5.type: prod='varchar(225)' v2='varchar(255)' (`D1:5068`)
- Kolom: file_5.null: prod='NO' v2='YES' (`D1:5068`)
- Kolom: file_surat_hilang.type: prod='varchar(250)' v2='varchar(255)' (`D1:5069`)
- Kolom: file_alasan.type: prod='varchar(225)' v2='varchar(255)' (`D1:5070`)
- Kolom: file_tanda_terima.type: prod='varchar(225)' v2='varchar(255)' (`D1:5071`)
- Kolom: reason_note.type: prod='mediumtext' v2='text' (`D1:5077`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:5083`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:5083`)
- KEY (nama/isi): KEY fk_nip_rkariskarsu_to_pegawai: prod=None v2=('nip',)
- KEY (nama/isi): KEY nip_status_rated_notif_date: prod=('nip', 'status', 'rated', 'notif_date') v2=None
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rkariskarsu_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_kariskarsu_status`

#### A `riwayat_tanda_jasa` — `D1:6507-6539` (24 kolom, collation utf8mb4_unicode_ci)
- Kolom hanya di prod: `siasn_lu` (`D1:6520`)
- Urutan kolom berbeda dari prod
- Kolom: id_tanda_jasa.type: prod='tinyint' v2='int' (`D1:6510`)
- Kolom: negara.type: prod='varchar(255)' v2='varchar(100)' (`D1:6515`)
- Kolom: keterangan.type: prod='tinytext' v2='text' (`D1:6516`)
- Kolom: siasn_error.type: prod='tinytext' v2='text' (`D1:6519`)
- Kolom: created_at.null: prod='YES' v2='NO' (`D1:6522`)
- Kolom: reason_note.type: prod='tinytext' v2='text' (`D1:6526`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:6527`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:6529`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:6529`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:6529`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:6530`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:6530`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:6530`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:6531`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:6531`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:6531`)
- KEY (nama/isi): KEY show_notif_notif_date: prod=('show_notif', 'notif_date') v2=None
- KEY (nama/isi): KEY status: prod=('status',) v2=None
- FK sama nama/kolom, aksi beda (2; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_id_tanda_jasa_rtj_to_tj`, `fk_nip_rwytj_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_tanda_jasa_status`

#### A `riwayat_organisasi` — `D1:6092-6114` (18 kolom, collation utf8mb4_unicode_ci)
- Urutan kolom berbeda dari prod
- Kolom: tgl_mulai.null: prod='YES' v2='NO' (`D1:6097`)
- Kolom: updated_at.null: prod='NO' v2='YES' (`D1:6102`)
- Kolom: show_notif.type: prod='tinyint(1)' v2='tinyint' (`D1:6106`)
- Kolom: show_ua_upt.type: prod='tinyint(1)' v2='tinyint' (`D1:6108`)
- Kolom: show_ua_upt.null: prod='NO' v2='YES' (`D1:6108`)
- Kolom: show_ua_upt.default: prod='0' v2='<NULL>' (`D1:6108`)
- Kolom: show_ua_deputi.type: prod='tinyint(1)' v2='tinyint' (`D1:6109`)
- Kolom: show_ua_deputi.null: prod='NO' v2='YES' (`D1:6109`)
- Kolom: show_ua_deputi.default: prod='0' v2='<NULL>' (`D1:6109`)
- Kolom: show_ua_biro.type: prod='tinyint(1)' v2='tinyint' (`D1:6110`)
- Kolom: show_ua_biro.null: prod='NO' v2='YES' (`D1:6110`)
- Kolom: show_ua_biro.default: prod='0' v2='<NULL>' (`D1:6110`)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_nip_rwyorganisasi_to_pegawai`
- CHECK [V2] (tidak ada di prod): `chk_riwayat_organisasi_status`

#### A `document_attachment` — `D1:498-520` (17 kolom, collation utf8mb4_unicode_ci)
- FK sama nama/kolom, aksi beda (1; prod ON UPDATE CASCADE → v2 RESTRICT/RESTRICT, deviasi [V2]): `fk_NIP_da_to_pegawai`
- FK fk_id_riwayat_da_to_jenis_rwy hanya v2 (['id_riwayat']->jenis_rwy RESTRICT/RESTRICT)

