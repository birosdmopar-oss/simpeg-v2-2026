# DB Validator Review — A-01 Migration Tabel Auth (Modul A)

**Status:** ✅ **DISETUJUI DB VALIDATOR — lanjut** (21-09-2026, jjoseph48). Persetujuan disertai tindak lanjut di Bagian 8 yang wajib diselesaikan sebelum sign-off akhir Fase 1 / deploy Production.

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
| 1 | Skema direview & di-approve DB Validator sebelum dijalankan | ✅ DISETUJUI (21-09-2026) | dokumen ini, Bagian 6-8 |
| 2 | Kolom `password_decode` tidak ada | OK | test `Tests\Auth\AuthSchemaTest::testPasswordDecodeColumnDoesNotExist` |
| 3 | `password` bertipe cukup panjang untuk hash (min 255) | OK | `VARCHAR(255)`, test `testPasswordColumnLength` |
| 4 | FK `pengguna.nip → pegawai.nip` (nullable sementara / di-defer) | ✅ DI-DEFER ke Fase 3 (B-01), dikonfirmasi DB Validator | `nip` tetap `NOT NULL UNIQUE`; FK ditambahkan lewat migration terpisah setelah `pegawai` ada. Sama untuk `id_unit`/`id_satker` (Fase 2). Syarat: collation diseragamkan dulu (Bagian 8 #3). |

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
| 1 | FK `pengguna.nip → pegawai.nip`: defer ke Fase 3 atau buat kolom nullable sekarang? | Defer (nip tetap NOT NULL UNIQUE) | ✅ Disetujui: defer, dengan syarat collation diseragamkan (Bagian 8 #3) |
| 2 | Skema `forgot_attempts` (Bagian 4) | Setujui | ✅ Disetujui, dengan perbaikan race token reset (Bagian 8 #2) |
| 3 | Masa transisi `password_legacy` sebelum kolom dihapus | 3 bulan pasca go-live | ✅ Disetujui 3 bulan. Akun yang belum pernah login saat kolom dihapus hanya bisa masuk lewat lupa password — siapkan komunikasinya |
| 4 | `pengguna.deleted_at` (soft delete) vs hard delete untuk `user/delete` | Soft delete + `status` untuk nonaktif | ✅ Disetujui, dengan jalur restore akun — diperbaiki di Fase 3 (Bagian 8 #6) |

## 7. Checklist DB Validator DEV-002 — Fase 1 Auth dan Akun (14 task)

Divalidasi 21-09-2026 di lokal (MariaDB 10.4, `simpeg_v2` + `simpeg_v2_testing`): migrate + rollback + migrate ulang, PHPUnit 162/163 (1 gagal = test queue Fase 0, `SKIP LOCKED` tidak didukung MariaDB 10.4), PHPStan tanpa error, CS-Fixer 0 file. Race condition diuji dengan dua pemanggilan kode asli secara bersamaan.

- [x] **A-01** Migration tabel auth — `password_decode` tidak ada, `password` VARCHAR(255), `nip` NOT NULL UNIQUE, DDL ter-apply sesuai migration, rollback berjalan
- [x] **A-02** Endpoint login — baca `pengguna`, tulis `login_attempts`, username maks. 30 karakter
- [x] **A-02b** Lazy rehash MD5 → Argon2id — `password` NULL → `$argon2id$`, `password_legacy` dikosongkan
- [x] **A-03** Captcha Turnstile — ditolak sebelum cek kredensial, secret dari `.env`
- [x] **A-04** Lockout — N=5, percobaan ke-6 ditolak, reset setelah sukses
- [x] **A-05** Refresh & logout — rotasi token, logout menghapus row *(tindak lanjut Bagian 8 #1)*
- [x] **A-06** Ganti password — Argon2id, seluruh refresh token dicabut
- [x] **A-07** Lupa/reset password — expiry, rate limit *(tindak lanjut Bagian 8 #2)*
- [x] **A-08** CRUD akun scoped — role 3 terbatas satker sendiri, role lain 403
- [x] **A-09** Lifecycle akun otomatis — username = NIP *(regression test ulang di akhir Fase 3, B-05)*
- [x] **A-10** Audit trail auth — login, logout, ganti password, perubahan akun tercatat; hash dimasking `***` *(tindak lanjut Bagian 8 #4)*
- [x] **A-11** Halaman login (FE) — di luar lingkup DB
- [x] **A-12** Halaman manajemen akun (FE) — di luar lingkup DB
- [x] **A-13** Unit test RBAC & JWT — seluruh test Modul A lulus, terintegrasi `check.sh`

## 8. Tindak lanjut (wajib sebelum sign-off akhir / Production)

| # | Temuan | Task | Tindakan |
|---|---|---|---|
| 1 | Race refresh token: 1 token dipakai 2x bersamaan → 2 sesi aktif, reuse detection tidak jalan (terbukti) | A-05 | `UPDATE token SET revoked=1 WHERE id=? AND revoked=0`, lanjut hanya jika affected rows = 1. **Status: diperbaiki di branch `fix/refresh-token-race` (24-09-2026), menunggu merge + QA ulang QASMTASK-024 + konfirmasi DB Validator.** `TokenModel::revoke()` bersyarat `revoked=0` + cek affected rows (error DB dilempar, tidak dibaca sebagai 0 baris); `JwtService::refresh()` menjalankan revoke + INSERT token baru dalam SATU transaksi, sehingga lock baris token lama ditahan sampai token baru ter-commit. Affected rows 0 → rollback lalu baca ulang: baris masih ada = reuse (cabut semua sesi, 401); baris sudah dihapus logout = token tidak dikenal (401, sesi lain tetap). Test regresi `JwtServiceTest`: interleaving deterministik; koneksi DB kedua untuk urutan "UPDATE pemenang → revokeAll pihak kalah → INSERT pemenang"; error DB / INSERT gagal di kedua mode `DBDebug`. Uji proses paralel, 1 token: sebelum PR = 8× 200 / 8 sesi aktif. Di MySQL 8.0.30 lokal, revoke bersyarat tanpa transaksi selalu 1× 200, tetapi dengan 2 proses 15/15 percobaan masih menyisakan 1 sesi aktif (8 proses: 0/5). Dengan transaksi: 2 proses ×15 dan 8 proses ×5 selalu 1× 200 + sisanya 401 reused / 0 sesi aktif |
| 2 | Race token reset: 1 token dipakai 2x → 2 ganti password berhasil (terbukti); token reset lain milik user tetap berlaku | A-07 | `UPDATE forgot_attempts SET used_at=? WHERE id=? AND used_at IS NULL` + cek affected rows, dalam transaksi; batalkan token reset lain setelah sukses |
| 3 | Collation app `utf8mb4_general_ci` vs seed `utf8mb4_unicode_ci` — FK ke `pegawai` (Fase 3) gagal di MySQL 8 | A-01 | Tetapkan satu collation dan set eksplisit |
| 4 | Audit reset password `nip_actor = NULL` | A-10 | Isi NIP pemilik akun sebagai actor |
| 5 | `down()` `AlterAuditLogsEventAddAuth` menghapus baris audit login/logout | A-01 | Tolak rollback jika baris tsb ada |
| 6 | Akun soft-delete tidak bisa dibuat ulang/dipulihkan; `AccountProvisioner` mengembalikan akun terhapus diam-diam | A-08, A-09 | Endpoint restore atau provisioner memulihkan akun — **dijadwalkan Fase 3** (bersama regression A-09 di B-05) |
| 7 | Timezone: app `UTC`, server DB WIB (UTC+7); data legacy kemungkinan WIB | lintas | Putuskan sebelum migrasi data legacy & Fase 5 |
| 8 | `strictOn = false` (app) vs `true` (test) | lintas | Aktifkan strict mode di Dev/Prod |
| 9 | Legacy `pengguna.id_pegawai` berisi NIP atau `pegawai.id_pegawai`? Legacy `nip` VARCHAR(30) vs v2 VARCHAR(20) | Mapping | Jalankan query cek di DB legacy |
| 10 | Tabel legacy yang merujuk `id_pengguna` (`fb_token`, `fb_pn_queue`, `news`, `news_flag`, `user_log`); `news` → `user_level` | Mapping | Pertahankan nilai `id_pengguna` saat migrasi atau ubah rujukan ke `nip` |
| 11 | Tabel legacy yang tidak dimigrasi: `token` (nama sama, isi beda), `pengguna_2021…2024` (kemungkinan berisi plaintext), `password_resets`, `oauth_*`, `ci_sessions`, `user_log`, `d_user*`, `sal_user`, `login_mysapk` | Mapping | Tandai eksplisit "tidak dimigrasi" |
| 12 | Tidak ada purge `login_attempts`, `forgot_attempts`, `token` | lintas | Job purge + kebijakan retensi |
| 13 | Lockout hanya per username (bisa dipakai mengunci akun orang lain), tanpa limit per IP | A-04 | Putuskan kebijakan |
| 14 | Validasi hanya di MariaDB 10.4 lokal | lintas | Ulangi validasi di MySQL 8 |
