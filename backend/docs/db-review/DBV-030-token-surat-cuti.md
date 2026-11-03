# DB Validator Review — DBV-030 token publik surat cuti (Fase 6 Jalur B, MAKE-065 F6-18)

**Key review:** `DBV-030`. Judul PR: `[CR][DBV] DBV-030 token publik surat cuti (QR TTE & verifikasi) + MAKE-065`.
Branch `fase6/dbv-030-token-surat-cuti` (dari `main` `51f2b84c`). Alur `[CR][DBV]`: PR, DB Validator me-review/approve,
lalu migration dipindah dari `MigrationsDitahan/` ke `Migrations/` + test skema; reviewer CR me-merge dengan izin Horii.

**Status:** ✅ **disetujui DB Validator @`227805a9` (03-11-2026)** — DBV dikerjakan sesi WS-2 atas perintah Horii; hasil &
keputusan di Bagian 12. Draf migration masih **DITAHAN** di `app/Database/MigrationsDitahan/CreateSuratCutiToken.php`
sampai dipindah ke `Migrations/` (syarat §10, §12.5).

**Penulis:** pelaksana Fase 6 Jalur B, 03-11-2026.

**Asal kebutuhan:** keputusan Horii 03-11-2026 F6-18 (kartu Trello Catatan Teknis `V8elXqPu`; PRD Fase 6 v1.0f CR-097,
§7.2, **Q6-22** dan **Q6-23**, **AC-02.7** dan **AC-02.8**):
- **K3b = Q6-22 (Q-1, ikut legacy)** — gambar QR tanda tangan BSrE (`imageTTD`) berisi URL LANGSUNG ke PDF surat cuti ber-TTE yang terbuka tanpa login
  (ikut legacy `L_cuti.php` yang memuat `ARSIP/{nip}/cuti/{berkas}_signed.pdf`). Bentuk v2: token acak tak tertebak, bukan
  path ber-NIP.
- **K3c = Q6-23 (Q-2, [V2])** — QR kaki surat (`SuratCutiService::render`, kini teks `SIMPEG/CUTI/{id}/{nip}`) diganti URL halaman verifikasi
  publik pola slip D-14 (ADR-032, `TokenSlipService`/`VerifikasiSlipService`): token 64 heks; DB menyimpan hash + salinan
  terenkripsi untuk cetak ulang; data minimal (jenis surat, nama penanda tangan, tanggal tanda tangan, status TTE) tanpa NIP
  dan tanpa unduh PDF. **Token yang sama** dipakai URL PDF K3b. Surat tanpa TTE tetap ber-QR kaki berstatus "belum".

**Rujukan:** `DBV-023` (`gaji_pegawai.qr_token`, `qr_token_enkripsi`, `gaji_pegawai_qr_history`), `DBV-027` (pola
dokumen), `DBV-022` (`riwayat_cuti`, `esign_hist`), ADR-008 (FK RESTRICT), ADR-032 (verifikasi slip).

**Label sumber:** **[K]** ikut kode/DDL legacy; **[V2]** deviasi v2 yang disengaja; **[I]** inferensi.

---

## 0. Ringkasan

| # | Objek | Jenis | Isi |
|---|---|---|---|
| 1 | `surat_cuti_token` | CREATE [V2] | Satu token publik per surat cuti: `token_hash` (SHA-256 heks, UNIQUE), `token_enkripsi` (VARBINARY, cetak ulang QR), `status` 1/2, FK `id_riwayat_cuti` RESTRICT, UNIQUE per cuti |

Tidak ada ALTER tabel lama, tidak ada seed, tidak ada kolom NIP (registry NIP tidak berubah).

## 1. Konvensi

`ENGINE=InnoDB`, `utf8mb4_unicode_ci`; stempel `created_at`/`updated_at` UTC diisi aplikasi; status proyek 1 aktif /
2 nonaktif (10 "dihapus" tidak dipakai — lihat 3.3); FK `ON DELETE RESTRICT ON UPDATE RESTRICT` (ADR-008); nama
`fk_*`, `uk_*`, `chk_*`.

