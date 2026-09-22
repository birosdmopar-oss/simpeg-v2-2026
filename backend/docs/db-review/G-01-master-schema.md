# DB Validator Review — G-01 Migration Tabel Master (Modul G)

**Status:** SEBAGIAN — MENUNGGU APPROVAL DB VALIDATOR (SOP Bagian 2 langkah 2).
Batch 1 (7 tabel Tier 0) sudah ditulis dan **hanya dijalankan di database lokal/test**. **Jangan dijalankan di Dev/Production sebelum kolom "Keputusan" di bawah diisi.** Sisa ~38 tabel Tier 0/1 belum ditulis — menunggu DDL legacy (Trello **ISSUE-003**).

**Rujukan:** `02-MasterData.md` G-01, Tech Spec §2.3 G-01, `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 0 & Tier 1, `simpeg_v2_local_seed.sql`, ERD legacy `simpeg01.erd` (relasi FK), `Legacy_System_Audit_SIMPEG.pdf` (Master Data & Pengaturan).

## 1. Checklist DoD G-01

| # | Kriteria DoD | Hasil | Bukti |
|---|---|---|---|
| 1 | Skema direview & di-approve DB Validator sebelum dijalankan | **PENDING** | dokumen ini |
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
| 1 | Setujui skema Batch 1 (Bagian 2) untuk dijalankan di Dev | Setujui | |
| 2 | Keunikan nama master: cukup di aplikasi, atau tambah UNIQUE index (`nama` / `induk+nama`)? | Aplikasi dulu; UNIQUE index setelah data quality audit legacy (duplikat legacy akan menggagalkan migrasi data) | |
| 3 | Kolom `created_at`/`updated_at` di tabel master? | Tidak (ikut legacy; histori di `audit_logs`) | |
| 4 | Panjang kode wilayah VARCHAR(10) cukup? (kode Kemendagri kelurahan tanpa titik = 10 digit; dengan titik = 13) | Konfirmasi format kode legacy dari DDL/data `simpeg01` | |
| 5 | Master tanpa `order` di seed: tambahkan `order` sesuai pola 02-MasterData.md? | Ya, semua master `order` + `status` | |
| 6 | `jenjang_jf`, `faq_related_article`, `dm_user_lokasi_presensi`: ikut dimigrasi di G-01? | Tunggu DDL, lalu putuskan | |
| 7 | FK `user_lokasi_presensi.nip` dan `faq_rate.nip` → `pegawai.nip`: tabel `pegawai` baru ada di Fase 3 | Defer FK ke B-01 (pola sama dengan A-01 `pengguna.nip`) | |
| 8 | FK `pengguna.id_unit`/`id_satker` → `unit`/`satker` (ditunda dari A-01 ke Fase 2) | Ditambahkan saat migration unit/satker (G-02) setelah DDL legacy ada | |
| 9 | Collation (A-01 Bagian 8 #3 — belum diputuskan): tabel master batch 1 mewarisi default koneksi (`utf8mb4_general_ci`). Keunikan nama master bergantung pada collation `*_ci` | Ikuti keputusan #3 A-01; kalau ditetapkan `utf8mb4_unicode_ci`, batch 1 ditambah `ALTER TABLE … CONVERT TO` sebelum dijalankan di Dev | |
