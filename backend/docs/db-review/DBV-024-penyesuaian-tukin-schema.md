# DB Validator Review — DBV-024 tabel `penyesuaian_tukin` (Fase 5B, penyesuaian Tukin per NIP)

**Key review:** `DBV-024` (Fase 5B, F5B-14, QASMTASK-083 bagian penyesuaian). Judul PR: `[DBV-024] Skema Fase 5B: tabel
penyesuaian_tukin`. Branch `fase5b/dbv-024-penyesuaian-tukin` (dari `main` `988c1ed`). Alur PRD 5B §10 M3: PR `[DBV]`,
DB Validator me-review/approve, lalu (masih M3) migration dipindah ke `app/Database/Migrations/` + registry NIP + test
skema; reviewer CR me-merge setelah CI hijau. M4 (MAKE-045, CRUD) menunggu DBV-024 di `main`.

**Status:** ⏳ **menunggu review DB Validator.** Draf migration **DITAHAN** di `app/Database/MigrationsDitahan/` (tidak
dijalankan `spark migrate`). Pra-review internal sisi CR: laporan M0 PRD 5B diterima reviewer CR 28-10-2026 (F1/F3/F4/G
disetujui; F2 Plt diajukan ke Horii, tidak menyangkut skema ini).

**Penulis:** developer Fase 5B (sesi WS-2), 28-10-2026.

**Asal kebutuhan:** PRD `docs/fase5/PRD_Fase5B_Cuti-TB-LaporanTukin-UangMakan.md` v1.0 §7.3.10 & §7.6, keputusan Horii
28-10-2026 F5B-14 (tabel admin pengganti hard-code), F5B-21 (riwayat diisi admin saat cutover, tidak di-commit), F5B-4
(laporan v2 hanya periode ≥ 2024-04).

**Rujukan:** `DBV-023-presensi-gaji-fase5a-schema.md` (pola dokumen, konvensi, migration ditahan → dipindah setelah
approve), `DBV-019-fk-g02-pegawai-schema.md` (pola `MigrationsDitahan`), ADR-008 (FK RESTRICT, koreksi NIP oleh aplikasi),
ADR-011 (`BaseAuditableModel` → `audit_logs`). Kode legacy = salinan CI3 HEAD `0cf7e67` folder `application/` (di luar
repo; rujukan `berkas:baris`; belum dicocokkan checksum produksi, AS-09 / Q5A-14).

**Label sumber:** **[K]** ikut kode legacy; **[V2]** deviasi v2 yang disengaja (alasan + keputusan); **[I]** inferensi.

---

## 0. Ringkasan

| # | Butir | Inti |
|---|---|---|
| 1 | Tabel baru `penyesuaian_tukin` [V2] | Satu baris = satu NIP × rentang bulan Tukin × persen penyesuaian × alasan; status 1/2/10; dikelola role 1 lewat CRUD + audit (MAKE-045) |
| 2 | FK `nip` → `pegawai` | RESTRICT/RESTRICT (ADR-008); registry NIP `TabelAnakNip` + cakupan `NipCascadeService` (ikut koreksi NIP), `FASE` tetap 5 |
| 3 | Tanpa impor | Sumber legacy berupa **kode**, bukan tabel; riwayat periode ≥ 2024-04 diisi admin saat cutover (F5B-21); data pribadi tidak di-commit |
| 4 | ENUM `audit_logs.event` | **Tidak** berubah (create/update/delete sudah ada) |

**Efek di aplikasi (bukan bagian skema, untuk konteks):** keterangan + kolom `penyesuaian {persen, alasan}` di laporan &
rekap Tukin; `total_potongan` hasil `KalkulasiTukin` **tidak** diubah (PRD 5B §7.3.10).

---

## 1. Konvensi yang dipakai