## 2. Sumber legacy

Legacy tidak punya tabel token: QR tanda tangan berisi URL publik ber-path NIP ke berkas di server arsip
(`L_cuti.php` bagian *SIGN SURAT CUTI*, `saveQRLogo($fpQR, ARSIP . $pathArsip . $coSigned, …)`), sehingga siapa pun yang tahu
NIP + pola nama berkas dapat menebak URL. v2 mempertahankan PERILAKU (URL langsung ke PDF ber-TTE, tanpa login) dengan
token acak 256-bit [V2], sesuai keputusan Horii K3b.

## 3. Skema v2 usulan

### 3.1 `surat_cuti_token` [V2]

```sql
CREATE TABLE `surat_cuti_token` (
  `id_surat_cuti_token` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_riwayat_cuti` INT NOT NULL,                 -- = tipe PK riwayat_cuti (DBV-022)
  `token_hash` CHAR(64) NOT NULL,                 -- SHA-256 heks token mentah
  `token_enkripsi` VARBINARY(255) NOT NULL,       -- Encrypter CI4 (pola gaji_pegawai.qr_token_enkripsi)
  `status` TINYINT NOT NULL DEFAULT 1,            -- 1 aktif, 2 nonaktif
  `nonaktif_at` DATETIME NULL DEFAULT NULL,       -- UTC
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT NULL DEFAULT NULL,             -- id_pengguna, tanpa FK (pola kolom pelaku)
  PRIMARY KEY (`id_surat_cuti_token`),
  UNIQUE KEY `uk_sct_id_riwayat_cuti` (`id_riwayat_cuti`),
  UNIQUE KEY `uk_sct_token_hash` (`token_hash`),
  CONSTRAINT `fk_sct_id_riwayat_cuti` FOREIGN KEY (`id_riwayat_cuti`) REFERENCES `riwayat_cuti` (`id_riwayat_cuti`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_surat_cuti_token_status` CHECK (`status` IN (1, 2)),
  CONSTRAINT `chk_surat_cuti_token_nonaktif` CHECK ((`status` = 1 AND `nonaktif_at` IS NULL) OR (`status` = 2 AND `nonaktif_at` IS NOT NULL))
);
```

### 3.2 Alasan desain

- **Tabel terpisah, bukan kolom di `riwayat_cuti`:** `riwayat_cuti` adalah tabel [K] (DBV-022); token publik murni [V2] dan
  dipakai dua URL. Tabel sendiri menjaga DDL [K] tetap utuh dan memudahkan audit/rotasi.
- **Hash + salinan terenkripsi (pola D-14, ADR-032):** pencarian saat verifikasi memakai `token_hash` (UNIQUE); token mentah
  hanya bisa dipulihkan dari `token_enkripsi` dengan kunci aplikasi untuk mencetak ulang QR (surat belum TTE → ber-TTE memakai
  token yang sama). Kebocoran baris DB tidak membuka URL.
- **Satu token per cuti (UNIQUE `id_riwayat_cuti`):** keputusan K3c "token yang sama" untuk QR kaki dan QR tanda tangan.
  Rotasi (bila kelak diperlukan) = UPDATE baris + jejak `audit_logs`, bukan baris riwayat seperti `gaji_pegawai_qr_history`
  — surat cuti tidak punya "buka ulang" seperti slip, dan QR lama yang dicabut memang harus 404.
- **`status` 1/2 + CHECK berpasangan dengan `nonaktif_at`:** surat dicabut/dibatalkan → token nonaktif → semua URL 404
  seragam (K3b). Domain proyek 10 (dihapus) tidak dipakai karena baris ikut dihapus bersama cuti (3.3).
- **Tanpa NIP:** URL dan tabel tidak memuat NIP (beda dari legacy) — registry NIP (`TabelAnakNip`) tidak berubah.

### 3.3 Hapus cuti

`CutiService::hapus` (role 1) menghapus `riwayat_cuti` secara FISIK beserta lampiran. Dengan FK RESTRICT, aplikasi
menghapus baris `surat_cuti_token` cuti itu di transaksi yang sama sebelum menghapus cuti (pola anak `riwayat_cuti_notif_kt`).
Usulan ini dipilih atas CASCADE agar penghapusan tetap eksplisit di kode (ADR-008); DBV boleh memilih CASCADE (DBV-030-Q2).

### 3.4 Deviasi & alasan (ringkas)

| Butir | Keputusan | Alasan |
|---|---|---|
| Token acak, bukan path ber-NIP | [V2] keputusan Horii 03-11-2026 Q6-22 | Perilaku legacy (URL publik langsung ke PDF) dipertahankan tanpa URL yang bisa ditebak dari NIP |
| Hash + enkripsi | [V2] pola D-14/ADR-032 | Kebocoran DB tidak membuka URL; QR bisa dicetak ulang |
| Satu token per cuti | [V2] Q6-23 (token sama dengan Q6-22) | Token sama untuk QR kaki dan QR tanda tangan |

## 4. Pemakaian aplikasi (MAKE-065 langkah 3, setelah approve; diuji AC-02.7 dan AC-02.8)

- Token terbit saat surat belum TTE dibuat (status final 1/4); QR kaki memuat URL verifikasi.
- Sebelum menandatangani, `TautanSuratCutiInterface::urlPdfTte()` memakai token yang sama untuk URL PDF di QR `imageTTD`.
- Route publik tanpa jwt (`ThrottlePublikFilter`): verifikasi (data minimal) dan PDF ber-TTE (`inline`); token
  salah/nonaktif/surat tanpa TTE → 404 seragam; `Cache-Control: no-store`, `X-Robots-Tag: noindex`; log tanpa token & IP.

## 5. Pra-cek

Tabel baru tanpa data lama — tidak ada pra-cek data. `up()` gagal → tabel di-DROP; `down()` men-DROP tabel.

## 6. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-030-audit-token-surat-cuti.sql` (hanya SELECT, hanya jumlah).

| ID | Kelas | Isi |
|---|---|---|
| B1 | WAJIB_0 | Token aktif untuk cuti tidak final |
| B2 | WAJIB_0 | Hash bukan 64 heks huruf kecil |
| B3 | WAJIB_0 | Salinan terenkripsi kosong |
| B4 | INFO | Token per status |
| B5 | INFO | Cuti final tanpa token |

## 7. Verifikasi developer

### 7.1 Hasil (03-11-2026) — DB scratch Jalur B `simpeg_v2_t_fase6b`, MySQL 8.0.30 port 3307, grup `tests` (prefix `t_`)

Draf dijalankan lewat kelasnya dari `tests/database/Layanan/SkemaSuratCutiTokenTest.php` (`db-isolasi-penuh`; ikut di PR
sehingga CI MySQL 8.0 & MariaDB 10.4 ikut menjalankannya). Bukan DB dev. Hasil: 3 test, 14 assertion, lolos.

| Uji | Hasil |
|---|---|
| Engine InnoDB, collation tabel & kolom string `utf8mb4_unicode_ci`; tipe `token_hash` CHAR(64), `token_enkripsi` VARBINARY(255), `id_riwayat_cuti` INT; tanpa kolom NIP | ✅ |
| Token kedua untuk cuti yang sama; hash yang sama untuk cuti lain | ✅ ditolak 1062 / 1062 |
| `status` 3; `status` 2 tanpa `nonaktif_at`; `status` 1 dengan `nonaktif_at` | ✅ ditolak 3819 (CHECK) |
| `status` 2 + `nonaktif_at` | ✅ diterima |
| Cuti tidak ada; hapus cuti ber-token | ✅ ditolak 1452 / 1451 |
| `down()` → tabel hilang; `up()` ulang berhasil | ✅ |
| Kelima kueri audit berjalan tanpa galat sintaks | ✅ |

## 8. Rencana test skema (setelah approve)

`tests/database/Layanan/SkemaSuratCutiTokenTest.php` (`DatabaseTestCase`, `db-isolasi-penuh`): struktur/collation,
UNIQUE (1062) per cuti & per hash, CHECK status/nonaktif (3819/4025), FK RESTRICT (1451 hapus cuti ber-token; 1452 cuti tidak
ada), siklus `down()/up()`, kueri audit berjalan.

## 9. Keputusan yang diminta dari DB Validator

| ID | Pertanyaan | Usulan |
|---|---|---|
| DBV-030-Q1 | Tabel terpisah vs kolom di `riwayat_cuti` | Tabel terpisah (3.2) |
| DBV-030-Q2 | FK `id_riwayat_cuti` RESTRICT (aplikasi hapus token dulu) vs CASCADE | RESTRICT (3.3) |
| DBV-030-Q3 | Satu token per cuti (UPDATE saat rotasi) vs tabel riwayat seperti `gaji_pegawai_qr_history` | Satu per cuti |
| DBV-030-Q4 | `token_enkripsi` NOT NULL (token selalu bisa dicetak ulang) | Setujui |
| DBV-030-Q5 | Timestamp migration saat dipindah | Lebih baru dari migration terakhir di `main` |

## 10. Syarat merge (⛔)

1. DB Validator menyetujui DBV-030 (tanggal + catatan dicatat di dokumen ini).
2. Migration dipindah ke `app/Database/Migrations/` dengan DDL tidak berubah dari yang disetujui (atau perubahan atas
   permintaan DBV tercatat), plus test skema; CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4).
