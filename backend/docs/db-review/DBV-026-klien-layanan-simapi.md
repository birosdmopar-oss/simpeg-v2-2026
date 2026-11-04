# DB Validator Review — DBV-026 klien layanan & SIMAPI (Fase 6 Jalur A, H-01 bagian A)

**Key review:** `DBV-026`. Judul PR: `[DBV] DBV-026 skema klien layanan & SIMAPI`.
Branch `fase6/a-lokal/dbv-026-klien-simapi` (dari `main` `158e4b98`). Alur `[DBV]`: PR, DB Validator me-review/approve,
lalu migration dipindah dari `MigrationsDitahan/` ke `Migrations/` + test skema permanen; reviewer CR me-merge dengan izin
Horii.

**Status:** ✅ **disetujui DB Validator @`79f00bb5` (04-11-2026)** — DBV dikerjakan sesi WS-2 atas perintah Horii (lewat
reviewer CR); hasil & keputusan di Bagian 12, syarat pemindahan dan syarat aplikasi di Bagian 13. **DBV-026-Q6 diputus
berbeda dari usulan:** `simapi_ch.nip` tidak masuk registry `TabelAnakNip` dan tidak ada case `JenisRujukanNip` baru (12.3).

**Penulis:** helper Jalur A Fase 6 (di bawah lead Jalur A), 04-11-2026.

**Asal kebutuhan:** PRD Fase 6 v1.0i §7.0.1 (auth antar-sistem, ADR-036), §7.1 (H-01 DBV-026), §6.1, BP-8, F6-03/F6-05/
F6-06/F6-08, `docs/fase6/6_2.md` A1; CR-093 (akun sistem integrasi tidak dicatat di `simapi_ch`, berlaku di penulis MAKE-060).

**Rujukan:** `DBV-027` (pola dokumen, D1 tidak tersedia = DBV-Q11), `DBV-030` (pola draf ditahan + test skema + audit
B2 hash heks), ADR-008 (FK RESTRICT).

**Label sumber:** **[K]** ikut DDL legacy; **[K-kode]** diturunkan dari kode legacy (DDL tidak tersedia); **[V2]** deviasi
v2 yang disengaja; **[I]** inferensi. Kolom bertanda ⚑ **wajib dicocokkan dengan D1** begitu potongan DDL tersedia.

---

## 0. Ringkasan

| # | Objek | Jenis | Isi |
|---|---|---|---|
| 1 | `klien_layanan` | CREATE [V2] | Klien antar-sistem: `username` UNIQUE, `rahasia_hash`, `jenis` 1/2/3, `hak` SET, `ip_allowlist`, `status` 1/2/10 |
| 2 | `simapi_token` | CREATE [K-kode]+[V2] | Token SIMAPI sebagai SHA-256 (`token_hash` PK), FK klien, `status` 1/2, `expired_at` |
| 3 | `simapi_jd` | CREATE [K-kode] | Master jenis data change history (`id_simapi_jd`, `jenis_data`) — seed menunggu D1 |
| 4 | `simapi_auth_jd` | CREATE [K-kode] | Filter jenis data per klien, PK (`id_klien`, `id_simapi_jd`) |
| 5 | `simapi_ch` | CREATE [K-kode] | Baris perubahan format pipe, `nip` tanpa FK |
| 6 | `simapi_auth_ch` | CREATE [K-kode] | Penanda sudah dikirim per klien, PK (`id_klien`, `id_simapi_ch`) |

Tidak ada ALTER tabel lama dan tidak ada data lama yang diimpor (F6-08). Draf: `app/Database/MigrationsDitahan/
CreateKlienLayananSimapi.php`. Audit: `docs/db-review/sql/DBV-026-audit-klien-simapi.sql`. Test:
`tests/database/Integrasi/SkemaKlienSimapiTest.php`.

## 1. Konvensi

`ENGINE=InnoDB`, `utf8mb4_unicode_ci`; stempel UTC diisi aplikasi; status proyek 1 aktif / 2 nonaktif / 10 dihapus;
FK `ON DELETE RESTRICT ON UPDATE RESTRICT` (ADR-008); nama `fk_*`, `uk_*`, `idx_*`, `chk_*`. Nama tabel SIMAPI dan nama
kolom [K-kode] dipertahankan agar padanan legacy jelas; kunci `username` legacy diganti `id_klien` (FK ke `klien_layanan`).

## 2. Sumber legacy

**D1 tidak tersedia di mesin pelaksana** (sama dengan DBV-027, DBV-Q11): tabel `simapi_*` tidak ada di kode legacy maupun
duplikat struktur lokal. Pencocokan D1 (DDL, isi `simapi_jd`, trigger penulis `simapi_ch`) = **ISSUE-056** (Bagian 9a), diteruskan
reviewer ke Horii (04-11-2026). Kolom berikut diturunkan dari kode legacy:

