# DB Validator Review — G-01 Migration Tabel Master (Modul G)

**Status:** MENUNGGU APPROVAL DB VALIDATOR (SOP Bagian 2 langkah 2).
Seluruh migration di bawah sudah ditulis dan **hanya dijalankan di database lokal & test**. **Jangan dijalankan di Dev/Production sebelum kolom "Keputusan" di Bagian 5 diisi.**

**Rujukan skema (urutan prioritas, keputusan Horii 22-09-2026):**

1. **DDL legacy `simpeg01`** — Mapping Migrasi Prinsip #1 (nama tabel & kolom mayoritas tetap sama).
   Sumber yang dipakai: dump struktur `simpeg01-3.sql` (2024-11: wilayah), `simpeg01-2.sql` (2023-11: unit, satker, jabatan),
   `ddl_simpeg01.sql` (2020-05: 303 tabel, struktur saja).
2. **ERD `simpeg01.erd`** (2026-09-21) — acuan relasi FK yang berlaku sekarang.
3. **`simpeg_v2_local_seed.sql`** — hanya untuk tabel yang tidak ada di ketiga dump di atas (ditandai di Bagian 3).

**Peringatan umur sumber:** dump legacy berumur 2–6 tahun, sedangkan ERD dari 2026. Kolom tabel yang DDL-nya hanya
tersedia dari dump 2020 (ditandai ⚠ di Bagian 2) sebaiknya di-cross-check ke DB legacy yang berjalan sebelum migrasi data.

## 1. Checklist DoD G-01

| # | Kriteria DoD | Hasil | Bukti |
|---|---|---|---|
| 1 | Skema direview & di-approve DB Validator sebelum dijalankan | **PENDING** | dokumen ini |
| 2 | Urutan migration mengikuti dependency (Tier 0 dulu, lalu Tier 1 sesuai hierarki) | OK | `php spark migrate --all` berjalan bersih dari nol; hierarki: group_jabatan → sub_group_jabatan → jabatan; provinsi → kabupaten_kota → kecamatan → kelurahan; bidang → jurusan pendidikan; tingkat → jenis hukdis; unit → satker; jenis_libur → hari_libur; faq_topic → sub_topic → article |
| 3 | FK aktif di seluruh relasi Tier 1 | OK **kecuali 2 FK yang tabel induknya belum ada DDL** (`jabatan.kelas_jabatan`, `jabatan.id_jenjang_jf`) dan FK ke `pegawai` yang di-defer ke Fase 3 | test `MasterGenericTcTest::testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted` menguji FK RESTRICT pada provinsi, unit, tingkat_hukdis |

## 2. Tabel yang dibuat (33 tabel)

| Migration | Tabel | Sumber kolom |
|---|---|---|
| `2026-09-22-000001_CreateWilayah` | provinsi, kabupaten_kota, kecamatan, kelurahan | DDL legacy 2024-11 |
| `2026-09-22-000002_CreateReferensiUmum` | agama, jenis_pegawai, jenis_status | seed lokal (tidak ada di dump legacy) |
| `2026-09-23-000001_CreateMasterJabatan` | group_jabatan ⚠, sub_group_jabatan ⚠, unit, satker, jabatan, periode_struktur_jabatan ⚠, struktur_jabatan ⚠ | unit/satker/jabatan: DDL 2023-11; sisanya DDL 2020-05 |
| `2026-09-23-000002_CreateMasterKpPendidikan` | pangkat ⚠, jenis_kp ⚠, gol_pppk, jenjang_pendidikan ⚠, bidang_pendidikan ⚠, jurusan_pendidikan ⚠ | DDL 2020-05; `gol_pppk` dari seed |
| `2026-09-23-000003_CreateMasterDiklatHukdisKonket` | diklat ⚠, tingkat_hukdis ⚠, jenis_hukdis ⚠, jenis_konket, tanda_jasa ⚠ | DDL 2020-05; `jenis_konket` dari seed |
| `2026-09-23-000004_CreateMasterLokasiPresensi` | lokasi_presensi, user_lokasi_presensi | seed lokal + SRS (radius ≥ 10 m) |
| `2026-09-23-000005_CreateMasterKantorLibur` | kantor, jenis_libur, hari_libur | seed lokal + relasi wilayah dari ERD; hari_libur juga Tech Spec G-08 |
| `2026-09-23-000006_CreateWebConfigFaq` | web_config ⚠, faq_topic, faq_sub_topic, faq_article | web_config: DDL 2020-05 + 2 kolom v2; FAQ dari seed |
| `2026-09-23-000007_CreateReferensiTier0Lain` | jenis_layanan ⚠, question_category ⚠ | DDL 2020-05 (migrasi saja, CRUD-nya di Fase 4 & 8) |

