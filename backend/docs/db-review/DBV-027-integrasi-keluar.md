# DB Validator Review — DBV-027 skema integrasi keluar (Fase 6 Jalur B, H-01 bagian B)

**Key review:** `DBV-027` (Fase 6, H-01 bagian B, QASMTASK-089). Judul PR: `[DBV-027] Skema Fase 6 Jalur B: integrasi
keluar (log integrasi, SIASN, firebase_token, UNIQUE SKP periodik)`. Branch `fase6/dbv-027-integrasi-keluar` (dari `main`
`02141174`, digabung `main` `84917849`). Alur PRD Fase 6 §2.1 / `6_1.md` B1: PR `[DBV]`, DB Validator me-review/approve, lalu migration dipindah dari
`MigrationsDitahan/` ke `Migrations/` + registry NIP + test skema; reviewer CR me-merge setelah CI hijau. B2 (MAKE-063)
menunggu DBV-027 di `main`.

**Status:** ⏳ **menunggu review DB Validator.** Draf migration **DITAHAN** di
`app/Database/MigrationsDitahan/CreateIntegrasiKeluarFase6.php` (tidak dijalankan `spark migrate`). Pra-review sisi CR:
laporan B0 diterima reviewer CR 02-11-2026 (jawaban Q1–Q8 dipakai di dokumen ini).

**Penulis:** pelaksana Fase 6 Jalur B, 02-11-2026.

**Asal kebutuhan:** PRD `docs/fase6/PRD_Fase6_Integrasi.md` v1.0b (CR-090) §7.1 (DBV-027), §7.0.2, §7.3, §7.4, §7.8, §8; keputusan
Horii 01-11-2026 F6-13, F6-14 ("SIASN ikut legacy penuh"), F6-15, F6-24, Q6-10, Q6-16; jawaban reviewer CR atas B0 (Q1
padanan `dsp`, Q7 akun sistem); keputusan Horii 02-11-2026 (Q2 B0): dorong diklat/sertifikasi/kursus/SKP 2022 ikut legacy.

**Rujukan:** `DBV-024-penyesuaian-tukin-schema.md` (pola dokumen, draf ditahan → dipindah setelah approve),
`DBV-022-layanan-fase4-schema.md` Bagian 2.19/2.20 (`firebase_token`, DQ-25/26), `B-01-B-02-pegawai-riwayat-schema.md`
(`riwayat_skp_periodik`, catatan `double_riwayat_kp`/`siasn_upload_arsip` sebagai tabel kerja SIASN), ADR-008 (FK RESTRICT),
ADR-015 (adapter). Kode legacy = salinan CI3 HEAD `0cf7e67` folder `application/` (di luar repo; rujukan `berkas:baris`
relatif: `SS/` = `controllers/hr/services/siasn_sync/`, `SI` = `controllers/hr/services/Siasn.php`, `EB` =
`controllers/hr/services/Ekin_bkn.php`, `L` = `controllers/hr/services/Local.php`, `FH` = `helpers/function_helper.php`;
belum dicocokkan checksum produksi, AS-09). Berkas rahasia legacy (PRD Cara pakai 6) **tidak** dibuka.

**Label sumber:** **[K]** ikut kode/DDL legacy; **[V2]** deviasi v2 yang disengaja (alasan + keputusan); **[I]** inferensi
(DDL legacy tidak tersedia — kolom diturunkan dari kode; DB Validator diminta mencocokkan dengan dump D1 bila ada).

---

## 0. Ringkasan

| # | Objek | Jenis | Inti |
|---|---|---|---|
| 1 | `integrasi_log` | CREATE [V2] | Log integrasi masuk/keluar teredaksi (F6-24); retensi 180 hari oleh `integrasi:bersihkan-log` (Q6-16) |
| 2 | `siasn_status_pegawai` | CREATE [K padanan `siasn_simpeg.dsp`] | Flag + stempel per jenis tarik SIASN per NIP (Q1 B0) |
| 3 | `siasn_upload_arsip` | CREATE [K tabel `simpeg01`, kolom [I]] | Antrean unggah dokumen riwayat ke SIASN (`SS/Arsip`) |
| 4 | `double_riwayat_kp` | CREATE [K tabel `simpeg01`, kolom [I]] | NIP dengan golongan ganda di SIASN (`SS/Rw_gol`) |
| 5 | `siasn_ref_unor` | CREATE [K padanan `siasn_simpeg.REF_UNOR`] | Referensi unor SIASN + pemetaan lokal (`SS/Ref_unor`) |
| 6 | `siasn_referensi` | CREATE [V2 gabungan] | 7 tabel referensi `siasn_simpeg.*` yang dipakai job aktif → satu tabel ber-`jenis` |
| 7 | `firebase_token` | ALTER | `nip` NULL-able, `id_pengguna` (FK), `platform` 1/2, `token_hash` UNIQUE **per pemilik** [K] (keputusan Horii 03-11-2026), `refresh_date` [K `fb_token`], CHECK pemilik |
| 8 | `riwayat_skp_periodik` | ALTER | UNIQUE (`id_pns`, `periode_id`, `skp_id`) (Q6-10) setelah **pra-cek duplikat** |
| 9 | `pengguna` | INSERT 1 baris | Akun sistem integrasi `sistem.integrasi` (role 1 ikut legacy — keputusan Horii 03-11-2026; nonaktif, tanpa password) untuk `updated_by`/`approved_by` (PRD §8) — pengecualian "migration tanpa seed" |

**Tidak** berubah: `esign_hist` (PRD §7.1), kolom `siasn_*` di `pegawai`/`riwayat_*` (dorong memakai kolom yang ada), ENUM
`audit_logs.event`, `bkn_periode_ekinper`.

---

## 1. Konvensi yang dipakai