| Kode | Konvensi | Rujukan |
|---|---|---|
| V1 / ADR-008 | FK ke `pegawai` **ON DELETE RESTRICT ON UPDATE RESTRICT**; koreksi NIP oleh aplikasi (`NipCascadeService`) | DBV-012/013/023 |
| Collation | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | DBV-Q15 (DBV-023) |
| Status | 1 Aktif, 2 Tidak Aktif, 10 Dihapus (pola master; hapus = status 10, baris tetap ada) | PRD 5B §6.1; AGENTS §2 |
| Waktu | `created_at`/`updated_at` = **UTC diisi aplikasi** (AS-12); tanpa default DB | DBV-011 Bagian 3; DBV-023 |
| Pelaku | `created_by`/`updated_by` = `id_pengguna`, **tanpa FK** (pola `dh_online`, `gaji_pegawai`) | DBV-023 |
| CHECK | Bernama `chk_<tabel>_<arti>` | DBV-023 |
| Nama FK | `fk_<kolom>_<singkatan tabel>_to_<induk>` (pola `fk_nip_dh_to_pegawai`) | DBV-023 |
| Migration | Migration di `main` tidak diedit; draf ditahan sampai approve | AGENTS §2 |

---

## 2. Sumber legacy [K] — daftar di kode, bukan tabel

Legacy menyimpan penyesuaian Tukin sebagai **array PHP** di `libraries/hr/L_presensi.php:11755-11806` (rekap) dan
`:6526-6560` (laporan per pegawai):

- Beberapa pasang daftar: `$list80*` (NIP yang Tukin-nya 80% → penyesuaian **−20**), `$list90*` (90% → **−10**), masing-
  masing dengan daftar periode `m-YYYY` (`$periode80*`), mis. `['3-2022', '4-2022', '5-2022', '6-2022']`.
- Sebagian blok dikomentari (periode lama). Daftar terakhir mencakup **2-2024..6-2024** (dua set, salah satunya hanya
  `2-2024`).
- Efek legacy: hanya **teks keterangan** di rekap ("Penyesuaian Tukin - Predikat Kinerja Sangat Kurang -20% (sebelum
  potongan lain)" / "… Kurang -10% …" dan varian huruf besarnya, `:13064-13120`); angka potongan tidak diubah.
- Perubahan dilakukan developer lewat rilis kode; tidak ada jejak siapa/kapan.

NIP dalam daftar itu = data pribadi; **tidak** disalin ke repo/dokumen ini (F5B-21).

**Konsekuensi untuk pengisian cutover:** satu NIP bisa muncul di lebih dari satu daftar dengan periode yang beririsan
(mis. daftar `2-2024` dan daftar `2-2024..6-2024`), dan daftar periode legacy berupa **himpunan bulan** (bisa tidak
berurutan). Di v2 satu baris = satu rentang berurutan dan rentang aktif per NIP **tidak boleh** tumpang tindih
(Bagian 3.3). Admin memecah himpunan bulan menjadi beberapa rentang berurutan dan menggabung irisan sebelum mengisi;
kueri audit B1 (Bagian 6) memastikan hasilnya bersih.

---

## 3. Skema v2 usulan

### 3.1 DDL

```sql
CREATE TABLE `penyesuaian_tukin` (
  `id_penyesuaian_tukin` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nip` VARCHAR(30) NOT NULL COMMENT 'Pegawai yang Tukin-nya disesuaikan',
  `bulan_mulai` DATE NOT NULL COMMENT 'Tanggal 1 bulan Tukin pertama yang disesuaikan (bulan laporan, bukan bulan kalender data)',
  `bulan_akhir` DATE NOT NULL COMMENT 'Tanggal 1 bulan Tukin terakhir yang disesuaikan (inklusif)',
  `persen` DECIMAL(5,2) NOT NULL COMMENT 'Persen penyesuaian; negatif = pengurangan (mis. -20.00); keterangan saja, tidak mengubah total potongan',
  `alasan` VARCHAR(255) NOT NULL COMMENT 'Alasan penyesuaian yang dicetak di keterangan laporan/rekap',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
  `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
  `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
  `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
  `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  PRIMARY KEY (`id_penyesuaian_tukin`),
  KEY `idx_ptk_nip_status_bulan` (`nip`, `status`, `bulan_mulai`),
  KEY `idx_ptk_status_bulan` (`status`, `bulan_mulai`, `bulan_akhir`),
  CONSTRAINT `fk_nip_ptk_to_pegawai` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_penyesuaian_tukin_bulan` CHECK (DAYOFMONTH(`bulan_mulai`) = 1 AND DAYOFMONTH(`bulan_akhir`) = 1 AND `bulan_akhir` >= `bulan_mulai`),
  CONSTRAINT `chk_penyesuaian_tukin_persen` CHECK (`persen` <> 0 AND `persen` BETWEEN -100 AND 100),
  CONSTRAINT `chk_penyesuaian_tukin_status` CHECK (`status` IN (1, 2, 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Penyesuaian Tukin per NIP per rentang bulan (pengganti daftar hard-code legacy); efek keterangan laporan/rekap saja';
```

