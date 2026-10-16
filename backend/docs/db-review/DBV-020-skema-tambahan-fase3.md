# DB Validator Review — DBV-020 skema tambahan Fase 3 (master sertifikasi, `dm_ak_jf`, `pengguna`, `pegawai_hist`)

**Key review:** `DBV-020`. Judul PR: `[DBV-020] Skema tambahan Fase 3: master sertifikasi, dm_ak_jf, must_change_password,
gelar pegawai_hist`. Branch `dbv-020/skema-tambahan-fase3` (dari `main` `5ddaf33`). Alur **DBV-only** (AGENTS.md §2): PR,
DB Validator me-review/approve **dan** merge. Rekomendasi key/alur dan alasannya: Bagian 10 #9.

**Status:** ✅ **DISETUJUI DB VALIDATOR (jjoseph48, komentar PR #27 15-10-2026
https://github.com/birosdmopar-oss/simpeg-v2-2026/pull/27#issuecomment-6188236160)** — verifikasi MariaDB 10.4
lolos (100 tabel / 204 FK, rollback & pengaman `down()` gelar terbukti); Keputusan Bagian 10 #1–#10 dicatat per butir.
Sebelum merge: branch disinkronkan dengan `main` terbaru + CI (permintaan review bagian 2), rujukan "runbook DBV-014" →
runbook impor DBV-011 (C-2). Isi Bagian 11 tetap verifikasi developer sebelum review.

**Asal kebutuhan:** kumpulan kebutuhan skema Fase 3 yang dicatat WS-1 (MAKE-007 Diklat, MAKE-008 AK) dan WS-2 (MAKE-010
Koreksi NIP & biodata, MAKE-011 NipCascade), disetujui user 13-10-2026 untuk diajukan sebagai satu unit DBV.

**Rujukan:** `B-01-B-02-pegawai-riwayat-schema.md` (DBV-012/013; Bagian 5.1 aturan snapshot, Bagian 8 keputusan #3, #6,
#7); `DBV-019-fk-g02-pegawai-schema.md` (pola migration ditahan `app/Database/MigrationsDitahan/`, pra-cek yatim, catatan
rebuild tabel); `A-01-auth-schema.md` Bagian 9 (DBV-010: `pengguna.expired_at`, `password_changed_at`);
`G-03-lokasi-presensi-schema.md` Bagian 6.1 (`user_lokasi_presensi`); `G-04-G-05-pangkat-pendidikan-schema.md` dan
`G-02-jabatan-unit-satker-schema.md` (PK `pangkat` TINYINT, `jabatan` INT). D1 = dump struktur produksi `simpeg01`
01-10-2026 (`simpeg01_struktur_lengkap_20261001.sql`, di luar repo; rujukan `D1:baris`). Kode legacy = folder aplikasi
SIMPEG legacy (`application/…`, di luar repo; rujukan `berkas:baris`).

**Label sumber:** **[K]** terkonfirmasi DDL D1 atau kode legacy (`berkas:baris`); **[V2]** keputusan v2 yang disengaja
(alasan ditulis di butir masing-masing).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-10-13-100000_CreateMasterSertifikasi.php` | Butir 1: `rumpun_sertifikasi`, `lembaga_sertifikasi` [K] D1; `down()` = DROP kedua tabel |
| `app/Database/Migrations/2026-10-13-100100_CreateDmAkJf.php` | Butir 2: `dm_ak_jf` [K] D1 + 2 FK RESTRICT ke `jabatan`/`pangkat`; `down()` = DROP |
| `app/Database/Migrations/2026-10-13-100200_AlterPenggunaPegawaiHistFase3.php` | Butir 4: `pengguna.must_change_password` [V2]; butir 5: `pegawai_hist.glr_awal`/`glr_akhir` → VARCHAR(50) [V2]; `down()` fail-closed bila gelar lebih panjang dari lebar lama |
| `app/Database/MigrationsDitahan/AddFkSertifikasiDiRiwayatDiklat.php` | Butir 1: 2 FK [V2] `riwayat_diklat` → master sertifikasi, **DITAHAN** (tidak dijalankan `spark migrate`), pra-cek yatim fail-closed |
| `tests/database/Kepegawaian/SkemaTambahanFase3Test.php` (baru) | information_schema (kolom, tipe, NULL, default, PK, index, FK + aksi, collation, engine, COMMENT); constraint (1452/1451/1062/1364); siklus `down()`/`up()`; `down()` ditolak bila gelar panjang; migration FK ditahan dipasang sementara lalu dilepas |
| `tests/_support/LepasMigrationKepegawaianTrait.php` | `lepasFkG02()` ikut melepas `2026-10-13-100100` (`dm_ak_jf` → `jabatan` RESTRICT menahan DROP `jabatan` di test down()/up() G-02) |
| `tests/database/Kepegawaian/PegawaiSnapshotSchemaTest.php` | ekspektasi skema akhir B-01 = D1 + deviasi butir 5 (gelar `pegawai_hist` 50); `SkemaD1` tetap = D1 |
| `tests/Auth/AkunNonPegawaiSchemaTest.php` | daftar kolom `pengguna` akhir + `must_change_password` |
| `tests/Kepegawaian/Riwayat/AkKumulatifTest.php` | memakai `dm_ak_jf` hasil migration (sebelumnya membuat tabel sementara dengan DDL sendiri lalu men-drop-nya) |

Tidak ada perubahan kode aplikasi, seed, maupun migration yang sudah ada di `main`.

## 0. Ringkasan

| # | Butir | Perubahan skema | Label | Migration |
|---|---|---|---|---|
| 1 | Master sertifikasi (Diklat jenis 5, WS-1 MAKE-007 keputusan C1) | CREATE `rumpun_sertifikasi`, `lembaga_sertifikasi`; FK `riwayat_diklat` → keduanya **ditahan** | [K] D1:6693-6702, D1:2137-2146; FK [V2] | `100000` + `MigrationsDitahan/…` |
| 2 | Master AK kumulatif JF (WS-1 MAKE-008) | CREATE `dm_ak_jf` + FK `fk_dm_ak_jf_ibfk_01/02` | [K] D1:457-469; aksi FK [V2] | `100100` |
| 3 | Aturan snapshot `pegawai_ak_siasn` (DBV-012 #6) | — (tabel sudah ada di `main`); keputusan saja | [K] trigger D1:8493-8577 + [V2] urutan seri | — |
| 4 | Penanda wajib ganti password (WS-2 MAKE-010) | ADD `pengguna.must_change_password` TINYINT(1) NOT NULL DEFAULT 0 | [V2] | `100200` |
| 5 | Gelar `pegawai_hist` (WS-2 MAKE-010) | MODIFY `glr_awal` VARCHAR(12)→(50), `glr_akhir` VARCHAR(24)→(50) | [V2] (D1:2948-2949 → = `pegawai` D1:2506-2507) | `100200` |
| 6 | Registry NIP `user_lokasi_presensi` (WS-2 MAKE-011) | — ; tabel tidak dibuat di v2 | [V2] | — |

Hasil di DB scratch MySQL 8.0.30 (Bagian 11): batch DBV-020 = 3 migration; tabel 97 → 100, FK 202 → 204; `migrate:rollback`
mengembalikan 97 tabel/202 FK dan lebar gelar 12/24; `migrate` ulang menghasilkan skema identik (checksum kolom & FK).

## 1. Butir 1 — `rumpun_sertifikasi` dan `lembaga_sertifikasi`

### 1.1 Sumber [K]

- DDL: `rumpun_sertifikasi` D1:6693-6702, `lembaga_sertifikasi` D1:2137-2146 — struktur sama: `id_*` INT NOT NULL **tanpa**
  AUTO_INCREMENT (PK), nama VARCHAR(255) NOT NULL, `status` TINYINT NOT NULL DEFAULT '1' (tanpa COMMENT), `created_at`
  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `created_by` INT NULL, `updated_at` DATETIME NULL ON UPDATE
  CURRENT_TIMESTAMP, `updated_by` INT NULL; tanpa UNIQUE/KEY lain.
- Kolom perujuk: `riwayat_diklat.id_rumpun_sertifikasi` / `id_lembaga_sertifikasi` INT NULL (D1:4719-4720) — **tanpa FK
  dan tanpa index** di D1 (D1:4748-4754 hanya FK `id_diklat` dan `nip`). Kolom ini sudah ada di `main` (migration
  `2026-09-30-130300`, `int DEFAULT NULL`).
- Kode legacy: dropdown hanya status 1, urut nama (`libraries/hr/rwy/L_diklat.php:53-54`); validasi jenis 5 hanya "wajib
  diisi" (`L_diklat.php:500-506`); simpan menyalin id kiriman klien lalu mengisi teks `rumpun_sertifikasi` /
  `instansi_penyelenggara` dari master (`L_diklat.php:652-661`) — id yang tidak ada di master tetap tersimpan (teks
  menjadi NULL); sinkron SIASN membaca kedua master lewat INNER JOIN
  (`controllers/hr/services/siasn_sync/Rw_pelatihan.php:334-339`). Tidak ada form kelola master ini di legacy.

### 1.2 Skema v2

Sama dengan D1 (nama tabel/kolom, urutan, tipe, default, nullability, PK INT tanpa AUTO_INCREMENT, kolom audit), dengan
satu deviasi [V2]: COMMENT `status` = `'1: Aktif, 2: Tidak Aktif, 10: Dihapus'` (konvensi status master v2). Tidak ada
UNIQUE nama dan CHECK status (sama dengan D1 dan dengan master v2 lain yang tidak memakai CHECK status) — keputusan #2.

PK tanpa AUTO_INCREMENT dipertahankan [K]: id diimpor apa adanya, dan legacy tidak pernah membuat baris master ini dari
aplikasi. Bila nanti master ini diberi CRUD admin v2, id harus diisi eksplisit (atau diubah lewat migration baru).

### 1.3 FK `riwayat_diklat` → master — diusulkan, DITAHAN

| FK [V2] | Anak | Induk | Tipe | Aksi |
|---|---|---|---|---|
| `fk_id_rumpun_sertifikasi_rdiklat_to_rs` | `riwayat_diklat.id_rumpun_sertifikasi` | `rumpun_sertifikasi.id_rumpun_sertifikasi` | INT / INT | RESTRICT/RESTRICT |
| `fk_id_lembaga_sertifikasi_rdiklat_to_ls` | `riwayat_diklat.id_lembaga_sertifikasi` | `lembaga_sertifikasi.id_lembaga_sertifikasi` | INT / INT | RESTRICT/RESTRICT |

Nama mengikuti pola FK legacy tabel yang sama (`fk_id_diklat_rdiklat_to_diklat`, D1:4753). KEY bernama sama ikut dibuat
(D1 tidak punya index untuk kedua kolom); `down()` melepas FK lalu KEY, sehingga `riwayat_diklat` kembali = D1.

**Mengapa ditahan** (pola DBV-019 `MigrationsDitahan`): FK ini **tidak ada di D1**, dan form legacy menyimpan id tanpa
memeriksa keberadaan master (1.1), jadi data impor bisa berisi id yatim — FK aktif akan menghentikan `migrate` di
lingkungan target (pra-cek fail-closed) atau, bila impor memakai `FOREIGN_KEY_CHECKS = 0`, meninggalkan pelanggaran
tersembunyi. Selain itu v2 saat ini selalu menulis kedua kolom NULL (Definisi Diklat, 1.4), jadi menunda FK tidak membuka
celah penulisan baru. Prasyarat aktivasi:

1. Master dan `riwayat_diklat` sudah diimpor di lingkungan target; audit berikut = 0 (baca-saja):

   ```sql
   SELECT 'id_rumpun_sertifikasi' AS kolom, COUNT(*) AS yatim FROM riwayat_diklat c
   WHERE c.id_rumpun_sertifikasi IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM rumpun_sertifikasi p WHERE p.id_rumpun_sertifikasi = c.id_rumpun_sertifikasi)
   UNION ALL
   SELECT 'id_lembaga_sertifikasi', COUNT(*) FROM riwayat_diklat c
   WHERE c.id_lembaga_sertifikasi IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM lembaga_sertifikasi p WHERE p.id_lembaga_sertifikasi = c.id_lembaga_sertifikasi);
   ```

   Tindak lanjut yatim per kasus: id `0` → NULL; id lain → impor baris master yang hilang (status 10 bila tidak dipakai
   lagi) atau NULL-kan dengan catatan rekonsiliasi (teks `rumpun_sertifikasi`/`instansi_penyelenggara` tetap ada).
2. Definisi Diklat memakai dropdown master (1.4), supaya kedua kolom mulai terisi id yang tervalidasi aplikasi.
3. Aktivasi: pindahkan file ke `app/Database/Migrations/<timestamp baru>_AddFkSertifikasiDiRiwayatDiklat.php` (unit DBV
   lanjutan), timestamp lebih besar dari migration terakhir di lingkungan target.

Rebuild: satu ALTER `riwayat_diklat` (2 KEY + 2 FK) saat aktivasi; AUTO_INCREMENT D1 `riwayat_diklat` ±36 ribu —
jalankan di jendela cutover/pemeliharaan.

### 1.4 Catatan aplikasi (bukan bagian unit ini)

Definisi Diklat v2 (`app/Libraries/Kepegawaian/Riwayat/Definisi/Diklat.php`, WS-1 MAKE-007) masih memakai isian **teks**
untuk jenis 5 (`rumpun_sertifikasi`, lembaga di `instansi_penyelenggara`) dan menulis `id_rumpun_sertifikasi` /
`id_lembaga_sertifikasi` = NULL. Pengalihan ke dropdown master (perilaku legacy `L_diklat.php:652-661`) = **MAKE
lanjutan WS-1** setelah unit ini disetujui dan master diimpor.

**Syarat DB Validator (C-1, keputusan #2):** begitu `rumpun_sertifikasi`/`lembaga_sertifikasi` diberi CRUD, UNIQUE nama
dipasang **bersamaan** (migration + review DBV baru), tidak ditunda lagi — mengikuti pola G-01..G-10.

## 2. Butir 2 — `dm_ak_jf`

### 2.1 Sumber [K]

- DDL D1:457-469: `id_jabatan` INT NOT NULL, `id_pangkat` TINYINT NOT NULL, `jenjang` VARCHAR(50) NULL, `ak` DOUBLE NOT
  NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_by` INT NULL, `updated_at` DATETIME NULL ON
  UPDATE CURRENT_TIMESTAMP; PRIMARY KEY (`id_jabatan`, `id_pangkat`); KEY `fk_dm_ak_jf_ibfk_02` (`id_pangkat`); FK
  `fk_dm_ak_jf_ibfk_01` → `jabatan(id_jabatan)` dan `fk_dm_ak_jf_ibfk_02` → `pangkat(id_pangkat)`, keduanya ON DELETE
  CASCADE ON UPDATE CASCADE. Tanpa `created_by` dan `status`.
  **Laporan WS-1 terverifikasi sama** dengan D1 (kolom, tipe, PK, FK).