| Tabel legacy | Kolom dipakai kode | Rujukan |
|---|---|---|
| `simapi_auth` | `username`, `password` (plaintext), `ip_address` (cek dikomentari), `type`, `status` | `A_auth.php:37-38`, `A_employee.php:36-40` |
| `simapi_token` | `token`, `username`, `status`, `expired_at` (satu baris per username, UPDATE saat kedaluwarsa) | `A_auth.php:46-91` |
| `simapi_auth_jd` | `username`, `id_simapi_jd` | `A_employee.php:57-60` |
| `simapi_auth_ch` | `username`, `id_simapi_ch` (MAX per username) | `A_employee.php:73-77`, `:92-107` |
| `simapi_jd` | `id_simapi_jd`, `jenis_data` | `A_employee.php:84-85` |
| `simapi_ch` | `id_simapi_ch`, `id_simapi_jd`, `id_rwy`, `nip`, `action` (`'1'` → "INSERT / UPDATE", lainnya → "DELETE"), `action_at`, `field_name`, `field_value` | `A_employee.php:84-98` |

`simapi_ch` diisi **trigger DB** legacy pada `pegawai`, `pengguna`, `detail_anak`, dan 13 `riwayat_*` (PRD Lampiran A.1);
trigger tidak dibawa (F6-06) — penulis v2 = event aplikasi sesudah commit (MAKE-060).

## 3. Skema v2 usulan

DDL lengkap ada di draf migration. Ringkasan per kolom:

### 3.1 `klien_layanan` [V2] (pengganti `simapi_auth`, `sal_user`, konstanta `MUDIG_*`)

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_klien` | INT UNSIGNED AI PK | [V2] | Kunci baru; legacy memakai `username` sebagai kunci |
| `nama` | VARCHAR(150) NOT NULL | [V2] | Nama sistem pemanggil |
| `username` | VARCHAR(100) NOT NULL, UNIQUE | [K-kode] ⚑ | User HTTP Basic (lebar dicocokkan D1) |
| `rahasia_hash` | VARCHAR(255) NOT NULL | [V2] BP-8 | `password_hash`; legacy plaintext (Lampiran B-1) |
| `jenis` | TINYINT NOT NULL, CHECK 1/2/3 | [K-kode] `type` + [V2] 3 | 1 SIMAPI lama/Pusdatin, 2 SIMAPI baru/Simanta, 3 layanan/MuDig |
| `hak` | SET(`simapi`,`stat`,`dm`,`esign`,`sinkron`) NOT NULL DEFAULT '' | [V2] | Grup endpoint (filter `klienLayanan:<hak>`) |
| `ip_allowlist` | VARCHAR(1000) NULL | [K-kode] `ip_address` + [V2] | NULL = semua IP; IP/CIDR dipisah koma |
| `status` | TINYINT DEFAULT 1, CHECK 1/2/10 | [K-kode] | Domain proyek |
| `rahasia_diubah_at` | DATETIME NULL | [V2] | Rotasi rahasia terakhir (`integrasi:klien rotasi`) |
| `created_at`/`updated_at`/`deleted_at` | DATETIME | [V2] | UTC |
| `created_by`/`updated_by` | INT NULL | [V2] | `id_pengguna` tanpa FK; NULL = command CLI |

### 3.2 `simapi_token` [K-kode]+[V2]

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `token_hash` | CHAR(64) PK | [V2] BP-8/F6-05 | SHA-256 heks token opak 30 karakter; token mentah tidak disimpan |
| `id_klien` | INT UNSIGNED NOT NULL, FK RESTRICT | [V2] | Ganti `username` |
| `status` | TINYINT DEFAULT 1, CHECK 1/2 | [K-kode] | 1 aktif, 2 dicabut |
| `expired_at` | DATETIME NOT NULL | [K-kode] | Terbit + 1 jam (`A_auth.php:77-78`); disimpan UTC (Q5) |
| `created_at` | DATETIME NOT NULL | [V2] | UTC |

Indeks: `idx_simapi_token_klien` (`id_klien`, `expired_at`), `idx_simapi_token_expired` (`expired_at`, pembersihan berkala).
**Satu baris per handshake** (F6-05): legacy mengembalikan token yang sama selama belum kedaluwarsa; v2 hanya menyimpan
hash sehingga tiap handshake menerbitkan token baru (token lama tetap sah sampai kedaluwarsa).

### 3.3 `simapi_jd` [K-kode]

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_simapi_jd` | INT AI PK | [K-kode] ⚑ | Nilai id **wajib sama dengan legacy** (dipakai klien di filter) |
| `jenis_data` | VARCHAR(100) NOT NULL, UNIQUE | [K-kode] ⚑ | Dikirim apa adanya di respons `change_history` |