| Kode | Konvensi | Rujukan |
|---|---|---|
| V1 / ADR-008 | FK ke `pegawai`/`pengguna` **ON DELETE RESTRICT ON UPDATE RESTRICT**; koreksi NIP oleh `NipCascadeService` | DBV-012/022/024 |
| Collation | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | DBV-Q15 (DBV-023) |
| Waktu | `created_at`/`updated_at`/stempel `_lu` = **UTC diisi aplikasi** (sesi DB UTC sejak CR-086); tanpa default DB | DBV-025 |
| CHECK | Bernama `chk_<tabel>_<arti>` | DBV-023/024 |
| Nama FK | `fk_<kolom>_<singkatan tabel>_to_<induk>` | DBV-023/024 |
| Nama tabel | Tabel `simpeg01` legacy dipertahankan namanya (`siasn_upload_arsip`, `double_riwayat_kp`); padanan skema `siasn_simpeg` diberi awalan `siasn_` huruf kecil (nama huruf besar legacy rawan `lower_case_table_names`) | [V2] |
| Migration | Migration di `main` tidak diedit; draf ditahan sampai approve; DDL ter-commit per statement → up() membatalkan langkah sebelumnya bila gagal | AGENTS §2 |

---

## 2. Sumber legacy

| Objek v2 | Sumber legacy [K] | DDL legacy |
|---|---|---|
| `siasn_status_pegawai` | `siasn_simpeg.dsp`: penulis `SS/Data_utama::sync_dsp` (:10-88: satu baris per pegawai aktif, baris pegawai nonaktif dihapus), flag `du`/`du_lu` (`::sync_siasn` :90-240, `du_lu` = `date('Ymd')`), `rw_gol`/`rw_didik`/`rw_pmk`/`rw_skp22`/`rw_ak` + `_lu` (pola sama di `SS/Rw_gol`, `Rw_pendidikan`, `Rw_pmk`, `Rw_skp`, `Rw_ak`: 2 diproses → 1 selesai / 0 gagal; usang = `_lu` < 6 jam lalu) | **tidak tersedia** (skema `siasn_simpeg`) → kolom [I] |
| `siasn_upload_arsip` | penulis `SS/Rw_jabatan::set_queue_arsip` :569-660, `SS/Rw_pelatihan::set_queue_arsip_sertifikasi` :406-495 (kunci ulang `nip***id_rw***arsip_path`); pemroses `SS/Arsip::upload_siasn` :10-156 (status 0 → 3 diproses → 1 sukses + `resp_*` / 2 gagal + `error_msg`) | ada di `simpeg01` (dicatat B-01 §1 sebagai tabel kerja SIASN) tetapi DDL tidak tercantum di dokumen repo → kolom [I] |
| `double_riwayat_kp` | `SS/Rw_gol::get_siasn` :69-74 (baca), :136-141 & :392-393 (insert `['nip' => $nip]` sekali per NIP) | sama dengan baris di atas → kolom [I] |
| `siasn_ref_unor` | `SS/Ref_unor::sync` :13-118 (kolom respons → kolom tabel), konsumen `SS/Rw_jabatan` :134, :213 (`id_jabatan`, `id_unit`+`id_satker`+`lvl_uker`) | tidak tersedia → kolom [I] |
| `siasn_referensi` | `JABATAN_FUNGSIONAL_ID`/`JABATAN_FUNGSIONAL_UMUM_ID` (`SS/Rw_jabatan` :186/:192, cari `NAMA` → `ID`), `JENIS_JABATAN_ID` (:133, kunci `id_group_jabatan`), `LATIHAN_STRUKTURAL_ID` (`SS/Rw_pelatihan` :172, kunci `id_diklat`), `TK_PENDIDIKAN` (`SS/Rw_pendidikan` :41, kunci `id_jenjang_pendidikan`), `JENIS_KP_ID` (`SS/Rw_gol` :48, kunci `id_jenis_kp`), `GOLONGAN_ID` (`SS/Rw_skp` :73/:553, kunci `id_pangkat`) | tidak tersedia → kolom [I] |
| `firebase_token` (ALTER) | Android: `firebase_token` legacy (D1:1156-1163, DBV-022 §2.20). Web: `fb_token` (`id_user`, `fcm_token`, `refresh_date`; `L::register_fb_token` :4224-4289 kunci `id_user`+`fcm_token`, `refresh_date` = +1 minggu; pembaca `FH` :406-574 join `fb_token.id_user = pengguna.id` untuk penerima admin role 1/3 dan pegawai) | `fb_token` tidak di DDL repo → kolom [K-kode] |
| `riwayat_skp_periodik` (UNIQUE) | Kunci logis `id_pns**periode_id**skp_id` (`EB::cron_skp_periodik` :245/:263); tabel tanpa unique key (B-14) | DDL v2 = D1 (`2026-09-30-130400`) |

**Yang tidak dibawa** (PRD §7.3 F6-14): `siasn_simpeg.data_utama` (staging data utama SIASN), `rw_*`/`rw_*_arsip`
staging generasi 1, `rw_harga`/`rw_harga_lu` dan `HARGA_ID` (`SS/Rw_tj` hanya staging tanpa pembaca), `data_anak`/
`data_ortu`/`data_pasangan`, `orang`. Alasan staging `data_utama` tidak dibuat: tiga konsumennya (`SI::sync_data_utama`
:380, `SI::sync_pns_id` :455, syarat `du.nipBaru` di job dorong) memakai endpoint & field yang sama; v2 menjalankan
pairing + salin data utama dalam satu langkah `siasn:tarik --jenis=data-utama` dan syarat "pegawai ada di SIASN" =
`pegawai.id_pns_siasn IS NOT NULL` (DBV-Q5).

---

## 3. Skema v2 usulan

Sumber kebenaran DDL = draf `app/Database/MigrationsDitahan/CreateIntegrasiKeluarFase6.php` (`create()`,
`alterFirebaseToken()`); blok di bawah diringkas dari draf (komentar kolom lengkap ada di draf).

### 3.1 `integrasi_log` [V2] (F6-24)

