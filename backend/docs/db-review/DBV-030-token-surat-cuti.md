# DB Validator Review — DBV-030 token publik surat cuti (Fase 6 Jalur B, MAKE-065 F6-18)

**Key review:** `DBV-030`. Judul PR: `[CR][DBV] DBV-030 token publik surat cuti (QR TTE & verifikasi) + MAKE-065`.
Branch `fase6/dbv-030-token-surat-cuti` (dari `main` `51f2b84c`). Alur `[CR][DBV]`: PR, DB Validator me-review/approve,
lalu migration dipindah dari `MigrationsDitahan/` ke `Migrations/` + test skema; reviewer CR me-merge dengan izin Horii.

**Status:** ⏳ **menunggu review DB Validator.** Draf migration **DITAHAN** di
`app/Database/MigrationsDitahan/CreateSuratCutiToken.php` (tidak dijalankan `spark migrate`).

**Penulis:** pelaksana Fase 6 Jalur B, 03-11-2026.

**Asal kebutuhan:** keputusan Horii 03-11-2026 F6-18 (kartu Trello Catatan Teknis `V8elXqPu`; PRD Fase 6 v1.0f CR-097):
- **K3b** — gambar QR tanda tangan BSrE (`imageTTD`) berisi URL LANGSUNG ke PDF surat cuti ber-TTE yang terbuka tanpa login
  (ikut legacy `L_cuti.php` yang memuat `ARSIP/{nip}/cuti/{berkas}_signed.pdf`). Bentuk v2: token acak tak tertebak, bukan
  path ber-NIP.
- **K3c** — QR kaki surat (`SuratCutiService::render`, kini teks `SIMPEG/CUTI/{id}/{nip}`) diganti URL halaman verifikasi
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
| Token acak, bukan path ber-NIP | [V2] keputusan Horii 03-11-2026 K3b | Perilaku legacy (URL publik langsung ke PDF) dipertahankan tanpa URL yang bisa ditebak dari NIP |
| Hash + enkripsi | [V2] pola D-14/ADR-032 | Kebocoran DB tidak membuka URL; QR bisa dicetak ulang |
| Satu token per cuti | [V2] K3c | Token sama untuk QR kaki dan QR tanda tangan |

## 4. Pemakaian aplikasi (MAKE-065 langkah 3, setelah approve)

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

- [ ] Nama tabel/kolom/FK/CHECK/indeks (Bagian 3)
- [ ] Hash + enkripsi, tanpa NIP, tanpa token mentah
- [ ] FK RESTRICT + hapus cuti (DBV-030-Q2)
- [ ] Satu token per cuti (DBV-030-Q3)
- [ ] Skrip audit hanya SELECT, hanya jumlah