Seed: **ditunda** sampai isi master D1 tersedia (DBV-026-Q3). Seed akan berupa migration/seeder terpisah dengan id eksplisit,
tanpa data pribadi.

### 3.4 `simapi_auth_jd` [K-kode]

PK (`id_klien`, `id_simapi_jd`); FK ke `klien_layanan` dan `simapi_jd` RESTRICT. Klien tanpa baris = semua jenis data
(`A_employee.php:63-68`).

### 3.5 `simapi_ch` [K-kode]

| Kolom | Tipe | Label | Catatan |
|---|---|---|---|
| `id_simapi_ch` | BIGINT UNSIGNED AI PK | [K-kode]+[V2] BIGINT (PRD §7.1) | Kursor `id > MAX(ack)` |
| `id_simapi_jd` | INT NOT NULL, FK RESTRICT | [K-kode] | |
| `id_rwy` | VARCHAR(50) NULL | [I] ⚑ | Id baris sumber; tipe legacy belum diketahui (untuk `pegawai` kemungkinan NIP) |
| `nip` | VARCHAR(30) NULL, tanpa FK | [K-kode]+[V2] Q6 | NULL untuk akun `pengguna` tanpa NIP |
| `action` | TINYINT NOT NULL, CHECK 1/2 | [K-kode] ⚑ | 1 = INSERT / UPDATE; 2 = DELETE [I] (kode hanya menguji `'1'`) |
| `action_at` | DATETIME NOT NULL | [K-kode] | Zona waktu: Q5 |
| `field_name` / `field_value` | TEXT NULL | [K] PRD §7.1 ⚑ | Format pipe |

Indeks: `idx_simapi_ch_jd` (`id_simapi_jd`, `id_simapi_ch`) untuk filter jenis + kursor; `idx_simapi_ch_nip` (`nip`) untuk
penelusuran/koreksi.

### 3.6 `simapi_auth_ch` [K-kode]

PK (`id_klien`, `id_simapi_ch`) — `MAX(id_simapi_ch)` per klien dibaca dari prefiks PK (indeks yang diminta PRD §7.1); kunci
PK sekaligus mencegah penanda ganda. `idx_simapi_auth_ch_ch` (`id_simapi_ch`) untuk FK. `created_at` [V2].

### 3.7 Deviasi & alasan (ringkas)

| Butir | Keputusan | Alasan |
|---|---|---|
| Rahasia klien di-hash, token SHA-256, `random_bytes` | [V2] BP-8, F6-05 | Legacy plaintext + `mt_rand` (Lampiran B-1/B-2) |
| Kunci `username` → `id_klien` + FK | [V2] | Integritas referensial; rotasi username tidak memutus relasi |
| `simapi_update`/`_hist` tidak dibuat | [V2] F6-06 | Ditulis trigger legacy tanpa pembaca |
| Kredensial, token, riwayat kirim legacy tidak diimpor | [V2] F6-08 | Klien diberi kredensial baru saat re-handshake (F6-03) |

## 4. Pemakaian aplikasi (MAKE-058/059/060, setelah approve)

- `integrasi:klien buat|rotasi|nonaktif|daftar` menulis `klien_layanan` (rahasia ditampilkan sekali).
- `a_auth/token`: Basic → klien jenis 1/2 status 1 → `random_bytes` → INSERT `simapi_token` (hash). Respons ikut legacy
  (`data.token` saja, keputusan reviewer 04-11-2026 atas Q6-02).
- Filter `simapiToken`: SHA-256 elemen kedua header `Authorization` → `status = 1 AND expired_at > UTC_TIMESTAMP()`.
- MAKE-060: penulis `simapi_ch` sesudah commit; `change_history` membaca `id_simapi_ch > MAX(simapi_auth_ch klien)`,
  filter `simapi_auth_jd`, `LIMIT 100` ASC, lalu INSERT penanda.

## 5. Pra-cek

Tabel baru tanpa data lama — tidak ada pra-cek data. `up()` gagal → semua tabel yang sempat dibuat di-DROP (urutan anak
dulu); `down()` men-DROP keenam tabel.

## 6. Kueri audit baca-saja

Berkas: `backend/docs/db-review/sql/DBV-026-audit-klien-simapi.sql` (hanya SELECT, hanya jumlah).