- Kode legacy: dibaca `libraries/hr/rwy/L_ak.php` form() :357-405 (query :387); dikelola
  `libraries/hr/master/Lm_jabatan.php:2277-2512` — tambah/ubah per pasangan jabatan + pangkat (`sp_akjf()` :2501-2512
  hanya menulis `id_jabatan`, `id_pangkat`, `ak`, `updated_by`; `jenjang` tidak ditulis form); `delete_akjf()` :2421-2452
  berisi salinan kode hapus unit kerja yang tidak berfungsi, jadi praktis tidak ada hapus.

### 2.2 Skema v2

Sama dengan D1. Tipe kolom FK = PK induk di `main`: `jabatan.id_jabatan` INT (G-02/DBV-008), `pangkat.id_pangkat` TINYINT
(G-04; komentar migration G-04 sudah menyebut `dm_ak_jf.id_pangkat tinyint`). Index PK (`id_jabatan` terdepan) melayani
FK `_01`. Deviasi [V2]: **aksi FK RESTRICT/RESTRICT** (legacy CASCADE/CASCADE) — konvensi V1 DBV-012/013 dan DBV-019:
master `jabatan`/`pangkat` dihapus lunak (status 10) dan PK tidak diubah, sehingga hapus/ubah keras yang memutus relasi
(atau, dengan CASCADE, diam-diam menghapus nilai AK) adalah bug yang harus ditolak DB. Tidak ditambah `status`/`created_by`
(legacy hapus keras pun tidak berfungsi; CRUD v2 master ini belum direncanakan) — keputusan #4.