```sql
CREATE TABLE `integrasi_log` (
  `id_integrasi_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `arah` TINYINT NOT NULL,            -- 1 keluar, 2 masuk
  `layanan` VARCHAR(30) NOT NULL,     -- esign, bkn, ekinerja, siasn, fcm, simapi, services
  `operasi` VARCHAR(100) NOT NULL,    -- tanpa query string
  `status` TINYINT NOT NULL,          -- 1 sukses, 2 gagal
  `http_status` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `kode` VARCHAR(50) NULL DEFAULT NULL,
  `pesan` VARCHAR(1000) NULL DEFAULT NULL,   -- teredaksi (BP-5)
  `durasi_ms` INT UNSIGNED NULL DEFAULT NULL,
  `percobaan` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `ref_id` VARCHAR(100) NULL DEFAULT NULL,
  `id_klien` INT UNSIGNED NULL DEFAULT NULL,  -- arah masuk (DBV-026, Jalur A), tanpa FK
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id_integrasi_log`),
  KEY `idx_il_created` (`created_at`),
  KEY `idx_il_layanan_created` (`layanan`, `created_at`),
  KEY `idx_il_status_created` (`status`, `created_at`),
  KEY `idx_il_layanan_ref` (`layanan`, `ref_id`),
  CONSTRAINT `chk_integrasi_log_arah` CHECK (`arah` IN (1, 2)),
  CONSTRAINT `chk_integrasi_log_status` CHECK (`status` IN (1, 2))
);
```

Pola akses: `integrasi:status` (per layanan, 24 jam terakhir → `idx_il_layanan_created`), `GET api/v1/integrasi/log`
(filter layanan/status/tanggal, paginasi), `integrasi:bersihkan-log` (`created_at < now − 180 hari` → `idx_il_created`),
penelusuran per rujukan (`idx_il_layanan_ref`). Append-only; tanpa `updated_at`. `pesan` maks 1.000 karakter **setelah**
redaksi (BP-5); payload mentah tidak disimpan (beda dengan legacy, Lampiran B PRD). Tabel dipakai bersama Jalur A (arah 2,
`id_klien`); nama antarmuka penulis disepakati di MAKE-063.

### 3.2 `siasn_status_pegawai` [K padanan `dsp`]

```sql
CREATE TABLE `siasn_status_pegawai` (
  `nip` VARCHAR(30) NOT NULL,
  `du` TINYINT NOT NULL DEFAULT 0, `du_lu` DATE NULL DEFAULT NULL,
  `rw_gol` TINYINT NOT NULL DEFAULT 0, `rw_gol_lu` DATETIME NULL DEFAULT NULL,
  `rw_didik` TINYINT NOT NULL DEFAULT 0, `rw_didik_lu` DATETIME NULL DEFAULT NULL,
  `rw_pmk` TINYINT NOT NULL DEFAULT 0, `rw_pmk_lu` DATETIME NULL DEFAULT NULL,
  `rw_skp22` TINYINT NOT NULL DEFAULT 0, `rw_skp22_lu` DATETIME NULL DEFAULT NULL,
  `rw_ak` TINYINT NOT NULL DEFAULT 0, `rw_ak_lu` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL, `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`nip`),
  CONSTRAINT `fk_nip_ssp_to_pegawai` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_siasn_status_pegawai_flag` CHECK (`du` IN (0,1,2) AND `rw_gol` IN (0,1,2) AND `rw_didik` IN (0,1,2)
    AND `rw_pmk` IN (0,1,2) AND `rw_skp22` IN (0,1,2) AND `rw_ak` IN (0,1,2))
);
```

- Arti flag [K]: 0 = belum/perlu diproses (atau dikembalikan setelah gagal), 2 = sedang diproses, 1 = selesai; batch
  memilih `flag = 0 OR _lu < (now − 6 jam)` (data utama: `du_lu < hari ini`). Offset batch legacy berupa parameter URL,
  bukan kolom → di v2 menjadi opsi command (tanpa kolom).
- `du_lu` DATE [I]: legacy menyimpan `date('Ymd')` dan membandingkan per tanggal.
- Siklus baris [K `sync_dsp`]: baris dibuat untuk pegawai `status = 1`, baris pegawai yang tidak aktif lagi **dihapus**
  (hapus fisik tabel kerja, bukan data bisnis). FK RESTRICT + registry NIP (Bagian 6) menjaga koreksi NIP.
- `rw_harga`/`rw_harga_lu` tidak dibuat (job `Rw_tj` tidak dibawa, PRD §7.3).
- Volume ±jumlah pegawai aktif; tanpa indeks tambahan (pemindaian penuh per batch murah) — DBV-Q3.

### 3.3 `siasn_upload_arsip` [K nama, kolom [I]]