| ID | Kelas | Isi |
|---|---|---|
| B1 | WAJIB_0 | Rahasia klien bukan hash `password_hash` |
| B2 | WAJIB_0 | Hash token bukan 64 heks huruf kecil (pola DBV-030 V-01) |
| B3 | WAJIB_0 | Token aktif belum kedaluwarsa milik klien tidak aktif |
| B4 | WAJIB_0 | Jenis klien tidak cocok dengan hak `simapi` |
| B5 | INFO | Klien per jenis dan status |
| B6 | INFO | Token kedaluwarsa yang masih tersimpan |
| B7 | INFO | Baris change history per jenis data; baris belum dikirim |

## 7. Verifikasi developer

### 7.1 Hasil (04-11-2026) — DB scratch `simpeg_v2_t_dbv026`, MySQL 8.0.30 port 3307, grup `tests` (prefix `t_`)

Draf dijalankan lewat kelasnya dari `tests/database/Integrasi/SkemaKlienSimapiTest.php` (`db-isolasi-penuh`; ikut di PR
sehingga CI MySQL 8.0 & MariaDB 10.4 ikut menjalankannya). Bukan DB dev. Hasil: 4 test, 51 assertion, lolos.

| Uji | Hasil |
|---|---|
| Engine InnoDB, collation tabel & kolom string `utf8mb4_unicode_ci` (enam tabel); tipe `rahasia_hash`, `token_hash`, `hak` SET, `simapi_ch`; tanpa kolom rahasia/token mentah | ✅ |
| `username` ganda; token hash ganda; `jenis_data` ganda; `simapi_auth_jd` / `simapi_auth_ch` ganda | ✅ ditolak 1062 |
| `jenis` 4; `status` 3 (klien); `status` 3 (token); `action` 3 | ✅ ditolak 3819 (CHECK) |
| `hak` di luar SET | ✅ ditolak 1265 |
| Token untuk klien tidak ada; `simapi_ch` untuk jenis data tidak ada | ✅ ditolak 1452 |
| Hapus `simapi_ch` yang sudah ditandai; hapus `simapi_jd` dipakai; hapus klien ber-token | ✅ ditolak 1451 |
| `down()` → keenam tabel hilang; `up()` ulang berhasil | ✅ |
| Kedelapan kueri audit berjalan tanpa galat sintaks | ✅ |

## 8. Rencana test skema (setelah approve)

Test di atas dipertahankan sebagai test permanen (hapus `require_once` draf saat migration dipindah). Registry NIP
(`TabelAnakNip`) ditambah entri `simapi_ch.nip` sesuai keputusan Q6.

## 9. Keputusan yang diminta dari DB Validator

| ID | Pertanyaan | Usulan |
|---|---|---|
| DBV-026-Q1 | `hak` sebagai SET vs tabel relasi `klien_layanan_hak` | SET (lima nilai tetap, dibaca filter per request; perubahan daftar = ALTER ber-DBV) |
| DBV-026-Q2 | `ip_allowlist` VARCHAR(1000) daftar dipisah koma vs tabel terpisah | VARCHAR (jarang dipakai; divalidasi command saat simpan) |
| DBV-026-Q3 | Seed `simapi_jd` | Ditunda sampai isi master D1 tersedia; migration/seeder terpisah dengan id eksplisit sama dengan legacy |
| DBV-026-Q4 | Kolom ⚑ (`username`, `id_simapi_jd`, `jenis_data`, `id_rwy`, `action`, `field_*`) dicocokkan D1 | Risiko diterima seperti DBV-Q11; koreksi lewat ALTER ber-DBV bila D1 berbeda |
| DBV-026-Q5 | Zona waktu `expired_at` dan `action_at` | Simpan UTC (konvensi proyek); penyaji SIMAPI mengonversi ke WIB saat menulis respons agar nilai `action_at` sama dengan legacy (`NOW()` WIB) |
| DBV-026-Q6 | `simapi_ch.nip` tanpa FK, tidak ikut Ubah NIP; registry NIP butuh case baru `JenisRujukanNip::NonFkLogRiwayat` ("kolom log riwayat tanpa FK; sengaja tidak diganti koreksi NIP, baris lama tetap NIP lama") | Setujui (reviewer CR setuju 04-11-2026; case baru diputus DBV) |
| DBV-026-Q7 | Token per handshake (banyak baris per klien) vs satu baris per klien seperti legacy | Banyak baris (F6-05); pembersihan token kedaluwarsa oleh command berkala (B6) |
| DBV-026-Q8 | FK `simapi_auth_ch` → `simapi_ch` RESTRICT (pembersihan riwayat harus menghapus penanda dulu) | RESTRICT (ADR-008); retensi `simapi_ch` belum diputus (legacy tanpa pembersihan) |
| DBV-026-Q9 | Timestamp migration saat dipindah | Lebih baru dari migration terakhir di `main` |

## 9a. Pencocokan D1 (ISSUE-056)