### 2.3 Dampak ke kode

`App\Libraries\Kepegawaian\Riwayat\Definisi\Ak::adaMasterAkJf()` mendeteksi tabel lewat `information_schema.TABLES`
(tanpa cache daftar tabel). Setelah migration ini jalan dan data diimpor, AK kumulatif (`ak_kum_kp`, `ak_kum_next_jen`)
terhitung otomatis tanpa perubahan kode; selama tabel kosong nilainya 0 (sama dengan sebelum tabel ada). Komentar kode yang
masih menyebut "belum ada di skema v2" diperbarui di MAKE berikutnya (unit ini tidak mengubah kode aplikasi).

## 3. Butir 3 — aturan snapshot `pegawai_ak_siasn` (konfirmasi DBV-012 #6, tanpa migration)

Tabel `pegawai_ak_siasn` (D1:2742-2779) sudah ada di `main` (migration `2026-09-30-120100`, DBV-012). Keputusan #6
dokumen B-01/B-02 (memasukkannya ke B-01) dan aturan pengisinya (Bagian 5.1) masih ⏳. Unit ini meminta konfirmasi aturan
yang sudah dipakai kode WS-1 MAKE-008 (`Definisi/AkSiasn.php`, `AturanSnapshot('pegawai_ak_siasn', …, urutan: ['tgl_sk' =>
'DESC', 'id_riwayat_ak_siasn' => 'DESC'])`):