Sumber kebenaran DDL = draf `app/Database/MigrationsDitahan/CreatePenyesuaianTukin.php` (`statement()`); blok di atas
identik (diekstrak dari draf saat verifikasi Bagian 8).

### 3.2 Pola akses (dasar indeks)

| Kueri aplikasi | Bentuk | Indeks |
|---|---|---|
| Rekap/laporan bulan M (semua NIP lingkup) | `status = 1 AND bulan_mulai <= M AND bulan_akhir >= M` | `idx_ptk_status_bulan` (EXPLAIN: `range`, Bagian 8) |
| Laporan per pegawai / cek tumpang tindih CRUD (kunci baris) | `nip = ? AND status = 1 AND bulan_mulai <= ? … FOR UPDATE` | `idx_ptk_nip_status_bulan` (EXPLAIN: `range`, `Using index`) |
| Daftar CRUD (filter `nip`, `bulan`, `status`, paginasi) | kombinasi di atas | sama |
| FK `nip` | — | prefiks `idx_ptk_nip_status_bulan` memenuhi kebutuhan indeks FK |

Volume diperkirakan kecil (puluhan–ratusan baris/tahun menurut ukuran daftar legacy); dua indeks dipertahankan agar kedua
pola akses utama tidak memindai tabel. Bila DBV menilai satu indeks cukup, `idx_ptk_status_bulan` dapat dibuang (DBV-Q4).

### 3.3 Deviasi & alasan

| Butir | Keputusan | Alasan |
|---|---|---|
| Tabel baru (legacy: array di kode) | [V2] F5B-14 | Data pribadi keluar dari kode; perubahan tercatat (`audit_logs`); dikelola admin tanpa rilis |
| `persen` bebas −100..100, ≠ 0, 2 desimal | [V2] | Legacy hanya −20/−10; v2 lebih umum, nilai lain sah bila diisi admin |
| `bulan_*` = DATE tanggal 1 | [V2] (DBV-Q1) | Perbandingan rentang alami (`<=`/`>=`), CHECK tanggal 1 menjaga makna "bulan"; legacy `m-YYYY` teks |
| Rentang (mulai–akhir) alih-alih himpunan bulan | [V2] | Satu baris per rentang berurutan; himpunan tak berurutan = beberapa baris (Bagian 2) |
| Tumpang tindih aktif per NIP | Ditolak **aplikasi** di bawah `FOR UPDATE` per NIP (`PenyesuaianTukinService`, M4) | Tidak dapat dijaga UNIQUE/CHECK; audit B1 sebagai jaring pengaman |
| Hapus = status 10 | [V2] pola master | Riwayat tetap ada untuk audit/laporan lama |
| Tanpa FK `created_by`/`updated_by` | pola DBV-023 | Konsisten dengan tabel transaksi v2 (DBV-Q2) |
| Tanpa ENUM audit baru | — | create/update/delete sudah ada (DBV-Q3) |

---

## 4. Registry NIP (PRD 5B §3.3)

Di commit migration (setelah approve, masih M3):

- `App\Libraries\Kepegawaian\Nip\TabelAnakNip`: baris baru
  `['penyesuaian_tukin', 'nip', 'fk_nip_ptk_to_pegawai', false, KeberadaanV2::Ada, 5, 'D-08', 'Penyesuaian Tukin per NIP']`
  (pola baris `dh_online`) → ikut `NipCascadeService` saat koreksi NIP.
- `NipCascadeService::FASE` **tetap 5**; `NipReferenceRegistryTest` & `TabelAnakNipTest` diperbarui (aditif) agar
  FK baru terdaftar; `NipCascadeTest` memakai `FASE`.