3. Merge oleh reviewer CR dengan izin Horii.

## 11. Checklist DB Validator

- [x] Nama tabel/kolom/FK/CHECK/indeks (Bagian 3)
- [x] Hash + enkripsi, tanpa NIP, tanpa token mentah
- [x] FK RESTRICT + hapus cuti (DBV-030-Q2)
- [x] Satu token per cuti (DBV-030-Q3)
- [x] Skrip audit hanya SELECT, hanya jumlah

---

## 12. Hasil review DB Validator

**DBV dikerjakan sesi WS-2 atas perintah Horii 03-11-2026** (independen dari penulis PR dan reviewer CR). SHA yang direview:
**`227805a9`** (DDL sama dengan `36ae1a4c`; `227805a9` hanya dokumen). **Keputusan: SETUJU.**

### 12.1 Verifikasi
| Uji | Lingkungan | Hasil |
|---|---|---|
| `migrate --all` (`main`, 59 migration) → draf sebagai migration lokal → `up()` → 5 kueri audit → `down()` → `up()` | MySQL 8.0.30 lokal (3307, DB scratch DBV `simpeg_v2_t_dbv030`, `.env` dari `.env.example`) | ✅ audit tanpa galat, WAJIB_0 = 0; `mysqldump --no-data` sesudah `down()` **identik** dengan sebelum `up()`; `up()` ulang identik |
| DDL hasil (`SHOW CREATE TABLE`) | MySQL 8.0.30 lokal | ✅ sama dengan Bagian 3.1; komentar UTF-8 tersimpan benar |
| `SkemaSuratCutiTokenTest` (draf ditahan) | MySQL 8.0.30 lokal | ✅ 3 test, 14 assertion |
| PHPStan + PHP-CS-Fixer (draf + test) | lokal | ✅ bersih |
| Quality gate (draf lewat test, matriks PHPUnit 8 job MySQL 8.0 + MariaDB 10.4) | CI #518 (`36ae1a4c`) | ✅ hijau |