Keputusan Horii 04-11-2026: pencocokan dengan DDL D1 dijadikan **ISSUE-056** (Trello 🚩 Issues `E0tqBYSG`) dan diproses
kemudian; DBV-026 berjalan dengan label [K-kode]/[I] (risiko sama dengan DBV-027 / DBV-Q11). QASMTASK-089/093/094/095 tidak
Done sebelum ISSUE-056 diimplementasikan.

| # | Objek | Yang dicocokkan dengan D1 | Nilai draf |
|---|---|---|---|
| P1 | `simapi_auth.username` → `klien_layanan.username` | Tipe/lebar, collation | VARCHAR(100) |
| P2 | `simapi_auth.type` → `klien_layanan.jenis` | Domain nilai (1/2 saja atau lebih) | TINYINT CHECK 1/2/3 (3 = [V2]) |
| P3 | `simapi_auth.ip_address` → `ip_allowlist` | Tipe/lebar, satu IP atau daftar | VARCHAR(1000) daftar |
| P4 | `simapi_token.expired_at`, `status` | Tipe, domain status | DATETIME; CHECK 1/2 |
| P5 | `simapi_jd.id_simapi_jd`, `jenis_data` | Tipe PK, lebar, UNIQUE | INT AI; VARCHAR(100) UNIQUE |
| P6 | **Seed `simapi_jd`** | Seluruh baris master (id + jenis_data) — id wajib sama dengan legacy | Belum ada seed (Q3) |
| P7 | `simapi_ch.id_simapi_ch` | Tipe (INT/BIGINT, unsigned) | BIGINT UNSIGNED |
| P8 | `simapi_ch.id_rwy` | Tipe (INT id riwayat / VARCHAR NIP untuk `pegawai`) | VARCHAR(50) NULL [I] |
| P9 | `simapi_ch.nip` | Lebar, NULL | VARCHAR(30) NULL |
| P10 | `simapi_ch.action` | Tipe dan nilai DELETE (kode hanya menguji `'1'`) | TINYINT CHECK 1/2 [I] |
| P11 | `simapi_ch.action_at` | Tipe; zona (`NOW()` server) | DATETIME (Q5) |
| P12 | `simapi_ch.field_name` / `field_value` | Tipe (TEXT/LONGTEXT/VARCHAR) | TEXT NULL |
| P13 | Indeks legacy `simapi_ch`, `simapi_auth_ch`, `simapi_auth_jd` | Indeks/UNIQUE yang ada di D1 | Lihat 3.4–3.6 |
| P14 | **Trigger penulis `simapi_ch`** (pegawai, pengguna, detail_anak, 13 `riwayat_*`) | Per tabel: `id_simapi_jd`, isi `id_rwy`, nilai `action` per INSERT/UPDATE/DELETE, daftar kolom dan urutan di `field_name`/`field_value`, pemisah | Bahan MAKE-060 (bukan DDL tabel); fixture kontrak A5 menunggu ini |

**Rencana koreksi bila D1 berbeda:**
1. Bila DBV-026 belum di `main`: draf di `MigrationsDitahan/` dikoreksi langsung, dokumen ini diperbarui, DBV me-review ulang delta.
2. Bila DBV-026 sudah di `main`: migration yang sudah ada **tidak diedit**; koreksi lewat migration ALTER baru dengan key
   DBV baru (dokumen review tersendiri, pra-cek data, `down()` bersih, test skema), lalu alur `[DBV]` biasa.
3. Seed `simapi_jd` (P6): migration/seeder terpisah dengan id eksplisit — tidak bergantung koreksi DDL.
4. Trigger (P14): tidak menjadi DDL v2; dipakai sebagai spesifikasi penulis event MAKE-060 dan fixture kontrak; koreksi di
   kode aplikasi + test, tanpa DBV kecuali mengubah tabel.

## 10. Syarat merge (⛔)

1. DB Validator menyetujui DBV-026 (tanggal + catatan dicatat di dokumen ini).
2. Migration dipindah ke `app/Database/Migrations/` dengan DDL tidak berubah dari yang disetujui (atau perubahan atas
   permintaan DBV tercatat), plus test skema dan registry NIP; CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4).
3. Merge oleh reviewer CR dengan izin Horii.

## 11. Checklist DB Validator

- [x] Nama tabel/kolom/FK/CHECK/indeks (Bagian 3)
- [x] Rahasia & token hanya hash (BP-8)
- [x] `simapi_ch.nip` tanpa FK + registry NIP (Q6 — di luar registry, README modul; 12.3)
- [x] Kolom ⚑ dicocokkan D1 atau risiko diterima (Q4 — risiko diterima, topologi FK dicocokkan ERD; 12.3)
- [x] Kueri audit (Bagian 6 — diuji dengan data pelanggaran; 12.1)