| | Legacy [K] | v2 |
|---|---|---|
| Sumber | Trigger `riwayat_ak_siasn_afDel` D1:8493-8520, `afIns` D1:8526-8545, `afUpd` D1:8551-8578 | `AturanSnapshot` engine riwayat (ADR-006: sinkron saat approval final/perubahan status) |
| Baris terpilih | `WHERE status='1' AND nip=… ORDER BY tgl_sk DESC LIMIT 1` (D1:8509, 8540, 8567) | `status = 1`, `ORDER BY tgl_sk DESC, id_riwayat_ak_siasn DESC`, satu baris |
| Tidak ada baris status 1 | snapshot dihapus (D1:8513, 8571) | snapshot dihapus (aturan bersama Bagian 5.1 B-01/B-02) |
| Seri `tgl_sk` sama | tidak deterministik (urutan fisik InnoDB) | [V2] PK terbesar (baris terbaru) menang — deterministik, sehingga rekonsiliasi impor bisa dihitung ulang dengan hasil sama |

`tgl_sk` NULL: MySQL/MariaDB menaruh NULL paling akhir pada `DESC`, jadi baris bertanggal selalu menang atas baris tanpa
tanggal — perilaku sama di legacy dan v2. Keputusan #5.

## 4. Butir 4 — `pengguna.must_change_password` [V2]

### 4.1 Kebutuhan

- Koreksi NIP (WS-2 MAKE-010, `App\Libraries\Kepegawaian\NipCascadeService`) mengikuti legacy
  `libraries/hr/L_employee.php:589-599` (keputusan user 13-10-2026): username **dan password** akun pegawai diganti NIP
  baru (v2: hash Argon2id, `password_changed_at` NULL).
- Reset password oleh admin (Manajemen Akun, `UserService::update` dengan `password`) dan reset massal per satker legacy
  (`libraries/hr/L_user.php:3288-3315`, `rp_satker()`: password = `md5(NIP)`) menghasilkan password yang diketahui pihak
  lain.

Legacy memaksa ganti password lewat popup FCP yang dihitung **saat login** (`core/MY_Controller.php:209-238`): role 2/6
dengan `password = md5(id_pegawai)` (:214-221), atau `expired_at` sudah lewat (:223-230). v2 menyimpan hash Argon2id
bergaram, sehingga "password = NIP" tidak bisa dicek lewat perbandingan kolom; penanda eksplisit dibutuhkan.

### 4.2 Skema

`must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT `'1: wajib ganti password saat login berikutnya (reset admin
/ Koreksi NIP), 0: tidak'`, sesudah `expired_at`. Nama mengikuti gaya kolom `pengguna` v2 yang berbahasa Inggris
(`password_changed_at`, `last_login_at`, `expired_at`). Semua akun yang ada menjadi 0. Tanpa CHECK 0/1 (konsisten dengan
flag TINYINT(1) [K] lain, mis. `show_notif`) — keputusan #6.

Alternatif yang ditimbang:
- (b) `password_changed_at IS NULL` sebagai penanda: ambigu — akun impor dan akun baru AccountProvisioner juga NULL,
  sementara reset admin mengisi tanggal sekarang (`UserService.php:357-361`).