### 12.2 Kesesuaian aturan skema proyek
| Aturan | Hasil |
|---|---|
| Label [K]/[V2]/[I] | ✅ tabel murni [V2] (legacy tanpa tabel token, Bagian 2); deviasi tercatat 3.4 |
| `ENGINE=InnoDB`, `utf8mb4_unicode_ci` tabel & kolom string | ✅ |
| Status 1/2/10 | ✅ diterima 1/2 saja: baris tidak pernah "dihapus lunak" — ikut dihapus keras bersama cuti (3.3); CHECK berpasangan `nonaktif_at` |
| FK `RESTRICT/RESTRICT` (ADR-008), tipe = PK induk | ✅ `id_riwayat_cuti INT` = `riwayat_cuti.id_riwayat_cuti INT` |
| Migration di `main` tidak diedit; `down()` | ✅ CREATE tabel baru saja; `down()` = DROP, terbukti identik |
| Tanpa kolom NIP / registry NIP | ✅ tidak ada kolom NIP; `TabelAnakNip` tidak berubah |
| Rahasia | ✅ hanya hash + salinan terenkripsi; token mentah tidak disimpan; audit hanya jumlah |
| Stempel (DBV-025) | ✅ catatan: `created_at DEFAULT CURRENT_TIMESTAMP` / `updated_at ON UPDATE CURRENT_TIMESTAMP` diterima sebagai **cadangan** (sesi DB UTC sejak CR-086, DBV-025); aplikasi tetap mengisi UTC eksplisit |