⚠ = kolom hanya dari dump 2020, perlu cross-check ke DB legacy berjalan.

### Beda yang disengaja dari legacy (berlaku ke semua tabel di atas)

| # | Legacy | v2 | Alasan |
|---|---|---|---|
| 1 | `status` tinyint: 1 Active, 2 Inactive, 10 Deleted | `status` ENUM('0','1') — '1' aktif, '0' non-aktif/soft delete | 02-MasterData.md & G-TC. **Migrasi data: 1 → '1'; 2 dan 10 → '0'** (perlu dicatat di Mapping Migrasi) |
| 2 | FK `ON DELETE SET NULL ON UPDATE CASCADE` | `ON DELETE RESTRICT ON UPDATE RESTRICT` | master yang direlasikan tidak boleh lepas/hilang diam-diam (G-TC soft-delete only); kode (PK) immutable di aplikasi |
| 3 | `created_at`, `updated_at`, `updated_by` | tidak dibuat | jejak perubahan ada di `audit_logs` (ADR-011), termasuk actor |
| 4 | sebagian master tanpa `order` | `order` ditambahkan di semua master dropdown | pola wajib 02-MasterData.md |
| 5 | `libur` (ID, Tahun, KdJnsLibur, NamaLibur, TglMulai, TglAkhir, Keterangan) | `hari_libur` (id_hari_libur, id_jenis_libur, nama, tgl_mulai, tgl_akhir, keterangan, status) | Mapping Migrasi Tier 1 + Tech Spec G-08 memakai nama `hari_libur` snake_case. **Butuh transformasi saat migrasi data**; kolom `Tahun` legacy tidak dibawa (bisa diturunkan dari tgl_mulai) |
| 6 | `web_config` (id_web_config, config_name, config_value) | + `tipe_data` ENUM, + `keterangan`, UNIQUE(config_name) | DoD G-09 "tipe data per key distandarkan". **Cek duplikat `config_name` di data legacy sebelum migrasi** |
| 7 | flag 1=Ya / 2=Tidak (`pangkat.cpns`, `jurusan_pendidikan.D_I`..`S_3`, `diklat.jenis_diklat`) | dipertahankan apa adanya | menghindari transformasi data; berbeda dari gaya boolean v2 (`affect_tukin` 0/1) — lihat Keputusan #4 |

## 3. Tabel Tier 0/1 yang BELUM dibuat

**Tanpa DDL di sumber mana pun (Trello ISSUE-003)** — 9 tabel:
`kelas_jabatan`, `peta_jabatan`, `jabatan_koordinasi`, `rumpun_jabatan`, `subrumpun_jabatan`, `jabatan_akademik`,
`dm_ak_jf`, `faq_rate`, `bidang_kursem`, `instansi_kursem`.

Dampak ke DoD:

| Task | Bagian yang tertunda |
|---|---|
| G-02 | CRUD kelas jabatan & peta formasi. Kolom `jabatan.kelas_jabatan` sudah ada (tinyint) tapi **tanpa FK** sampai tabelnya dibuat |
| G-10 | Rating artikel (`faq_rate`) — juga butuh tabel `pegawai` (Fase 3) |