- (c) memakai `expired_at` [K] (isi tanggal lampau saat reset): menyamakan dua makna (kedaluwarsa berkala vs wajib ganti
  sekali) dan nilai impor legacy (`expired_at` terisi massal, A-01 Bagian 9) ikut memicu paksa ganti; perilaku
  `expired_at` di v2 belum diputuskan (A-01: "belum dipakai aplikasi v2").

### 4.3 Di luar unit ini

Pengisian kolom (Koreksi NIP dan reset admin menulis 1; ganti password sukses menulis 0) dan penegakan di login/frontend
(blok fitur sampai password diganti) = **pekerjaan MAKE terpisah setelah DBV approve**. Sebelum itu kolom selalu 0 dan
tidak dibaca aplikasi.

## 5. Butir 5 — lebar gelar `pegawai_hist` [V2]

- [K] `pegawai.glr_awal`/`glr_akhir` VARCHAR(50) NULL (D1:2506-2507); `pegawai_hist.glr_awal` VARCHAR(12) NULL,
  `glr_akhir` VARCHAR(24) NULL (D1:2948-2949) — lebar lama yang sama dengan arsip `pegawai_20201224` (D1:2564-2565), jadi
  `pegawai` dilebarkan legacy tanpa ikut melebarkan `pegawai_hist`.
- Masalah: pengajuan biodata (MAKE-010) menyalin baris `pegawai` ke `pegawai_hist`; gelar > 12/24 karakter yang sah di
  `pegawai` gagal disimpan (strict mode: error 1406) atau, di server non-strict, terpotong.
- Perubahan: `MODIFY glr_awal VARCHAR(50) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL`, `MODIFY glr_akhir VARCHAR(50) …`
  dalam satu ALTER. Nullability, default NULL, dan collation dipertahankan (diuji). `SkemaD1` (ekspektasi D1) tidak
  diubah; test B-01 menerapkan deviasi ini di atasnya.
- Dampak ALTER: lebar byte maksimum utf8mb4 48→200 dan 96→200, tetap ≤ 255 (prefix panjang 1 byte), sehingga pelebaran
  dikerjakan **in-place tanpa menyalin tabel** — diverifikasi di MySQL 8.0.30 dengan `ALGORITHM=INPLACE, LOCK=NONE`
  (Bagian 11). MariaDB 10.4 seharusnya sama (perluasan VARCHAR dalam kelas prefix yang sama), mohon dicek DBV (Bagian
  11.3). Rollback (penyempitan) **memerlukan ALGORITHM=COPY** (MySQL menolak INPLACE, error 1846) — rebuild penuh
  `pegawai_hist` (D1: tabel histori pengajuan biodata, ukuran produksi tidak diketahui); jalankan di jendela
  cutover/pemeliharaan seperti DBV-019 Bagian 4 #4. `down()` menolak sebelum ALTER apa pun bila ada gelar lebih panjang
  dari 12/24 karakter.

## 6. Butir 6 — registry NIP `user_lokasi_presensi` (catatan, tanpa migration)

- [K] `user_lokasi_presensi` (D1:7595-7605): ekspansi aturan penargetan lokasi presensi per NIP (tanpa PK, 3 FK CASCADE),
  diisi legacy saat aturan `dm_user_lokasi_presensi` disimpan (G-03 Bagian 1 P-7, Bagian 6.1).
- Registry NIP v2 (`app/Libraries/Kepegawaian/Nip/TabelAnakNip.php:116`) masih mencatatnya `KeberadaanV2::Ada` fase 2;
  NipCascadeService melewatinya karena tabel tidak ada (`NipCascadeService.php:50`, `tests/Kepegawaian/NipCascadeTest.php`
  `TERLEWAT`).
- Usulan [V2]: tabel ini **tidak dibuat** di v2. Sumber kebenaran tetap aturan `dm_user_lokasi_presensi` (sudah di `main`,
  G-03); NIP yang dirujuk aturan disimpan di JSON `target_peg` / `excl_peg`, dan Koreksi NIP mengoreksi NIP di dalam kedua
  JSON itu (WS-2 MAKE-011). Ekspansi per NIP dihitung saat dibutuhkan (presensi Fase 5), bukan disimpan — tidak ada salinan
  yang bisa basi saat aturan, lokasi, atau penempatan pegawai berubah, dan tidak ada FK CASCADE ke `pegawai` yang
  bertentangan dengan K1.
- Konsekuensi: G-03 Bagian 6.2 #6 ("`user_lokasi_presensi` diimpor di Fase 3 bersama `pegawai`") gugur — baris legacy
  tidak diimpor (dapat dibangun ulang dari aturan). Entri registry diubah menjadi "tidak dibuat di v2" dan kolom JSON
  didaftarkan sebagai rujukan NIP non-FK oleh MAKE-011 (perubahan kode, bukan unit ini). Keputusan #8.

## 7. Migration, urutan, rollback

1. **Urutan.** `2026-10-13-100000` → `100100` → `100200`, setelah seluruh migration `main` (terakhir `2026-10-07-100100`).
   `100100` bergantung pada `jabatan` (`2026-09-30-100000`) dan `pangkat` (`2026-09-25-110000`); `100200` pada `pengguna`
   (A-01 + DBV-010 `2026-09-25-130100`, kolom `expired_at`) dan `pegawai_hist` (`2026-09-30-120000`). Migration ditahan:
   timestamp ditetapkan saat diaktifkan.
