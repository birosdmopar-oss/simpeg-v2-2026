# DB Validator Review — DBV-031 koreksi SIMAPI hasil D1 (Fase 6 Jalur A, ISSUE-056/057)

| | |
|---|---|
| Key | DBV-031 (alur [CR]+[DBV]: PR → review/approve DB Validator → merge reviewer CR dengan izin Horii) |
| Paket | Izin [#3-WorkloadSimapiD1] (Horii 09-11-2026): DBV-031 → A5b (MAKE-060 lanjutan) → MAKE-076 |
| Dasar | DBV-026 (di `main` 7185bdd5) §9a P1–P14 + rencana koreksi; PRD Fase 6 v1.0n (F6-06, Q6-02/03/39/41, Lampiran A.1) |
| Sumber D1 | DDL + isi `simapi_jd` + definisi trigger produksi (struktur saja, tanpa data), diambil Horii 09-11-2026, diperiksa reviewer. Isi mentah TIDAK disalin ke repo; dokumen ini hanya memuat spesifikasi turunan |
| Migration | `2026-11-09-100000_AlterSimapiChD1`, `2026-11-09-100100_CreateSimapiUpdate`, `2026-11-09-100200_SeedSimapiJd` |
| Test | `tests/database/Integrasi/SkemaSimapiD1Test.php` (`db-isolasi-penuh`) |
| Audit | `docs/db-review/sql/DBV-031-audit-simapi-d1.sql` (hanya SELECT) |

## 0. Ringkasan

- Server legacy: MySQL 8.0.21, `sql_mode` NO_ENGINE_SUBSTITUTION (non-strict), zona waktu sistem WIB.
- Delapan tabel `simapi_*` legacy (InnoDB, `utf8mb4_unicode_ci`). Enam sudah dibuat DBV-026 dengan pola v2; DBV-031 menambah
  **`simapi_update` + `simapi_update_hist`** (Q6-41: API SIMAPI lama `simapi/a_pegawai/pegawai_update` ikut legacy, MAKE-076).
- Koreksi `simapi_ch` hasil pencocokan: `id_rwy` INT, `nip` NOT NULL, `field_name` MEDIUMTEXT, `field_value` LONGTEXT.
- Seed `simapi_jd` 14 baris dengan id EKSPLISIT = legacy (DBV-026 §13.2 A7: tanpa seed penulis `simapi_ch` ditolak FK).
- Tidak ada perubahan pada migration yang sudah di `main`; tidak ada data pegawai.

## 1. Pencocokan D1 per butir (DBV-026 §9a)

| # | Butir | D1 (legacy) | v2 (DBV-026) | Keputusan |
|---|---|---|---|---|
| P1 | `simapi_auth.username` → `klien_layanan.username` | VARCHAR(50) NOT NULL, PK | VARCHAR(100) UNIQUE | **Tetap** [V2] (lebih lebar; username lama muat) |
| P2 | `simapi_auth.type` → `jenis` | TINYINT 1 API lama (Pusdatin) / 2 API baru (Simanta) | CHECK 1/2/3 | **Tetap** (cocok; 3 = layanan [V2]) |
| P3 | `simapi_auth.ip_address` → `ip_allowlist` | VARCHAR(50) NOT NULL, satu IP; API lama mencocokkan IP di token dan endpoint data | VARCHAR(1000) NULL, daftar IP/CIDR | **Tetap** — lihat §3.1 |
| P4 | `simapi_token.status`, `expired_at` | status 1/2/3 (COMMENT 3 = Expired), `expired_at` NULL | CHECK 1/2, `expired_at` NOT NULL | **Tetap** [V2] — lihat §3.2 |
| P5 | `simapi_jd` | `id_simapi_jd` TINYINT AI, `jenis_data` VARCHAR(100) TANPA UNIQUE (isi unik) | INT AI, UNIQUE | **Tetap** [V2] (isi 14 baris unik) |
| P6 | Seed `simapi_jd` | 14 baris (AUTO_INCREMENT 15) | kosong | **Seed** 14 id eksplisit (§2.3) |
| P7 | `simapi_ch.id_simapi_ch` | BIGINT signed | BIGINT UNSIGNED | **Tetap** [V2] |
| P8 | `simapi_ch.id_rwy` | INT NULL (NULL untuk Data Umum/pengguna dan DELETE) | VARCHAR(50) NULL [I] | **Koreksi** → INT NULL [K] |
| P9 | `simapi_ch.nip` | VARCHAR(30) NOT NULL | VARCHAR(30) NULL | **Koreksi** → NOT NULL [K] — lihat §3.3 |
| P10 | `simapi_ch.action` | TINYINT 1 INSERT/UPDATE, 2 DELETE | CHECK 1/2 | **Tetap** (cocok) |
| P11 | `simapi_ch.action_at` | DEFAULT CURRENT_TIMESTAMP (WIB server) | diisi aplikasi UTC (DBV-026-Q5) | **Tetap** [V2]; dikirim WIB |
| P12 | `simapi_ch.field_name` / `field_value` | MEDIUMTEXT / LONGTEXT | TEXT / TEXT | **Koreksi** → MEDIUMTEXT / LONGTEXT [K] (TEXT 64 KB bisa memotong) |
| P13 | Indeks & FK `simapi_*` | FK CASCADE/CASCADE; kunci klien `username` | FK RESTRICT; kunci `id_klien`; indeks setara | **Tetap** [V2] (DBV-026; klien dihapus lunak) |
| P14 | Trigger penulis `simapi_ch`/`simapi_update` | 47 trigger (§4) | — | Bukan DDL; spesifikasi A5b/MAKE-076 |

## 2. Perubahan skema

### 2.1 `simapi_ch` (ALTER)

`id_rwy` INT NULL, `nip` VARCHAR(30) NOT NULL, `field_name` MEDIUMTEXT NULL, `field_value` LONGTEXT NULL. Pra-cek sebelum ALTER
(tanpa mengubah data): `nip` NULL atau `id_rwy` bukan bilangan bulat → migration gagal dengan jumlah baris yang melanggar.
`down()` mengembalikan tipe DBV-026.

### 2.2 Tabel baru

`simapi_update` [K D1]: `id_simapi_update` INT UNSIGNED AI PK, `nip` VARCHAR(30) NOT NULL (tanpa FK, log), `last_action`
VARCHAR(30) NOT NULL + CHECK IN ('INSERT','UPDATE','DELETE') [V2; nilai yang dipakai trigger D1 persis tiga itu],
`last_update` DATETIME NOT NULL (UTC [V2]), `nama_field`/`nilai_lama`/`nilai_baru` MEDIUMTEXT NULL (pipe), `created_at`;
KEY `nip` [V2].

`simapi_update_hist` [K D1]: `id_klien` (FK `klien_layanan` RESTRICT; legacy `username`), `id_simapi_update` (FK RESTRICT),
PK (`id_klien`, `id_simapi_update`), `created_at`. Pola `simapi_auth_ch`.

`simapi_update.nip` di luar registry NIP (`TabelAnakNip`), sama dengan `simapi_ch.nip` (DBV-026-Q6): log riwayat, koreksi NIP tidak
mengubah baris lama. Dicatat di README modul Kepegawaian dan `NON_FK` `NipReferenceRegistryTest`.

### 2.3 Seed `simapi_jd`

1 Data Umum, 2 Alamat, 3 Keluarga, 4 Pendidikan, 5 Pelatihan, 6 Kursus Seminar, 7 Organisasi, 8 SKP, 9 Pangkat, 10 Jabatan,
11 KGB, 12 Tanda Jasa, 13 Hukuman Disiplin, 14 Anak. Migration data terpisah: baris yang sudah sama dilewati (aman diulang);
id yang berisi lain → gagal tanpa mengubah data; `down()` menghapus 14 id ini (gagal bila sudah dirujuk, FK RESTRICT).

## 3. Catatan keputusan

### 3.1 IP klien API lama (P3, Q6-41)

API lama `simapi/a_pegawai/*` tidak memeriksa `type`; syarat **allowlist tidak kosong + IP cocok** berlaku untuk SEMUA klien yang
memanggilnya, bukan per jenis. Allowlist kosong/NULL → gagal seperti legacy: "Authentication failed" di `token`, "Token is not
valid" di `detail`/`pegawai_update` (MAKE-076). API baru (`simapi/v1/*`) tetap: allowlist NULL = semua IP (ADR-036).

### 3.2 Status token (P4)

Kode legacy hanya menulis status 1 (A_auth.php, A_pegawai.php); nilai 3 tidak pernah ditulis kode. Bila baris token legacy ikut
diimpor, status 3 dipetakan ke 2. **Token TIDAK diimpor** (keputusan Horii 09-11-2026; F6-05 hash + CSPRNG; klien re-handshake, PN-4).

### 3.3 `nip` NOT NULL (P9)

Trigger legacy selalu mengisi `nip` (NEW/OLD.nip, atau `pengguna.id_pegawai`, atau hasil lookup untuk `detail_anak`). Akun
`pengguna` tanpa NIP (mis. akun sistem integrasi) TIDAK dicatat oleh penulis v2 (PRD §7.5, CR-093), jadi NOT NULL tidak
menolak kasus sah. `detail_anak` yang induk keluarganya tidak ditemukan → tidak dicatat (A5b, dicatat di README).

### 3.4 Waktu

`action_at` dan `last_update` disimpan UTC [V2]. `change_history` (A5a) dan `pegawai_update` (MAKE-076) WAJIB mengirim WIB
`Y-m-d H:i:s`, setara `NOW()` server legacy.

### 3.5 Data lama tidak dimigrasi (KEPUTUSAN Horii 09-11-2026, opsi a)

`simapi_ch` (±12 juta baris), `simapi_auth_ch`, `simapi_update` (±56 ribu), `simapi_update_hist` legacy **TIDAK dimigrasi**
(keputusan Horii 09-11-2026, opsi a): antrean v2 mulai kosong saat cutover; perubahan sebelum cutover yang belum diambil klien
dianggap selesai saat freeze. `simapi_token` juga tidak diimpor (klien re-handshake, §3.2).
Risiko: klien yang tertinggal menarik perubahan sebelum freeze kehilangan baris itu → mitigasi (masuk daftar PN re-handshake):
klien diminta menarik antrean `change_history`/`pegawai_update` sampai habis sebelum freeze; bila perlu klien melakukan tarik penuh (`detail`/`data_umum`) sekali
sesudah cutover. Dicatat juga di `docs/migrasi/Mapping_Migrasi_Tidak_Dimigrasi.md`.

## 4. Ringkasan trigger D1 (P14) — spesifikasi turunan, tanpa isi mentah

Penulis `simapi_ch` (`id_simapi_jd`, `id_rwy`, sumber `nip`, jumlah field di `field_name`/`field_value`, pemisah `|`, nilai
NULL → '' lewat COALESCE):

| Tabel sumber | jd | id_rwy | nip | Field | Peristiwa |
|---|---|---|---|---|---|
| `pegawai` | 1 | NULL | NEW/OLD.nip | 13 | INS/UPD → action 1; DEL → 2 |
| `pengguna` | 1 | NULL | `id_pegawai` (NEW/OLD) | 2 (nip, email) | INS/UPD saja, bersyarat (status aktif, level pegawai, email berubah) |
| `riwayat_alamat` | 2 | id riwayat | NEW/OLD.nip | 13 | status 1 → 1; status 2 → 2; DEL → 2 |
| `riwayat_keluarga` | 3 | id riwayat | NEW/OLD.nip | 7 | idem |
| `riwayat_pendidikan` | 4 | id riwayat | NEW/OLD.nip | 9 | idem |
| `riwayat_diklat` | 5 | id riwayat | NEW/OLD.nip | 9 | idem |
| `riwayat_seminar` | 6 | id riwayat | NEW/OLD.nip | 10 | idem |
| `riwayat_organisasi` | 7 | id riwayat | NEW/OLD.nip | 7 | idem |
| `riwayat_skp` | 8 | id riwayat | NEW/OLD.nip | 17 | idem |
| `riwayat_kp` | 9 | id riwayat | NEW/OLD.nip | 13 | idem |
| `riwayat_mutasi_jabatan` | 10 | id riwayat | NEW/OLD.nip | 22 | idem |
| `riwayat_kgb` | 11 | id riwayat | NEW/OLD.nip | 8 | idem |
| `riwayat_tanda_jasa` | 12 | id riwayat | NEW/OLD.nip | 7 | idem |
| `riwayat_hukdis` | 13 | id riwayat | NEW/OLD.nip | 16 | idem |
| `detail_anak` | 14 | id anak (NULL saat DEL) | lookup `riwayat_keluarga.nip` | 6 | INS/UPD → 1; DEL → 2 |

Cabang ganti NIP (`OLD.nip != NEW.nip`): baris ditulis dengan **OLD.nip**. Penulis `simapi_update` (MAKE-076): `pegawai`
(INS/UPD/DEL), `pegawai_mutasi_jabatan` (INS/UPD/DEL), `pengguna` (INS/UPD; termasuk username|password|email). Tulisan lain di
trigger yang sama (tabel SIASN, URL arsip foto) di luar scope; URL foto v2 lewat `PenyajiPegawai::urlFoto()` (Q6-39). Rincian
urutan kolom per tabel menjadi fixture kontrak A5b.

## 5. Uji

`SkemaSimapiD1Test`: tipe kolom `simapi_ch`, struktur & collation dua tabel baru, seed 14 id, CHECK `last_action` (3819/4025),
NOT NULL `nip` (1048), PK ganda (1062), FK (1451/1452), pra-cek ALTER gagal jelas tanpa mengubah data, seed menolak isi berbeda,
siklus `down()`/`up()`. Audit `DBV-031-audit-simapi-d1.sql`: seed lengkap, nip kosong, `last_action` di luar domain, penanda tanpa
induk (hanya jumlah).