### 12.3 Keputusan
| ID | Keputusan DBV |
|---|---|
| DBV-030-Q1 | **Setuju tabel terpisah.** `riwayat_cuti` tabel [K] (DBV-022) tidak ditambah kolom [V2]; token dipakai dua URL dan punya siklus sendiri (aktif/nonaktif) |
| DBV-030-Q2 | **Setuju FK RESTRICT**, dengan syarat MAKE-065: `CutiService::hapus` menghapus baris `surat_cuti_token` cuti itu **di transaksi yang sama sebelum** menghapus `riwayat_cuti` (kini baru `riwayat_cuti_notif_kt`, `CutiService.php` fungsi `hapus`) + test (hapus cuti ber-token berhasil, token ikut hilang, URL 404). Tanpa itu hapus cuti ber-token gagal 1451 |
| DBV-030-Q3 | **Setuju satu token per cuti** (UNIQUE `id_riwayat_cuti`, rotasi = UPDATE + `audit_logs`), dengan batas: **rotasi hanya boleh sebelum surat ber-TTE.** PDF ber-TTE bersifat tetap dan memuat QR berisi token itu (Q6-22), sehingga rotasi sesudah TTE membuat QR di PDF bertanda tangan mati tanpa bisa dicetak ulang. Sesudah TTE yang diizinkan hanya **nonaktif** (status 2, mis. surat dicabut) dan aktif kembali (status 1, `nonaktif_at` NULL), keduanya tercatat di `audit_logs`. Diterapkan di aplikasi + test MAKE-065 |
| DBV-030-Q4 | **Setuju `token_enkripsi` NOT NULL** (pola `gaji_pegawai.qr_token_enkripsi` VARBINARY(255); keluaran Encrypter CI4 untuk token 64 karakter jauh di bawah 255 byte); audit B3 WAJIB_0 |
| DBV-030-Q5 | Timestamp saat dipindah **lebih baru dari migration terakhir di `main`** (kini `2026-11-03-100000`, DBV-027) **dan** dari migration lain yang masuk lebih dulu (mis. DBV-026) |

### 12.4 Catatan non-blok
- **Surat cuti legacy ber-TTE (cutover):** QR di PDF lama menunjuk URL legacy ber-path NIP di server arsip (Bagian 2), bukan
  token v2; tabel ini kosong saat cutover (audit B5 = surat final tanpa token, INFO). Bila server arsip legacy dimatikan, QR di
  surat lama tidak lagi terbuka. Perlakuan (pengalihan pola URL legacy ke storage v2 yang mempertahankan path legacy — K-M3
  opsi A, DBV-029 — atau diterima mati) diputus di runbook cutover/Horii, bukan skema.
- `token_hash` ber-collation `utf8mb4_unicode_ci` (tidak peka huruf besar/kecil): aman karena aplikasi selalu menulis heks huruf
  kecil (audit B2 WAJIB_0).
- Test permanen §8: saat pemindahan, `require_once` `MigrationsDitahan` dihapus dan versi migration disesuaikan (pola DBV-027).