**Ada di legacy tapi di luar daftar Tier 0/1 Mapping Migrasi** (perlu keputusan): `jenjang_jf` (direferensikan
`jabatan.id_jenjang_jf`), `faq_related_article`, `dm_user_lokasi_presensi`.

## 4. FK yang di-defer

| FK | Alasan | Rencana |
|---|---|---|
| `user_lokasi_presensi.nip` → `pegawai.nip` | tabel `pegawai` baru ada di Fase 3 | migration terpisah di B-01 (pola sama dengan `pengguna.nip`, A-01) |
| `faq_rate.nip` → `pegawai.nip` | tabel & DDL belum ada | setelah ISSUE-003 + B-01 |
| `pengguna.id_unit` / `id_satker` → `unit` / `satker` | **tipe tidak cocok**: `pengguna` memakai VARCHAR(10), sedangkan PK `unit`/`satker` legacy adalah INT AUTO_INCREMENT | perlu keputusan: ubah kolom di `pengguna` ke INT (ALTER + migrasi data akun), atau simpan kode satker string. Lihat Keputusan #5 |
| `jabatan.kelas_jabatan`, `jabatan.id_jenjang_jf` | tabel induk belum ada DDL | setelah ISSUE-003 |

## 5. Keputusan yang diminta dari DB Validator / Tech Lead

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | Setujui skema 33 tabel (Bagian 2) untuk dijalankan di Dev | Setujui | |
| 2 | Pemetaan `status` legacy → v2 saat migrasi data: 1 → '1', 2 dan 10 → '0' | Setujui & catat di Mapping Migrasi | |
| 3 | Kolom `created_at`/`updated_at`/`updated_by` legacy tidak dibawa (jejak di `audit_logs`) | Setujui | |
| 4 | Flag legacy 1=Ya/2=Tidak dipertahankan, atau dinormalkan ke 0/1 seperti `affect_tukin`? | Pertahankan (hindari transformasi); konsistensi diurus di layer aplikasi | |
| 5 | `pengguna.id_unit`/`id_satker` VARCHAR(10) vs PK `unit`/`satker` INT | Ubah `pengguna` ke INT UNSIGNED + FK saat migrasi data akun (A-01 tindak lanjut) | |
| 6 | Kolom PK `char(2/4/7/10)` wilayah sesuai legacy — cukup untuk data yang ada? | Ikut legacy | |
| 7 | Keunikan nama master: cukup di aplikasi, atau tambah UNIQUE index (`induk`+`nama`)? | Aplikasi dulu; UNIQUE index setelah data quality audit legacy | |
| 8 | `web_config.config_name` dibuat UNIQUE — bagaimana kalau data legacy punya duplikat? | Dedup saat migrasi (ambil baris terbaru) | |
| 9 | Collation: tabel master mewarisi default koneksi (`utf8mb4_general_ci`); keunikan nama bergantung collation `*_ci` | Ikut keputusan A-01 Bagian 8 #3; kalau ditetapkan `utf8mb4_unicode_ci`, tambah `ALTER TABLE … CONVERT TO` sebelum Dev | |
| 10 | `jenjang_jf`, `faq_related_article`, `dm_user_lokasi_presensi` ikut dimigrasi di G-01? | Tunggu DDL (ISSUE-003), lalu putuskan | |
| 11 | `gol_pppk`: Legacy Audit menyebut memuat nominal uang makan per golongan, tapi kolomnya tidak ada di sumber mana pun | Tambahkan kolom nominal setelah DDL legacy tersedia (dibutuhkan kalkulasi uang makan Fase 5) | |
| 12 | `jenis_hukdis.masa_sanksi_bulan` ada di seed tapi tidak ada di DDL legacy 2020 | Belum dibuat; tambahkan kalau memang dipakai modul Hukdis (Fase 3) | |