**Pelaksanaan setelah approve (28-10-2026, deviasi kecil dicatat):** baris didaftarkan di `NON_FK` sebagai
`JenisRujukanNip::NonFkFkV2` (keberadaan `Ada`, fase 5, pemilik `D-08`; isi sama dengan DBV-Q6), bukan di `FK_LEGACY`.
Alasan: `FK_LEGACY` khusus FK yang ada di dump struktur legacy D1 (75 FK / 70 tabel, sumber `[K] FK <nama>`, dijaga
`TabelAnakNipTest`), sedangkan `penyesuaian_tukin` tabel [V2] tanpa padanan legacy — polanya sama dengan
`firebase_token.nip` / `inbox.nip_penerima` DBV-022 (FK RESTRICT di CREATE). Efek ke koreksi NIP B-06 sama: kolom
ikut `TabelAnakNip::sampaiFase(5)` dan `NipCascadeService`.

---

## 5. Impor & cutover

- **Tidak ada impor dari legacy** (tidak ada tabel sumber; Bagian 2). Butir ini dicatat "tidak dimigrasi — diisi admin" di
  runbook impor DBV-011 saat cutover.
- Riwayat periode ≥ 2024-04 (F5B-4) diisi **admin role 1 lewat CRUD** (MAKE-045) atau skrip cutover di luar repo
  (F5B-21); periode < 2024-04 tidak perlu (laporan v2 menolak periode itu, 422 `periode_tidak_didukung`).
- Setelah pengisian: jalankan kueri audit Bagian B (B1, B2 wajib 0).

---

## 6. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-024-audit-penyesuaian-tukin.sql` (hanya SELECT; hasil yang memuat NIP tidak
di-commit).

| ID | Kelas | Isi |
|---|---|---|
| A1 | STOP | `pegawai.nip` = `varchar(30)` `utf8mb4_unicode_ci` (syarat FK) |
| A2 | INFO | Tabel belum ada sebelum migration |
| A3 | STOP | ENUM `audit_logs.event` memuat create/update/delete |
| A4 | INFO | Nama FK `fk_nip_ptk_to_pegawai` belum dipakai |
| B1 | WAJIB_0 | Rentang aktif tumpang tindih per NIP |
| B2 | WAJIB_0 | Rentang < 2024-04 (selain status 10) |
| B3 | INFO | Ringkasan per status (tanpa NIP) |
| B4 | INFO | Penyesuaian aktif untuk pegawai nonaktif (dicek admin) |

---

## 7. Keputusan yang diminta dari DB Validator

| ID | Pertanyaan | Usulan |
|---|---|---|
| DBV-Q1 | Tipe `bulan_mulai`/`bulan_akhir`: DATE (tanggal 1) atau `CHAR(7)` `YYYY-MM`? | DATE + CHECK `DAYOFMONTH = 1` (rentang alami, CHECK kuat) |
| DBV-Q2 | FK `created_by`/`updated_by` → tabel pengguna? | Tanpa FK (pola DBV-023 `dh_online`/`gaji_pegawai`) |
| DBV-Q3 | ENUM `audit_logs.event` perlu nilai baru? | Tidak (create/update/delete cukup) |
| DBV-Q4 | Dua indeks (`nip,status,bulan_mulai` dan `status,bulan_mulai,bulan_akhir`) atau satu? | Dua (pola akses Bagian 3.2); boleh dikurangi bila DBV menilai volume terlalu kecil |
| DBV-Q5 | `persen` DECIMAL(5,2) dengan CHECK `≠ 0` dan −100..100 | Disetujui seperti usulan |
| DBV-Q6 | Nama FK `fk_nip_ptk_to_pegawai` & klasifikasi registry (`KeberadaanV2::Ada`, fase 5, `D-08`) | Disetujui seperti usulan |
| DBV-Q7 | Tumpang tindih rentang aktif dijaga aplikasi (bukan DB) + audit B1 | Disetujui seperti usulan |
| DBV-Q8 | Timestamp migration saat dipindah: lebih baru dari `2026-10-27-100100` (mis. `2026-10-29-100000`) | Ditetapkan saat pemindahan setelah approve |

---

## 8. Verifikasi developer (sebelum review DB Validator)