### 12.5 Persetujuan
**DBV APPROVE @`227805a9`** — 03-11-2026, DBV (sesi WS-2 atas perintah Horii). Syarat merge §10 tetap: migration dipindah ke
`Migrations/` dengan DDL **tidak berubah** + timestamp DBV-030-Q5, test skema permanen, syarat aplikasi Q2/Q3 di MAKE-065, CI
hijau MySQL 8.0 & MariaDB 10.4, diverifikasi DBV sebelum reviewer CR me-merge.

---

## 13. Validasi ulang DBV (independen, 03-11-2026)

Atas perintah Horii 03-11-2026 (lewat reviewer CR), peran DB Validator DBV-030 dialihkan ke sesi Worker Fix Bug untuk
validasi ulang independen atas persetujuan §12 (@`227805a9`). Sesi ini tidak ikut menulis draf maupun review §12.
SHA yang divalidasi: **`227805a9`** (isi DDL sama dengan `36ae1a4c`; `1ada0674` hanya menambah dokumen).
**Keputusan: SETUJU @`227805a9`**, dengan satu temuan non-blok (13.3) dan satu koreksi atas klaim §12.4.

### 13.1 Verifikasi
Lingkungan: MySQL 8.0.30 lokal (3307), DB scratch BARU `simpeg_v2_t_dbv030v` (dibuat kosong, `.env` ditulis sendiri,
bukan salinan `.env` dev), prefix kosong.

| Uji | Hasil |
|---|---|
| `migrate --all` pada `main` `51f2b84c` (60 migration terdaftar sesudah draf dipasang) | ✅ |
| Draf dipasang sebagai `Migrations/2026-11-03-110000_CreateSuratCutiToken.php` (lokal, tidak di-commit) → `migrate` | ✅ `up()` berhasil |
| `SHOW CREATE TABLE` | ✅ sama dengan Bagian 3.1; komentar UTF-8 (tanda panah) tersimpan benar (diperiksa dengan klien `utf8mb4`) |
| `migrate:rollback` → `mysqldump --no-data` dibandingkan dengan snapshot sebelum `up()` | ✅ **identik** (nol selisih) |
| `migrate` ulang → snapshot dibandingkan dengan `up()` pertama | ✅ identik |
| 5 kueri audit pada tabel kosong | ✅ tanpa galat; semua WAJIB_0 = 0 |
| 5 kueri audit dengan data sintetis (4 cuti status 1/4/0/1; 3 token, satu `token_enkripsi` kosong, satu hash huruf besar) | B1 = 1 ✅ (token aktif pada cuti status 0), B3 = 1 ✅, B4 = 3 aktif ✅, B5 = 1 ✅ (cuti final tanpa token), **B2 = 0 ✗** (lihat 13.3) |
| `SkemaSuratCutiTokenTest` (draf ditahan, grup `db-isolasi-penuh`) pada DB yang sama | ✅ 3 test, 14 assertion |

Data sintetis dihapus dan migration di-rollback setelah uji; DB scratch tidak dihapus (menunggu izin Horii).

### 13.2 Kesesuaian aturan skema proyek (cek ulang)
| Aturan | Hasil |
|---|---|
| Label [K]/[V2]/[I] | ✅ murni [V2]; legacy tidak punya tabel token |
| `ENGINE=InnoDB`, tabel & kolom string `utf8mb4_unicode_ci` | ✅ (`token_hash`; `token_enkripsi` biner tanpa collation) |
| Status 1/2/10 | ✅ 1/2 dengan CHECK berpasangan `nonaktif_at`; 10 tidak dipakai karena baris ikut dihapus keras |
| FK `RESTRICT/RESTRICT`, tipe sama dengan induk | ✅ `INT` = `riwayat_cuti.id_riwayat_cuti INT` (bertanda, sama dengan induk); tidak ada CHECK pada kolom FK dengan aksi CASCADE/SET NULL |
| Migration di `main` tidak diedit; `down()` bersih | ✅ CREATE tabel baru; `down()` = `DROP TABLE IF EXISTS`, identik terbukti |
| Tanpa kolom NIP | ✅ |
| Indeks | ✅ pencarian publik lewat `uk_sct_token_hash`, hapus/ambil per cuti lewat `uk_sct_id_riwayat_cuti`; tidak perlu indeks `status` (tidak pernah dicari tanpa hash/cuti) |
| `created_by INT NULL` tanpa FK | ✅ sama dengan pola `created_by` di `lokasi_presensi`, `riwayat_tb_ib`, `riwayat_pmk`, `dh_online` |
| Pola `gaji_pegawai_qr_history` / `TokenSlipService` | ✅ hash SHA-256 heks + salinan terenkripsi + UNIQUE hash + FK RESTRICT. Beda sah: satu baris per cuti (bukan riwayat) karena PDF TTE tetap dan rotasi dilarang sesudah TTE (Q3); `token_hash` CHAR(64) (slip memakai VARCHAR(64) pada `qr_token`) sama-sama muat |
| Panjang `token_enkripsi` | ✅ keluaran Encrypter CI4 untuk token 64 karakter (HMAC 32 + IV 16 + isi 64 = 112 byte mentah, ±150 bila base64) < 255 |
| Stempel UTC | ✅ `DEFAULT CURRENT_TIMESTAMP`/`ON UPDATE` hanya cadangan; sesi DB UTC sejak CR-086 |