---

## 12. Hasil review DB Validator

**DBV dikerjakan sesi WS-2 atas perintah Horii 04-11-2026** (lewat reviewer CR; independen dari penulis PR). SHA yang
direview: **`79f00bb5`** (DDL sama dengan `bc11c67f`; `79f00bb5` hanya dokumen). **Keputusan: SETUJU**, dengan keputusan
Q6 berbeda dari usulan dan syarat aplikasi di Bagian 13.

### 12.1 Verifikasi

Lingkungan: MySQL 8.0.30 lokal (3307), DB scratch BARU `simpeg_v2_t_dbv026r` (dibuat kosong oleh DBV, bukan DB pelaksana
`simpeg_v2_t_dbv026`; `.env` ditulis sendiri, bukan salinan `.env` dev), worktree terpisah.

| Uji | Hasil |
|---|---|
| `SkemaKlienSimapiTest` (draf ditahan) | ✅ 4 test, 51 assertion |
| `migrate --all` (`main`) → draf disalin lokal sebagai migration (tidak di-commit) → `up()` → potret → `migrate:rollback` → `up()` ulang | ✅ `up()` hanya menjalankan migration ini; sesudah `down()` 0 dari 6 tabel tersisa, 140 tabel lain utuh; `mysqldump --no-data` sesudah `up()` ulang **identik** dengan `up()` pertama |
| DDL hasil (`SHOW CREATE`) | ✅ sama dengan Bagian 3: 6 PK, 2 UNIQUE, 6 FK `RESTRICT/RESTRICT` (padanan 6 dari 8 FK legacy; 2 sisanya milik `simapi_update_hist` yang tidak dibuat), 4 CHECK, indeks sesuai; tipe kolom FK = PK induk (`INT UNSIGNED` klien, `INT` jd, `BIGINT UNSIGNED` ch) |
| Kueri audit dengan data uji (tiap WAJIB_0 diberi tepat satu pelanggaran + baris sah: bcrypt & argon2id, heks huruf besar, token aktif klien nonaktif, jenis 1 tanpa `simapi`, klien status 10 dengan `simapi` yang harus dikecualikan) | ✅ B1 = B2 = B3 = B4 = 1 (tepat yang disisipkan); B5–B7 INFO sesuai data; skrip hanya SELECT |
| Hash token huruf besar dengan nilai sama | ✅ ditolak PK 1062 (collation `_ci`), sesuai catatan DBV-030 12.4 |
| Quality gate (draf lewat test, matriks PHPUnit 8 job MySQL 8.0 + MariaDB 10.4, PHPStan + CS-Fixer) | ✅ CI #566 (`bc11c67f`) hijau, dicek lewat halaman Actions |

Data uji dihapus dan migration lokal di-rollback; DB scratch kembali ke keadaan `main`.

### 12.2 Kesesuaian aturan skema proyek

| Aturan | Hasil |
|---|---|
| Label [K]/[K-kode]/[V2]/[I] | ✅ per kolom (Bagian 3); D1 tidak tersedia → [K-kode]/[I] dengan risiko DBV-Q11 (Q4) |
| `ENGINE=InnoDB`, `utf8mb4_unicode_ci` tabel & kolom string | ✅ (diuji test + dump) |
| Status 1/2/10 | ✅ `klien_layanan` 1/2/10 + `deleted_at`; `simapi_token` 1/2 diterima (token tidak dihapus lunak; dicabut = 2, kedaluwarsa dibersihkan keras, 13.2); tabel lain tanpa status ikut legacy |
| FK `RESTRICT/RESTRICT` (ADR-008), nama `fk_*`/`uk_*`/`idx_*`/`chk_*` | ✅ |
| Migration di `main` tidak diedit; `down()` | ✅ hanya CREATE tabel baru; `down()` = DROP urutan anak dulu, terbukti bersih |
| Registry NIP | ✅ dengan keputusan Q6 (12.3): tanpa entri `TabelAnakNip`; test B-21 (`NipCascadeTest::testRegistryCocokDenganSkema`) hanya memeriksa FK ke `pegawai(nip)` sehingga tidak terpengaruh |
| Rahasia (BP-8) | ✅ tanpa kolom rahasia/token mentah; audit hanya jumlah |
| Stempel (DBV-025) | ✅ `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP` diterima sebagai cadangan (sesi DB UTC sejak CR-086); aplikasi tetap mengisi UTC eksplisit |

