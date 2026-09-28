# DB Validator Review — A-01 Migration Tabel Auth (Modul A)

**Status:** ✅ **DISETUJUI DB VALIDATOR — lanjut** (21-09-2026, jjoseph48). Persetujuan disertai tindak lanjut di Bagian 8 yang wajib diselesaikan sebelum sign-off akhir Fase 1 / deploy Production.

**Bagian 9 (DBV-010, collation + strict mode + akun non-pegawai + identitas `id_pengguna`):** ⏳ **MENUNGGU APPROVAL DB VALIDATOR + REVIEW KODE (CR-013)** — skema Bagian 2-5 berubah setelah DBV-010; lihat Bagian 9 untuk skema sesudahnya.

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
| 4 | FK `pengguna.nip → pegawai.nip` (nullable sementara / di-defer) | ✅ DI-DEFER ke Fase 3 (B-01), dikonfirmasi DB Validator | FK ditambahkan lewat migration terpisah setelah `pegawai` ada. Sama untuk `id_unit`/`id_satker` (Fase 2). Syarat: collation diseragamkan dulu (Bagian 8 #3). **DBV-010 ⏳ (Bagian 9):** `nip` menjadi VARCHAR(30) `utf8mb4_unicode_ci` **NULL** UNIQUE (akun role 1/3/4/5/8 tanpa NIP, K2) — FK nullable tetap bisa dipasang ke `pegawai.nip` (K1). |

## 2. pengguna — perbandingan vs legacy & seed

> Skema awal A-01 (disetujui 21-09-2026). Setelah DBV-010 ⏳: `nip` VARCHAR(30) NULL, `username` VARCHAR(100), kolom `name`/`email`/`expired_at`, seluruh kolom string `utf8mb4_unicode_ci` — lihat Bagian 9.2. Legacy `id_pegawai` berisi NIP (Bagian 8 #9).

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
| 2 | Race token reset: 1 token dipakai 2x → 2 ganti password berhasil (terbukti); token reset lain milik user tetap berlaku | A-07 | `UPDATE forgot_attempts SET used_at=? WHERE id=? AND used_at IS NULL` + cek affected rows, dalam transaksi; batalkan token reset lain setelah sukses. **Diperbaiki 24-09-2026** (`fix/reset-password-race`): `ResetPasswordService::reset()` dalam satu transaksi dengan urutan lock tetap: baris `pengguna` `FOR UPDATE` → `ForgotAttemptModel::markUsed()` bersyarat `used_at IS NULL` + cek affected rows → update password → `invalidateOtherTokens()` → cabut seluruh refresh token. Setiap langkah dicek (hasil tulis + `transStatus`, karena di CI4 4.7 query gagal di dalam transaksi tidak melempar exception), begitu juga hasil `transCommit()`; gagal → rollback + 500, token reset tetap belum terpakai. Token lain yang dibatalkan: `used_at` diisi dan `expires_at` dipangkas ke waktu pembatalan (`expires_at <= used_at` = dibatalkan, bukan dipakai); pesannya "tidak berlaku lagi". Test regresi `ResetPasswordTest` dan `ResetPasswordTransactionTest` (lock wait timeout, trigger, deadlock, commit gagal; 2 proses paralel dengan token berbeda → 1× 200 + 1× 422 tanpa deadlock). Menunggu merge + QA ulang QASMTASK-026 |
| 3 | Collation app `utf8mb4_general_ci` vs seed `utf8mb4_unicode_ci` — FK ke `pegawai` (Fase 3) gagal di MySQL 8 | A-01 | Tetapkan satu collation dan set eksplisit. **Dikerjakan DBV-010 ⏳ (Bagian 9):** 5 tabel auth + `queue_jobs(_failed)` → `utf8mb4_unicode_ci` (migration `2026-09-25-130000`), `DBCollat` grup default & tests `utf8mb4_unicode_ci`; `ALTER DATABASE` oleh DBA lewat runbook 9.8 |
| 4 | Audit reset password `nip_actor = NULL` | A-10 | Isi NIP pemilik akun sebagai actor. **Diperbaiki 24-09-2026** (`fix/reset-password-race`): `nip_actor` = NIP pemilik akun lewat `PenggunaModel::withActor()`; hash password (baru dan legacy) tetap dimasking `***`. Test regresi `ResetPasswordTest::testResetAuditRecordsAccountOwnerAsActor`. Menunggu merge + QA ulang QASMTASK-029 |
| 5 | `down()` `AlterAuditLogsEventAddAuth` menghapus baris audit login/logout | A-01 | Tolak rollback jika baris tsb ada |
| 6 | Akun soft-delete tidak bisa dibuat ulang/dipulihkan; `AccountProvisioner` mengembalikan akun terhapus diam-diam | A-08, A-09 | Endpoint restore atau provisioner memulihkan akun — **dijadwalkan Fase 3** (bersama regression A-09 di B-05) |
| 7 | Timezone: app `UTC`, server DB WIB (UTC+7); data legacy kemungkinan WIB | lintas | Putuskan sebelum migrasi data legacy & Fase 5 |
| 8 | `strictOn = false` (app) vs `true` (test) | lintas | Aktifkan strict mode di Dev/Prod. **Prasyarat aplikasi dikerjakan di CR-007 (25-09-2026, branch `cr-007/api-utf8-422`):** input non-UTF-8 di body form dan query string ditolak 422 di `ApiController` sebelum menyentuh DB (menutup 1366 → 500 dan username `NIP` + byte `0xC3` yang cocok dengan `NIP` di `utf8mb4_unicode_ci`); segmen URL non-UTF-8 sudah ditolak Router CI4 → 400 envelope "Permintaan tidak valid." (handler global kini mengenali path `/api/...` tanpa `Accept` JSON); dan error data MySQL 1406/1264/1366/1292/1265/1364 diterjemahkan ke 422 generik (detail hanya di log). **DBV-010 ⏳ (Bagian 9):** `strictOn = true` di grup default `Config\Database` (test `DatabaseConfigTest`, `SqlModeTest`); NO_ZERO_* tetap dari `sql_mode` server (runbook 9.8, D-11) |
| 9 | Legacy `pengguna.id_pegawai` berisi NIP atau `pegawai.id_pegawai`? Legacy `nip` VARCHAR(30) vs v2 VARCHAR(20) | Mapping | Jalankan query cek di DB legacy. **Terjawab (DBV-010 ⏳, Bagian 9.1):** kode legacy (`L_user.php` `set_param`) mengisi `id_pegawai` dengan NIP; kolom v2 dilebarkan ke VARCHAR(30) (K1) dan NULL untuk akun non-pegawai (K2) |
| 10 | Tabel legacy yang merujuk `id_pengguna` (`fb_token`, `fb_pn_queue`, `news`, `news_flag`, `user_log`); `news` → `user_level` | Mapping | Pertahankan nilai `id_pengguna` saat migrasi atau ubah rujukan ke `nip`. **Arah (DBV-010 ⏳, Bagian 9.4):** identitas akun v2 = `id_pengguna` (= `pengguna.id` legacy, dipertahankan saat impor); rujukan tetap memakai `id_pengguna` |
| 11 | Tabel legacy yang tidak dimigrasi: `token` (nama sama, isi beda), `pengguna_2021…2024` (kemungkinan berisi plaintext), `password_resets`, `oauth_*`, `ci_sessions`, `user_log`, `d_user*`, `sal_user`, `login_mysapk` | Mapping | Tandai eksplisit "tidak dimigrasi" |
| 12 | Tidak ada purge `login_attempts`, `forgot_attempts`, `token` | lintas | Job purge + kebijakan retensi |
| 13 | Lockout hanya per username (bisa dipakai mengunci akun orang lain), tanpa limit per IP | A-04 | Putuskan kebijakan |
| 14 | Validasi hanya di MariaDB 10.4 lokal | lintas | Ulangi validasi di MySQL 8 |

> **Pengecualian F0-04 (disetujui reviewer CR, 24-09-2026, CR-005):** audit reset password bersifat **fail-closed** — bila INSERT `audit_logs` gagal, seluruh transaksi reset dibatalkan (HTTP 500, password tidak berubah, token reset tetap berlaku). Alasan: ganti password tanpa jejak audit adalah celah keamanan, dan di dalam transaksi kegagalan audit tidak bisa dibedakan dari transaksi yang sudah di-rollback server. Jalur lain tetap fail-open sesuai F0-04.

## 9. DBV-010 — collation, strict mode, akun non-pegawai, identitas `id_pengguna`

**Status:** ⏳ **MENUNGGU APPROVAL DB VALIDATOR (DBV-010) DAN REVIEW KODE (CR-013)** — migration `2026-09-25-130000_AlterAuthKeUnicodeCi`, `2026-09-25-130100_AlterPenggunaAkunNonPegawai`, dan `2026-09-25-130200_AlterIdentitasAkunIdPengguna` JANGAN dijalankan di Dev/Production sebelum disetujui. Ketiganya dijalankan bersama (satu batch) setelah PR `[DBV-010][CR-013]` disetujui dan di-merge. **Mohon verifikasi eksplisit di MariaDB 10.4 (Bagian 9.9).**

Label: [K] = legacy terkonfirmasi (DDL/kode), [V2] = keputusan v2, [I] = dugaan. "uni" = `utf8mb4_unicode_ci`, "gen" = `utf8mb4_general_ci`.

### 9.1 Latar belakang & keputusan

Menyelesaikan tindak lanjut Bagian 8 #3 (collation), #8 (strict mode), #9 (lebar NIP dan isi `id_pegawai`), #10 (rujukan `id_pengguna`), ISSUE-008 dan ISSUE-010 untuk tabel auth.

Keputusan final user (25-09-2026), tidak dibuka ulang:

| # | Keputusan | Dampak di DBV-010 |
|---|---|---|
| K1 | PK pegawai = `nip` VARCHAR(30) uni | `pengguna.nip` VARCHAR(30) uni, siap FK ke `pegawai.nip` (B-01) |
| K2 | Akun role 1/3/4/5/8 ikut legacy: boleh tanpa NIP, kolom `name`; identitas akun pindah ke `id_pengguna` | `pengguna.nip` NULL, `name`; JWT/token/audit memakai `id_pengguna` |
| K3 | Kanal reset password = email (driver SMTP belum ada) | kolom `email` (tujuan tautan reset) |
| B | `email` VARCHAR(150) NULL, `name`, `expired_at`; validasi NIP ikut legacy (angka, maks. 18, kolom 30); username tidak dibatasi ASCII dulu; collation + strict untuk 5 tabel auth + antrean, `migrations` dikecualikan, CONVERT untuk tabel tanpa JSON, MODIFY eksplisit untuk `token`/`audit_logs`, cek tabrakan UNIQUE & tanggal nol sebelum ALTER, `down()` kembali ke gen dengan cek yang sama, DBCollat uni dua grup, `strictOn` default true, `token_hash` ikut uni | seluruh Bagian 9 |

Legacy yang dirujuk: `simpeg_prod_duplikat.pengguna` [K] (provenance DDL belum pasti): `id` INT AI, `username` VARCHAR(100) UNIQUE, `id_pegawai` VARCHAR(30) NULL berisi NIP (menjawab Bagian 8 #9), `name`/`email` VARCHAR(150) NULL, `expired_at` DATETIME NULL, semua uni. Kode `L_user.php` (validasi & `set_param` akun): role 1/4/5/8 → `name` wajib, `id_pegawai` NULL; role 2/6/7 (`UL_PEGAWAI`) → `id_pegawai` wajib; role 3 → `name` + unit wajib, `id_pegawai` NULL; email wajib di form admin. Login legacy hanya lewat `username`. `expired_at` = batas berlaku password (popup paksa ganti), tidak ada kode legacy yang mengisinya [I].

### 9.2 Skema sebelum / sesudah

`pengguna` (tabel gen → uni)

| Kolom | Sebelum | Sesudah | Migration | Label |
|---|---|---|---|---|
| id_pengguna | INT UNSIGNED AI PK | tetap | — | [V2] (legacy `id`; impor memakai ID legacy) |
| nip | VARCHAR(20) gen NOT NULL, UNIQUE `nip` | VARCHAR(30) uni **NULL** DEFAULT NULL COMMENT 'NIP pegawai (legacy id_pegawai); NULL untuk akun non-pegawai (role 1/3/4/5/8)', UNIQUE `nip` tetap | 130000, 130100 | [K] + K1/K2 |
| username | VARCHAR(30) gen NOT NULL, UNIQUE | VARCHAR(100) uni NOT NULL, UNIQUE | 130000, 130100 | [K] (D-5) |
| name | — | VARCHAR(150) uni NULL COMMENT 'nama akun (legacy name); wajib diisi aplikasi untuk akun tanpa NIP', setelah `username` | 130100 | [K] |
| email | — | VARCHAR(150) uni NULL COMMENT 'email akun (legacy); tujuan tautan reset password (K3)', setelah `name`; tanpa UNIQUE | 130100 | [K] (D-6) |
| password, password_legacy, id_unit, id_satker, status | gen | uni, definisi lain tetap | 130000 | [V2] (`status`, `id_unit`/`id_satker` tipe legacy di luar cakupan) |
| expired_at | — | DATETIME NULL COMMENT 'legacy: batas berlaku password (paksa ganti); belum dipakai aplikasi v2', setelah `password_changed_at` | 130100 | [K] |
| user_level, last_login_at, password_changed_at, created_at, updated_at, deleted_at | — | tetap | — | [V2] |

Index `pengguna` tetap: PRIMARY, UNIQUE `nip`, UNIQUE `username`, KEY `user_level`, `id_satker`, `status`. UNIQUE `nip` menerima banyak NULL (MySQL/MariaDB). Tidak ada CHECK role↔nip di DB (D-4).

`token`

| Kolom | Sebelum | Sesudah | Migration |
|---|---|---|---|
| id_pengguna | — | INT UNSIGNED NOT NULL COMMENT 'pemilik sesi (pengguna.id_pengguna)' setelah `id`, KEY `id_pengguna`, tanpa FK (D-7) | 130200 |
| nip | VARCHAR(20) gen NOT NULL, KEY `nip` | VARCHAR(30) uni NULL COMMENT 'NIP pemilik saat token terbit (jejak); NULL untuk akun tanpa NIP'; KEY `nip` dihapus | 130000, 130200 |
| token_hash | CHAR(64) gen NOT NULL, UNIQUE | CHAR(64) uni (D-2), sisanya sama | 130000 |
| claims_json | JSON NULL | tidak disentuh (isi kini `sub` = id_pengguna, `nip`, `ver` = 2, `role`, `id_unit`, `id_satker`) | — |

Isi `token` DIKOSONGKAN oleh 130200 `up()` (D-9). Tabel v2 murni, legacy `token` tidak dimigrasi (Bagian 8 #11).

`audit_logs`

| Kolom | Sebelum | Sesudah | Migration |
|---|---|---|---|
| id_pengguna_actor | — | INT UNSIGNED NULL COMMENT 'pelaku (pengguna.id_pengguna); NULL untuk proses sistem/CLI' setelah `id_log`, KEY `id_pengguna_actor`, tanpa FK (D-8); baris lama diisi dari `nip_actor` | 130200 |
| nip_actor | VARCHAR(20) gen NULL, KEY | VARCHAR(30) uni NULL COMMENT 'NIP pelaku saat kejadian (jejak); NULL untuk akun tanpa NIP dan proses sistem/CLI', KEY tetap | 130000, 130200 |
| entity, entity_id, event | gen | uni, definisi lain tetap | 130000 |
| before_json, after_json | JSON NULL | tidak disentuh | — |

`login_attempts.username`, `forgot_attempts.username`: VARCHAR(30) gen → VARCHAR(100) uni NOT NULL (130000, 130100). Kolom string lain kedua tabel (`ip_address`, `forgot_attempts.token_hash`) → uni. `queue_jobs`, `queue_jobs_failed`: hanya collation (CONVERT), tipe/default/index tetap. Tabel `migrations` milik CI4 tidak disentuh (D-1).

### 9.3 Migration, urutan & pengaman

| # | File | Isi | `down()` |
|---|---|---|---|
| 130000 | `AlterAuthKeUnicodeCi` | collation 7 tabel. CONVERT untuk tabel tanpa JSON; `token`/`audit_logs`: `DEFAULT CHARACTER SET` + `MODIFY` eksplisit tiap kolom string dengan definisi sama persis (tipe, NULL/DEFAULT, COMMENT); kolom JSON tidak disentuh (MariaDB 10.4: LONGTEXT utf8mb4_bin + CHECK) | kembali ke gen secara eksplisit (bukan lewat DBCollat), dengan pengaman yang sama |
| 130100 | `AlterPenggunaAkunNonPegawai` | `pengguna` (nip, username, name, email, expired_at) + `username` 100 di `login_attempts`/`forgot_attempts` | menolak akun tanpa NIP (termasuk soft-deleted), username > 30, NIP > 20; menghapus log login/forgot ber-username > 30; lalu MODIFY balik + DROP kolom tambahan |
| 130200 | `AlterIdentitasAkunIdPengguna` | kosongkan `token`; `token.id_pengguna` + KEY, `nip` jejak, DROP KEY `nip`; `audit_logs.id_pengguna_actor` + KEY + backfill, `nip_actor` 30 | menolak baris audit berpelaku tanpa NIP atau NIP pelaku > 20; kosongkan `token`; DROP kolom/KEY baru, MODIFY balik |

Pengaman 130000 (dijalankan SEBELUM ALTER apa pun; semua temuan dikumpulkan dalam satu exception `DBV-010: ...`, tidak ada ALTER yang jalan):
- Tabrakan UNIQUE di collation tujuan untuk `pengguna.nip`, `pengguna.username`, `token.token_hash`, `forgot_attempts.token_hash` (daftar ini = seluruh UNIQUE berkolom string di 7 tabel; dicek test). Contoh: `strasse`/`straße` beda di gen, sama di uni; `admin`/`admın` (i tanpa titik) beda di uni, sama di gen.
- Tanggal nol / nol-di-tanggal (`MONTH(col) = 0 OR DAYOFMONTH(col) = 0`) di seluruh kolom DATE/DATETIME/TIMESTAMP tabel yang akan dikonversi: ALTER COPY di koneksi strict gagal 1292 di tengah jalan.

Sifat lain:
- Tabel yang tabel dan kolom stringnya sudah ber-collation tujuan dilewati (idempoten). Lingkungan baru membuat tabel lewat Forge dengan DBCollat uni, jadi `up()` 130000 tidak mengubah apa pun di sana; `down()` di lingkungan itu menghasilkan gen yang tidak pernah ada (kosmetik, seperti DBV-001).
- Bagian ADD/DROP INDEX 130100/130200 dilewati bila sudah dalam keadaan tujuan, sehingga `migrate` yang gagal di tengah bisa diulang. DDL ter-commit per statement; satu ALTER per tabel per migration.
- SQL mentah memakai `COLLATE` eksplisit dan nama tabel ber-prefix (`t_` di DB test). Query gagal dengan `DBDebug=false` tetap menjadi exception.
- Backfill `id_pengguna_actor`: `UPDATE audit_logs a JOIN pengguna p ON p.nip = a.nip_actor SET a.id_pengguna_actor = p.id_pengguna WHERE a.id_pengguna_actor IS NULL AND a.nip_actor IS NOT NULL` (JOIN baru mungkin setelah 130000 menyamakan collation; akun soft-deleted ikut; NIP tanpa akun tetap NULL).

Konsekuensi test: `DatabaseTestTrait` menjalankan `down()` semua migration sebelum setiap test di atas sisa data test sebelumnya. Test yang membuat akun tanpa NIP, username > 30, atau audit berpelaku tanpa NIP wajib membersihkannya di `tearDown()` (`AuthTestTrait::bersihkanDataDbv010()`); seeder `AuthSeeder` tidak memuat akun tanpa NIP. Lupa membersihkan → test berikutnya gagal dengan pesan `DBV-010: rollback ditolak ...`. Biaya: +17 ALTER per siklus regress+migrate test (130000 `down()` 7, 130100 3+3, 130200 2+2; `up()` 130000 dilewati karena tabel test sudah uni).

### 9.4 Identitas akun `id_pengguna` dan kompatibilitas deploy

Alasan `id_pengguna` (bukan NIP atau username): satu-satunya kunci yang dimiliki **semua** akun (K2); stabil (NIP bisa berubah lewat B-06, username bisa diubah admin); impor memakai `pengguna.id` legacy apa adanya dan kolom `*_by` legacy/v2 sudah berisi id akun, sehingga identitas sesi, audit, dan stamp master berada di satu ruang nilai (menjawab Bagian 8 #10: nilai `id_pengguna` dipertahankan saat migrasi). Alternatif ditolak: `sub` = nip-atau-`u:<id>` (dua arti dalam satu claim), `sub` = username (bisa berubah, bisa non-ASCII).

- **JWT access token:** `sub` = id_pengguna (string bilangan bulat 1..4294967295), claim baru `nip` (NULL untuk akun tanpa NIP) dan `ver` = 2. Token tanpa `ver` = 2 atau `sub` bukan id valid → 401 generik.
- **Refresh token:** baris `token` menyimpan `id_pengguna` (+ `nip` jejak); pencabutan massal (ganti/reset password, ubah/hapus akun oleh admin) dan reuse detection per `id_pengguna`, sehingga akun-akun tanpa NIP tidak saling mencabut sesi. Baris ber-claims format lama → dihapus + 401 "tidak dikenal" (bukan reuse).
- **Audit:** pelaku = `id_pengguna_actor`; `nip_actor` tetap diisi NIP pelaku saat kejadian (NULL untuk akun tanpa NIP). Jalur tanpa sesi (login, lazy rehash, reset password) memakai pemilik akun (`PenggunaModel::withActor(row)`).
- **Stamp master:** `created_by`/`updated_by` = id_pengguna dari sesi (sebelumnya dicari lewat `pengguna.nip`, sehingga Super Admin tanpa NIP akan men-stamp NULL).
- **Login:** tetap lewat `username` — NIP untuk akun pegawai (default A-09) atau username bebas untuk akun non-pegawai, sama dengan legacy. Batas 100 karakter.

Kompatibilitas saat deploy (paksa login ulang, sekali): 130200 `up()` mengosongkan `token`, dan access token lama (`sub` = NIP, tanpa `ver`) ditolak filter → 401 → FE memanggil `/auth/refresh` → 401 (cookie refresh dihapus server) → halaman login. `jwt.secret` tidak perlu dirotasi. Hook deploy menjalankan `migrate` sebelum build FE dan pindah symlink, jadi kode lama sempat berjalan di atas skema baru selama build: login kode lama bisa gagal sesaat (INSERT `token` tanpa `id_pengguna`), dan baris yang sempat tertulis ditolak cek `ver`. Karena itu deploy dijadwalkan di luar jam kerja (runbook langkah 8).

### 9.5 Aturan aplikasi akun (A-08/A-09) dan dampak kode

- Role 2/6/7 (`Role::UL_PEGAWAI`, konstanta legacy) wajib NIP. Role 1/3/4/5/8 NIP opsional — legacy memaksa NULL, v2 membolehkan terisi agar akun v2 ber-NIP yang sudah ada tetap valid [V2].
- NIP: angka saja, maksimal 18 digit (NIK 16 digit Non-PNS diterima); kolom 30.
- `name` wajib (≤ 150) untuk akun tanpa NIP; `email` opsional, valid, ≤ 150 (legacy mewajibkan email di form admin — D-6); username default = NIP, wajib bila NIP kosong, ≤ 100, unik dalam uni.
- Ubah akun: `name`/`email` bisa diubah; `nip` hanya boleh DIISI untuk akun yang belum punya NIP (menautkan ke pegawai); mengubah/menghapus NIP yang ada → 422 (ranah B-06). Mengisi NIP / mengubah role, status, password, satker mencabut sesi akun. Invarian (role 2/6/7 ⇒ NIP; tanpa NIP ⇒ nama) dicek pada hasil akhir bila role/NIP/nama ikut berubah.
- Hapus dan nonaktifkan akun sendiri ditolak (dibandingkan per `id_pengguna`; sebelumnya per NIP sehingga dengan NIP NULL semua akun tanpa NIP dianggap "diri sendiri").
- Kode yang berubah: `JwtService`, `TokenModel`, `AuthContext` (`idPengguna()`, `nip()` dari claim), `AuthService`, `PasswordService`, `PasswordVerifier`, `ResetPasswordService`, `UserService`, `AccountProvisioner`, `PenggunaModel`, `AuditLogModel::record(..., ?int $actorId, ?string $actorNip)`, `BaseAuditableModel` (`currentActor()`, `actorIdPengguna()`), `MasterModel::currentActorId()`, controller Auth. `FaqService` tidak berubah (rating hanya role 2/6/7 yang selalu ber-NIP; `nip` kini dibaca dari claim).
- FE Manajemen Akun: kolom Nama, NIP "—" bila kosong, isian Nama/Email, NIP wajib hanya untuk role 2/6/7, NIP read-only saat edit akun ber-NIP / "Tautkan NIP" untuk akun tanpa NIP, baris sendiri dikenali per `id_pengguna`; batas username login 100.

Strict mode (`strictOn = true` grup default) dan DBCollat uni dua grup ada di `Config\Database`. Prasyarat aplikasi sudah di main: penjaga UTF-8 + terjemahan error data 1406/1264/1366/1292/1265/1364 → 422 (CR-007), batas angka per tipe kolom master (CR-009). Seluruh suite PHPUnit memang sudah berjalan strict (grup `tests`). Sisa risiko yang dicatat (tidak diubah di DBV-010): payload `queue_jobs` > 64 KB kini gagal (dulu terpotong); impor legacy lewat koneksi aplikasi akan menolak nilai yang dulu terpotong, jadi impor wajib audit data dulu.

### 9.6 Verifikasi developer (sebelum review DB Validator)

Lingkungan: MySQL 8.0.30 lokal (Laragon, `@@GLOBAL.sql_mode` = STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION). DB scratch (grup default, DBPrefix kosong, tabel lama `utf8mb4_general_ci` berisi akun ber-NIP termasuk username non-ASCII, satu baris `token`, dan baris audit) untuk siklus `spark migrate --all` → `migrate:rollback` → `migrate`: `token` kosong dan pelaku audit terisi dari `nip_actor` setelah up; rollback ditolak 130100 selama ada akun tanpa NIP (130200 sudah ter-rollback), lalu lolos setelah akun itu dihapus; byte username (`HEX`) utuh; `migrate` ulang tanpa error dan 0 kolom string non-uni selain `migrations`; DB test (DBPrefix `t_`, `strictOn=true`) untuk PHPUnit. Database dev `simpeg_v2` tidak disentuh. **Belum diverifikasi di MariaDB 10.4.**

Test (backend):
- `Tests\Auth\AuthCollationMigrationTest` (130000): semua kolom string 7 tabel uni tanpa `_0900_`; `down()`/`up()` hanya mengubah collation (snapshot `information_schema.COLUMNS` & `STATISTICS`); data non-ASCII (`Ñoño`, fullwidth, emoji 4 byte) utuh per byte (`HEX`); tabrakan `strasse`/`straße` dan tanggal nol menolak `up()` tanpa ALTER; `admin`/`admın` menolak `down()` tanpa ALTER; JOIN `pengguna` × `faq_rate` gagal 1267 di gen, berhasil di uni; FK dari `pengguna.nip` ke kolom NIP VARCHAR(30) uni hanya bisa dibuat setelah konversi; daftar pra-cek = seluruh UNIQUE berkolom string; `up()` ulang tanpa ALTER.
- `Tests\Auth\AkunNonPegawaiSchemaTest` (130100/130200): kolom, COMMENT, collation, index sesudah migration; 130200 mengosongkan `token` dan mengisi `id_pengguna_actor` (akun terhapus ikut, NIP tak dikenal NULL); penolakan `down()` 130200 (audit berpelaku tanpa NIP) dan 130100 (akun tanpa NIP / username > 30 / NIP > 20) tanpa ALTER; roundtrip menjaga akun dan menghapus log username > 30.
- `Tests\Database\CollationInvariantTest`: seluruh tabel ber-prefix (kecuali `migrations`) dan kolom stringnya uni. Jebakan SQL mentah tanpa `COLLATE` hanya efektif bila default database test bukan uni (mis. `simpeg_v2_testing` gen).
- `Tests\Unit\Config\DatabaseConfigTest`, `Tests\Database\SqlModeTest`: grup default & tests strict + DBCollat uni (setelah override `.env`); koneksi dari konfigurasi grup default memuat `STRICT_ALL_TABLES` dan menolak isian terlalu panjang (1406) tanpa menyimpan baris terpotong.
- `Tests\Auth\AkunTanpaNipTest` (feature): login username bebas, token/claims per id, audit login (rehash, last_login, login) berpelaku id dengan `nip_actor` NULL, refresh/reuse/logout per akun, ganti & reset password, stamp `updated_by` master oleh Super Admin tanpa NIP, CRUD akun teraudit, hapus/nonaktifkan diri ditolak per id, scoping Admin Satker, token & baris refresh format lama → 401.
- Test lama diperbarui: `JwtServiceTest`, `TokenTest`, `SessionRevocationTest`, `RbacFilterTest`, `BaseAuditableModelTest`, `AuthSchemaTest`, `UserCrudScopedTest` (aturan 9.5), `AccountProvisionerTest`, `LoginTest` (username 100/101), `ResetTokenNotifierTest`, `RoleTest`.

Hasil `./check.sh` dan mutation check: lihat deskripsi PR `[DBV-010][CR-013]`.

### 9.7 Keputusan yang diminta dari DB Validator (DBV-010)

| # | Pertanyaan | Usulan | Keputusan |
|---|---|---|---|
| D-1 | Cakupan collation | 5 tabel auth + `queue_jobs`/`queue_jobs_failed`; `migrations` dikecualikan | ⏳ |
| D-2 | Metode & kolom hash | CONVERT (tabel tanpa JSON) + `DEFAULT CHARACTER SET` & MODIFY eksplisit `token`/`audit_logs`; `token_hash` ikut uni (bukan `ascii_bin`) | ⏳ |
| D-3 | Semantik `down()` | 130000 kembali ke gen eksplisit dengan cek tabrakan & tanggal nol; 130100/130200 menolak data yang tidak bisa disimpan skema lama (akun tanpa NIP, username > 30, NIP > 20, audit berpelaku tanpa NIP), menghapus log login/forgot ber-username > 30 dan seluruh `token` | ⏳ |
| D-4 | `pengguna.nip` VARCHAR(30) NULL UNIQUE; CHECK role↔nip di DB? | Tanpa CHECK (data legacy bisa melanggar sehingga impor gagal); aturan di aplikasi + audit data sebelum impor | ⏳ |
| D-5 | `username` 30 → 100 (legacy [K]) di `pengguna`/`login_attempts`/`forgot_attempts` | Ya, sekalian rebuild. Bila ditolak: hapus MODIFY `username` di 130100 dan ubah `PenggunaModel::USERNAME_MAX` + `USERNAME_MAX` FE ke 30 | ⏳ |
| D-6 | `name`/`email` VARCHAR(150) NULL, `expired_at` DATETIME NULL; email tanpa UNIQUE & opsional | Ya. Legacy mewajibkan email di form admin → mohon konfirmasi TL apakah v2 ikut mewajibkan | ⏳ |
| D-7 | `token.id_pengguna` NOT NULL + KEY, `nip` jejak NULL; FK ke `pengguna`? | Tanpa FK (tabel sesi ephemeral, legacy tanpa FK). Alternatif: `fk_id_pengguna_token_to_pengguna` RESTRICT | ⏳ |
| D-8 | `audit_logs.id_pengguna_actor` NULL + KEY + backfill, `nip_actor` 30 tetap sebagai jejak; FK? | Tanpa FK (tabel log append-only) | ⏳ |
| D-9 | 130200 `up()` mengosongkan `token` (paksa login ulang) | Ya. Alternatif backfill `id_pengguna` dari `nip` tidak berguna karena token lama tetap ditolak cek `ver` | ⏳ |
| D-10 | `strictOn` default true + DBCollat uni dua grup; `ALTER DATABASE` oleh DBA; DB test dibiarkan default gen sebagai jebakan SQL mentah | Ya | ⏳ |
| D-11 | Server target (README-deploy menyebut MySQL 8.x, validasi DBV sebelumnya di MariaDB 10.4) & `@@GLOBAL.sql_mode` | Konfirmasi engine/versi server Dev/Prod; minta NO_ZERO_DATE, NO_ZERO_IN_DATE, ERROR_FOR_DIVISION_BY_ZERO di level server (`strictOn` hanya menambah STRICT_ALL_TABLES) | ⏳ |

### 9.8 Runbook server (Dev/Prod)

1. Cek server: `SELECT @@version, @@GLOBAL.sql_mode, @@collation_server, @@character_set_server;` dan `SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '<db>';`.
2. DBA: pastikan `sql_mode` global memuat STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION (default MariaDB 10.4 tidak memuat NO_ZERO_*).
3. DBA: `ALTER DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` (hanya default tabel baru; data tidak berubah). Tidak dilakukan lewat migration.
4. Pastikan `shared/backend.env` tidak meng-override `database.default.strictOn` / `database.default.DBCollat`.
5. Backup: `mysqldump` tabel `pengguna`, `token`, `audit_logs`, `login_attempts`, `forgot_attempts`, `queue_jobs`, `queue_jobs_failed`.
6. Pra-cek manual (sama dengan migration, agar deploy tidak gagal di hook): tabrakan uni di `pengguna.nip`/`username`, `token.token_hash`, `forgot_attempts.token_hash` (`SELECT col COLLATE utf8mb4_unicode_ci k, COUNT(*) n FROM t WHERE col IS NOT NULL GROUP BY k HAVING n > 1`); tanggal nol di kolom DATE/DATETIME/TIMESTAMP ketujuh tabel.
7. **Bersihkan data QA sebelum migrate:** `simpeg_v2` dev belum menjalankan DBV-001 dan tabel Batch 1 berisi data QA, sedangkan `AlterBatch1KeSkemaLegacy::up()` menolak tabel berisi data → kosongkan (urutan kelurahan, kecamatan, kabupaten_kota, provinsi, agama, jenis_pegawai, jenis_status). Akun QA di `pengguna` boleh tetap (semua ber-NIP).
8. Jadwalkan di luar jam kerja dan umumkan: semua sesi berakhir, pengguna login ulang sekali (`token` dikosongkan; selama build, kode lama berjalan di atas skema baru → login bisa gagal sesaat).
9. Deploy (hook `migrate --all`); verifikasi `php spark migrate:status`, `SHOW CREATE TABLE` ketujuh tabel, query `information_schema` (0 kolom non-uni selain `migrations`), `SELECT @@SESSION.sql_mode` dari koneksi aplikasi memuat STRICT_ALL_TABLES, lalu smoke: login akun ber-NIP dan tanpa NIP, refresh, logout, ganti password, tambah/ubah master.
10. Rollback: `php spark migrate:rollback -b <batch>`; `down()` menolak bila ada akun tanpa NIP / audit berpelaku tanpa NIP (pesan menjelaskan); rollback membuat semua sesi berakhir lagi. Pra-cek dulu agar rollback tidak berhenti di tengah: `SELECT COUNT(*) FROM audit_logs WHERE id_pengguna_actor IS NOT NULL AND (nip_actor IS NULL OR CHAR_LENGTH(nip_actor) > 20)` dan `SELECT COUNT(*) FROM pengguna WHERE nip IS NULL OR CHAR_LENGTH(username) > 30 OR CHAR_LENGTH(nip) > 20` harus 0. CI4 me-rollback per migration: bila 130100 menolak, 130200 sudah ter-rollback dan keadaan tetap konsisten (skema lama untuk `token`/`audit_logs`, skema baru untuk `pengguna`); bereskan datanya lalu ulangi rollback, atau `migrate` untuk kembali ke skema baru.

### 9.9 Permintaan verifikasi MariaDB 10.4 (eksplisit, kepada DB Validator)

DBV-001/002 belum pernah melaporkan verifikasi MariaDB 10.4 secara terpisah. Mohon dijalankan sekali di MariaDB 10.4 sebelum approval, dan hasilnya dicatat di PR:

- [ ] (a) `php spark migrate --all` di DB kosong → `migrate:rollback` batch DBV-010 (130200, 130100, 130000) → `migrate` lagi, tanpa error.
- [ ] (b) Hal yang sama di DB berisi salinan data tabel auth Dev (setelah backup), termasuk pra-cek runbook langkah 6.
- [ ] (c) `SHOW CREATE TABLE` ketujuh tabel: kolom JSON `token.claims_json`, `audit_logs.before_json/after_json` tetap LONGTEXT `utf8mb4_bin` + CHECK `json_valid` (tidak ikut dikonversi); kolom string lain uni; COMMENT sesuai 9.2.
- [ ] (d) UNIQUE `pengguna.nip` menerima banyak NULL; `ALTER TABLE ... DEFAULT CHARACTER SET ..., MODIFY ...` berjalan.
- [ ] (e) Koneksi aplikasi (grup default) memuat STRICT_ALL_TABLES; `@@GLOBAL.sql_mode` server dicatat (D-11).
- [ ] (f) Bila memungkinkan, PHPUnit penuh di MariaDB (test skema sudah menormalisasi `int(10)`/`tinyint(3)` dan JSON = LONGTEXT). Catatan: test antrean `SKIP LOCKED` diketahui gagal di 10.4 (Bagian 7).

### 9.10 Efek ke dokumen lain dan di luar cakupan

- G-01 Bagian 8.5 dan G-10 D2 (catatan JOIN `pengguna` × `faq_rate` beda collation): setelah DBV-010 JOIN tersebut boleh. Dokumen G tidak diubah di PR ini (milik grup master); tindak lanjut satu baris setelah merge.
- Di luar cakupan (usul CR/issue terpisah): perilaku paksa ganti password dari `expired_at` (FCP legacy); pembatasan legacy "Admin Satker hanya membuat akun UL_PEGAWAI" (`L_user.php`), v2 saat ini hanya melarang role 1; `pengguna.status` ENUM vs legacy TINYINT dan `id_unit`/`id_satker` VARCHAR(10) vs legacy INT (DBV-009, setelah DBV-008); FK `pengguna.nip`/`faq_rate.nip` → `pegawai` (B-01); penggantian NIP (B-06); `AppShell` menampilkan `name`.
- Impor legacy tetap butuh audit data: username > 100 / non-ASCII / duplikat dalam uni, akun role 2/6/7 tanpa `id_pegawai`, tanggal nol.