2. **Batch & rollback.** Pada `migrate` normal ketiganya satu batch; `migrate:rollback` membatalkan `100200` → `100100` →
   `100000`. `down()` `100200` fail-closed (gelar panjang) dan menghapus `must_change_password` (nilainya hilang);
   `100100`/`100000` DROP tabel (data master hilang — rollback setelah impor = impor ulang).
3. **Kegagalan di tengah.** `100000`: tabel yang sempat dibuat pada run itu di-drop lalu error dilempar ulang. `100200`:
   bila ALTER `pegawai_hist` gagal, kolom `must_change_password` yang baru ditambahkan dilepas lagi. Migration yang gagal
   tidak tercatat; bila pembersihan otomatis ikut gagal, drop manual (prefix sesuai lingkungan) `rumpun_sertifikasi`,
   `lembaga_sertifikasi`, `dm_ak_jf`, atau `ALTER TABLE pengguna DROP COLUMN must_change_password`, lalu ulang `migrate`
   — **jangan** `migrate:rollback`.
4. **Rebuild/lock.** CREATE tidak mengunci tabel yang ada. ADD COLUMN `pengguna` = ALGORITHM=INSTANT di MySQL 8.0.29+
   (diverifikasi 8.0.30); MODIFY gelar = in-place (Bagian 5). Rollback gelar = COPY.
5. **FK `dm_ak_jf` dan rollback master.** FK RESTRICT ke `jabatan`/`pangkat` menahan DROP master G-02/G-04 selama
   `dm_ak_jf` ada; urutan batch CodeIgniter sudah menjamin `dm_ak_jf` di-rollback lebih dulu. Test master yang
   menjalankan down()/up() G-02 melepas `100100` lewat `lepasFkG02()` (G-04 sudah lewat `lepasMigrationKepegawaian()`,
   versi ≥ `2026-09-30-120000`).

## 8. Interaksi impor

| Tabel/kolom | Aturan impor (bahan runbook impor DBV-011; C-2 review DBV) |
|---|---|
| `rumpun_sertifikasi`, `lembaga_sertifikasi` | Salin apa adanya termasuk id (tanpa AUTO_INCREMENT). Audit `SELECT status, COUNT(*) … GROUP BY status`: nilai di luar 1/2/10 dilaporkan dan dipetakan sebelum impor (legacy hanya membaca `status='1'`). Impor **sebelum** `riwayat_diklat` bila FK yang ditahan akan diaktifkan |
| `riwayat_diklat.id_rumpun_sertifikasi` / `id_lembaga_sertifikasi` | Diimpor apa adanya (tanpa FK di unit ini); audit yatim Bagian 1.3 dilaporkan, id `0` → NULL. Nilai teks `rumpun_sertifikasi`/`instansi_penyelenggara` tetap disalin (dipakai tampilan v2) |
| `dm_ak_jf` | Impor **setelah** `jabatan` dan `pangkat`. FK legacy CASCADE membuat data D1 seharusnya tanpa yatim; tetap diaudit (`… WHERE NOT EXISTS (SELECT 1 FROM jabatan …)` / `pangkat`). Bila impor dengan `FOREIGN_KEY_CHECKS = 0`, audit wajib 0 sebelum aplikasi dibuka. `jenjang` boleh NULL |
| `pengguna.must_change_password` | Default 0 untuk akun impor. Opsi untuk menjaga perilaku popup FCP legacy: isi 1 untuk akun role 2/6 yang `password_legacy = MD5(nip)` (password masih = NIP, `MY_Controller.php:214-221`) — keputusan #7 |
| `pegawai_hist.glr_awal`/`glr_akhir` | Data legacy ≤ 12/24 karakter, jadi impor lossless ke VARCHAR(50) |
| `user_lokasi_presensi` | Tidak diimpor (butir 6); aturan `dm_user_lokasi_presensi` (G-03) yang diimpor |
| `pegawai_ak_siasn` | Impor apa adanya; rekonsiliasi dengan aturan butir 3 (hitung ulang snapshot dari `riwayat_ak_siasn`, laporkan selisih — termasuk seri `tgl_sk` yang di legacy tidak deterministik) |

## 9. Interaksi dengan test yang sudah ada

- `JabatanUnitSatkerSchemaTest` (down()/up() G-02): FK `dm_ak_jf` → `jabatan` RESTRICT menahan DROP `jabatan` (3730), jadi
  `LepasMigrationKepegawaianTrait::lepasFkG02()` kini ikut melepas `2026-10-13-100100` dan memasangnya ulang paling akhir.
- `PegawaiSnapshotSchemaTest`: ekspektasi skema akhir = `SkemaD1::B01` + deviasi butir 5 (helper lokal); assertion lain
  tetap; ditambah test yang mengunci D1 = 12/24 dan v2 = lebar `pegawai`.
- `AkunNonPegawaiSchemaTest`: daftar kolom `pengguna` akhir memuat `must_change_password`; siklus down()/up() DBV-010 di
  test itu tidak menyentuh kolom baru.
- `AkKumulatifTest`: tidak lagi membuat/men-drop `dm_ak_jf` sendiri (DDL) — memakai tabel migration, sehingga test tidak
  lagi butuh `db-isolasi-penuh`. Cabang "tabel tidak ada → 0" di `Ak::adaMasterAkJf()` tidak lagi diuji di sini (tabel
  selalu ada setelah migrate); master kosong → 0 tetap diuji.

