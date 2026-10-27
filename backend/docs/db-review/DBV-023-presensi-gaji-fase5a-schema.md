# DB Validator Review — DBV-023 skema Fase 5A Presensi & Slip Gaji (`dh_online`, `gaji_pegawai`, `gaji_pegawai_qr_history`, ENUM `audit_logs.event`)

**Key review:** `DBV-023` (Fase 5A D-01, QASMTASK-076). Judul PR: `[DBV-023] Skema Fase 5A: dh_online, gaji_pegawai,
gaji_pegawai_qr_history, ENUM audit`. Branch `fase5a/dbv-023-skema-presensi-gaji` (dari `main` `6806c38`). Alur PRD 5A
§3.5/§7.1.4: PR `[DBV]`, DB Validator me-review/approve, reviewer CR me-merge setelah syarat M2 (Bagian 12).

**Status:** ✅ **disetujui DB Validator 20-10-2026 (jjoseph48)** — komentar PR #28 atas HEAD `f323ca8`, diverifikasi di
MariaDB 10.4.27. Keputusan DBV-Q1..Q18 di Bagian 9 dan catatan C-1..C-4 di Bagian 14, dicatat sesuai komentar tersebut.
Syarat M2 #2 (DBV-022 di `main` `598a5f3`, 27-10-2026) dan #3 (Q5A-02, ISSUE-049) terpenuhi; **M2 dikerjakan 27-10-2026**
(Bagian 15): migration dipindah ke `app/Database/Migrations/` (DDL tidak berubah), registry NIP Fase 5, test skema. PR
di-merge reviewer CR setelah CI hijau (Bagian 12). Pra-review internal sisi CR sebelumnya: laporan M0 PRD 5A diterima reviewer CR
20-10-2026.

**Penulis:** developer Fase 5A (sesi WS-2), 20-10-2026.

**Asal kebutuhan:** PRD `docs/fase5/PRD_Fase5A_Skema-SlipGaji-Presensi.md` v1.0 (19-10-2026) §7.1, keputusan user
19-10-2026 F5-1, F5-2, F5-4, F5-6, F5-11, F5-12, F5-14, Q5A-01 = opsi (a).

**Rujukan:** `G-03-lokasi-presensi-schema.md` (`lokasi_presensi`, `dm_user_lokasi_presensi`; Bagian 6.1
`user_lokasi_presensi`), `DBV-020-skema-tambahan-fase3.md` (pola dokumen, butir 6 `user_lokasi_presensi` tidak dibuat),
`DBV-019-fk-g02-pegawai-schema.md` (pola migration ditahan `app/Database/MigrationsDitahan/`), `DBV-011-runbook-impor-
master-zona-waktu.md` (aturan zona waktu R1a/R2/R4, runbook impor tunggal), `G-02-jabatan-unit-satker-schema.md`
(`satker.zonasi`). D1 = dump struktur produksi `simpeg01` 01-10-2026 (`simpeg01_struktur_lengkap_20261001.sql`, di luar
repo; rujukan `D1:baris`). SQL-L = berkas SQL Slip Gaji di root salinan legacy HEAD `771f8b4` (di luar repo):
`sql/2026_09_01_slip_gaji_migration.sql`, `sql/2026_09_02_slip_gaji_qr_history.sql`,
`sql/2026_10_08_slip_gaji_uang_makan.sql`. Kode legacy = folder `application/` salinan yang sama (rujukan `berkas:baris`;
belum dicocokkan checksum produksi, AS-09 / Q5A-14).

**Label sumber:** **[K]** ikut DDL D1 / SQL-L / kode legacy (rujukan baris); **[V2]** deviasi v2 yang disengaja (alasan +
keputusan); **[I]** inferensi bila sumber tidak cukup.

| File | Isi |
|---|---|
| `app/Database/Migrations/2026-10-27-100000_CreatePresensiGaji.php` | Migration butir 1–3 (`dh_online`, `gaji_pegawai`, `gaji_pegawai_qr_history`); `down()` = DROP anak dulu. M1: draf di `MigrationsDitahan/`; dipindah di M2 (Bagian 15) |
| `app/Database/Migrations/2026-10-27-100100_AlterAuditLogsEventPresensi.php` | Migration butir 4: ENUM `audit_logs.event` + `warning`, `import`; `down()` fail-closed. M1: draf; dipindah di M2 |
| `docs/db-review/sql/DBV-023-audit-presensi-gaji.sql` | Kueri audit baca-saja data legacy (Bagian 8) |

Draf ditaruh di `MigrationsDitahan/` agar dapat dibaca dan dijalankan DB Validator, tetapi tidak ikut `migrate` di CI
maupun lingkungan mana pun sebelum syarat Bagian 12 terpenuhi (PRD 5A §7.1.4). Di M2 keduanya dipindah ke
`app/Database/Migrations/` dengan timestamp **lebih baru** dari semua migration DBV-022 (Fase 4), lalu registry NIP
dinaikkan (Bagian 6) dan test skema ditambahkan (Bagian 11).

---

## 0. Ringkasan

| # | Butir | Inti | Status D1 |
|---|---|---|---|
| 1 | `dh_online` | Presensi harian per `(nip, tgl)`; D1 [K] + kolom bukti presensi online [V2] F5-12 | Ada di D1 (D1:390-401) |
| 2 | `gaji_pegawai` | Slip gaji per `(nip, bulan, tahun)`; struktur legacy terbaru `771f8b4` (28 kolom GPP/TK + uang makan) [K]; 15 kolom generik D1 dibuang [V2] F5-2 | Ada di D1 dengan struktur lama (D1:1242-1273) |
| 3 | `gaji_pegawai_qr_history` | Riwayat token QR (PDF lama tetap terverifikasi) [K] SQL-L + snapshot verifikasi [V2] | Tidak ada di D1; SQL-L `2026_09_02` |
| 4 | ENUM `audit_logs.event` | + `warning` (mismatch nama–NIP impor), + `import` (ringkasan commit impor) [V2] F5-4 | — (tabel v2) |

**Tidak dibuat** (PRD 5A §7.1.1, F5-12, F5-14, DBV-020 butir 6): `d_dh_online` (arsip trigger), `dh_online_coordinate`
(D1:406-416), `user_geo` (D1:7547-7560), `user_lokasi_presensi` (D1:7595-7605), `absen_real`, `absen_kategori`, tabel
pratinjau/log impor Slip Gaji (F5-3/F5-4). Trigger `dh_online_beDel` (D1:7709-7714) tidak dibawa. Syarat DBV-Q7: tabel
yang tidak dimigrasi dicatat eksplisit "tidak dimigrasi" di runbook impor DBV-011 2.11 (Bagian 14 C-Q7).

---

## 1. Konvensi yang dipakai