```sql
CREATE TABLE `siasn_upload_arsip` (
  `id_siasn_upload_arsip` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nip` VARCHAR(30) NOT NULL,
  `rw_name` VARCHAR(50) NOT NULL,            -- 'Rw Jabatan' | 'Rw Sertifikasi' [K]
  `id_rw` INT NOT NULL,
  `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
  `id_da` INT NULL DEFAULT NULL,             -- document_attachment.id_attachment, tanpa FK
  `arsip_path` VARCHAR(200) NULL DEFAULT NULL,
  `arsip_name` VARCHAR(255) NULL DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 0,       -- 0 antre, 1 terunggah, 2 gagal, 3 diproses [K]
  `error_msg` VARCHAR(500) NULL DEFAULT NULL,
  `resp_dok_id` VARCHAR(100) NULL, `resp_dok_nama` VARCHAR(255) NULL, `resp_dok_uri` VARCHAR(500) NULL,
  `resp_object` VARCHAR(255) NULL, `resp_slug` VARCHAR(50) NULL,
  `created_at` DATETIME NULL, `updated_at` DATETIME NULL,
  PRIMARY KEY (`id_siasn_upload_arsip`),
  UNIQUE KEY `uq_sua_rw_da` (`rw_name`, `id_rw`, `id_da`),
  KEY `idx_sua_status` (`status`, `id_siasn_upload_arsip`),
  KEY `idx_sua_nip` (`nip`),
  CONSTRAINT `fk_nip_sua_to_pegawai` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_siasn_upload_arsip_status` CHECK (`status` IN (0, 1, 2, 3))
);
```

- UNIQUE [V2]: legacy mencegah antrean ganda di aplikasi dengan kunci `nip***id_rw***arsip_path`; v2 memakai kunci yang
  stabil `rw_name + id_rw + id_da` (lampiran yang sama tidak diantre dua kali, idempoten BP-6). `arsip_path` tetap
  disalin (jejak berkas saat diantre).
- `id_da` tanpa FK [I]: lampiran v2 dihapus keras + audit (B-01 keputusan 9); antrean riwayat tidak boleh menahan
  penghapusan lampiran — pemroses menandai `status = 2` bila berkas sudah tidak ada (DBV-Q4).
- Pemroses legacy mengambil **1 baris per menit** urut `id` (`idx_sua_status`).

### 3.4 `double_riwayat_kp` [K nama, kolom [I]]

`nip` VARCHAR(30) PK + FK RESTRICT ke `pegawai`, `created_at`. Legacy hanya menulis `nip` sekali per NIP (cek `isset` di
aplikasi) → PK `nip` menjaga hal yang sama di DB. Isi = penanda tindak lanjut admin (golongan ganda di SIASN); tidak ada
pembaca otomatis.

### 3.5 `siasn_ref_unor` [K padanan `REF_UNOR`]

Kolom respons SIASN [K `SS/Ref_unor` :13-118]: `id` (PK), `nama_unor`, `nama_jabatan`, `eselon_id`, `cepat_kode`,
`atasan_id` (`DiatasanId`), `induk_unor_id`, `instansi_id`, `is_unor_sekolah`, `jenis_unor_id`, `pemimpin_pns_id`.
Kolom pemetaan lokal [K konsumen `SS/Rw_jabatan`]: `id_jabatan` (INT, = `jabatan.id_jabatan` v2), `id_unit`, `id_satker`
(INT), `lvl_uker` (TINYINT). Indeks `idx_sru_jabatan`, `idx_sru_unit_satker_lvl` sesuai dua pola baca dorong jabatan.
Tanpa FK ke master (pemetaan diisi impor/admin; referensi SIASN bisa memuat unor yang tidak dipetakan) — DBV-Q6.

### 3.6 `siasn_referensi` [V2 gabungan]

```sql
CREATE TABLE `siasn_referensi` (
  `jenis` VARCHAR(40) NOT NULL,       -- CHECK 7 nilai di bawah
  `id_siasn` VARCHAR(100) NOT NULL,   -- [K kolom ID]
  `nama` VARCHAR(255) NULL,           -- [K kolom NAMA]
  `id_lokal` VARCHAR(20) NULL,        -- pemetaan ke id master v2
  `created_at` DATETIME NULL, `updated_at` DATETIME NULL,
  PRIMARY KEY (`jenis`, `id_siasn`),
  KEY `idx_sr_jenis_lokal` (`jenis`, `id_lokal`),
  KEY `idx_sr_jenis_nama` (`jenis`, `nama`)
);
```

| `jenis` | Tabel legacy | Kolom kunci lokal legacy → `id_lokal` | Dipakai job |
|---|---|---|---|
| `jabatan_fungsional` | `JABATAN_FUNGSIONAL_ID` | — (dicari lewat `NAMA`) | dorong jabatan |
| `jabatan_fungsional_umum` | `JABATAN_FUNGSIONAL_UMUM_ID` | — (lewat `NAMA`) | dorong jabatan |
| `jenis_jabatan` | `JENIS_JABATAN_ID` | `id_group_jabatan` | dorong jabatan |
| `latihan_struktural` | `LATIHAN_STRUKTURAL_ID` | `id_diklat` | dorong diklat |
| `tk_pendidikan` | `TK_PENDIDIKAN` | `id_jenjang_pendidikan` | tarik pendidikan |
| `jenis_kp` | `JENIS_KP_ID` | `id_jenis_kp` | tarik golongan |
| `golongan` | `GOLONGAN_ID` | `id_pangkat` | tarik/dorong SKP |

Alasan gabung [V2]: ketujuh tabel berbentuk sama (ID + NAMA + satu kolom pemetaan lokal), hanya dibaca job, dan diisi
sekali saat cutover; satu tabel ber-`jenis` mengurangi tujuh migration/model kembar. Alternatif tujuh tabel terpisah
ikut nama legacy (DBV-Q7). `id_lokal` VARCHAR(20) karena kolom lokal bertipe campuran (INT/TINYINT).

### 3.7 ALTER `firebase_token` (F6-13, DQ-25)

```sql
ALTER TABLE `firebase_token`
  MODIFY `nip` VARCHAR(30) NULL DEFAULT NULL,
  ADD `id_pengguna` INT UNSIGNED NULL DEFAULT NULL AFTER `nip`,
  ADD `platform` TINYINT NOT NULL AFTER `id_pengguna`,        -- 1 web/desktop, 2 android (baris lama → 2)
  ADD `token_hash` CHAR(64) NULL DEFAULT NULL AFTER `fcm_token`,
  ADD `refresh_date` DATETIME NULL DEFAULT NULL AFTER `token_hash`,
  ADD UNIQUE KEY `uq_firebase_token_pengguna_hash` (`id_pengguna`, `token_hash`),
  ADD UNIQUE KEY `uq_firebase_token_nip_hash` (`nip`, `token_hash`),
  ADD CONSTRAINT `fk_id_pengguna_fbt_to_pengguna` FOREIGN KEY (`id_pengguna`) REFERENCES `pengguna` (`id_pengguna`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `chk_firebase_token_platform` CHECK (`platform` IN (1, 2)),
  ADD CONSTRAINT `chk_firebase_token_pemilik` CHECK ((`platform` = 1 AND `id_pengguna` IS NOT NULL) OR (`platform` = 2 AND `nip` IS NOT NULL));
```

Dijalankan dalam tiga statement (kolom dengan DEFAULT 2 sementara → isi `token_hash = SHA2(fcm_token, 256)` baris lama →
buang DEFAULT + constraint).

- **`nip` NULL-able + `id_pengguna` [K]**: token web legacy (`fb_token`) dikunci **`id_user`** (akun), dan penerimanya
  termasuk admin role 1/3 (`FH` :406-574) yang di v2 boleh tanpa NIP (DBV A-01 akun non-pegawai). Dengan `nip NOT NULL`
  token web admin tanpa NIP tidak bisa disimpan. Android tetap per NIP (`firebase_token` legacy). CHECK pemilik menjaga
  pasangan platform ↔ kolom pemilik. Untuk token web milik pegawai, aplikasi mengisi `nip` juga (penerima "pegawai").
- **`token_hash` UNIQUE per pemilik** [K] — **keputusan Horii 03-11-2026 (DBV-Q8): ikut legacy.** Legacy `fb_token`
  mengunci `id_user + fcm_token` (`L::register_fb_token` :4251), sehingga satu token boleh tercatat untuk lebih dari satu
  akun (browser yang sama dipakai akun lain tetap menerima push kedua akun, seperti legacy). Web (platform 1): UNIQUE
  (`id_pengguna`, `token_hash`); Android (platform 2): UNIQUE (`nip`, `token_hash`) — tabel `firebase_token` legacy
  tidak punya kunci sama sekali (D1:1156-1163, DBV-022 §2.20), jadi deduplikasi per NIP adalah pasangan kunci web
  (DQ-25). Indeks UNIQUE berawalan `id_pengguna` sekaligus melayani FK. `TEXT` tidak bisa di-UNIQUE langsung → hash.
  Upsert aplikasi (MAKE-066) per pemilik.
- **`refresh_date` [K]**: diperpanjang 1 minggu saat SPA mendaftar ulang; di legacy **tidak ada pembaca** lain (tidak
  dipakai menyaring penerima). Dipertahankan untuk paritas + bahan pembersihan token basi kelak (DBV-Q9).
- Baris lama (bila ada) → `platform = 2` (padanan `firebase_token` legacy = Android). AC-01.2: tabel kosong saat cutover.
  Pra-cek menolak migrate bila ada baris lama tanpa `nip` (setelah menjadi platform 2 akan melanggar CHECK pemilik; di
  skema DBV-022 `nip` NOT NULL sehingga normalnya 0 — audit A6b).
- **Token ganda di lingkungan berisi (dev/demo dengan token Fase 4).** Pra-cek menolak migrate bila ada token ganda
  **per pemilik** (`nip` + token; semua baris lama ber-nip, audit A6). Saat cutover tabel kosong, jadi aman. Untuk dev/demo,
  langkah dedup manual sebelum migrate — simpan baris `id_firebase_token` **terbesar** per (`nip`, token) (pendaftaran
  terakhir), hapus sisanya:

  ```sql
  DELETE f FROM firebase_token f
  JOIN (SELECT nip, SHA2(fcm_token, 256) AS h, MAX(id_firebase_token) AS simpan
        FROM firebase_token WHERE fcm_token IS NOT NULL
        GROUP BY nip, SHA2(fcm_token, 256) HAVING COUNT(*) > 1) d
    ON f.nip = d.nip AND SHA2(f.fcm_token, 256) = d.h AND f.id_firebase_token <> d.simpan;
  ```

  Alternatifnya, langkah yang sama dijalankan deterministik di dalam migration sebelum UNIQUE (DBV-Q13). Token push
  hanya cache pendaftaran perangkat (dibuat ulang saat aplikasi/SPA mendaftar), jadi kehilangan baris ganda tidak
  menghilangkan data bisnis.
- FK `id_pengguna` RESTRICT: `pengguna` dihapus lunak (`deleted_at`), jadi RESTRICT tidak menghalangi alur v2.

### 3.8 ALTER `riwayat_skp_periodik` UNIQUE (Q6-10, F6-15)

`ADD UNIQUE KEY uq_rsp_pns_periode_skp (id_pns, periode_id, skp_id)` — tiga kolom VARCHAR(150) (indeks 1.800 byte <
3.072). Kolom `periode_id`/`skp_id` NULL-able: baris dengan NULL tidak dibatasi UNIQUE (MySQL/MariaDB), sama dengan kunci
logis legacy yang hanya terbentuk dari respons BKN (yang selalu mengirim keduanya). Penulis v2 (`bkn:tarik-skp-periodik`)
menolak baris tanpa `periode_id`/`skp_id`. Pra-cek & aturan duplikat: Bagian 5.

### 3.9 Deviasi & alasan (ringkas)

| Butir | Keputusan | Alasan |
|---|---|---|
| Padanan `dsp` jadi tabel v2 | [K] Q1 B0 | F6-14 ikut legacy penuh; satu `pegawai.siasn_lu` untuk semua jenis = deviasi |
| Staging `data_utama` tidak dibuat | [V2] DBV-Q5 | Satu langkah tarik; syarat dorong = `id_pns_siasn` terisi |
| 7 tabel referensi digabung | [V2] DBV-Q7 | Bentuk identik, baca saja |
| UNIQUE `siasn_upload_arsip` | [V2] | Idempoten antrean (BP-6) |
| `firebase_token` per akun (web) | [K] `fb_token.id_user` | Penerima admin tanpa NIP |
| `token_hash` UNIQUE per pemilik (`id_pengguna`/`nip` + token) | [K] keputusan Horii 03-11-2026 (DBV-Q8) | Ikut legacy `fb_token` (`id_user + fcm_token`); deduplikasi per pemilik (DQ-25) |
| Tanpa ALTER `siasn_lu` di `riwayat_diklat`/`riwayat_seminar`/`riwayat_skp` | [K] **keputusan Horii 02-11-2026 (Q2 B0)**, PRD v1.0b (CR-090) | Dorong otomatis diklat, sertifikasi, kursus/seminar, SKP 2022 hanya `id_rw_siasn IS NULL AND siasn_flag = 0` (ikut legacy); AC-03.5 (`updated_at > siasn_lu`) hanya untuk tabel yang punya `siasn_lu` dan memakainya di legacy (jabatan) |

---

## 4. Akun sistem integrasi (PRD §8, Q7 B0)

Penulisan oleh job (tarik SIASN ke `riwayat_*`/`pegawai`, e-Kinerja, dorong) butuh pelaku `updated_by`/`approved_by` dan
`audit_logs.user_id` — padanan konstanta legacy `ID_USR_DEVSIMPEG` (nilai tidak dibuka; konstanta di berkas rahasia).

| Opsi | Isi | Plus | Minus |
|---|---|---|---|
| **(a) — rekomendasi** | Baris `pengguna` khusus: `username = 'sistem.integrasi'`, `name = 'Sistem Integrasi'`, `nip`/`email`/`password`/`password_legacy` NULL, `status = '0'`, `user_level = 1`, di-seed migration ini; aplikasi mencari id lewat username (`Config\Integrations` menyimpan **username**, bukan id, agar sama di semua lingkungan) | Pelaku nyata di audit & tampilan ("Sistem Integrasi"), konsisten dengan kolom tanpa FK | Pengecualian "migration tanpa seed" (G-02) |
| (b) | Konstanta/konfigurasi id tanpa baris baru (mis. `0`) | Tanpa seed | Audit merujuk id yang tidak ada; tampilan pelaku kosong; rawan bentrok bila id dipakai akun nyata |

Pengaman opsi (a): akun **tidak bisa login** — `status = '0'` ditolak `AuthService` (`AuthService.php:69`), dan walau
diaktifkan tetap ditolak karena tanpa password maupun hash legacy (diuji, Bagian 8).

**Level akun — keputusan Horii 03-11-2026 (DBV-Q10): ikut legacy, `user_level = 1`.** Padanan legacy = akun developer
`ID_USR_DEVSIMPEG` (nilai tidak dibuka; konstanta di berkas rahasia) yang dipakai cron legacy sebagai pelaku
`updated_by`/`approved_by`; akun developer legacy berlevel super admin. **Risiko diterima:** hak tinggi pada akun yang tidak
pernah login. **Mitigasi:** akun nonaktif (`status = '0'`) tanpa password maupun hash legacy — login tetap ditolak walau
`status` diubah '1' (diuji, Bagian 8); audit B1 (bisa login) wajib 0; audit B1b menampilkan level/status (INFO).
(Usulan sebelumnya role 8 non-admin dicabut oleh keputusan ini.) Pengecualian seed
setara preseden data wajib migration lain (mis. `jenis_rwy`, `jenis_cuti` seed DBV-022) — dicatat eksplisit di sini.
Pra-cek: migration menolak bila username sudah dipakai. down() menghapus baris akun (setelah `firebase_token`
dikembalikan, karena token web bisa merujuknya).

---

## 5. Pra-cek duplikat UNIQUE `riwayat_skp_periodik` (data impor cutover)

`riwayat_skp_periodik` diisi impor cutover (MAKE-056, `DaftarTabel.php:169`) lalu job e-Kinerja. Migration menjalankan
pra-cek **sebelum DDL apa pun** dan berhenti dengan pesan jumlah (tanpa NIP) bila ada kunci ganda:

```sql
SELECT COUNT(*) AS kunci_ganda, COALESCE(SUM(n), 0) AS baris_terlibat
FROM (SELECT COUNT(*) AS n FROM riwayat_skp_periodik
      WHERE id_pns IS NOT NULL AND periode_id IS NOT NULL AND skp_id IS NOT NULL
      GROUP BY id_pns, periode_id, skp_id HAVING COUNT(*) > 1) d;
```

(= audit A3; rincian per himpunan status di A3b.) Kolom ber-NULL dikecualikan karena UNIQUE MySQL/MariaDB membolehkan
NULL ganda — grup ber-NULL bukan pelanggaran (`id_pns` sendiri NOT NULL di DDL, kondisinya dipasang untuk ketegasan). Data UP-1 (laporan reviewer CR, angka saja): ada NIP dengan > 1 baris
SKP aktif per periode (April 4 NIP, Mei 8 NIP) — kuncinya **berbeda** dari UNIQUE ini (`skp_id` lain, mis. SKP
Plt/Plh/PJ `is_skp_plt_plh_pjb`), jadi tidak melanggar UNIQUE; audit A5 memantaunya. Legacy sendiri tidak membuat
duplikat kunci ini (insert hanya bila kunci belum ada), sehingga duplikat hanya mungkin dari salinan data/jalur lain.

**Aturan pemilihan bila A3 > 0 — diputus DBV (DBV-Q1).** Usulan: per kunci, pertahankan baris yang saat ini **dibaca
Tukin** — `status = 1`, `hasil_akhir` terisi, `id_riwayat_skp_periodik` terkecil (`SumberDataPegawaiTukin.php:162-172`,
`ORDER BY id ASC`) — agar hasil Tukin tidak berubah; baris lain dikeluarkan dari tabel tujuan sebelum migrate (tidak ada
pembaca lain). Bila DBV memilih aturan ini, alat impor MAKE-056 menirunya saat impor (koordinasi lewat reviewer CR) sehingga
pra-cek di server tujuan bernilai 0.

---

## 6. Registry NIP (setelah approve, bersama pemindahan migration)

Tiga tabel baru ber-FK `nip` → `pegawai` didaftarkan di `App\Libraries\Kepegawaian\Nip\TabelAnakNip` (`NON_FK`,
`JenisRujukanNip::NonFkFkV2`, keberadaan `Ada`, fase 6, pemilik `H-03`), pola baris `firebase_token`/`penyesuaian_tukin`:

| Tabel | FK | Catatan |
|---|---|---|
| `siasn_status_pegawai` | `fk_nip_ssp_to_pegawai` | [K-kode] `siasn_simpeg.dsp.nip` |
| `siasn_upload_arsip` | `fk_nip_sua_to_pegawai` | [K] `simpeg01.siasn_upload_arsip.nip` (tanpa FK di legacy) |
| `double_riwayat_kp` | `fk_nip_drkp_to_pegawai` | [K] `simpeg01.double_riwayat_kp.nip` |

`NipCascadeService::FASE` 5 → **6**; `NipReferenceRegistryTest`/`TabelAnakNipTest`/`NipCascadeTest` diperbarui aditif.
`firebase_token.nip` sudah terdaftar (DBV-022) dan tetap ikut koreksi NIP (kolom kini NULL-able; NULL dilewati).

---

## 7. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-027-audit-integrasi-keluar.sql` (hanya SELECT, hanya jumlah; hasil tidak
di-commit).

| ID | Kelas | Isi |
|---|---|---|
| A1 | STOP | `pegawai.nip` VARCHAR(30) unicode_ci, `pengguna.id_pengguna` INT UNSIGNED |
| A2 | INFO | Tabel baru belum ada |
| A3 | WAJIB_0 | Kunci UNIQUE `riwayat_skp_periodik` ganda (kunci + baris) |
| A3b | INFO | Rincian A3 per himpunan status |
| A4 | INFO | Baris `periode_id`/`skp_id` NULL |
| A5 | INFO | NIP > 1 baris SKP aktif per periode (kunci berbeda; Tukin memakai id terkecil) |
| A6 | WAJIB_0 | Token FCM ganda per pemilik (`nip` + token; langkah dedup Bagian 3.7) |
| A6b | WAJIB_0 | Baris token lama tanpa `nip` |
| A7 | STOP | Username akun sistem sudah dipakai |
| B1 | WAJIB_0 | Akun sistem bisa login (status ≠ 0 atau ada password) |
| B1b | INFO | Level & status akun sistem (ikut legacy = 1, DBV-Q10) |
| B2 | WAJIB_0 | Token tanpa `token_hash` |
| B3 | INFO | Token per platform |
| B4 | INFO | Antrean unggah arsip per status |
| B5 | WAJIB_0 | Antrean "diproses" macet > 1 hari |
| B6 | INFO | Pegawai aktif tanpa baris `siasn_status_pegawai` |
| B7 | INFO | Log integrasi 7 hari per layanan × status |
| B8 | WAJIB_0 | Log melewati retensi 180 hari |
| B9 | INFO | Referensi SIASN per jenis & yang belum dipetakan (2 kueri) |

---

## 8. Verifikasi developer (sebelum review DB Validator)

### 8.1 Lingkungan
DB scratch tunggal Jalur B `simpeg_v2_t_fase6b` di instance uji **MySQL 8.0.30** port 3307 (`tools/mysql-test`), grup
`tests` (prefix `t_`), semua migration `main` `02141174`. Draf dijalankan lewat kelasnya (`CreateIntegrasiKeluarFase6::up()
/down()`) dari test sementara bertanda `db-isolasi-penuh` (tidak di-commit; menjadi dasar test skema Bagian 9). Bukan DB dev.

### 8.2 Hasil (03-11-2026) — 7 test, 97 assertion, lolos

| Uji | Hasil |
|---|---|
| `up()` membuat 6 tabel; engine InnoDB, collation tabel & semua kolom string `utf8mb4_unicode_ci` | ✅ |
| `firebase_token`: token web tanpa NIP dengan `id_pengguna` diterima; android dengan NIP diterima | ✅ |
| `firebase_token`: platform 2 tanpa NIP, platform 1 tanpa `id_pengguna`, platform 3 | ✅ ditolak 3819 (CHECK) |
| `firebase_token`: token sama untuk pemilik yang SAMA (web per `id_pengguna`, android per `nip`); `id_pengguna` tidak ada; insert tanpa `platform` | ✅ ditolak 1062 / 1452 / 1364 |
| `firebase_token`: token sama untuk pemilik LAIN (web & android) — DBV-Q8 ikut legacy | ✅ diterima |
| `riwayat_skp_periodik`: kunci sama ditolak; baris `periode_id` NULL ganda tetap boleh | ✅ 1062 / diterima |
| `siasn_status_pegawai` flag 3; NIP tidak ada | ✅ ditolak 3819 / 1452 |
| `siasn_upload_arsip` (`rw_name`,`id_rw`,`id_da`) ganda; status 4 | ✅ ditolak 1062 / 3819 |
| `double_riwayat_kp` NIP ganda; `siasn_referensi` jenis di luar daftar; `integrasi_log` arah 3 / status 0 | ✅ ditolak 1062 / 3819 / 3819 |
| Hapus pegawai yang punya baris SIASN | ✅ ditolak 1451 |
| Akun sistem: role 1 (ikut legacy), `status '0'`, tanpa password; login ditolak (`AuthException`), juga setelah `status` diubah '1' | ✅ |
| Pra-cek token FCM ganda per pemilik: up() berhenti sebelum DDL (pesan jumlah) | ✅ |
| Pra-cek baris token lama tanpa `nip`: up() berhenti sebelum DDL (diuji dengan skema DBV-022 dilonggarkan sementara di test) | ✅ |
| Pra-cek duplikat SKP: up() berhenti sebelum DDL, pesan berisi jumlah tanpa NIP, tabel baru tidak terbentuk | ✅ |
| Gagal di tengah (tabel `siasn_referensi` sudah ada): 5 tabel yang sempat dibuat di-DROP, `firebase_token` tidak berubah | ✅ |
| `down()` → tabel hilang, kolom `firebase_token` & indeks `riwayat_skp_periodik` sama dengan sebelum, akun terhapus; `up()` ulang berhasil | ✅ |
| Seluruh kueri audit (20 SELECT) berjalan tanpa galat sintaks setelah `up()` | ✅ |

### 8.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator)
Mohon jalankan draf (atau DDL Bagian 3) + skrip audit di MariaDB 10.4, terutama: CHECK `chk_firebase_token_pemilik` pada
kolom ber-FK RESTRICT (`nip`, `id_pengguna`), `ALTER COLUMN … DROP DEFAULT`, `SHA2()` di UPDATE, CHECK `IN (…)` string
pada `siasn_referensi`. CI `quality-gate` (MySQL 8.0 & MariaDB 10.4) juga menjalankan test skema setelah migration dipindah.

---

## 9. Rencana test skema (setelah approve)

`tests/database/Integrasi/SkemaIntegrasiKeluarTest.php` (`extends DatabaseTestCase`, `db-isolasi-penuh`): isi test
sementara Bagian 8 (struktur/collation, CHECK 3819/4025, 1062/1451/1452, pra-cek, siklus `down()/up()`, akun sistem tidak
bisa login, kueri audit berjalan), ditambah registry NIP (`NipReferenceRegistryTest`, `TabelAnakNipTest`, `NipCascadeTest`).

---

## 10. Keputusan yang diminta dari DB Validator

| ID | Pertanyaan | Usulan |
|---|---|---|
| DBV-Q1 | Aturan pemilihan bila pra-cek A3 > 0 | Pertahankan baris yang dibaca Tukin (status 1, `hasil_akhir` terisi, id terkecil); lainnya dikeluarkan sebelum migrate; alat impor MAKE-056 meniru (Bagian 5) |
| DBV-Q2 | Kolom & tipe `integrasi_log` (termasuk `id_klien` untuk Jalur A, tanpa FK) dan 4 indeks | Setujui; `id_klien` FK ke tabel klien DBV-026 bisa ditambah Jalur A bila DBV menghendaki |
| DBV-Q3 | `siasn_status_pegawai` tanpa indeks tambahan; `du_lu` DATE | Setujui (volume ±pegawai aktif) |
| DBV-Q4 | `siasn_upload_arsip.id_da` tanpa FK; UNIQUE `rw_name,id_rw,id_da` | Setujui |
| DBV-Q5 | Staging `siasn_simpeg.data_utama` tidak dibuat | Setujui (Bagian 2) |
| DBV-Q6 | `siasn_ref_unor` tanpa FK ke master | Setujui |
| DBV-Q7 | 7 tabel referensi digabung `siasn_referensi` | Setujui; alternatif 7 tabel terpisah nama legacy |
| DBV-Q8 | Kunci unik token FCM | **Diputus Horii 03-11-2026: ikut legacy** — UNIQUE per pemilik (`id_pengguna`,`token_hash`) web dan (`nip`,`token_hash`) android |
| DBV-Q9 | `refresh_date` dipertahankan walau tanpa pembaca | Setujui (paritas, bahan pembersihan) |
| DBV-Q10 | Level akun sistem | **Diputus Horii 03-11-2026: ikut legacy** — `user_level = 1` (padanan `ID_USR_DEVSIMPEG`), risiko diterima dengan mitigasi Bagian 4 |
| DBV-Q10b | Akun sistem opsi (a) baris `pengguna` + seed migration vs opsi (b) konstanta id | Opsi (a) |
| DBV-Q11 | Kolom tabel kerja SIASN berlabel [I] (DDL `siasn_simpeg`/`siasn_upload_arsip`/`double_riwayat_kp` tidak tersedia) | Cocokkan dengan dump D1 bila tersedia di lingkungan DBV |
| DBV-Q12 | Timestamp migration saat dipindah | Lebih baru dari `2026-10-28-100000` dan dari DBV-026 bila masuk lebih dulu |
| DBV-Q13 | Token FCM ganda di lingkungan berisi: pra-cek gagal + dedup manual (Bagian 3.7) atau dedup deterministik di migration (simpan id terbesar) | Pra-cek + dedup manual (perilaku migration tidak menghapus data diam-diam) |

---

## 11. Syarat merge (⛔)

1. DB Validator menyetujui DBV-027 (tanggal + catatan dicatat di dokumen ini dan `06-Integrasi.md`).
2. Migration dipindah ke `app/Database/Migrations/` dengan DDL **tidak berubah** dari yang disetujui (atau perubahan atas
   permintaan DBV tercatat).
3. Registry NIP + test skema hijau; CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4).
4. Merge oleh reviewer CR. B2 MAKE-063 dimulai setelah DBV-027 di `main`.

---

## 12. Checklist DB Validator

- [ ] Nama tabel/kolom/FK/CHECK/indeks (Bagian 3)
- [ ] `firebase_token`: `nip` NULL-able, `id_pengguna` + FK, CHECK pemilik, `token_hash` UNIQUE per pemilik (DBV-Q8, diputus Horii), `refresh_date` (DBV-Q9)
- [ ] UNIQUE `riwayat_skp_periodik` + pra-cek + aturan duplikat (DBV-Q1)
- [ ] Tabel kerja SIASN [I] (DBV-Q3..Q7, Q11)
- [ ] `integrasi_log` (DBV-Q2), retensi 180 hari oleh command
- [ ] Akun sistem integrasi (Bagian 4; level 1 diputus Horii, DBV-Q10) — tidak bisa login
- [ ] Registry NIP fase 6 (Bagian 6)
- [ ] Skrip audit hanya SELECT, hanya jumlah; klasifikasi A/B
- [ ] Verifikasi MariaDB 10.4 (Bagian 8.3)