## 10. Keputusan yang diminta dari DB Validator

| # | Pertanyaan | Usulan developer | Keputusan |
|---|---|---|---|
| 1 | Butir 1: `rumpun_sertifikasi`/`lembaga_sertifikasi` = D1 (PK INT tanpa AUTO_INCREMENT) + COMMENT status v2 | Setujui | ✅ Setuju (PK INT tanpa AUTO_INCREMENT = D1; preseden `kelas_jabatan`) |
| 2 | Butir 1: tanpa UNIQUE nama dan tanpa CHECK status (= D1) | Setujui; UNIQUE nama menyusul lewat migration baru setelah audit duplikat data impor bila dikehendaki | ✅ Setuju **dengan syarat C-1**: UNIQUE nama WAJIB dipasang bersamaan saat kedua master diberi CRUD (bukan ditunda lagi) |
| 3 | Butir 1: 2 FK [V2] `riwayat_diklat` → master (Bagian 1.3, RESTRICT, KEY bernama sama) — isi disetujui sekarang, migration **ditahan** di `MigrationsDitahan/` sampai prasyarat 1.3 #1–#2 | Setujui isi + penahanan. Alternatif: (b) aktifkan sekarang (risiko `migrate` berhenti di lingkungan target bila data impor berisi yatim); (c) tanpa FK sama sekali (= D1) | ✅ Setuju isi 2 FK + penahanan di `MigrationsDitahan/` (terverifikasi tidak terpasang) |
| 4 | Butir 2: `dm_ak_jf` = D1 dengan FK RESTRICT/RESTRICT (D1 CASCADE/CASCADE); tanpa `status`/`created_by` | Setujui (konvensi V1) | ✅ Setuju RESTRICT/RESTRICT (C-3: service wajib menghapus baris `dm_ak_jf` lebih dulu dalam satu transaksi saat tabel mulai dikelola) |
| 5 | Butir 3: aturan snapshot `pegawai_ak_siasn` = status 1, `tgl_sk DESC`, seri → `id_riwayat_ak_siasn DESC`; tidak ada baris → snapshot dihapus. Sekaligus menutup keputusan #6 DBV-012 (tabel masuk B-01) | Setujui | ✅ Setuju (pemecah seri `id_riwayat_ak_siasn DESC` eksplisit; menutup DBV-012 #6) |
| 6 | Butir 4: kolom `pengguna.must_change_password` TINYINT(1) NOT NULL DEFAULT 0 sesudah `expired_at` (nama, tipe, tanpa CHECK); alternatif (b)/(c) Bagian 4.2 ditolak | Setujui; pengisian & penegakan = MAKE terpisah | ✅ Setuju (TINYINT(1) NOT NULL DEFAULT 0 tanpa CHECK) |
| 7 | Butir 4, impor: akun role 2/6 dengan `password_legacy = MD5(nip)` diberi `must_change_password = 1` | Setujui (menjaga perilaku FCP legacy); alternatif: semua 0 | ✅ Setuju; kueri penentunya masuk runbook impor DBV-011 langkah 5 (`pengguna`) |
| 8 | Butir 6: `user_lokasi_presensi` tidak dibuat di v2; ekspansi dihitung dari `dm_user_lokasi_presensi`; NIP di `target_peg`/`excl_peg` dikoreksi NipCascade (MAKE-011); G-03 6.2 #6 gugur | Setujui | ✅ Setuju (menutup G-03 6.2 #6) |
| 9 | Key & alur: 3 migration aktif + 1 migration ditahan + 1 schema test baru + penyesuaian 3 test dan 1 trait test support; tanpa kode aplikasi/seed | `[DBV-020]` saja, alur DBV-only (PR, DB Validator merge) — semua perubahan skema + test skema (preseden DBV-019 #5). Alternatif `[DBV-020][CR-…]` bila reviewer kode ingin me-review perubahan test | ✅ Setuju `[DBV-020]` saja |
| 10 | Butir 5 + Bagian 7 #4: MODIFY gelar in-place (tanpa jendela khusus); rollback (COPY) hanya di jendela pemeliharaan | Setujui; dicatat di runbook impor (DBV-011) | ✅ Setuju; dicatat di runbook impor (DBV-011), lihat C-2 |

Koreksi setelah approval dilakukan lewat migration baru, bukan dengan mengedit migration yang sudah di `main`.

## 11. Verifikasi developer (sebelum review DB Validator)

### 11.1 Lingkungan

MySQL 8.0.30 (instance test lokal `127.0.0.1:3307`, `tools/mysql-test`), PHP 8.4.2, CodeIgniter 4.7.4, PHPUnit 10.5.
Worktree branch `dbv-020/skema-tambahan-fase3` dari `origin/main` `5ddaf33` (13-10-2026). Semua uji database memakai
**DB scratch bernama eksplisit** yang dibuat lalu di-DROP dengan nama persis: `simpeg_v2_s_d20` (siklus `spark migrate`,
grup `default`), `simpeg_v2_t_d20` (PHPUnit, grup `tests`). `.env` uji dibuat dari `.env.example` (host/port/DB scratch,
captcha `mock`), dicek dengan `php spark db:table --show` sebelum `migrate`, tidak di-commit, dihapus setelah selesai.
Tidak ada uji di DB pengembangan bersama, DB test bersama, maupun DB legacy; dump D1 dan kode legacy hanya dibaca
strukturnya.

### 11.2 Hasil

| Perintah / uji | Hasil |
|---|---|
| Pencocokan DDL D1 vs migration (baca dump: `dm_ak_jf` D1:457-469, `rumpun_sertifikasi` D1:6693-6702, `lembaga_sertifikasi` D1:2137-2146, `riwayat_diklat` D1:4714-4755, `pegawai_hist` D1:2938-2949, `pegawai` D1:2506-2507, `pengguna` D1:3735-3757) | ✅ kolom, tipe, NULL, default, PK, KEY, nama FK sama; deviasi hanya yang dicatat [V2] |
| `simpeg_v2_s_d20`: `migrate --all` tanpa migration DBV-020 (= `main`) | ✅ 97 tabel, 202 FK; `pegawai_hist.glr_awal/akhir` varchar(12)/(24) |
| Kembalikan migration DBV-020, `migrate --all` | ✅ batch 2 = `100000`, `100100`, `100200`; 100 tabel, 204 FK (`fk_dm_ak_jf_ibfk_01/02`, RESTRICT/RESTRICT di `REFERENTIAL_CONSTRAINTS`); gelar varchar(50) unicode_ci NULL; `must_change_password` tinyint(1) NOT NULL |
| `migrate:rollback` | ✅ hanya batch 2 (`100200` → `100100` → `100000`); 97 tabel, 202 FK; gelar kembali 12/24; kolom dihapus |
| `migrate --all` lagi | ✅ checksum `information_schema.COLUMNS` (tipe, NULL, default, collation, COMMENT) dan `REFERENTIAL_CONSTRAINTS` identik dengan run pertama |
| Uji algoritma ALTER di DB scratch | ✅ `MODIFY` gelar ke 50 dengan `ALGORITHM=INPLACE, LOCK=NONE` diterima; penyempitan ke 12/24 ditolak INPLACE (1846, perlu COPY); `ADD/DROP COLUMN must_change_password … ALGORITHM=INSTANT` diterima; skema akhir identik (checksum) |
| `composer analyse` (PHPStan level 5) | ✅ No errors (termasuk `app/Database/MigrationsDitahan/`) |
| `composer cs-check` (PHP-CS-Fixer dry-run) | ✅ 0 dari 518 file perlu diperbaiki |
| PHPUnit `tests/database/Kepegawaian/SkemaTambahanFase3Test.php` | ✅ OK (7 test, 162 assertion) |
| PHPUnit `tests/database` | ✅ OK (85 test, 4349 assertion) |
| PHPUnit `tests/MasterData` (termasuk `JabatanUnitSatkerSchemaTest`, `JabatanSisaSchemaTest`, `PangkatPendidikanSchemaTest`) | ✅ OK (241 test, 12308 assertion) |
| PHPUnit `tests/Auth` (termasuk `AkunNonPegawaiSchemaTest`) | ✅ OK (242 test, 2426 assertion) |
| PHPUnit `tests/Kepegawaian` + `tests/unit/Kepegawaian` (termasuk `AkKumulatifTest`, `NipCascadeTest`, `DiklatTest`) | ✅ OK (600 test, 4620 assertion) |

Gate penuh (`check.sh`: frontend + PHPUnit penuh) tidak dijalankan di unit ini (gate cepat); perubahan tidak menyentuh
frontend.

### 11.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator)

1. `php spark migrate` → `migrate:rollback` → `migrate`: batch DBV-020 berisi `2026-10-13-100000`..`100200`; migration
   ditahan tidak dijalankan.
2. Aksi RESTRICT `fk_dm_ak_jf_ibfk_01/02` dicek lewat `information_schema.REFERENTIAL_CONSTRAINTS`.
3. `ALTER TABLE pegawai_hist MODIFY glr_awal VARCHAR(50) …, MODIFY glr_akhir VARCHAR(50) …, ALGORITHM=INPLACE, LOCK=NONE`
   diterima (konfirmasi tanpa rebuild).
4. `tests/database/Kepegawaian/SkemaTambahanFase3Test.php`, `PegawaiSnapshotSchemaTest.php`,
   `tests/MasterData/JabatanUnitSatkerSchemaTest.php`, `tests/Auth/AkunNonPegawaiSchemaTest.php` lolos di DB test MariaDB.

## 12. Checklist DB Validator

- [x] `rumpun_sertifikasi`/`lembaga_sertifikasi` cocok dengan D1 (Bagian 1.1–1.2)
- [x] FK sertifikasi ditahan: isi (Bagian 1.3) dan penahanan diterima
- [x] `dm_ak_jf` cocok dengan D1; FK RESTRICT/RESTRICT diterima (Bagian 2)
- [x] Aturan snapshot `pegawai_ak_siasn` (Bagian 3) diterima
- [x] `pengguna.must_change_password` (Bagian 4) diterima
- [x] Pelebaran gelar `pegawai_hist` (Bagian 5) diterima; dampak ALTER dicatat runbook
- [x] `user_lokasi_presensi` tidak dibuat di v2 (Bagian 6)
- [x] `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4 (Bagian 11.3)
- [x] Keputusan Bagian 10 #1–#10 diisi