| Kode | Konvensi | Rujukan |
|---|---|---|
| K4 | Nama tabel/kolom ikut legacy kecuali keputusan tertulis | PRD 5A §5.2; keputusan 23-09 |
| V1 / ADR-008 | FK ke `pegawai`/master **ON DELETE RESTRICT ON UPDATE RESTRICT** dengan **nama FK legacy**; CASCADE legacy diganti; koreksi NIP oleh aplikasi (`NipCascadeService`) | DBV-012/013; PRD 5A §2.6 |
| Collation | Tabel baru `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | DBV-Q15 |
| Status | Tidak ada tabel master baru di unit ini (status 1/2/10 tidak berlaku) | PRD 5A §2.6 |
| Waktu | Stempel audit (`created_at`, `updated_at`, `viewed_at`) = **UTC diisi aplikasi**; default DB `CURRENT_TIMESTAMP` hanya cadangan (G2, AS-12). Tanggal/jam bisnis presensi = **jam dinding lokal satker** (Bagian 5) | DBV-011 Bagian 3; G-01 8.4 #3 |
| Trigger | Trigger legacy tidak dibawa; logika pindah ke service + `audit_logs` | PRD 5A §2.6 |
| CHECK | CHECK domain [V2] bernama `chk_<tabel>_<arti>` (pola `chk_absen_ijin_*`, `chk_hari_libur_rentang`) | Migration `2026-09-30-130500`, `2026-09-25-100000` |
| Migration | Migration yang sudah ada di `main` tidak diedit; koreksi lewat migration baru | AGENTS.md §2 |
| Kolom [V2] | Diletakkan paling akhir agar urutan kolom [K] tidak bergeser (pola G-03 `dm_user_lokasi_presensi.status`) | `2026-10-01-000001_CreateLokasiPresensi` |

---

## 2. Butir 1 — `dh_online`

### 2.1 Sumber [K] D1:390-401

```sql
CREATE TABLE IF NOT EXISTS `dh_online` (
  `nip` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tgl` date NOT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`nip`,`tgl`),
  CONSTRAINT `fk_nip_dh_to_pegawai` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Penulis legacy: presensi online `po_in`/`po_out` (`controllers/hr/services/Local.php:1737-1882`, `:1884-2048`) dan
presensi manual akun khusus (`libraries/hr/L_presensi.php:3304-3478`). Koordinat legacy dicatat terpisah di
`dh_online_coordinate`/`user_geo` lewat `record_geolocation` (`Local.php:4071-4123`), tidak terkait baris presensi.

### 2.2 Skema v2 usulan

```sql
CREATE TABLE `dh_online` (
  `nip` VARCHAR(30) NOT NULL,
  `tgl` DATE NOT NULL COMMENT 'Tanggal lokal satker (WIB + satker.zonasi), bukan UTC',
  `jam_masuk` TIME NULL DEFAULT NULL COMMENT 'Jam dinding lokal satker',
  `jam_pulang` TIME NULL DEFAULT NULL COMMENT 'Jam dinding lokal satker',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
  `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
  `lat_masuk` DECIMAL(10,7) NULL, `lng_masuk` DECIMAL(10,7) NULL, `akurasi_masuk` DECIMAL(10,2) NULL,
  `id_lokasi_masuk` INT NULL,
  `lat_pulang` DECIMAL(10,7) NULL, `lng_pulang` DECIMAL(10,7) NULL, `akurasi_pulang` DECIMAL(10,2) NULL,
  `id_lokasi_pulang` INT NULL,
  `sumber` TINYINT NULL COMMENT '1: Online, 2: Manual (melekat), NULL: impor legacy',
  `created_by` INT NULL COMMENT 'id_pengguna pembuat',
  PRIMARY KEY (`nip`, `tgl`),
  KEY `tgl` (`tgl`),
  KEY `fk_id_lokasi_masuk_dh_to_lp` (`id_lokasi_masuk`),
  KEY `fk_id_lokasi_pulang_dh_to_lp` (`id_lokasi_pulang`),
  CONSTRAINT `fk_nip_dh_to_pegawai` FOREIGN KEY (`nip`) REFERENCES `pegawai` (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_id_lokasi_masuk_dh_to_lp` FOREIGN KEY (`id_lokasi_masuk`) REFERENCES `lokasi_presensi` (`id_lokasi_presensi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_id_lokasi_pulang_dh_to_lp` FOREIGN KEY (`id_lokasi_pulang`) REFERENCES `lokasi_presensi` (`id_lokasi_presensi`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_dh_online_sumber` CHECK (`sumber` IN (1, 2)),
  CONSTRAINT `chk_dh_online_jam` CHECK (`jam_masuk` IS NOT NULL OR `jam_pulang` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

COMMENT lengkap setiap kolom ada di draf migration (`CreatePresensiGaji::statements()`).

### 2.3 Deviasi per kolom/index/FK/CHECK

| Elemen | v2 | Label | Alasan |
|---|---|---|---|
| `nip`, `tgl`, `jam_masuk`, `jam_pulang`, `created_at`, `updated_at`, `updated_by` | sama dengan D1 (tipe, NULL, default, urutan) | [K] | D1:391-397. COMMENT ditambah (semantik waktu, Bagian 5) |
| PK `(nip, tgl)` | dipertahankan | [K] D1:398 | Memenuhi DoD "UNIQUE `(nip, tgl)` eksplisit di DB" (PRD 5A §5.3); INSERT ganda → 1062. Surrogate PK tidak dipakai (DBV-Q1) |
| FK `fk_nip_dh_to_pegawai` | RESTRICT/RESTRICT | [V2] V1 | D1 CASCADE/CASCADE. Koreksi NIP lewat `NipCascadeService` (Bagian 6); hapus pegawai ber-presensi ditolak 1451 |
| `lat_masuk`, `lng_masuk`, `lat_pulang`, `lng_pulang` DECIMAL(10,7) | baru | [V2] F5-12 | Koordinat kiriman perangkat saat presensi online (bukti geofence server). 7 desimal ≈ 1 cm; DECIMAL(10,7) memuat ±180. NULL untuk manual/impor/tanpa GPS |
| `akurasi_masuk`, `akurasi_pulang` DECIMAL(10,2) | baru | [V2] F5-12 | Meter (`coords.accuracy`); dicatat, tidak dipakai menolak (F5-10) |
| `id_lokasi_masuk`, `id_lokasi_pulang` INT + FK ke `lokasi_presensi` RESTRICT | baru | [V2] F5-12 | Lokasi yang mengesahkan presensi (terdekat dalam radius). Tipe = PK `lokasi_presensi.id_lokasi_presensi` INT (G-03). RESTRICT aman karena lokasi dihapus lunak (status 10), tidak fisik (G-03). Nama FK pola legacy `fk_<kolom>_<alias anak>_to_<alias induk>` (DBV-Q6) |
| `sumber` TINYINT NULL + CHECK 1/2 | baru | [V2] F5-12 | 1 = online; 2 = manual (baris pernah ditambah/diubah lewat D-03, melekat); NULL = impor legacy (sumber tidak diketahui) |
| `created_by` INT NULL | baru | [V2] | Pelaku pembuat (`id_pengguna`); legacy insert tanpa `created_by` (`L_presensi.php:3476`) (DBV-Q3) |
| KEY `tgl` | baru | [V2] | Daftar/rekap admin per tanggal (5B rekap per periode); PK `(nip, tgl)` sudah melayani kueri per pegawai (DBV-Q1) |
| KEY `fk_id_lokasi_*` | baru | [V2] | Index wajib FK (dinamai sama dengan FK, pola DBV-020 1.3) |
| CHECK `chk_dh_online_jam` | baru | [V2] usulan | Baris tanpa kedua jam tidak bermakna (legacy manual mewajibkan minimal satu jam, `L_presensi.php:3434-3466`). **Dipasang** (DBV-Q5 diputus DB Validator 20-10-2026): bila kueri audit A2 > 0, baris tanpa kedua jam **dibersihkan saat impor**, CHECK tidak dibuang |

### 2.4 Trigger legacy

`dh_online_beDel` (BEFORE DELETE, salin baris ke `d_dh_online`, D1:7709-7714) **tidak dibawa** (F5-14). Penggantinya:
setiap hapus presensi manual menulis `audit_logs` event `delete` dengan `before_json` = baris lengkap di transaksi yang
sama (PRD 5A §7.6.3). Tabel `d_dh_online` tidak dibuat; isinya tidak diimpor (Q5A-15, DBV-Q4).

### 2.5 Risiko impor

- `created_at`/`updated_at` legacy = default DB server legacy (WIB) → konversi R1a ke UTC; `tgl`/`jam_*` = DATE/TIME
  tanpa konversi (R2) (Bagian 5).
- Kolom [V2] F5-12 diisi NULL (koordinat, akurasi, lokasi, `sumber`, `created_by`) — data `dh_online_coordinate`/`user_geo`
  tidak diimpor (F5-12).
- FK RESTRICT ke `pegawai`: NIP yatim (kueri A1) menghentikan impor dengan `FOREIGN_KEY_CHECKS = 1`; D1 ber-FK CASCADE
  sehingga seharusnya 0.
- CHECK `chk_dh_online_jam` menolak baris kedua jam NULL (kueri A2); baris seperti itu dibersihkan saat impor (DBV-Q5).

---

## 3. Butir 2 — `gaji_pegawai`

### 3.1 Sumber [K]

- **D1:1242-1273** (struktur produksi 01-10-2026): `id` BIGINT UNSIGNED AI, `nip` VARCHAR(30), `bulan` TINYINT UNSIGNED,
  `tahun` SMALLINT UNSIGNED, **15 kolom generik** (`gaji_pokok`, `tunj_istri_suami`, `tunj_anak`, `tunj_umum`,
  `tunj_struktural`, `tunj_fungsional`, `tunj_lainlain`, `pembulatan`, `tunj_beras`, `tunj_khusus_pajak`,
  `pot_iuran_wajib`, `pot_bpjs_kesehatan`, `pot_pph`, `pot_taperum`, `tunj_kinerja`), `uang_makan`, `viewed_at`,
  `viewed_ip`, `qr_token`, `created_at`, `updated_at`; `UNIQUE uk_nip_bulan_tahun (nip, bulan, tahun)`, `KEY idx_nip`,
  `KEY idx_bulan_tahun`, `KEY idx_qr_token`; tanpa FK; COMMENT tabel "Data rincian slip gaji bulanan pegawai SIMPEG".
- **SQL-L `2026_09_01…:51-79`** [K `771f8b4`]: 28 kolom GPP/TK DECIMAL(14,2) NOT NULL DEFAULT 0.00 setelah `tahun`
  (komentar "GPP: …" / "TK: …"); kolom generik lama dibiarkan (`:7-10`).
- **SQL-L `2026_10_08…:27-29`** [K]: `uang_makan_hari` SMALLINT UNSIGNED NULL, `uang_makan_periode` VARCHAR(7) NULL
  (format `m-YYYY`, mis. `9-2026`) setelah `qr_token`; `uang_makan` (sudah ada di D1) dipakai sebagai snapshot (`:12-17`).
- Kode `771f8b4`: buka slip `Lsl_gaji.php:157-220` (tulis `viewed_at`/`viewed_ip`/`qr_token` + snapshot uang makan +
  baris riwayat token), buka ulang `:850-881` (kosongkan `viewed_at`/`viewed_ip`/`qr_token`, snapshot uang makan
  dipertahankan), verifikasi `:650-656` (lookup lewat `gaji_pegawai_qr_history`).

### 3.2 Skema v2 usulan

```sql
CREATE TABLE `gaji_pegawai` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nip` VARCHAR(30) NOT NULL COMMENT 'NIP pegawai (tanpa FK; tidak ikut Ubah NIP)',
  `bulan` TINYINT UNSIGNED NOT NULL COMMENT 'Bulan gaji (1-12)',
  `tahun` SMALLINT UNSIGNED NOT NULL COMMENT 'Tahun gaji (misal 2026)',
  -- 28 kolom: gjpokok, tjistri, tjanak, tjupns, tjstruk, tjfungs, tjdaerah, tjpencil, tjlain, tjkompen, pembul, tjberas,
  --           tjpph, potpfkbul, potpfk2, potpfk10, potpph, potswrum, potkelbtj, potlain, pottabrum, bersih_gpp,
  --           kotor, potongan, bersih_tk, pajak, tunj_pajak, bersih_2
  --           DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'GPP: …' / 'TK: …' (komentar SQL-L apa adanya)
  `uang_makan` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot uang makan presensi bulan N-1, dihitung saat slip dibuka',
  `viewed_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat pertama kali dibuka pegawai (NULL = belum dibuka)',
  `viewed_ip` VARCHAR(50) NULL DEFAULT NULL,
  `qr_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Hash SHA-256 heks token QR aktif; NULL = tidak ada token aktif',
  `uang_makan_hari` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `uang_makan_periode` VARCHAR(7) NULL DEFAULT NULL COMMENT 'format m-YYYY (mis. 9-2026)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT NULL, `updated_by` INT NULL,
  `qr_token_enkripsi` VARBINARY(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nip_bulan_tahun` (`nip`, `bulan`, `tahun`),
  UNIQUE KEY `uk_qr_token` (`qr_token`),
  KEY `idx_nip` (`nip`),
  KEY `idx_bulan_tahun` (`bulan`, `tahun`),
  CONSTRAINT `chk_gaji_pegawai_bulan` CHECK (`bulan` BETWEEN 1 AND 12),
  CONSTRAINT `chk_gaji_pegawai_tahun` CHECK (`tahun` BETWEEN 1900 AND 2100),
  CONSTRAINT `chk_gaji_pegawai_nominal` CHECK (/* 28 kolom >= 0 */ AND `uang_makan` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Data rincian slip gaji bulanan pegawai SIMPEG';
```

Urutan 28 kolom = `App\Libraries\Presensi\SlipGaji\KolomGaji` (`KolomGaji.php:25-60`) = SQL-L; draf migration menurunkan
daftar kolom dan CHECK dari satu konstanta `CreatePresensiGaji::KOLOM_NOMINAL` (test skema M2 akan mencocokkannya dengan
`KolomGaji`).

### 3.3 Deviasi

| Elemen | v2 | Label | Alasan |
|---|---|---|---|
| `id`, `nip`, `bulan`, `tahun`, `uang_makan`, `viewed_at`, `viewed_ip`, `qr_token`, `created_at`, `updated_at` | tipe/NULL/default D1 | [K] | D1:1243-1269. COMMENT `viewed_at`/`qr_token`/`uang_makan` diperbarui sesuai arti v2 |
| 28 kolom GPP/TK | = SQL-L | [K] `771f8b4` | F5-2; dipakai `PratinjauImpor`/`RingkasanSlip` |
| `uang_makan_hari`, `uang_makan_periode` | = SQL-L | [K] `771f8b4` | Snapshot uang makan N−1 (F5-1); format `m-YYYY` legacy (`Lsl_gaji.php:192-195`) dipertahankan |
| 15 kolom generik D1 | **dibuang** | [V2] F5-2 | Tidak dipakai kode Slip Gaji `771f8b4`. Guard B2 (STOP) **tidak berlaku**: produksi belum memuat data `gaji_pegawai` (ISSUE-049, Bagian 7.2). Pengecekan ulang produksi di kartu DEPLOY-002.1 |
| Tanpa FK `nip` | dipertahankan | [K] | Registry `NonFkSlipGaji` (`TabelAnakNip.php:133-137`): slip lama tidak ikut Ubah NIP |
| `qr_token` isi | hash SHA-256 heks (64) dari token aktif | [V2] F5-6 | Nama/tipe [K]; token mentah tidak disimpan di kolom ini |
| `UNIQUE uk_qr_token` menggantikan `KEY idx_qr_token` | UNIQUE (NULL ganda boleh) | [V2] usulan | Token acak 256-bit → hash unik; UNIQUE menjaga satu baris per token aktif (DBV-Q9) |
| `KEY idx_nip` | dipertahankan | [K] | Tertutup prefiks `uk_nip_bulan_tahun`; boleh dibuang bila DB Validator memilih (PRD 5A §7.1.1). Usulan developer: dipertahankan (= D1, biaya kecil) |
| `created_by`, `updated_by` INT NULL | baru | [V2] | Pelaku impor/koreksi (`id_pengguna`) |
| `qr_token_enkripsi` VARBINARY(255) NULL | baru | [V2] Q5A-01 (a) | Token aktif terenkripsi `CodeIgniter\Encryption` (driver OpenSSL, AES-256-CTR + HMAC-SHA512, `rawData = true` di `Config\Encryption`) untuk mencetak ulang QR di PDF. Keluaran mentah = 16 IV + 64 ciphertext + 64 HMAC = 144 byte, jadi VARBINARY(255) cukup tanpa base64; dikosongkan saat buka ulang. Lookup verifikasi tetap via hash (DBV-Q17). Alternatif: TEXT (PRD) atau VARCHAR base64 |
| CHECK `bulan` 1..12, `tahun` 1900..2100, nominal ≥ 0 | baru | [V2] usulan | Aplikasi sudah menolak bulan/nominal tidak sah (`PratinjauImpor`, `NominalGaji`); CHECK = jaring pengaman (DBV-Q10). Satu CHECK nominal untuk 29 kolom |

### 3.4 Risiko impor

- **Struktur produksi berbeda dari `771f8b4`.** D1 masih struktur lama (tanpa 28 kolom); SQL-L `2026_09_01` dinyatakan
  dijalankan di duplikat struktur produksi dengan 0 baris (`:14-16`). Volume & isi produksi = hasil kartu DBA (Q5A-02,
  Bagian 7.2).
- `viewed_at` legacy ditulis string WIB (`Lsl_gaji.php:166`, `getWibDateTimeString`) → konversi R1a ke UTC saat impor.
- `qr_token` legacy = token mentah → di-hash SHA-256 saat impor; `qr_token_enkripsi` diisi hasil enkripsi token mentah
  yang sama (agar PDF dapat dicetak ulang dengan QR yang sama) — **diputus DB Validator** (alternatif: NULL, PDF hasil
  cetak ulang tanpa QR sampai dibuka ulang) (DBV-Q17).
- Token/PDF lingkungan dev legacy tidak diimpor (AS-10).

---

## 4. Butir 3 — `gaji_pegawai_qr_history`

### 4.1 Sumber [K] SQL-L `2026_09_02…:28-40`

```sql
CREATE TABLE IF NOT EXISTS `gaji_pegawai_qr_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_gaji_pegawai` BIGINT UNSIGNED NOT NULL,
  `qr_token` VARCHAR(64) NOT NULL,
  `viewed_at` DATETIME NOT NULL,
  `viewed_ip` VARCHAR(50) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qr_token` (`qr_token`),
  KEY `idx_id_gaji_pegawai` (`id_gaji_pegawai`),
  CONSTRAINT `fk_gpqh_id_gaji_pegawai` FOREIGN KEY (`id_gaji_pegawai`) REFERENCES `gaji_pegawai` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Riwayat setiap token QR …';
```

Tidak ada di D1. Penulis legacy: `Lsl_gaji.php:208-217` (satu baris per buka); pembaca: verifikasi `:650-656`.

### 4.2 Skema v2 usulan & deviasi

| Elemen | v2 | Label | Alasan |
|---|---|---|---|
| `id`, `id_gaji_pegawai`, `viewed_at`, `viewed_ip`, `created_at`, COMMENT tabel | = SQL-L | [K] | — |
| `qr_token` | VARCHAR(64) NOT NULL, isi hash SHA-256 | [K] nama/tipe, [V2] isi | F5-6 |
| `UNIQUE uk_gpqh_qr_token` menggantikan `KEY idx_qr_token` | UNIQUE | [V2] | Satu baris per token; backfill SQL-L yang dijalankan dua kali menggandakan token (`:22-25`) → kueri audit C1 (DBV-Q11) |
| FK `fk_gpqh_id_gaji_pegawai` | RESTRICT/RESTRICT | [V2] V1 | SQL-L CASCADE. Slip tidak dihapus fisik di v2 (tidak ada endpoint hapus slip, PRD 5A §6.2), sehingga RESTRICT menjaga riwayat verifikasi PDF yang sudah beredar (DBV-Q11). **Bukan "FK nip"** (P5-47) |
| `snapshot_satker` VARCHAR(255) NULL | baru | [V2] AS-06 | Nama satker jabatan pegawai saat token terbit; verifikasi publik menampilkan data saat terbit, bukan data terkini (PRD 5A §7.4.3) |
| `snapshot_nama` VARCHAR(255) NULL | baru | [V2] AS-06 | Nama **tersamar** saat terbit (huruf pertama + `***` per kata, gelar dibuang, Q5A-08); nama utuh tidak disimpan di tabel ini |

Tidak punya kolom NIP → tidak masuk registry NIP (Bagian 6).

### 4.3 Risiko impor

Tabel tidak ada di D1. Bila hasil kartu DBA menunjukkan tabel ini ada di produksi: impor apa adanya dengan token di-hash,
`viewed_at` R1a, snapshot diisi dari data pegawai saat impor [I] (PRD 5A §7.4.3). Bila tidak ada: backfill dari
`gaji_pegawai` yang `viewed_at` & `qr_token` terisi (pola SQL-L `2026_09_02…:45-48`), token di-hash, snapshot [I].

---

## 5. Butir 4 — ENUM `audit_logs.event`

| | Nilai |
|---|---|
| Sekarang di `main` (`2026-09-17-000004_AlterAuditLogsEventAddAuth`, kolasi `2026-09-25-130000_AlterAuthKeUnicodeCi`) | `create`, `update`, `delete`, `login`, `logout` |
| Usulan DBV-023 [V2] F5-4 | + `warning` (catatan non-mutasi: ketidakcocokan nama–NIP saat pratinjau impor Slip Gaji, sekali per kunci per pratinjau), + `import` (ringkasan satu commit impor Slip Gaji, tanpa nominal) |

- `up()` = `MODIFY event ENUM(<lama>, 'warning', 'import') NOT NULL` (lewat `forge->modifyColumn`, kolasi kolom tetap
  `utf8mb4_unicode_ci` = default tabel; diverifikasi Bagian 10).
- `down()` **fail-closed**: bila masih ada baris `warning`/`import`, rollback **ditolak** (exception) tanpa menghapus baris
  audit. Ini **sengaja berbeda** dari pola `2026-09-17-000004::down()` yang menghapus baris `login`/`logout` sebelum
  mengecilkan ENUM (keputusan reviewer CR 20-10-2026; PRD 5A §7.1.1 butir 4).
- Interaksi DBV-022: draf DBV-022 (branch `fase4/dbv-022-skema-layanan` `192f006`) tidak mengubah ENUM `event`. Bila versi
  final DBV-022 mengubahnya, `AlterAuditLogsEventPresensi::NILAI_LAMA` diganti nilai ENUM terkini di `main` (gabungan) dan
  timestamp migration ini lebih baru (PRD 5A §3.3).
- Interaksi rollback lama: `AlterAuthKeUnicodeCi::down()` memodifikasi `event` dengan daftar 5 nilai eksplisit; urutan
  batch menjamin migration ini di-rollback lebih dulu (dan menolak bila ada baris bernilai baru).
- Bila DB Validator menolak nilai baru: mismatch dicatat dengan event yang DB Validator tetapkan; fallback ke log berkas
  saja **dilarang** (PRD 5A §7.1.1 butir 4).

---

## 6. Registry NIP (PRD 5A §3.4)

| Kolom | Jenis registry | Fase | Dampak |
|---|---|---|---|
| `dh_online.nip` | FK legacy `fk_nip_dh_to_pegawai` (sudah terdaftar `TabelAnakNip::FK_LEGACY`, `TabelAnakNip.php:47`, keberadaan `Ada`, fase 5) | 5 | Dikoreksi `NipCascadeService` (`UPDATE … WHERE nip = lama` sebelum baris `pegawai` lama dihapus). FK RESTRICT = jaring pengaman |
| `gaji_pegawai.nip` | `NonFkSlipGaji` (`TabelAnakNip.php:133-137`, fase 5) | 5 | Tidak dikoreksi [K]: slip lama tetap ber-NIP lama |
| `gaji_pegawai_qr_history` | — (tanpa kolom NIP) | — | Tidak masuk registry |

Perubahan kode registry **di M2** (bukan M1), setelah DBV-022 ada di `main`, di commit migration DBV-023:
`NipCascadeService::FASE` 3 → **5**; `NipCascadeTest` memakai `TabelAnakNip::sampaiFase(NipCascadeService::FASE)`
(sekali, commit tersendiri, kecuali Fase 4 sudah mengubahnya); hitungan `TabelAnakNipTest` diperbarui hanya untuk entri
5A. Pemeriksaan M0: entri fase 5 di registry hanya dua kolom di atas, jadi `sampaiFase(5)` tidak memunculkan tabel lain
yang belum ada di v2. Versi migration untuk `NipReferenceRegistryTest::REGISTRY` = timestamp migration M2.

**Pelaksanaan M2 (27-10-2026):** Fase 4 (DBV-022) sudah menaikkan `FASE` ke 4 dan mengganti literal di `NipCascadeTest`,
jadi M2 cukup `FASE` 4 → 5. "Tidak dikoreksi" untuk `gaji_pegawai.nip` ditegakkan lewat `NipCascadeService::TIDAK_DIARAHKAN`
(tanpa itu, kolom non-FK registry ikut di-UPDATE). Rincian di Bagian 15.

---

## 7. Impor (J1.07; bahan runbook impor DBV-011)

### 7.1 Urutan & aturan

| Urutan | Tabel | Aturan |
|---|---|---|
| Tier 3 (setelah `pegawai`, `lokasi_presensi`) | `dh_online` | Salin `nip, tgl, jam_masuk, jam_pulang, created_at (R1a → UTC), updated_at (R1a), updated_by`; kolom [V2] NULL (`sumber` NULL = impor). Audit A1, A4 = 0 lebih dulu; baris A2 (kedua jam NULL) dibersihkan (tidak diimpor) di transformasi dan dicatat di log impor (DBV-Q5). `d_dh_online`, `dh_online_coordinate`, `user_geo` **tidak diimpor** |
| setelah `pegawai` (tanpa FK; urutan bebas) | `gaji_pegawai` | Per ISSUE-049 (Bagian 7.2) produksi saat ini tidak punya histori → tidak ada baris untuk diimpor; isi v2 datang dari unggah Excel. Aturan berikut tetap berlaku seandainya kelak ada data (pengecekan ulang di DEPLOY-002.1): **semua baris produksi** dimigrasi (F5-2). Kolom = irisan D1 ∩ v2; 28 kolom GPP/TK diisi dari kolom produksi bila ada (hasil Q5A-02), 0 bila tidak ada; `viewed_at` R1a; `qr_token` = SHA-256(token mentah); `qr_token_enkripsi` sesuai keputusan DBV-Q17. B2 (STOP) tidak berlaku per ISSUE-049; bila DEPLOY-002.1 kelak menemukan data, B2 dijalankan ulang sebelum impor. `id` disalin apa adanya (counter AUTO_INCREMENT disalin, pola DBV-011) |
| setelah `gaji_pegawai` | `gaji_pegawai_qr_history` | Impor apa adanya bila tabel ada di produksi, token di-hash; bila tidak: backfill dari `gaji_pegawai` (`viewed_at` & `qr_token` terisi). Snapshot satker/nama diisi dari data pegawai saat impor [I] |

Semua sesi impor diawali `SET SESSION time_zone = '+00:00'` + strict mode (DBV-011 R4).

### 7.2 Hasil kartu DBA (Q5A-02, ISSUE-049)

✅ **Ditutup keputusan user 20-10-2026 (ISSUE-049)**, diteruskan reviewer CR:

- Fitur Slip Gaji legacy **belum masuk database produksi** (deployment produksi masih ditahan), jadi histori
  `gaji_pegawai` produksi saat ini **tidak ada** (volume histori = 0). F5-2 (semua baris dimigrasi) tetap berlaku
  seandainya kelak ada data.
- Berkas `import_slip_gaji_uji_2026-01_s.d_09.xlsx` berisi **data fiktif** (1 NIP, Januari–September 2026, sheet GPP + TK,
  28 kolom) — bukan data produksi. Isi `gaji_pegawai` v2 datang dari unggah Excel (D-10); uang makan dihitung dari
  presensi.
- `gaji_pegawai_qr_history` menjaga QR lama tetap valid — perbaikan bug legacy (reset admin membuat QR lama tidak valid).
- **B2 (STOP) tidak berlaku.** DBV-Q5/A2 sudah diputus DB Validator: baris tanpa jam dibersihkan saat impor; CHECK
  `chk_dh_online_jam` tetap dipasang.
- Pengecekan ulang produksi dipindah ke kartu **DEPLOY-002.1**.

Dengan ini DoD "keputusan volume histori `gaji_pegawai` final" (AC-D01.5) terpenuhi dan syarat merge M2 #3 tertutup.

---

## 8. Kueri audit baca-saja

Berkas `docs/db-review/sql/DBV-023-audit-presensi-gaji.sql` (hanya SELECT; dijalankan di salinan dump, bukan produksi
yang melayani aplikasi; hasil yang memuat NIP/nominal tidak dicommit):

| # | Kueri | Kelas |
|---|---|---|
| P0 | Prasyarat bentuk legacy: kolom `gaji_pegawai.gaji_pokok` ada di `information_schema` (C-2) | **BERHENTI** bila tidak ada — skrip dijalankan di DB yang salah (mis. DB v2); hasil kueri lain tidak bermakna |
| A1 | `dh_online` NIP yatim terhadap `pegawai` | WAJIB_0 |
| A2 | `dh_online` kedua jam NULL | WAJIB_0 setelah transformasi: baris dibersihkan saat impor (DBV-Q5), CHECK tetap dipasang |
| A3 | `dh_online` `jam_masuk > jam_pulang` | INFO |
| A4 | `dh_online` tanggal setelah tanggal dump (+1 hari zonasi) | WAJIB_0 |
| A5 | `dh_online` volume & rentang | INFO |
| B1 | `gaji_pegawai` `COUNT(*)` + distribusi `(tahun, bulan)` | INFO (AC-D01.5) |
| B2 | `gaji_pegawai` berisi nilai di 15 kolom generik yang dibuang | **STOP** — tidak berlaku per ISSUE-049 (produksi tanpa data); dijalankan ulang bila DEPLOY-002.1 menemukan data |
| B3 | `gaji_pegawai` kunci `(nip, bulan, tahun)` ganda | WAJIB_0 |
| B4 | `gaji_pegawai` bulan/tahun di luar domain CHECK | WAJIB_0 |
| B5 | `gaji_pegawai` nominal negatif (`uang_makan`; 28 kolom bila ada) | WAJIB_0 |
| B6 | `gaji_pegawai.qr_token` non-NULL ganda | WAJIB_0 |
| B7 | `viewed_at`/`qr_token` tidak berpasangan | INFO |
| C0–C2 | `gaji_pegawai_qr_history` (bila ada): keberadaan, token ganda, `id_gaji_pegawai` yatim | INFO / WAJIB_0 |
| D1–D2 | Collation kolom; NIP > 30 karakter atau berspasi tepi | INFO / WAJIB_0 |

Sintaks skrip diverifikasi di DB scratch berisi DDL D1 apa adanya (Bagian 10). Skrip digabung ke runbook impor DBV-011
(langkah audit pra-impor) saat M2.

---

## 9. Keputusan yang diminta dari DB Validator

Kolom "Keputusan" diisi dari komentar persetujuan DB Validator (jjoseph48, PR #28, 20-10-2026).

| # | Pertanyaan | Usulan developer | Keputusan |
|---|---|---|---|
| DBV-Q1 | `dh_online` PK `(nip, tgl)` [K] vs surrogate + UNIQUE | PK komposit [K] (memenuhi DoD); + KEY `tgl` | ✅ Setuju PK komposit `(nip, tgl)` [K] + KEY `tgl`; terverifikasi menolak baris ganda per hari (1062). Konsekuensi koreksi NIP: C-3 |
| DBV-Q2 | FK `dh_online.nip` | RESTRICT + `NipCascadeService` (terjawab K4/ADR-008) | — terjawab K4/ADR-008; FK RESTRICT terverifikasi terpasang |
| DBV-Q3 | `created_by` di `dh_online` (`sumber` terjawab F5-12) | Tambah INT NULL | ✅ Setuju `created_by` INT NULL: NULL untuk baris impor/otomatis, terisi untuk input manual |
| DBV-Q4 | Arsip `d_dh_online` + trigger | Tidak dibuat, tidak diimpor; diganti `audit_logs` (terjawab F5-14; Q5A-15) | — terjawab F5-14; setuju arsip `d_dh_online` + trigger tidak dibawa, `audit_logs` memadai |
| DBV-Q5 | CHECK `chk_dh_online_jam` (minimal satu jam) | Pasang bila audit A2 = 0; bila > 0, DB Validator memilih: buang CHECK atau bersihkan baris saat impor | ✅ Setuju pasang CHECK; bila A2 > 0 dipilih **bersihkan baris saat impor**, bukan membuang CHECK. Final (ISSUE-049, 20-10-2026): baris tanpa jam dibersihkan saat impor, CHECK tetap dipasang |
| DBV-Q6 | Tipe kolom koordinat/akurasi & FK lokasi | DECIMAL(10,7)/(10,2); `id_lokasi_*` INT + FK RESTRICT `fk_id_lokasi_masuk_dh_to_lp`/`…_pulang_…` | ✅ Setuju; terverifikasi. `decimal(10,7)` cukup untuk lintang/bujur dan lebih aman daripada VARCHAR (pelajaran G-03) |
| DBV-Q7 | `dh_online_coordinate`, `absen_real`, `absen_kategori` | Tidak dibuat/diimpor (terjawab F5-12) — konfirmasi ke Mapping | ✅ Setuju tidak dibuat/diimpor, **dengan syarat** dicatat eksplisit "tidak dimigrasi" di Mapping Migrasi (bukan hanya di dokumen DBV ini) → runbook DBV-011 2.11; Mapping Migrasi (docx di luar repo) diteruskan reviewer CR ke user |
| DBV-Q8 | 15 kolom generik `gaji_pegawai` | Dibuang (terjawab F5-2), kecuali temuan B2 > 0 | — terjawab F5-2, dengan pengecualian temuan B2 bertanda STOP; B2 tidak berlaku per ISSUE-049 (produksi tanpa data `gaji_pegawai`; cek ulang DEPLOY-002.1) |
| DBV-Q9 | `gaji_pegawai.qr_token` UNIQUE | UNIQUE `uk_qr_token` menggantikan `idx_qr_token`; `idx_nip` dipertahankan | ✅ Setuju; terverifikasi banyak NULL diterima, slip tanpa QR tidak saling bentrok |
| DBV-Q10 | CHECK `bulan`, `tahun`, nominal ≥ 0 | Ya: `chk_gaji_pegawai_bulan` 1..12, `_tahun` 1900..2100, `_nominal` (29 kolom ≥ 0) | ✅ Setuju ketiga CHECK; terverifikasi bulan 13, tahun 1800, nominal negatif ditolak 4025 |
| DBV-Q11 | `qr_history` aksi FK & UNIQUE token | FK RESTRICT/RESTRICT + `UNIQUE uk_gpqh_qr_token` | ✅ Setuju; RESTRICT berarti slip yang punya riwayat QR tidak bisa dihapus — memang diinginkan untuk jejak verifikasi |
| DBV-Q12 | Cache resolusi aturan lokasi | Tidak (resolusi dinamis per request, Q5A-04 default) | — terjawab Q5A-04; setuju resolusi dinamis, cache baru dipertimbangkan bila terbukti jadi beban |
| DBV-Q13 | Tabel pratinjau/log impor | Tanpa tabel; log ke `audit_logs` (terjawab F5-3/F5-4) | — terjawab F5-3/F5-4; setuju tanpa tabel pratinjau, log ke `audit_logs` |
| DBV-Q14 | Semantik waktu | Terjawab F5-11 + AS-12 (Bagian 1 dan 2.5, 3.4) | — terjawab F5-11 + AS-12; pastikan konsisten dengan keputusan zona waktu DBV-011 Bagian B yang masih menunggu ISSUE-024 |
| DBV-Q15 | Collation | `utf8mb4_unicode_ci` (terjawab) | — terverifikasi `utf8mb4_unicode_ci` |
| DBV-Q16 | Nilai ENUM `audit_logs.event` baru | `warning`, `import`; `down()` fail-closed (Bagian 5) | ✅ Setuju; `down()` fail-closed terverifikasi (ENUM tetap 7 nilai, tabel utuh, baris audit tidak dihapus) — perbaikan atas pola A-01 |
| DBV-Q17 | Kolom snapshot (`snapshot_satker`, `snapshot_nama` VARCHAR(255) NULL) dan `qr_token_enkripsi` | Snapshot: setuju bentuk di Bagian 4.2. Token terenkripsi: **VARBINARY(255) NULL** (keluaran mentah 144 byte). Impor: isi dari token mentah legacy (alternatif NULL) | ✅ Setuju `VARBINARY(255)` (keluaran mentah tidak melewati konversi charset/kolasi; 144 byte muat) dan kolom snapshot `VARCHAR(255) NULL` |
| DBV-Q18 | Penempatan draf di `MigrationsDitahan/` sampai M2 | Setuju (PRD 5A §7.1.4: draf dibaca DBV, tidak dijalankan) | ✅ Setuju draf ditahan di `MigrationsDitahan/` sampai M2 (preseden DBV-019/DBV-020); terbukti tidak ikut `spark migrate` |

Koreksi setelah approval dilakukan lewat commit baru di PR yang sama (sebelum merge) atau migration baru (sesudah merge).

---

## 10. Verifikasi developer (sebelum review DB Validator)

### 10.1 Lingkungan

MySQL 8.0.30 (instance test lokal port 3307, `tools/mysql-test`), PHP 8.4.2, CodeIgniter 4.7.4. Worktree branch
`fase5a/dbv-023-skema-presensi-gaji` dari `origin/main` `6806c38` (20-10-2026). DB scratch bernama eksplisit:
`simpeg_v2_t_ws2_dbv023` (siklus `spark migrate`, grup `default`) dan `simpeg_v2_t_ws2_dbv023a` (validasi skrip audit,
di-DROP setelah dipakai). `.env` uji tidak di-commit. Tidak ada uji di DB pengembangan bersama, DB legacy, atau
produksi; D1 dan kode legacy hanya dibaca. Untuk siklus migrate, kedua draf **disalin sementara** ke
`app/Database/Migrations/` dengan timestamp `2026-10-20-900000`/`900100` lalu dihapus lagi (tidak di-commit).

### 10.2 Hasil

| Uji | Hasil |
|---|---|
| Pencocokan DDL: D1:390-401 (`dh_online`), D1:1242-1273 (`gaji_pegawai`), SQL-L `2026_09_01:51-79`, `2026_09_02:28-40`, `2026_10_08:27-29` vs `SHOW CREATE TABLE` hasil draf | ✅ kolom [K] (tipe, NULL, default, urutan), PK/UNIQUE/KEY, nama FK sama; deviasi hanya yang dicatat [V2] |
| `migrate --all` (= `main`) lalu draf DBV-023 | ✅ 100 → **103 tabel**, 204 → **208 FK**, CHECK 45 → **50**; ENUM `event` = `create,update,delete,login,logout,warning,import`, kolasi `utf8mb4_unicode_ci` |
| Perilaku constraint (32 kasus, data sintetis, dalam transaksi lalu rollback) | ✅ semua sesuai: `dh_online` PK ganda 1062; NIP yatim 1452; `id_lokasi_masuk` yatim 1452; `sumber = 3` 3819; kedua jam NULL 3819; hapus pegawai/lokasi yang dirujuk 1451; ubah NIP pegawai ber-presensi 1451 (RESTRICT); baris manual & impor (`sumber` 2/NULL tanpa koordinat) sah. `gaji_pegawai`: NIP tanpa pegawai sah (tanpa FK); `(nip,bulan,tahun)` ganda 1062; bulan 0/13, tahun 2101, nominal negatif (`potlain`, `uang_makan`) 3819; `qr_token` hash ganda 1062, NULL ganda sah; `qr_token_enkripsi` 144 byte sah. `qr_history`: token ganda 1062, `id_gaji_pegawai` yatim 1452, hapus slip ber-riwayat 1451. `audit_logs`: `warning`/`import` sah, nilai lain 1265 |
| `down()` ENUM fail-closed | ✅ dengan 1 baris `import`: `migrate:rollback` berhenti dengan pesan "rollback ENUM audit_logs.event ditolak — masih ada 1 baris …", tabel DBV-023 tetap ada, baris audit tidak dihapus. Setelah baris uji dihapus: rollback sukses |
| `migrate:rollback` | ✅ kembali ke 100 tabel / 204 FK / 45 CHECK; ENUM kembali 5 nilai |
| `migrate --all` lagi | ✅ checksum `information_schema` COLUMNS (tipe, NULL, default, kolasi, COMMENT, EXTRA), REFERENTIAL_CONSTRAINTS, STATISTICS, CHECK_CONSTRAINTS **identik** dengan run pertama |
| Skrip `sql/DBV-023-audit-presensi-gaji.sql` di DB scratch berisi DDL D1 apa adanya (`dh_online`, `gaji_pegawai` struktur lama) + data sintetis | ✅ 17 hasil tanpa galat sintaks; bagian bertanda [SQL-L]/C1–C2 dikomentari (kolom/tabel tidak ada di D1) |
| `composer analyse` (PHPStan level 5, termasuk `MigrationsDitahan/`) | ✅ (lihat laporan PR) |
| `composer cs-check` | ✅ (lihat laporan PR) |

**Catatan jumlah CHECK (C-1 DB Validator):** di MariaDB jumlah CHECK +3 karena `json_valid` implisit pada kolom JSON
(`audit_logs.before_json`/`after_json`, `token.claims_json`); angka MariaDB 48→53 = MySQL 45→50. Pertambahan DBV-023
sama di kedua engine (+5); selisih angka absolut bukan perbedaan skema.

### 10.3 Permintaan verifikasi MariaDB 10.4 (lingkungan DB Validator)

1. Salin kedua draf ke `app/Database/Migrations/` dengan timestamp sementara setelah migration terakhir, lalu
   `php spark migrate` → `migrate:rollback` → `migrate`.
2. CHECK constraint ditegakkan (MariaDB ≥ 10.2): `chk_dh_online_sumber`, `chk_dh_online_jam`, `chk_gaji_pegawai_*`
   (kode galat MariaDB 4025).
3. `REFERENTIAL_CONSTRAINTS`: `fk_nip_dh_to_pegawai`, `fk_id_lokasi_*_dh_to_lp`, `fk_gpqh_id_gaji_pegawai` =
   RESTRICT/RESTRICT.
4. `UNIQUE uk_qr_token` menerima beberapa NULL; `VARBINARY(255)` menerima 144 byte.
5. `down()` ENUM menolak saat ada baris `warning`/`import`.

### 10.4 Hasil verifikasi DB Validator (MariaDB 10.4.27, komentar PR #28, 20-10-2026)

Ringkasan dari komentar DB Validator (jjoseph48): migrate bertahap 100 → **103 tabel**, 204 → **208 FK**; ENUM `event`
7 nilai; CHECK **+5 (48 → 53)** — lihat catatan C-1 di atas; `migrate:rollback` kembali 100/204 dan ENUM 5 nilai;
`migrate` ulang identik antar-run; uji perilaku constraint dengan data (1062/1452/4025 sesuai, `sumber` NULL diterima,
`uk_qr_token` banyak NULL diterima); `down()` ENUM fail-closed tanpa rollback separuh jalan; skrip audit hanya-baca
(18 result set). Yang tidak diverifikasi DB Validator: kesetiaan baris-per-baris terhadap D1/SQL-L (dump di luar repo),
skrip audit pada data legacy sungguhan (ISSUE-003), hasil Q5A-02 (ISSUE-049), quality gate penuh (CI hijau `f323ca8`).

---

## 11. Rencana test skema (M2)

`tests/database/Presensi/SkemaPresensiGajiTest.php` (`information_schema` per tabel: kolom, tipe, NULL, default, PK,
index, FK + aksi, kolasi, COMMENT, CHECK; 28 kolom = `KolomGaji`; ketiadaan 15 kolom generik; 1062 `(nip,tgl)` dan
`(nip,bulan,tahun)`; 1452/1451 FK; CHECK; ENUM `event`) dan `MigrasiPresensiGajiSiklusTest.php`
(`#[Group('db-isolasi-penuh')]`: siklus `down()`/`up()` kedua migration, `down()` ENUM fail-closed). Registry:
`NipReferenceRegistryTest`, `NipCascadeTest` (`sampaiFase(NipCascadeService::FASE)`, koreksi NIP memindahkan baris
`dh_online` dan tidak menyentuh `gaji_pegawai`), `TabelAnakNipTest`. AC-D01.1–D01.9 (PRD 5A §7.1.5).

---

## 12. Syarat merge (⛔, PRD 5A §7.1.4)

1. ✅ DB Validator menyetujui DBV-023 (Bagian 9 diisi) — 20-10-2026 (jjoseph48), dicatat di `backend/docs/progress/05-Presensi.md`.
2. ✅ DBV-022 (Fase 4) ada di `main` (`598a5f3`, PR #29, 27-10-2026); `main` digabung ke branch PR #28 lewat merge (bukan
   rebase, arahan reviewer CR), migration DBV-023 bertimestamp `2026-10-27-100000/100100` > `2026-10-26-100800`.
3. ✅ Q5A-02 ditutup keputusan user 20-10-2026 (ISSUE-049), dicantumkan di Bagian 7.2; pengecekan ulang produksi di DEPLOY-002.1.
4. ✅ Draf dipindah ke `app/Database/Migrations/`, registry NIP (Bagian 6) dan test skema (Bagian 11) ditambahkan (Bagian
   15); CI hijau di MySQL 8.0 dan MariaDB 10.4 dilaporkan ke reviewer CR, lalu reviewer CR me-merge (izin Horii).

Sebelum itu **dilarang** me-merge migration, model, service, controller, atau test yang bergantung pada tabel ini, dan
dilarang menjalankan migration di DB dev/produksi.

---

## 13. Checklist DB Validator

Dicentang sesuai komentar persetujuan DB Validator (PR #28, 20-10-2026); kesetiaan baris-per-baris terhadap D1/SQL-L
tidak diverifikasi DB Validator (dump di luar repo, Bagian 10.4).

- [x] `dh_online` deviasi [V2] F5-12 diterima (Bagian 2; DBV-Q1/Q3/Q6)
- [x] `gaji_pegawai` UNIQUE/CHECK diterima (Bagian 3; DBV-Q9/Q10); 15 kolom generik dibuang (B2 tidak berlaku, ISSUE-049)
- [x] `gaji_pegawai_qr_history` RESTRICT + UNIQUE + snapshot diterima (Bagian 4; DBV-Q11/Q17)
- [x] ENUM `audit_logs.event` + `down()` fail-closed diterima (Bagian 5; DBV-Q16)
- [ ] Registry NIP (Bagian 6) — dikerjakan di M2; aturan impor (Bagian 7) + hasil Q5A-02 tercantum (✅ ISSUE-049, Bagian 7.2); C-3/C-4 di Bagian 14
- [x] `migrate` → `migrate:rollback` → `migrate` di MariaDB 10.4 (Bagian 10.4)
- [x] Keputusan Bagian 9 diisi

---

## 14. Catatan DB Validator C-1..C-4 dan tindak lanjut (komentar PR #28, 20-10-2026)

| # | Catatan DB Validator (ringkas) | Tindak lanjut |
|---|---|---|
| C-1 | Jumlah CHECK di MariaDB selalu 3 lebih banyak daripada MySQL (CHECK implisit `json_valid()` untuk kolom JSON), bukan perbedaan skema | ✅ Dicatat di Bagian 10 (catatan jumlah CHECK) |
| C-2 | Skrip audit benar menyasar salinan legacy, tetapi salah sasaran (DB v2) hanya terlihat sebagai satu `1054` di tengah keluaran | ✅ Kueri pembuka P0 `BERHENTI:` (cek kolom `gaji_pegawai.gaji_pokok` di `information_schema`, pola skrip counter DBV-011) di `sql/DBV-023-audit-presensi-gaji.sql`; Bagian 8 |
| C-3 | PK `(nip, tgl)` + FK RESTRICT: koreksi NIP memperbarui kolom kunci pada banyak baris `dh_online` dalam satu transaksi; urutan induk/anak harus jelas di bawah RESTRICT | ⏳ **Butir pra-cutover:** uji koreksi NIP (`NipCascadeService`) pada volume `dh_online` realistis + urutan induk/anak di bawah FK RESTRICT. Dicatat juga sebagai Q cutover di `backend/docs/progress/05-Presensi.md`. Test fungsional registry/cascade tetap di M2 (Bagian 11) |
| C-4 | `sumber` NULL lolos `chk_dh_online_sumber` (UNKNOWN) — sah untuk baris impor legacy; jangan sampai menjadi "nilai ketiga yang sah" | ✅ Aturan ditulis di runbook impor DBV-011 2.11: NULL hanya untuk baris impor legacy; setelah cutover setiap baris baru wajib 1/2, ditegakkan aplikasi. ⏳ Kode presensi online/manual (M5) selalu mengisi `sumber` + test |
| C-Q7 | Syarat DBV-Q7: tabel yang tidak dimigrasi dicatat eksplisit di Mapping Migrasi | ✅ Dicatat di runbook impor DBV-011 2.11 (`user_geo`, `dh_online_coordinate`, `d_dh_online`/arsip, dst.). Mapping Migrasi resmi (docx Bahan Baku, di luar repo) diteruskan reviewer CR ke user |

**DBV-Q5** dan **B2** ditutup keputusan user 20-10-2026 (ISSUE-049, Bagian 7.2): baris tanpa jam dibersihkan saat impor
(CHECK tetap), B2 tidak berlaku; pengecekan ulang produksi di DEPLOY-002.1.

---

## 15. Pelaksanaan M2 (27-10-2026)

**Basis.** `origin/main` `598a5f3` (DBV-022 M2) digabung ke branch PR #28 dengan `git merge` (tanpa rebase/force push,
arahan reviewer CR); tanpa konflik.

**Migration.** Kedua draf dipindah dari `app/Database/MigrationsDitahan/` ke `app/Database/Migrations/` dengan DDL/isi
tidak berubah; yang berubah hanya docblock status. Nama kelas tetap; timestamp baru:

| Draf M1 | Migration M2 |
|---|---|
| `MigrationsDitahan/CreatePresensiGaji.php` | `Migrations/2026-10-27-100000_CreatePresensiGaji.php` |
| `MigrationsDitahan/AlterAuditLogsEventPresensi.php` | `Migrations/2026-10-27-100100_AlterAuditLogsEventPresensi.php` |

Keduanya lebih baru dari 9 migration Modul C `2026-10-26-100000..100800` (DBV-022). DBV-022 tidak mengubah ENUM
`audit_logs.event`, jadi `AlterAuditLogsEventPresensi::NILAI_LAMA` tetap 5 nilai.

**Registry NIP (Bagian 6, AC-D01.7).**
- `NipCascadeService::FASE` 4 → **5**. `NipCascadeTest` sudah memakai `sampaiFase(NipCascadeService::FASE)` sejak DBV-022.
- `sampaiFase(5)` = `sampaiFase(4)` + `dh_online.nip` + `gaji_pegawai.nip` (65 entri; `TabelAnakNipTest`).
- `dh_online.nip` (FK RESTRICT) diarahkan ulang cascade. `gaji_pegawai.nip` (`NonFkSlipGaji` [K]) masuk
  `NipCascadeService::TIDAK_DIARAHKAN`: slip lama tetap ber-NIP lama dan tidak tercatat di hasil koreksi (PRD 5A §3.4;
  test `testPresensiPindahSlipGajiTetapNipLama`).
- `NipReferenceRegistryTest`: `REGISTRY['2026-10-27-100000']` = `fk_nip_dh_to_pegawai`; `gaji_pegawai.nip` di `NON_FK`.

**Test skema (Bagian 11).** `tests/_support/Presensi/SkemaPresensiGaji.php` (daftar tabel/FK/CHECK bersama, autoload untuk
CI ter-shard), `tests/database/Presensi/SkemaPresensiGajiTest.php`, `tests/database/Presensi/MigrasiPresensiGajiSiklusTest.php`
(`db-isolasi-penuh`). Test lama yang ikut disesuaikan karena FK/ENUM baru:
- `tests/MasterData/LokasiPresensiSchemaTest.php` — `dh_online.id_lokasi_*` merujuk `lokasi_presensi` (FK RESTRICT), jadi
  migration `CreatePresensiGaji` dilepas sebelum `down()` G-03 dan dipasang lagi sesudahnya (urutan regress).
- `tests/Auth/AuthCollationMigrationTest.php` — `AlterAuthKeUnicodeCi` menulis ENUM `event` 5 nilai eksplisit, jadi
  `AlterAuditLogsEventPresensi` di-rollback di `setUp()` dan dipasang lagi di `tearDown()`.

**Verifikasi developer (MySQL 8.0.30, 3307, DB scratch `simpeg_v2_t_ws2_dbv023`; DB test terpisah
`simpeg_v2_t_ws2_make030`, prefix `t_`).** Batch 1 = `main` tanpa DBV-023, batch 2 = DBV-023:

| Tahap | Tabel | FK | CHECK | ENUM `event` |
|---|---|---|---|---|
| `main` (batch 1) | 120 | 235 | 84 | 5 nilai |
| + DBV-023 (batch 2) | **123** | **239** | **89** | 7 nilai |
| `migrate:rollback` | 120 | 235 | 84 | 5 nilai |
| `migrate` ulang | 123 | 239 | 89 | 7 nilai |

Checksum `information_schema` (COLUMNS, REFERENTIAL_CONSTRAINTS, STATISTICS, CHECK_CONSTRAINTS) identik antara batch 2
pertama dan `migrate` ulang. Pertambahan +3 tabel / +4 FK / +5 CHECK sama dengan M1 dan verifikasi DB Validator
(MariaDB: angka absolut CHECK +3, catatan C-1).