### 13.3 Temuan
| ID | Berat | Isi |
|---|---|---|
| V-01 | Non-blok | **Kueri audit B2 tidak mendeteksi heks huruf besar.** `REGEXP` mengikuti collation kolom (`utf8mb4_unicode_ci`, tidak peka huruf): hash `UPPER(SHA2(...))` lolos sebagai "sah" (B2 = 0 pada uji 13.1). Klaim §12.4 "aman karena audit B2 WAJIB_0" karena itu tidak berlaku sepenuhnya. Dampak nyata kecil (aplikasi menulis `hash('sha256', …)` huruf kecil; UNIQUE dan pencarian toh tidak peka huruf). Saran perbaikan oleh pelaksana (bukan DDL): `WHERE BINARY token_hash NOT REGEXP '^[0-9a-f]{64}$'` pada `DBV-030-audit-token-surat-cuti.sql` B2 dan kata-kata §12.4 disesuaikan. Tidak mengubah skema. |

Tidak ada temuan yang mengubah DDL. Persetujuan tidak bergantung pada V-01.

### 13.4 Konfirmasi keputusan Q1–Q5 (§12.3)
| ID | Sikap |
|---|---|
| DBV-030-Q1 | **Setuju** tabel terpisah (`riwayat_cuti` tabel [K], tidak ditambah kolom [V2]) |
| DBV-030-Q2 | **Setuju** FK RESTRICT, syarat MAKE-065 (`CutiService::hapus` menghapus token dulu di transaksi yang sama) dipertahankan; harus diuji di PR aplikasi |
| DBV-030-Q3 | **Setuju** satu token per cuti; rotasi hanya sebelum TTE (syarat aplikasi, diuji AC-02.7/02.8) |
| DBV-030-Q4 | **Setuju** `token_enkripsi` NOT NULL (VARBINARY(255) cukup, 13.2) |
| DBV-030-Q5 | **Setuju**: timestamp > `2026-11-03-100000`. Migration terakhir di `main` saat divalidasi memang `2026-11-03-100000_CreateIntegrasiKeluarFase6`; pelaksana wajib cek ulang `origin/main` dan branch yang masuk lebih dulu (mis. DBV-029) tepat sebelum memindahkan |

### 13.5 Verifikasi pemindahan (langkah 4)
**Belum ada.** Branch masih di `1ada0674` saat validasi ini ditulis (draf masih di `MigrationsDitahan/`). Setelah pelaksana
mem-push pemindahan, DBV memeriksa dan mencatat hasilnya di bagian ini: (1) DDL di `Migrations/` identik dengan draf
@`227805a9` (beda hanya docblok); (2) timestamp sesuai Q5; (3) test skema permanen ada (`require_once` ke
`MigrationsDitahan` dihapus, `MigrationsDitahan/CreateSuratCutiToken.php` tidak tersisa); (4) test lama tidak dilonggarkan;
(5) CI MySQL 8.0 + MariaDB 10.4 hijau.