**Bukti tambahan untuk Q4/P13 (D1 tetap tidak tersedia).** Di mesin DBV ada diagram ER struktur produksi `simpeg01.erd`
(DBeaver, 21-09-2026, di luar repo, tanpa data dan **tanpa kolom**). Isinya mengonfirmasi 8 tabel `simapi_*` dan 8 FK legacy:
`simapi_token`/`simapi_auth_jd`/`simapi_auth_ch`/`simapi_update_hist` → `simapi_auth` (kolom `username`),
`simapi_ch`/`simapi_auth_jd` → `simapi_jd`, `simapi_auth_ch` → `simapi_ch`, `simapi_update_hist` → `simapi_update`. Topologi
FK draf (dengan `id_klien` menggantikan `username`) **sama** dengan legacy, tanpa FK yang hilang atau tambahan untuk tabel yang
dibuat. Lebar kolom, indeks non-FK, aturan ON DELETE/UPDATE legacy, isi `simapi_jd`, dan trigger tetap butuh D1 (ISSUE-056).

### 12.3 Keputusan

| ID | Keputusan DBV |
|---|---|
| DBV-026-Q1 | **Setuju SET.** Lima nilai tetap, dibaca per request. Syarat: nilai baru hanya **ditambah di akhir** daftar (ALTER ber-DBV); urutan/nama nilai lama tidak diubah (SET disimpan sebagai bitmask posisi) |
| DBV-026-Q2 | **Setuju VARCHAR(1000)**, NULL = semua IP. Syarat MAKE-058: command menormalkan (tanpa spasi, tanpa entri ganda) dan menolak entri yang bukan IPv4/IPv6/CIDR sah sebelum simpan; filter tidak pernah menganggap string kosong sebagai "semua IP" (kosong ditolak saat simpan, simpan NULL) |
| DBV-026-Q3 | **Setuju ditunda.** Seed `simapi_jd` lewat migration/seeder terpisah ber-id eksplisit = legacy (ISSUE-056 P6), tanpa data pribadi, ber-DBV. Syarat go-live: `simapi_jd` terisi sebelum klien jenis 2 diaktifkan (13.2) |
| DBV-026-Q4 | **Risiko diterima** (pola DBV-027 / DBV-Q11) untuk kolom ⚑; topologi FK sudah cocok dengan ERD produksi (12.2). Koreksi mengikuti rencana 9a: sebelum di `main` draf dikoreksi + review delta; sesudah di `main` lewat migration ALTER key DBV baru |
| DBV-026-Q5 | **Setuju UTC** untuk `expired_at`, `action_at`, `created_at`. Filter token membandingkan dengan `UTC_TIMESTAMP()` (atau `NOW()` di sesi aplikasi yang sudah UTC); penyaji `change_history` mengonversi `action_at` ke WIB berformat `Y-m-d H:i:s` saat menulis respons (paritas nilai `NOW()` WIB legacy). Kueri audit dan alat di luar aplikasi wajib memakai `UTC_TIMESTAMP()` (B3/B6 sudah) |
| DBV-026-Q6 | **Setuju tanpa FK dan tidak ikut Ubah NIP; usulan case `JenisRujukanNip::NonFkLogRiwayat` DITOLAK.** `TabelAnakNip` adalah cakupan koreksi NIP: entri yang tidak diarahkan ulang butuh pengecualian ganda (case + `NipCascadeService::TIDAK_DIARAHKAN`), dan bila salah satu terlewat, log riwayat ikut ter-UPDATE sehingga bertentangan dengan legacy. Keputusan B-06 sudah menaruh kolom NIP tanpa FK yang tidak diganti legacy **di luar registry**, terdaftar di README modul (`app/Libraries/Kepegawaian/README.md` bagian "Kolom NIP tanpa FK di luar registry", yang sudah memuat `simapi_update.nip` sebagai log H-01 dan `simapi_ch` sebagai tabel legacy). Saat pemindahan: `simapi_ch` dipindah dari baris "Tabel legacy lain yang tidak ada di Mapping" ke baris tabel v2 dengan keterangan "log riwayat SIMAPI, DBV-026, tidak diarahkan ulang"; `TabelAnakNip`, `JenisRujukanNip`, dan jumlah entri registry tidak berubah. Bagian 8 kalimat registry dibaca sesuai keputusan ini |
| DBV-026-Q7 | **Setuju banyak baris per handshake** (F6-05), dengan syarat pembersihan berkala (13.2) dan pencabutan token saat klien dinonaktifkan |
| DBV-026-Q8 | **Setuju RESTRICT.** Retensi `simapi_ch` belum diputus (legacy tanpa pembersihan); bila kelak diputus, penanda `simapi_auth_ch` dihapus lebih dulu dalam transaksi yang sama, lewat dokumen DBV/runbook tersendiri |
| DBV-026-Q9 | Timestamp saat dipindah **lebih baru dari migration terakhir di `main` saat itu** (kini `2026-11-03-110000`, DBV-030) dan dari migration lain yang masuk lebih dulu |

