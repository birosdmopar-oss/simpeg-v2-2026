# Progress Fase 2 — Modul G: Master Data & Pengaturan

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `02-MasterData.md`.

**Terakhir diperbarui:** 23 September 2026 (DBV-001)
**Entry criteria:** Fase 1 sign-off dikonfirmasi user (21 Sep 2026).
**Blocker utama:** DDL legacy 13 tabel Tier 0/1 belum ada → Trello **ISSUE-003**. Keputusan skema → `backend/docs/db-review/G-01-master-schema.md` Bagian 4.

| Task | Status | Ringkas |
|---|---|---|
| G-01 Migration tabel master | **IN_PROGRESS** | Batch 1 (7 tabel Tier 0) **disetujui DB Validator 23-09-2026**; revisi ke skema legacy **DBV-001 menunggu approval** (G-01 Bagian 8); sisa ~38 tabel menunggu DDL legacy |
| G-02 Jabatan, Unit & Satker | TODO (blocked) | Butuh DDL `jabatan` (5 FK di legacy), `kelas_jabatan`, `peta_jabatan` |
| G-03 Lokasi Presensi | TODO (blocked) | Kolom `lokasi_presensi` ada di seed; `user_lokasi_presensi` butuh `pegawai` (Fase 3) + `dm_user_lokasi_presensi` |
| G-04 Kenaikan Pangkat | TODO (blocked) | `gol_pppk` legacy memuat nominal uang makan — tidak ada di seed |
| G-05 Pendidikan | TODO | Kolom ada di seed; perlu keputusan #5 (`order`) → bisa langsung pakai engine |
| G-06 Diklat, Hukdis, Konket, Tanda Jasa | TODO | Kolom ada di seed; perlu keputusan #5 (`order`) → bisa langsung pakai engine |
| G-07 Data Umum & Wilayah | **IN_PROGRESS** | agama, jenis_pegawai, jenis_status, wilayah 4 level SELESAI (backend + FE + G-TC). `kantor` belum (blocked DDL) |
| G-08 Hari Libur | TODO | Kolom ada di seed/Tech Spec; butuh migration `jenis_libur` + validasi overlap (tidak cocok engine generik murni) |
| G-09 Web Config | TODO (blocked) | Konflik nama kolom `config_key` (seed) vs `config_name` (legacy); daftar key + tipe data belum ada |
| G-10 FAQ | TODO (blocked) | `faq_rate` tanpa definisi kolom; FK `nip` → `pegawai` (Fase 3) |

## G-01 — IN_PROGRESS

**Sudah jalan**
- `app/Database/Migrations/2026-09-22-000001_CreateWilayah.php` — provinsi → kabupaten_kota → kecamatan → kelurahan, FK RESTRICT.
- `app/Database/Migrations/2026-09-22-000002_CreateReferensiUmum.php` — agama, jenis_pegawai, jenis_status.
- Dokumen review DB Validator: `backend/docs/db-review/G-01-master-schema.md`.
- Dijalankan HANYA di DB lokal (`simpeg_v2`) & test (`simpeg_v2_testing`).
- **DBV-001** `app/Database/Migrations/2026-09-23-000000_AlterBatch1KeSkemaLegacy.php` — ALTER ke skema legacy (nama/tipe kolom legacy, status 1/2/10, kolom audit, `utf8mb4_unicode_ci`, UNIQUE nama, kode wilayah CHAR(2/4/7/10)). ⏳ Menunggu approval DB Validator — **jangan jalankan di Dev**. Menyelesaikan ISSUE-008/009/010 untuk 7 tabel ini.

**Belum**
- ~~Approval DB Validator~~ → Batch 1 **DISETUJUI** 23-09-2026 (Keputusan #1, #3, #5 terisi). Keputusan #2, #4, #9 ditunda sampai seluruh Fase 2 selesai → **dikerjakan lebih awal di DBV-001** atas keputusan user 23-09-2026 (menunggu approval, G-01 Bagian 8.5; #3 dibalik); #6, #7, #8 masih menunggu DDL legacy.
- Wajib sebelum impor data master legacy: ~~panjang kode wilayah, UNIQUE index nama, collation~~ → dikerjakan di DBV-001 (menunggu approval); tersisa strict mode koneksi (A-01) + audit duplikat data legacy + pencocokan nilai dugaan dengan dump struktur produksi (G-01 Bagian 8.3).
- ~38 tabel Tier 0/1 sisanya — menunggu hasil `mysqldump --no-data simpeg01 …` (ISSUE-003).
- Berhenti di: menunggu DDL. Langkah berikut: tulis migration per grup (G-02..G-10) mengikuti DDL legacy, tambah entri di `Config\MasterData`.

## G-07 — IN_PROGRESS

**Sudah jalan (DoD terpenuhi untuk 7 master ini)**
- CRUD agama, jenis pegawai, jenis status, provinsi, kabupaten/kota, kecamatan, kelurahan — `UmumController` (engine generik).
- Dropdown berjenjang wilayah 4 level: backend `master/{entity}/options?parent=` + FE `useCascadeOptions` (form & filter).
- G-TC #1–#6 lolos otomatis: `tests/MasterData/MasterGenericTcTest.php`, `RbacMasterEndpointsTest.php`; skema DBV-001: `Batch1LegacySchemaTest.php`.
- DBV-001: skema legacy (kolom `provinsi`, `agama`, …; `kd_area`, `kd_pos`, `status_pegawai`), status Aktif/Tidak Aktif/Dihapus + pulihkan, kode wilayah tepat 2/4/7/10 digit, kode agama/jenis_* otomatis.
- FE: `/master/:entity?` (menu "Master Data" hanya role 1), toggle switch + badge Aktif hijau/Tidak Aktif abu/Dihapus merah, naik/turun urutan, form tambah/edit, konfirmasi hapus (soft).
- Diverifikasi manual di browser (lokal): tambah, duplikat nama ditolak di field, toggle status, reorder, edit (induk berjenjang ter-isi), hapus soft, role 2 → 403 & menu tersembunyi, tampilan mobile.

**Belum**
- `kantor` (blocked DDL: legacy FK ke 4 tabel wilayah).
- G-TC #7 QA Lapis 1 vs Figma — desain belum ada.

## Engine CRUD master generik (fondasi G-02..G-10)

Dipakai semua master berbentuk "kode + nama (+ induk) + order + status". Lihat `app/Controllers/Api/MasterData/README.md`.
Master dengan field tambahan (satker `offset_zona_menit`, lokasi presensi lat/long/radius ≥10 m, jenis_konket `affect_tukin`,
hari libur rentang tanggal non-overlap, web config bertipe) butuh perluasan engine (field ekstra per definisi) — dikerjakan
bersama task masing-masing setelah skema disetujui.

## Perubahan di luar folder Modul G (bug yang ditemukan — scope diperluas sesuai 00-INDEX)

- `app/Models/BaseAuditableModel.php` — `rowKey()` meng-cast semua PK numerik ke int → kode `'01'` tercatat `1` di `audit_logs`. Diperbaiki: hanya integer kanonik yang di-cast.
- `app/Controllers/Api/ApiController.php` — `_remap()` meng-cast parameter route digit ke int → kode `'01'` jadi `1`. Ditambah flag `$castNumericParams` (default tetap `true`; dimatikan di controller master).
