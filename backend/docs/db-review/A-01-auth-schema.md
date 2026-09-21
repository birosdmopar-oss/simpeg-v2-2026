# DB Validator Review — A-01 Migration Tabel Auth (Modul A)

**Status:** MENUNGGU APPROVAL DB VALIDATOR (SOP Bagian 2 langkah 2). Migration sudah ditulis dan hanya dijalankan di database lokal/test. **Jangan dijalankan di Dev/Production sebelum kolom "Keputusan" di bawah diisi.**

**Rujukan:** `01-Auth.md` A-01, Tech Spec §2.2 A-01, `Mapping_Migrasi_Data_SIMPEG_v2.docx` Tier 2, `simpeg_v2_local_seed.sql` (pengguna, login_attempts), `Legacy_System_Audit_SIMPEG.docx` (Skema Database → Autentikasi & Akun).

File migration:

| File | Tabel |
|---|---|
| `app/Database/Migrations/2026-09-17-000001_CreatePengguna.php` | `pengguna` |
| `app/Database/Migrations/2026-09-17-000002_CreateLoginAttempts.php` | `login_attempts` |
| `app/Database/Migrations/2026-09-17-000003_CreateForgotAttempts.php` | `forgot_attempts` |
| `app/Database/Migrations/2026-09-17-000004_AlterAuditLogsEventAddAuth.php` | `audit_logs.event` (+ `login`, `logout`) |

## 1. Checklist DoD A-01

| # | Kriteria DoD | Hasil | Bukti |
|---|---|---|---|
| 1 | Skema direview & di-approve DB Validator sebelum dijalankan | **PENDING** | dokumen ini |
| 2 | Kolom `password_decode` tidak ada | OK | test `Tests\Auth\AuthSchemaTest::testPasswordDecodeColumnDoesNotExist` |
| 3 | `password` bertipe cukup panjang untuk hash (min 255) | OK | `VARCHAR(255)`, test `testPasswordColumnLength` |
| 4 | FK `pengguna.nip → pegawai.nip` (nullable sementara / di-defer) | **DI-DEFER** ke Fase 3 (B-01) | `nip` tetap `NOT NULL UNIQUE`; FK ditambahkan lewat migration terpisah setelah `pegawai` ada. Sama untuk `id_unit`/`id_satker` (Fase 2). **Perlu konfirmasi DB Validator.** |

## 2. pengguna — perbandingan vs legacy & seed

| Kolom v2 | Tipe | Legacy | Catatan |
|---|---|---|---|
| id_pengguna | INT UNSIGNED PK AI | id_pengguna | sama |
| nip | VARCHAR(20) NOT NULL UNIQUE | id_pegawai/NIP | rujukan ke pegawai memakai `nip` (mayoritas relasi legacy pakai nip, bukan id_pegawai) |
| username | VARCHAR(30) NOT NULL UNIQUE | username | default = NIP (A-09) |
| password | VARCHAR(255) NULL | password (MD5/SHA) | Argon2id; NULL sampai login pertama sukses (lazy rehash, A-02b) |
| password_legacy | VARCHAR(32) NULL | password | hash MD5 legacy, DEPRECATED; dikosongkan setelah rehash; **hapus total setelah masa transisi (usulan 3 bulan, [TBD])** |
| ~~password_decode~~ | — | password_decode (plaintext) | **DIBUANG, tidak dimigrasi** |
| user_level | TINYINT UNSIGNED NOT NULL | UserLevel → user_level (tabel) | role constants 1-8, bukan tabel (ADR-005) |
| id_unit / id_satker | VARCHAR(10) NULL | sama | FK ditunda sampai master unit/satker (Fase 2) |
| status | ENUM('0','1') default '1' | status | 0 = nonaktif (tidak bisa login) |
| last_login_at | DATETIME NULL | — | baru: dipakai audit login (update via Model → audit_logs) |
| password_changed_at | DATETIME NULL | — | baru: jejak A-06/A-07 |
| created_at / updated_at / deleted_at | DATETIME NULL | created_at | soft delete untuk "hapus akun" (A-08) agar histori audit tetap konsisten |

Index: UNIQUE(nip), UNIQUE(username), KEY(user_level), KEY(id_satker), KEY(status).

## 3. login_attempts (sesuai seed)

| Kolom | Tipe |
|---|---|
| id | INT UNSIGNED PK AI |
| username | VARCHAR(30) NOT NULL |
| ip_address | VARCHAR(45) NULL |
| success | TINYINT(1) default 0 |
| attempted_at | DATETIME NOT NULL |

Index: KEY(username, attempted_at). Data legacy tidak dimigrasi (Mapping Migrasi: log, mulai fresh).

## 4. forgot_attempts (usulan — tidak ada di seed)

Legacy: request dicatat di `forgot_attempts`, token disimpan di `token`. Di v2 tabel `token` sudah dipakai refresh token JWT (F0-06), jadi token reset disatukan di `forgot_attempts` (1 baris = 1 permintaan = 1 token single-use).

| Kolom | Tipe | Catatan |
|---|---|---|
| id | INT UNSIGNED PK AI | |
| username | VARCHAR(30) NOT NULL | dicatat walau username tidak dikenal (rate limit anti-enumerasi) |
| ip_address | VARCHAR(45) NULL | |
| token_hash | CHAR(64) NULL UNIQUE | SHA-256; NULL kalau username tidak dikenal |
| expires_at | DATETIME NULL | requested_at + `auth.resetTokenTtl` |
| used_at | DATETIME NULL | terisi saat dipakai → reuse ditolak |
| requested_at | DATETIME NOT NULL | |

Index: UNIQUE(token_hash), KEY(username, requested_at).

## 5. audit_logs.event

ENUM diperluas: `create, update, delete` → `+ login, logout` (A-10). Tidak mengubah data yang sudah ada.

## 6. Keputusan yang diminta dari DB Validator / Tech Lead

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| 1 | FK `pengguna.nip → pegawai.nip`: defer ke Fase 3 atau buat kolom nullable sekarang? | Defer (nip tetap NOT NULL UNIQUE) | |
| 2 | Skema `forgot_attempts` (Bagian 4) | Setujui | |
| 3 | Masa transisi `password_legacy` sebelum kolom dihapus | 3 bulan pasca go-live | |
| 4 | `pengguna.deleted_at` (soft delete) vs hard delete untuk `user/delete` | Soft delete + `status` untuk nonaktif | |