### 12.4 Catatan non-blok

- **`up()` gagal → `down()` men-DROP keenam nama tabel dengan `IF EXISTS`**, termasuk tabel bernama sama yang sudah ada
  sebelum `up()` (penyebab `CREATE` gagal). DB v2 terpisah dan tabel `simapi_*` legacy tidak diimpor (F6-08), jadi risikonya
  kecil; pola sama dengan DBV-027/DBV-030. Saran (tidak wajib): `up()` mencatat tabel yang benar-benar dibuat dan hanya
  men-DROP itu saat gagal.
- Komentar B7 kedua di kueri audit menyebut "klien type 2 aktif", padahal kuerinya menghitung baris tanpa penanda dari klien
  mana pun. Angkanya tetap benar sebagai INFO; komentar boleh dirapikan saat pemindahan.
- `token_hash` ber-collation `utf8mb4_unicode_ci`: aman karena aplikasi menulis heks huruf kecil (`hash('sha256', …)`) dan
  audit B2 WAJIB_0 (pola DBV-030).
- Baris Mapping Migrasi baru tumpang tindih dengan baris lama "Antrean `simapi_*`"; isinya tidak bertentangan dan lebih
  rinci, boleh dibiarkan.

### 12.5 Persetujuan

**DBV APPROVE @`79f00bb5`** — 04-11-2026, DBV (sesi WS-2 atas perintah Horii). Syarat merge §10 tetap, ditambah Bagian 13.

---

## 13. Syarat pemindahan dan syarat aplikasi

### 13.1 Pemindahan (sebelum merge, diverifikasi DBV)

1. Migration dipindah ke `app/Database/Migrations/<timestamp Q9>_CreateKlienLayananSimapi.php` dengan DDL **tidak berubah**
   dari `79f00bb5` (perubahan hanya atas permintaan DBV dan dicatat di sini).
2. Test skema permanen: `require_once` `MigrationsDitahan` dihapus, test tetap menguji struktur/constraint/`down()`/audit
   (pola DBV-027/DBV-030).
3. README modul Kepegawaian diperbarui sesuai Q6; **tidak** ada perubahan `TabelAnakNip`/`JenisRujukanNip`.
4. CI `quality-gate` hijau (MySQL 8.0 & MariaDB 10.4) pada SHA pemindahan; DBV memverifikasi delta lalu melapor ke reviewer CR.

### 13.2 Syarat aplikasi (MAKE-058/059/060; diuji di paket masing-masing, bukan syarat merge DBV-026)

| # | Paket | Syarat |
|---|---|---|
| A1 | MAKE-058 | `integrasi:klien nonaktif` (dan hapus/status 10) mencabut semua token aktif klien itu (`simapi_token.status = 2`) di transaksi yang sama → audit B3 = 0 |
| A2 | MAKE-058 | Pembersihan berkala token kedaluwarsa (DELETE `expired_at < UTC_TIMESTAMP() - INTERVAL n`), dijadwalkan di runbook; B6 = bahan pemantauan |
| A3 | MAKE-058 | Validasi `ip_allowlist` (Q2) dan `hak` sesuai jenis (B4 = 0) di command |
| A4 | MAKE-059 | Filter `simapiToken` mencari baris lewat PK `token_hash` = SHA-256 heks huruf kecil dari elemen kedua header `Authorization` (panjang dibatasi sebelum di-hash), lalu `status = 1 AND expired_at > UTC_TIMESTAMP()` dan klien status 1 (Q5). Perbandingan rahasia klien tetap `password_verify`; `hash_equals` (BP-8) dipakai bila ada perbandingan hash di PHP |
| A5 | MAKE-060 | Ack `simapi_auth_ch` tahan balapan: dua `change_history` serentak dari klien yang sama membaca `MAX` yang sama, sehingga INSERT penanda kedua bertabrakan PK 1062. Pakai kunci baris klien (`SELECT … FOR UPDATE` pada `klien_layanan`) dalam satu transaksi baca+tandai, atau `INSERT IGNORE` + respons hanya baris yang berhasil ditandai; diuji |
| A6 | MAKE-060 | Koreksi NIP (B-06) tidak mengubah baris `simapi_ch` lama (Q6). Baris baru yang dihasilkan perubahan NIP mengikuti cabang trigger legacy `IF OLD.nip != NEW.nip` (ISSUE-056 P14) begitu D1 tersedia |
| A7 | go-live | `simapi_jd` terisi (Q3) sebelum klien jenis 2 diaktifkan; tanpa seed, FK `fk_simapi_ch_jd` menolak setiap penulisan `simapi_ch` (1452) |