### 8.1 Lingkungan
DB scratch tunggal 5B `simpeg_v2_t_ws2_fase5b` di instance uji **MySQL 8.0.30** port 3307 (`tools/mysql-test`), dibuat
kosong lalu `spark migrate --all` dari `main` `988c1ed` (semua migration hingga 5A). Draf dijalankan lewat kelasnya sendiri
(`CreatePenyesuaianTukin::up()/down()`) dari test sementara (tidak di-commit); bukan DB dev.

### 8.2 Hasil (28-10-2026)

| Uji | Hasil |
|---|---|
| `up()` membuat tabel; `SHOW CREATE TABLE` = DDL Bagian 3.1; collation `utf8mb4_unicode_ci` | ✅ |
| Baris sah (−20.00, 2024-04..2024-06) | ✅ diterima |
| NIP tidak ada di `pegawai` | ✅ ditolak 1452 |
| `bulan_mulai` bukan tanggal 1; `bulan_akhir < bulan_mulai` | ✅ ditolak 3819 (`chk_penyesuaian_tukin_bulan`) |
| `persen` = 0; `persen` = −100.01 | ✅ ditolak 3819 (`chk_penyesuaian_tukin_persen`); `persen` = 100.00 diterima |
| `status` = 3 | ✅ ditolak 3819 (`chk_penyesuaian_tukin_status`) |
| Hapus pegawai ber-penyesuaian; ubah `pegawai.nip` | ✅ ditolak 1451 (RESTRICT; koreksi NIP lewat aplikasi) |
| EXPLAIN kueri rekap bulan M | ✅ `range` pada `idx_ptk_status_bulan` |
| EXPLAIN cek tumpang tindih per NIP (`FOR UPDATE`) | ✅ `range` pada `idx_ptk_nip_status_bulan`, `Using index` |
| `down()` lalu `up()` ulang | ✅ tabel hilang lalu terbentuk lagi |
| Skrip audit Bagian A (sebelum tabel) | ✅ A1 OK, A2 0, A3 OK, A4 0 |
| Skrip audit Bagian B (tabel kosong) | ✅ tanpa galat sintaks; B1 kosong, B2 0, B3 kosong, B4 0 |

### 8.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator)
Mohon jalankan draf (atau DDL Bagian 3.1) + skrip audit di MariaDB 10.4: terutama CHECK dengan `DAYOFMONTH()` dan
`BETWEEN -100 AND 100` pada DECIMAL. CI `quality-gate` juga menjalankan matriks MySQL 8.0 & MariaDB 10.4 setelah migration
dipindah (test skema Bagian 9).

---

## 9. Rencana test skema (M3, setelah approve)

`tests/database/Presensi/SkemaPenyesuaianTukinTest.php` (`extends DatabaseTestCase`; DDL siklus bertanda
`db-isolasi-penuh`): struktur & collation; 1452/1451; ketiga CHECK (3819/4025 MariaDB); indeks ada; siklus `down()/up()`;
registry NIP (`NipReferenceRegistryTest`, `TabelAnakNipTest`) hijau; `NipCascadeTest` memakai `FASE`.

---

## 10. Syarat merge (⛔)

1. DB Validator menyetujui DBV-024 (tanggal + catatan dicatat di dokumen ini dan `05-Presensi.md`).
2. Migration dipindah ke `app/Database/Migrations/` dengan DDL **tidak berubah** dari yang disetujui (atau perubahan atas
   permintaan DBV tercatat), timestamp lebih baru dari `2026-10-27-100100`.
3. Registry NIP + test skema hijau; CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4).
4. Merge oleh reviewer CR (izin Horii). M4 MAKE-045 dimulai setelah DBV-024 di `main`.

---

## 11. Checklist DB Validator

- [ ] Nama tabel/kolom/FK/CHECK/indeks
- [ ] Tipe `bulan_*` (DBV-Q1), `persen` (DBV-Q5)
- [ ] FK `nip` RESTRICT/RESTRICT; tanpa FK pelaku (DBV-Q2)
- [ ] Indeks (DBV-Q4) sesuai pola akses
- [ ] Status 1/2/10 & hapus lunak
- [ ] Tidak ada impor; pengisian cutover oleh admin (F5B-21)
- [ ] ENUM audit tidak berubah (DBV-Q3)
- [ ] Skrip audit hanya SELECT; klasifikasi A/B
- [ ] Verifikasi MariaDB 10.4 (Bagian 8.3)
