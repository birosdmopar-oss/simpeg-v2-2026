# DB Validator Review — DBV-019 FK master G-02 dan FK masuk `pengguna`/`faq_rate` → `pegawai` (lanjutan DBV-012/DBV-013)

**Key review:** `DBV-019` — pull request #23, branch `dbv-019/fk-g02-pegawai` (disinkronkan dengan `main` lewat merge,
tanpa rebase). Alur DBV: DB Validator me-review/approve dan merge (AGENTS.md §2). Rekomendasi key final dan alasannya:
Bagian 7 #5.

**Status:** ⏳ **MENUNGGU REVIEW DB VALIDATOR.** Kolom **Keputusan** di Bagian 7 masih kosong (⏳); isi Bagian 8 adalah
verifikasi developer sebelum review (pra-review internal sisi CR), **bukan** hasil review DB Validator. Migration jangan
dijalankan di Dev/Production sebelum disetujui.

**Rujukan:** `B-01-B-02-pegawai-riwayat-schema.md` (DBV-012/013, PR #20; Bagian 4.1, 7, keputusan #3, #12, #14) dan
catatan review DB Validator PR #20 06-10-2026 (C-2: FK yang ditahan menjadi unit DBV lanjutan; C-3: tipe PK master v2
tetap INT); `G-02-jabatan-unit-satker-schema.md` (DBV-008, PR #17); `G-02b-jabatan-sisa-schema.md` (DBV-018, PR #18);
D1 = dump struktur produksi `simpeg01` 01-10-2026 (`simpeg01_struktur_lengkap_20261001.sql`, di luar repo; rujukan
`D1:baris`).

**Label sumber:** **[K]** DDL D1 (nomor baris); **[V2]** keputusan v2 yang disengaja (alasan di Bagian 3).

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-10-07-100000_AddFkG02Pegawai.php` | 15 FK snapshot `pegawai_*` → master (Bagian 2.1); pra-cek kolom + nilai yatim fail-closed; satu ALTER per tabel; `down()` = DROP FK |
| `app/Database/Migrations/2026-10-07-100100_AddFkG02Riwayat.php` | 40 FK riwayat/penugasan → master (Bagian 2.2); pola sama |
| `app/Database/MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate.php` (dipindah dari draf `Migrations/2026-09-30-120200_…`) | 2 FK masuk `pengguna.nip`/`faq_rate.nip` → `pegawai.nip` (Bagian 2.4) dengan pra-cek NIP yatim fail-closed — **DITAHAN**: di luar folder `Migrations`, jadi tidak dijalankan `spark migrate`; diaktifkan (dipindah + diberi timestamp baru) setelah prasyarat Bagian 1.2 |
| `tests/database/Kepegawaian/NipReferenceRegistryTest.php` | `pengguna.nip`/`faq_rate.nip` tetap NON_FK selama ditahan (sama dengan `main`); ditambah test pra-cek orphan migration yang ditahan (dipasang sementara: fail-closed per tabel, 1452/1451, `up()`/`down()` aman diulang, index lama tetap, lalu dilepas lagi) |
| `tests/database/Kepegawaian/FkG02Test.php` (baru) | daftar FK terpasang = konstanta migration = ekspektasi D1 (`SkemaD1`, jenis `G02` + `NOV2`); RESTRICT/RESTRICT; tipe anak = PK induk; 1452/1451; pra-cek yatim tanpa FK terpasang sebagian; `up()`/`down()` aman diulang, index tetap |
| `tests/_support/LepasMigrationKepegawaianTrait.php` | tambah `lepasFkG02()` — melepas hanya dua migration FK ini (bukan seluruh B-01/B-02) |
| `tests/MasterData/JabatanUnitSatkerSchemaTest.php`, `tests/MasterData/JabatanSisaSchemaTest.php` | test `down()`/`up()` master G-02/G-02b melepas FK DBV-019 lebih dulu dan memasangnya ulang paling akhir (FK RESTRICT menahan DROP tabel induk, 3730); assertion skema master tidak berubah |

Tidak ada perubahan kode aplikasi, seed, atau migration yang sudah ada di `main` (`AuthSeeder` sama dengan `main`); di luar migration hanya test dan test support.

## 0. Ringkasan

| Hal | Isi |
|---|---|
| FK | **55** FK D1 [K] dipasang: 15 dari snapshot B-01 + 40 dari riwayat/penugasan B-02; induk `jabatan` 17, `unit` 8, `satker` 8, `jabatan_koordinasi` 8, `group_jabatan` 6, `sub_group_jabatan` 6, `rumpun_jabatan` 2 (= 45 FK "ke tabel G-02" + 10 FK "ke `jabatan_koordinasi`/`rumpun_jabatan`" dokumen B-01/B-02 Bagian 4.1). **2** FK masuk `pengguna.nip`/`faq_rate.nip` → `pegawai.nip` ikut diajukan sebagai migration yang **ditahan** (total 57 FK yang dulu ditahan PR #20) |
| Sumber | Nama FK, kolom anak, tabel/kolom induk = D1 persis (57/57 dicek per FK terhadap dump, Bagian 2); index setiap kolom sudah dibuat migration Create B-01/B-02 (`120100`, `130100`, `130400`, `130600`) dan A-01/G-10 (FK masuk), jadi cukup `ADD CONSTRAINT` |
| Deviasi [V2] | Aksi `ON DELETE RESTRICT ON UPDATE RESTRICT` (D1: 52 FK master SET NULL/CASCADE, 3 FK `konv_ak` CASCADE/CASCADE; FK masuk `pengguna` SET NULL/CASCADE, `faq_rate` CASCADE/CASCADE) — keputusan #3 DBV-012/013 (K1); pra-cek nilai yatim fail-closed |
| Tipe kolom | Anak = induk di `main`: INT untuk 53 FK (PK master G-02/`jabatan_koordinasi` INT, C-3 DBV), TINYINT untuk 2 FK `rumpun_jabatan` (PK TINYINT [K] DBV-018); FK masuk VARCHAR(30) `utf8mb4_unicode_ci` (= `pegawai.nip`). Tidak ada ALTER kolom |
| Migration | Timestamp baru `2026-10-07-100000`/`100100` (draf: `2026-09-30-129900`/`139900`), Bagian 4. FK masuk: timestamp ditetapkan saat diaktifkan |
| FK masuk ditahan | Prasyarat B-01/B-02 Bagian 7 #1–#2 belum terpenuhi (`pegawai` di Dev/Production belum diimpor; Manajemen Akun/AccountProvisioner belum memvalidasi NIP → 1452 menjadi error server). Dicoba aktif di DB test: 6 dari 9 test `AccountProvisionerTest` error 1452 dan banyak test Auth lain ikut gagal (Bagian 1.2, 8.2). Keputusan #4 |
| Verifikasi | DB scratch MySQL 8.0.30: siklus `migrate` → `migrate:rollback` → `migrate` dan pra-cek yatim (Bagian 8) |

## 1. Latar belakang & cakupan

### 1.1 Mengapa sekarang

Dokumen B-01/B-02 (PR #20, di `main` lewat `957729f` + perbaikan `96fcca2`) memasang 96 FK saat CREATE dan 14 FK
snapshot → riwayat (`131000`), tetapi **menahan** FK ke tabel master G-02 karena tabel induknya belum ada di `main`
(keputusan #12) dan FK masuk `pengguna`/`faq_rate` (keputusan #14). Review DB Validator PR #20 (06-10-2026, catatan C-2)
meminta FK yang ditahan diajukan sebagai unit DBV lanjutan. Syarat keputusan #12 kini terpenuhi: `main` sudah memuat G-02
(PR #17, DBV-008: `unit`, `satker`, `group_jabatan`, `sub_group_jabatan`, `jabatan`) dan G-02 sisa (PR #18, DBV-018:
`jabatan_koordinasi`, `rumpun_jabatan`), sehingga ke-55 FK — termasuk 10 FK yang sebelumnya "menunggu master baru" —
bisa dipasang sekaligus.

### 1.2 FK masuk `pengguna.nip` / `faq_rate.nip` → `pegawai.nip` — ikut diajukan, tetap ditahan

Keputusan #14 dokumen B-01/B-02 menahan `AddFkPegawaiDiPenggunaFaqRate` (draf `120200`) sampai prasyarat Bagian 7 dokumen
itu terpenuhi. Unit ini membawa migration itu (isi FK dan pra-cek orphan sama dengan draf yang sudah dipra-review di
DBV-012) supaya ke-57 FK yang ditahan PR #20 ada di satu unit, tetapi menyimpannya di
`app/Database/MigrationsDitahan/` (bukan `app/Database/Migrations/`), sehingga `php spark migrate` tidak menjalankannya.
Status prasyarat:

| # | Prasyarat (B-01/B-02 Bagian 7) | Status |
|---|---|---|
| 1 | `pegawai` terisi di lingkungan target (impor Tier 2 / B-05) | **Belum** di Dev/Production. Migration fail-closed: selama `pengguna`/`faq_rate` berisi NIP yang tidak ada di `pegawai`, `php spark migrate` berhenti di migration ini (sebelum ALTER) dan migration bertimestamp lebih baru ikut tertahan |
| 2 | Pembuatan/ubah akun ber-NIP memvalidasi NIP ada di `pegawai` (422) | **Belum** — `UserService::create/update` (Manajemen Akun) dan `AccountProvisioner` menyimpan NIP apa pun yang lolos format; dengan FK terpasang, penyimpanan akun ber-NIP tanpa baris `pegawai` gagal 1452 sebagai error server, bukan 422. Butuh perubahan kode aplikasi (pekerjaan MAKE baru) |
| 3 | Seed & test yang membuat akun/rating ber-NIP menyisipkan `pegawai` lebih dulu | **Belum** — `AuthSeeder` saja tidak cukup: test Auth yang membuat akun ber-NIP sendiri (mis. `AccountProvisionerTest`, `UserCrudScopedTest`) ikut gagal 1452 bila FK aktif (Bagian 8.2) |

Langkah aktivasi (unit DBV + MAKE lanjutan setelah prasyarat 1–3): (a) validasi NIP 422 di Manajemen
Akun/AccountProvisioner + penyesuaian seed/test Auth; (b) audit Bagian 6 FK masuk = 0 di lingkungan target; (c) pindahkan
file ke `app/Database/Migrations/<timestamp baru>_AddFkPegawaiDiPenggunaFaqRate.php`, daftarkan kedua FK di
`NipReferenceRegistryTest::REGISTRY` dan hapus dari `NON_FK`. Kolom `pengguna.nip` di D1 bernama `id_pegawai` (D1:3756);
penamaan `nip` sudah diputuskan di DBV-010 (A-01), bukan deviasi baru.

## 2. Daftar FK (55 aktif + 2 ditahan)

Kolom "Tipe" = `COLUMN_TYPE` kolom anak dan PK induk hasil migration di `main` (keduanya sama; dicek `FkG02Test`). Baris
D1 = baris `CONSTRAINT` di DDL tabel anak.

### 2.1 Snapshot B-01 → master (`2026-10-07-100000_AddFkG02Pegawai`, #1–#15)

Tabel anak: `pegawai_mutasi_jabatan` (D1:3192-3277, 13 FK), `pegawai_ak` (D1:2673-2717, 1 FK), `pegawai_ak_siasn`
(D1:2742-2779, 1 FK — tambahan revisi D1 keputusan #6 DBV-012).

### 2.2 Riwayat/penugasan B-02 → master (`2026-10-07-100100_AddFkG02Riwayat`, #16–#55)

Tabel anak: `riwayat_mutasi_jabatan` (D1:5713-5810, 13 FK), `pegawai_plt` (D1:3414-3460, 10 FK), `pegawai_plh`
(D1:3363-3409, 10 FK; nomor `ibfk` → kolom = D1), `riwayat_lckh` (D1:5455-5495, 2), `riwayat_ak` (D1:4034-4092, 1),
`riwayat_ak_siasn` (D1:4174-4212, 1), `konv_ak` (D1:2057-2088, 3).

### 2.3 Tabel lengkap

| # | FK (nama D1) | Anak `tabel.kolom` | Induk `tabel.kolom` | Tipe (anak = induk, v2) | D1 | Aksi D1 → v2 |
|---|---|---|---|---|---|---|
| 1 | `fk_id_group_jabatan_pmj_to_gj` | `pegawai_mutasi_jabatan.id_group_jabatan` | `group_jabatan.id_group_jabatan` | INT | D1:3267 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 2 | `fk_id_sub_group_jabatan_pmj_to_sgj` | `pegawai_mutasi_jabatan.id_sub_group_jabatan` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:3272 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 3 | `fk_id_unit_pmj_to_unit` | `pegawai_mutasi_jabatan.id_unit` | `unit.id_unit` | INT | D1:3273 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 4 | `fk_id_satker_pmj_to_satker` | `pegawai_mutasi_jabatan.id_satker` | `satker.id_satker` | INT | D1:3271 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 5 | `fk_id_jabatan_pmj_to_jabatan` | `pegawai_mutasi_jabatan.id_jabatan` | `jabatan.id_jabatan` | INT | D1:3269 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 6 | `fk_id_atasan_es_1_pmj_to_jabatan` | `pegawai_mutasi_jabatan.id_atasan_es_1` | `jabatan.id_jabatan` | INT | D1:3261 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 7 | `fk_id_atasan_es_2_pmj_to_jabatan` | `pegawai_mutasi_jabatan.id_atasan_es_2` | `jabatan.id_jabatan` | INT | D1:3262 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 8 | `fk_id_atasan_es_3_pmj_to_jabatan` | `pegawai_mutasi_jabatan.id_atasan_es_3` | `jabatan.id_jabatan` | INT | D1:3264 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 9 | `fk_id_atasan_es_4_pmj_to_jabatan` | `pegawai_mutasi_jabatan.id_atasan_es_4` | `jabatan.id_jabatan` | INT | D1:3266 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 10 | `fk_id_jabatan_koord_pmj_to_jabkoor` | `pegawai_mutasi_jabatan.id_jabatan_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:3268 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 11 | `fk_id_atasan_es_3_koord_pmj_to_jabkoor` | `pegawai_mutasi_jabatan.id_atasan_es_3_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:3263 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 12 | `fk_id_atasan_es_4_koord_pmj_to_jabkoor` | `pegawai_mutasi_jabatan.id_atasan_es_4_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:3265 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 13 | `pegawai_mutasi_jabatan_ibfk_02` | `pegawai_mutasi_jabatan.id_rumpun_jabatan` | `rumpun_jabatan.id_rumpun_jabatan` | TINYINT | D1:3276 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 14 | `fk_id_jabatan_cak_to_jab` | `pegawai_ak.id_jabatan` | `jabatan.id_jabatan` | INT | D1:2713 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 15 | `fk_id_jabatan_peg_ak_siasn_03` | `pegawai_ak_siasn.id_jabatan` | `jabatan.id_jabatan` | INT | D1:2775 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 16 | `fk_id_group_jabatan_rwymj_to_gj` | `riwayat_mutasi_jabatan.id_group_jabatan` | `group_jabatan.id_group_jabatan` | INT | D1:5801 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 17 | `fk_id_sub_group_jabatan_rwymj_to_sgj` | `riwayat_mutasi_jabatan.id_sub_group_jabatan` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:5805 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 18 | `fk_id_unit_rwymj_to_unit` | `riwayat_mutasi_jabatan.id_unit` | `unit.id_unit` | INT | D1:5806 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 19 | `fk_id_satker_rwymj_to_satker` | `riwayat_mutasi_jabatan.id_satker` | `satker.id_satker` | INT | D1:5804 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 20 | `fk_id_jabatan_rwymj_to_jabatan` | `riwayat_mutasi_jabatan.id_jabatan` | `jabatan.id_jabatan` | INT | D1:5803 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 21 | `fk_id_atasan_es_1_rwymj_to_jabatan` | `riwayat_mutasi_jabatan.id_atasan_es_1` | `jabatan.id_jabatan` | INT | D1:5795 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 22 | `fk_id_atasan_es_2_rwymj_to_jabatan` | `riwayat_mutasi_jabatan.id_atasan_es_2` | `jabatan.id_jabatan` | INT | D1:5796 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 23 | `fk_id_atasan_es_3_rwymj_to_jabatan` | `riwayat_mutasi_jabatan.id_atasan_es_3` | `jabatan.id_jabatan` | INT | D1:5798 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 24 | `fk_id_atasan_es_4_rwymj_to_jabatan` | `riwayat_mutasi_jabatan.id_atasan_es_4` | `jabatan.id_jabatan` | INT | D1:5800 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 25 | `fk_id_jabatan_koord_rmj_to_jabkoor` | `riwayat_mutasi_jabatan.id_jabatan_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:5802 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 26 | `fk_id_atasan_es_3_koord_rmj_to_jabkoor` | `riwayat_mutasi_jabatan.id_atasan_es_3_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:5797 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 27 | `fk_id_atasan_es_4_koord_rmj_to_jabkoor` | `riwayat_mutasi_jabatan.id_atasan_es_4_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:5799 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 28 | `riwayat_mutasi_jabatan_ibfk_02` | `riwayat_mutasi_jabatan.id_rumpun_jabatan` | `rumpun_jabatan.id_rumpun_jabatan` | TINYINT | D1:5809 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 29 | `fk_id_group_jabatan_plt_to_gj` | `pegawai_plt.id_group_jabatan` | `group_jabatan.id_group_jabatan` | INT | D1:3450 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 30 | `fk_id_group_jabatan_plt_plt_to_gj` | `pegawai_plt.id_group_jabatan_plt` | `group_jabatan.id_group_jabatan` | INT | D1:3449 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 31 | `fk_id_sub_group_jabatan_plt_to_sgj` | `pegawai_plt.id_sub_group_jabatan` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:3456 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 32 | `fk_id_sub_group_jabatan_plt_plt_to_sgj` | `pegawai_plt.id_sub_group_jabatan_plt` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:3455 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 33 | `fk_id_unit_plt_to_unit` | `pegawai_plt.id_unit` | `unit.id_unit` | INT | D1:3458 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 34 | `fk_id_unit_plt_plt_to_unit` | `pegawai_plt.id_unit_plt` | `unit.id_unit` | INT | D1:3457 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 35 | `fk_id_satker_plt_to_satker` | `pegawai_plt.id_satker` | `satker.id_satker` | INT | D1:3454 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 36 | `fk_id_satker_plt_plt_to_satker` | `pegawai_plt.id_satker_plt` | `satker.id_satker` | INT | D1:3453 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 37 | `fk_id_jabatan_plt_to_jabatan` | `pegawai_plt.id_jabatan` | `jabatan.id_jabatan` | INT | D1:3452 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 38 | `fk_id_jabatan_koord_plt_to_jabkoord` | `pegawai_plt.id_jabatan_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:3451 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 39 | `fk_pegawai_plh_ibfk_02` | `pegawai_plh.id_jabatan` | `jabatan.id_jabatan` | INT | D1:3399 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 40 | `fk_pegawai_plh_ibfk_03` | `pegawai_plh.id_jabatan_koord` | `jabatan_koordinasi.id_jabatan_koordinasi` | INT | D1:3400 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 41 | `fk_pegawai_plh_ibfk_04` | `pegawai_plh.id_group_jabatan` | `group_jabatan.id_group_jabatan` | INT | D1:3401 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 42 | `fk_pegawai_plh_ibfk_05` | `pegawai_plh.id_sub_group_jabatan` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:3402 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 43 | `fk_pegawai_plh_ibfk_06` | `pegawai_plh.id_unit` | `unit.id_unit` | INT | D1:3403 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 44 | `fk_pegawai_plh_ibfk_07` | `pegawai_plh.id_satker` | `satker.id_satker` | INT | D1:3404 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 45 | `fk_pegawai_plh_ibfk_08` | `pegawai_plh.id_group_jabatan_plh` | `group_jabatan.id_group_jabatan` | INT | D1:3405 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 46 | `fk_pegawai_plh_ibfk_09` | `pegawai_plh.id_sub_group_jabatan_plh` | `sub_group_jabatan.id_sub_group_jabatan` | INT | D1:3406 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 47 | `fk_pegawai_plh_ibfk_10` | `pegawai_plh.id_unit_plh` | `unit.id_unit` | INT | D1:3407 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 48 | `fk_pegawai_plh_ibfk_11` | `pegawai_plh.id_satker_plh` | `satker.id_satker` | INT | D1:3408 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 49 | `fk_riwayat_lckh_ibfk_03` | `riwayat_lckh.id_unit` | `unit.id_unit` | INT | D1:5493 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 50 | `fk_riwayat_lckh_ibfk_04` | `riwayat_lckh.id_satker` | `satker.id_satker` | INT | D1:5494 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 51 | `fk_id_jabatan_rak_to_jab` | `riwayat_ak.id_jabatan` | `jabatan.id_jabatan` | INT | D1:4089 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 52 | `fk_id_jabatan_rw_ak_siasn_02` | `riwayat_ak_siasn.id_jabatan` | `jabatan.id_jabatan` | INT | D1:4209 | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 53 | `fk_id_jabatan_konvak_to_jabatan` | `konv_ak.id_jabatan` | `jabatan.id_jabatan` | INT | D1:2083 | CASCADE/CASCADE → RESTRICT/RESTRICT |
| 54 | `fk_id_unit_konvak_to_unit` | `konv_ak.id_unit` | `unit.id_unit` | INT | D1:2086 | CASCADE/CASCADE → RESTRICT/RESTRICT |
| 55 | `fk_id_satker_konvak_to_satker` | `konv_ak.id_satker` | `satker.id_satker` | INT | D1:2085 | CASCADE/CASCADE → RESTRICT/RESTRICT |

Semua kolom anak #1–#55 nullable [K] (`DEFAULT NULL`), jadi NULL = "tidak dirujuk" dan tidak dicek FK.

### 2.4 FK masuk → `pegawai.nip` (`MigrationsDitahan/AddFkPegawaiDiPenggunaFaqRate`, #56–#57, DITAHAN)

| # | FK (nama D1) | Anak `tabel.kolom` | Induk `tabel.kolom` | Tipe (anak = induk, v2) | D1 | Aksi D1 → v2 |
|---|---|---|---|---|---|---|
| 56 | `fk_id_pegawai_pengguna_to_pegawai` | `pengguna.nip` (NULL = akun non-pegawai, K2/DBV-010; UNIQUE `nip` yang ada dipakai sebagai index) | `pegawai.nip` | VARCHAR(30) | D1:3756 (kolom D1 `id_pegawai`) | SET NULL/CASCADE → RESTRICT/RESTRICT |
| 57 | `fk_nip_faqrate_to_peg` | `faq_rate.nip` (NOT NULL; KEY bernama sama sudah dibuat migration FAQ G-10) | `pegawai.nip` | VARCHAR(30) | D1:982 | CASCADE/CASCADE → RESTRICT/RESTRICT |

Kelengkapan: dump D1 dibandingkan per FK dengan konstanta ketiga migration (57/57 sama nama, tabel/kolom anak, induk;
`pengguna`: kolom D1 `id_pegawai` = v2 `nip`).
FK D1 lain yang merujuk master G-02 berasal dari tabel master itu sendiri (sudah dipasang DBV-008/DBV-018) atau dari tabel
yang belum/tidak dibawa ke v2 (`dm_ak_jf`, `jabatan_plt`, `news`, `pa_layanan`, arsip `riwayat_lckh_20xx`) — di luar
cakupan.

## 3. Deviasi [V2]

| # | Deviasi | D1 [K] | v2 | Alasan |
|---|---|---|---|---|
| V1 | Aksi FK | 53 FK `ON DELETE SET NULL ON UPDATE CASCADE` (#1–#52, #56); 4 FK `ON DELETE CASCADE ON UPDATE CASCADE` (`konv_ak` #53–#55, `faq_rate` #57) | `ON DELETE RESTRICT ON UPDATE RESTRICT` (55 FK; 2 FK masuk saat diaktifkan) | Keputusan #3 DBV-012/013 dan konvensi G2: master G-02 dihapus lunak (status 10) dan PK tidak diubah, jadi hapus/ubah keras yang memutus relasi adalah bug yang harus ditolak DB. Khusus `konv_ak`: CASCADE legacy akan **menghapus** baris konversi angka kredit saat jabatan/unit/satker dihapus keras — v2 menolaknya. FK masuk: ganti NIP lewat B-06 (K1: salin → arahkan ulang → hapus dalam satu transaksi), bukan UPDATE CASCADE; pegawai yang punya akun/rating tidak bisa dihapus keras |
| V2 | Pra-cek fail-closed | — (FK legacy sudah ada) | `up()` menghitung nilai yatim per FK sebelum ALTER pertama dan berhenti dengan pesan jumlah per FK; kolom anak/induk wajib ada | `ADD FOREIGN KEY` sendiri menolak (1452) tetapi tanpa angka per FK dan setelah sebagian tabel di-ALTER; pola sama dengan `131000` |
| V3 | Timestamp migration | — | `2026-10-07-100000`/`100100` | Bagian 4 |

Tidak ada deviasi tipe kolom: seluruh pasangan anak/induk bertipe sama dengan D1 (INT/INT, TINYINT/TINYINT untuk
`rumpun_jabatan`), sesuai C-3 (PK master v2 tetap INT; tidak ada FK di unit ini ke 4 master yang di-INT-kan, V5 DBV-012).

## 4. Urutan migration & rollback

1. **Urutan.** `100000` (snapshot) lalu `100100` (riwayat), keduanya setelah seluruh migration `main`: G-02 `2026-09-30-100000`
   (`unit`..`jabatan`), G-02b `2026-09-30-100100` (`jabatan_koordinasi`, `rumpun_jabatan`), `2026-09-30-100200` (FK jabatan →
   jenjang_jf), B-01 `120000`/`120100`, B-02 `130000`..`131000`, G-03 `2026-10-01-000001`, G-09 `2026-10-01-100000`. FK masuk
   (ditahan) bergantung pada `pengguna` (A-01 + ALTER DBV-010), `faq_rate` (G-10) dan `pegawai` (`120000`); saat diaktifkan
   diberi timestamp setelah migration terakhir di lingkungan target.
2. **Mengapa timestamp baru** (`2026-09-30-129900`/`139900` → `2026-10-07-100000`/`100100`). Secara isi, draf sudah
   berjalan setelah tabel anak dan induknya (`1000xx` < `1201xx`/`1301xx` < `129900`/`139900`). Namun Dev dan DB lain yang
   sudah ter-migrate sampai G-03/G-09 (`2026-10-01-*`) akan menerima migration bertanggal **lebih lama** dari batch
   terakhirnya. CodeIgniter memang tetap menjalankan migration yang belum tercatat, tetapi riwayat `migrations` menjadi
   tidak monoton dan bertentangan dengan aturan Bagian 7 dokumen B-01/B-02 ("timestamp ditetapkan ulang agar lebih besar
   dari migration yang sudah jalan di lingkungan target"). Branch ini belum pernah di-merge dan drafnya hanya dijalankan
   di DB scratch, jadi mengganti nama file aman. Catatan: DB yang pernah menjalankan draf `129900`/`139900` (hanya DB
   scratch) akan ditolak CodeIgniter dengan "gap in the migration sequence" — buang DB itu, jangan sunting `migrations`.
3. **Batch & rollback.** Pada `migrate` normal keduanya masuk satu batch; `migrate:rollback` membatalkan batch itu saja
   (`100100` lalu `100000`), hanya melepas FK (index milik migration Create tetap), skema master/B-01/B-02 utuh. `down()`
   aman diulang (FK yang sudah lepas dilewati).
4. **Rebuild tabel.** MySQL/MariaDB memasang FK dengan `foreign_key_checks = 1` lewat ALGORITHM=COPY (tabel ditulis ulang
   dan dikunci tulis selama ALTER). Karena itu FK dikelompokkan **satu ALTER per tabel anak** (10 ALTER, bukan 55). Di
   Production setelah impor, tabel terbesar yang di-rebuild adalah `riwayat_lckh` (AUTO_INCREMENT D1 ±1,6 juta) dan
   `riwayat_ak_siasn` (±284 ribu); jalankan di jendela cutover, bukan saat aplikasi melayani tulis.
5. **Hubungan dengan impor** (bahan runbook DBV-014; dokumen B-01/B-02 Bagian 6 #1 dan #4). Bila DB v2 di-migrate penuh
   sebelum impor, master G-02 + `jabatan_koordinasi`/`rumpun_jabatan` wajib diimpor sebelum snapshot/riwayat, dan nilai id
   master `0`/`''` dinormalkan ke NULL saat impor. Bila impor dijalankan dengan `FOREIGN_KEY_CHECKS = 0`, kueri audit
   Bagian 6 wajib menghasilkan 0 sebelum aplikasi dibuka.

### 4.1 Pemulihan bila `up()` gagal di tengah

Pra-cek menjamin kegagalan karena kolom hilang atau nilai yatim terjadi **sebelum** ALTER pertama (skema tidak berubah,
migration tidak tercatat). Bila ALTER sendiri gagal (mis. kehabisan ruang/lock timeout), FK yang dipasang pada run itu
dilepas otomatis lalu error dilempar ulang. Bila pembersihan otomatis ikut gagal: `ALTER TABLE <tabel anak> DROP FOREIGN
KEY <nama>` untuk FK Bagian 2 yang tertinggal (prefix sesuai lingkungan), lalu ulang `migrate` — **jangan**
`migrate:rollback` (migration yang gagal tidak tercatat).

## 5. Interaksi dengan test master yang sudah ada

FK RESTRICT ke `jabatan`/`unit`/`satker`/`group_jabatan`/`sub_group_jabatan`/`jabatan_koordinasi`/`rumpun_jabatan` menahan
`DROP TABLE` induk (3730). Dua test skema master yang menjalankan `down()`/`up()` migration G-02 di tengah test
(`JabatanUnitSatkerSchemaTest`, `JabatanSisaSchemaTest`) kini melepas kedua migration DBV-019 lebih dulu
(`LepasMigrationKepegawaianTrait::lepasFkG02()`, hanya versi `2026-10-07-100000`/`100100` yang sudah jalan) dan memasangnya
ulang paling akhir — pola yang sama dengan `lepasMigrationKepegawaian()` yang dipakai `Batch1LegacySchemaTest`,
`PangkatPendidikanSchemaTest`, `DiklatHukdisKonketSchemaTest` sejak PR #20. Assertion skema master tidak berubah.
Konsekuensi operasional yang sama berlaku di luar test: master G-02 yang sudah dirujuk tidak bisa di-rollback sebelum
batch DBV-019 di-rollback (urutan batch CodeIgniter sudah menjamin ini).

## 6. Audit data yatim pra-impor / pra-migrate (kueri baca-saja)

Dijalankan di DB v2 tujuan setelah impor (atau di salinan legacy untuk perkiraan; FK legacy SET NULL/CASCADE seharusnya
membuat hasil 0 kecuali nilai `0` yang lolos saat `FOREIGN_KEY_CHECKS = 0`). Semua kueri hanya `SELECT`. Hasil yang
diharapkan: setiap baris `yatim = 0`. Pola per FK (ganti tabel/kolom sesuai Bagian 2):

```sql
SELECT 'fk_id_jabatan_pmj_to_jabatan' AS fk, COUNT(*) AS yatim
FROM pegawai_mutasi_jabatan c
WHERE c.id_jabatan IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM jabatan p WHERE p.id_jabatan = c.id_jabatan);
```

Ringkasan per tabel anak (satu pemindaian per tabel), contoh `pegawai_plh` (10 FK):

```sql
SELECT
  SUM(c.id_jabatan               IS NOT NULL AND j.id_jabatan            IS NULL) AS fk_pegawai_plh_ibfk_02,
  SUM(c.id_jabatan_koord         IS NOT NULL AND k.id_jabatan_koordinasi IS NULL) AS fk_pegawai_plh_ibfk_03,
  SUM(c.id_group_jabatan         IS NOT NULL AND g.id_group_jabatan      IS NULL) AS fk_pegawai_plh_ibfk_04,
  SUM(c.id_sub_group_jabatan     IS NOT NULL AND s.id_sub_group_jabatan  IS NULL) AS fk_pegawai_plh_ibfk_05,
  SUM(c.id_unit                  IS NOT NULL AND u.id_unit               IS NULL) AS fk_pegawai_plh_ibfk_06,
  SUM(c.id_satker                IS NOT NULL AND t.id_satker             IS NULL) AS fk_pegawai_plh_ibfk_07,
  SUM(c.id_group_jabatan_plh     IS NOT NULL AND g2.id_group_jabatan     IS NULL) AS fk_pegawai_plh_ibfk_08,
  SUM(c.id_sub_group_jabatan_plh IS NOT NULL AND s2.id_sub_group_jabatan IS NULL) AS fk_pegawai_plh_ibfk_09,
  SUM(c.id_unit_plh              IS NOT NULL AND u2.id_unit              IS NULL) AS fk_pegawai_plh_ibfk_10,
  SUM(c.id_satker_plh            IS NOT NULL AND t2.id_satker            IS NULL) AS fk_pegawai_plh_ibfk_11
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
```

FK masuk (#56–#57, prasyarat aktivasi) — NIP akun/rating yang tidak ada di `pegawai` (akun non-pegawai `nip IS NULL` bukan yatim; akun
soft-deleted ikut dihitung karena FK berlaku untuk semua baris):

```sql
SELECT 'pengguna.nip' AS kolom, COUNT(*) AS yatim
FROM pengguna c
WHERE c.nip IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip)
UNION ALL
SELECT 'faq_rate.nip', COUNT(*)
FROM faq_rate c
WHERE NOT EXISTS (SELECT 1 FROM pegawai p WHERE p.nip = c.nip);
```

Tindak lanjut yatim FK masuk diputuskan per kasus (impor pegawai yang hilang, akun dijadikan non-pegawai sesuai K2, atau
rating dibuang dengan catatan rekonsiliasi), tidak dihapus diam-diam oleh migration.

Tambahan yang dilaporkan bersama audit (bukan pemblokir FK, tetapi memengaruhi tampilan): jumlah rujukan ke master yang
berstatus 10 (dihapus lunak) per FK, mis. `SELECT COUNT(*) FROM riwayat_mutasi_jabatan c JOIN jabatan p ON p.id_jabatan =
c.id_jabatan WHERE p.status = 10`. Tindak lanjut nilai yatim: id `0`/`''` → NULL (Bagian 6 #4 dokumen B-01/B-02); id
lain → impor baris master yang hilang (status 10 bila sudah tidak dipakai) atau NULL-kan dengan catatan rekonsiliasi —
diputuskan per kasus, tidak dihapus diam-diam. Migration sendiri menjalankan kueri pola pertama untuk ke-55 FK (dan 2 FK masuk saat diaktifkan) dan menolak
jalan bila ada yang > 0.

## 7. Keputusan yang diminta dari DB Validator / user

| # | Pertanyaan | Usulan (pra-review CR) | Keputusan |
|---|---|---|---|
| 1 | Setujui 55 FK Bagian 2.1–2.3 (nama, kolom, induk = D1) dengan aksi RESTRICT/RESTRICT (V1), termasuk 3 FK `konv_ak` yang di D1 CASCADE/CASCADE | Setujui (lanjutan keputusan #3 DBV-012/013) | ⏳ |
| 2 | Timestamp baru `2026-10-07-100000`/`100100` menggantikan draf `129900`/`139900` (Bagian 4 #2) | Setujui | ⏳ |
| 3 | Pra-cek nilai yatim fail-closed (V2) + aturan normalisasi Bagian 6 sebagai langkah runbook DBV-014 | Setujui | ⏳ |
| 4 | FK masuk `pengguna.nip`/`faq_rate.nip` (Bagian 2.4): isi FK (nama/kolom/induk D1, RESTRICT) disetujui sekarang, tetapi migration-nya tetap **ditahan** di `app/Database/MigrationsDitahan/` sampai prasyarat Bagian 1.2 #1–#3 terpenuhi; aktivasi = unit DBV+CR lanjutan (validasi NIP 422 + seed/test Auth sebagai pekerjaan MAKE baru + audit Bagian 6 = 0) | Setujui isi + penahanan. Alternatif: (b) aktifkan sekarang dengan timestamp `2026-10-07-100200` — butuh CR kode aplikasi dan penyesuaian banyak test Auth, dan `migrate` di Dev/Production berhenti sampai `pegawai` diimpor; (c) keluarkan dari PR ini (draf hanya di riwayat commit) | ⏳ |
| 5 | Key & alur: perubahan = 2 migration aktif + 1 migration ditahan + 1 schema test baru + penyesuaian `NipReferenceRegistryTest`, 2 schema test master, 1 trait test support; tanpa kode aplikasi/seed | `[DBV-019]` saja, alur DBV-only (PR, DB Validator merge): semua perubahan adalah skema + test skema. Alternatif `[DBV-019][MAKE-(nomor menyusul)]` bila reviewer kode ingin me-review perubahan test master/test support — preseden keputusan #15 PR #20 | ⏳ |
| 6 | Jendela eksekusi Production: ALTER = rebuild tabel (Bagian 4 #4), dijalankan saat cutover setelah audit Bagian 6 = 0 | Setujui; masuk runbook DBV-014 | ⏳ |

Koreksi setelah approval dilakukan lewat migration baru, bukan dengan mengedit migration yang sudah di `main`.

## 8. Verifikasi developer (pra-review internal sisi CR)

### 8.1 Lingkungan

MySQL 8.0.30 (Laragon lokal), PHP 8.4.2, CodeIgniter 4.7.4, PHPUnit 10.5. Worktree branch `dbv-019/fk-g02-pegawai`
setelah merge `origin/main` `6ca4bb9` (07-10-2026; merge berikutnya `805dc00` hanya dokumen `docs/fase3`). Semua uji database memakai **DB scratch bernama eksplisit** (dibuat
lalu di-DROP dengan nama persis): `simpeg_v2_scr_d19` untuk siklus migrate, `simpeg_v2_t_d19` untuk PHPUnit. `.env` khusus
uji di worktree (tidak di-commit) menunjuk DB scratch, dicek lewat `php spark db:table --show` sebelum `spark migrate`.
Tidak ada uji di DB pengembangan bersama, DB test bersama, maupun DB legacy.

### 8.2 Hasil

| Perintah / uji | Hasil |
|---|---|
| Pencocokan konstanta 3 migration vs dump D1 (skrip baca dump, per FK) | ✅ 57/57: nama, tabel/kolom anak, tabel/kolom induk sama (`pengguna`: kolom D1 `id_pegawai` = v2 `nip`); aksi D1 52 SET NULL/CASCADE + 3 CASCADE/CASCADE (master), SET NULL/CASCADE (`pengguna`), CASCADE/CASCADE (`faq_rate`). FK D1 lain ke master G-02 hanya dari tabel master sendiri atau tabel yang tidak dibawa ke v2 |
| `simpeg_v2_scr_d19`: `php spark migrate --all` tanpa kedua migration DBV-019 (= `main`) | ✅ **147 FK** |
| Kembalikan kedua migration, `migrate --all` | ✅ **202 FK** (+55, batch 2: `100000`, `100100`); per tabel anak: `pegawai_mutasi_jabatan` 13, `riwayat_mutasi_jabatan` 13, `pegawai_plt` 10, `pegawai_plh` 10, `konv_ak` 3, `riwayat_lckh` 2, `pegawai_ak`/`pegawai_ak_siasn`/`riwayat_ak`/`riwayat_ak_siasn` 1; ke-55 FK `RESTRICT/RESTRICT` (`information_schema.REFERENTIAL_CONSTRAINTS`) |
| `migrate:rollback` | ✅ **147 FK** (hanya batch 2, urutan `100100` lalu `100000`); daftar FK identik dengan sebelum `migrate` |
| `migrate --all` lagi | ✅ **202 FK**; daftar FK (nama, tabel, aksi, induk) **identik** dengan run pertama |
| FK masuk dicoba aktif (sebelum ditahan): `tests/Auth/AccountProvisionerTest.php` dengan migration `100200` + `AuthSeeder` menyisipkan `pegawai` | ❌ 6 dari 9 test error 1452 `fk_id_pegawai_pengguna_to_pegawai` (akun ber-NIP tanpa `pegawai`); suite `tests/Auth` juga menunjukkan banyak error serupa — dasar Bagian 1.2 |
| `composer analyse` (PHPStan level 5) + `composer cs-check` (PHP-CS-Fixer dry-run), termasuk `app/Database/MigrationsDitahan/` | ✅ 0 error; 0 dari 371 file perlu diperbaiki |
| PHPUnit terarah di `simpeg_v2_t_d19` (DB baru): `tests/database/Kepegawaian` (`FkG02Test`, `NipReferenceRegistryTest`, `PegawaiSnapshotSchemaTest`, `RiwayatSchemaTest`, `SnapshotRiwayatFkTest`) | ✅ OK (22 test, 3965 assertion) — termasuk pra-cek yatim G-02 menolak sebelum ALTER tanpa FK terpasang sebagian, 1452/1451, dan pra-cek orphan FK masuk yang ditahan |
| `tests/MasterData/JabatanUnitSatkerSchemaTest.php`, `JabatanSisaSchemaTest.php` | ✅ OK (6 test, 255 assertion); OK (6 test, 246 assertion) |
| Test master pengguna `LepasMigrationKepegawaianTrait`: `Batch1LegacySchemaTest`, `PangkatPendidikanSchemaTest`, `DiklatHukdisKonketSchemaTest` | ✅ OK (5/52), OK (8/344), OK (6/184) |
| `tests/Auth/AccountProvisionerTest.php`, `tests/Auth/AuthSchemaTest.php` (FK masuk ditahan, `AuthSeeder` = `main`) | ✅ OK (9/66), OK (5/18) |

DB scratch `simpeg_v2_scr_d19` dan DB test scratch `simpeg_v2_t_d19` di-DROP dengan nama persis setelah selesai. `.env`
worktree khusus uji, tidak di-commit.

### 8.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator)

1. `php spark migrate` → `migrate:rollback` → `migrate`: batch DBV-019 berisi `2026-10-07-100000` dan `100100`; rollback
   hanya batch itu; jumlah FK Bagian 2.1–2.3 sebelum/sesudah = 0/55; migration yang ditahan tidak dijalankan.
2. Aksi RESTRICT dicek lewat `information_schema.REFERENTIAL_CONSTRAINTS` (MariaDB bisa tidak menampilkan klausa RESTRICT
   di `SHOW CREATE TABLE`).
3. `tests/database/Kepegawaian/FkG02Test.php`, `NipReferenceRegistryTest.php`, `JabatanUnitSatkerSchemaTest`,
   `JabatanSisaSchemaTest` lolos di DB test MariaDB.

## 9. Checklist DB Validator

- [ ] Ke-55 FK Bagian 2.1–2.3 cocok dengan D1 (nama, kolom, induk); tidak ada FK lain yang ditambahkan
- [ ] Aksi RESTRICT/RESTRICT (V1) diterima, termasuk `konv_ak` (D1 CASCADE/CASCADE)
- [ ] Tipe kolom anak = PK induk di `main` (INT; TINYINT untuk `rumpun_jabatan`), tanpa ALTER kolom
- [ ] Penggantian timestamp (Bagian 4 #2) diterima; urutan setelah seluruh migration `main`
- [ ] `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4: rollback hanya membatalkan batch DBV-019, index tetap
- [ ] Pra-cek yatim fail-closed dan kueri audit Bagian 6 diterima sebagai langkah runbook DBV-014
- [ ] FK masuk `pengguna`/`faq_rate` (Bagian 2.4): isi disetujui, migration tetap ditahan sampai prasyarat Bagian 1.2 (keputusan #4)
- [ ] Keputusan Bagian 7 #1–#6 diisi
