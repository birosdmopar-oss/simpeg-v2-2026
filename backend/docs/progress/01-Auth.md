# Progress Fase 1 — Modul A: Autentikasi & Akun

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `01-Auth.md`. Skema & keputusan DB: `backend/docs/db-review/A-01-auth-schema.md`. API: `backend/app/Controllers/Api/Auth/README.md`.

**Terakhir diperbarui:** 29 September 2026 (CR-022: sinkron status CR-018/CR-019 dan label kartu. CR-018 ISSUE-021 dan CR-019 ISSUE-023 + syarat DBV-010 D-6 email unik (ISSUE-006) sudah di main — merge `4790b84` dan `b4a9b87`, menunggu QA; sebelumnya DBV-010/CR-013 di-merge ke main `6bb29b5`, sisa D-11 DBA dan deploy Dev)
**Status fase:** sign-off Fase 1 dikonfirmasi user 21-09-2026; DB Validator menyetujui A-01 (21-09-2026) dengan tindak lanjut A-01 Bagian 8. QA ulang QAFUNC-002-R2 (25–26 September 2026) Pass untuk cakupan Gelombang 1 (CR-006 s.d. CR-009); QA ulang QAFUNC-002-R3 (29-09-2026) Pass untuk DBV-010/CR-013 (temuan → CR-017, ISSUE-023).

| Task | Status | Ringkas |
|---|---|---|
| A-01 Migration tabel auth | DONE + ✅ DBV-010 | Skema awal disetujui 21-09-2026. DBV-010 (A-01 Bagian 9, ✅ 29-09-2026, main `6bb29b5`; D-11 ⏳ DBA): collation `utf8mb4_unicode_ci`, `pengguna.nip` VARCHAR(30) NULL, `username` 100, kolom legacy `name`/`email`/`expired_at`, `token.id_pengguna`, `audit_logs.id_pengguna_actor` |
| A-02 Login | DONE (✅ CR-013) | Login lewat username (NIP atau username bebas untuk akun non-pegawai), maks. 100 karakter |
| A-02b Lazy rehash MD5 → Argon2id | DONE | pelaku audit rehash = pemilik akun (T-02, per `id_pengguna` setelah CR-013) |
| A-03 Captcha Turnstile | DONE | CR-018 (ISSUE-021): di production `auth.captchaDriver = mock` dan `auth.exposeResetTokenInResponse = true` (A-07) ditolak (ConfigException → 500, pola guard notifier CR-008); di main (merge `4790b84`, 29-09-2026), menunggu QA |
| A-04 Lockout | DONE | kebijakan per IP masih terbuka (A-01 Bagian 8 #13) |
| A-05 Refresh & logout | DONE (✅ CR-013, QA ulang) | race & T-01 selesai (QASMTASK-024 Pass di R2); CR-013: sesi, pencabutan massal, reuse per `id_pengguna`, token format lama → 401 (login ulang sekali saat deploy) |
| A-06 Ganti password | DONE | |
| A-07 Lupa/reset password | DONE (backend) | QASMTASK-026 backend Pass; kartu penuh menunggu driver email SMTP (K3, CR-014) → kartu **Marked** (ISSUE-006 SMTP). Syarat DBV-010 D-6 (email unik saat buat/ubah akun) dipenuhi **CR-019** (di main, merge `b4a9b87`, menunggu QA) |
| A-08 CRUD akun scoped | DONE (✅ CR-013, QA ulang) | CR-013: akun tanpa NIP role 1/3/4/5/8 (nama wajib), NIP wajib role 2/6/7, NIP angka maks. 18 digit, email, tautkan NIP, hapus/nonaktifkan diri ditolak per `id_pengguna`; Admin Satker hanya role 2/6/7 dan tidak bisa mengubah role sendiri (legacy); CR-017: Admin Satker hanya mengelola akun 2/6/7 + akunnya sendiri di satkernya (akun 1/3/4/5/8 lain → 403, tidak di daftar); isian non-teks/balapan UNIQUE → 422 (CR-019: semua field termasuk `status`/`user_level`/`id_unit`/`id_satker`/`password`, bukan 500); rename membatalkan token reset tertunda |
| A-09 Lifecycle akun otomatis | DONE (QA ulang CR-013; butir regresi lintas modul B-05 ditunda ke Fase 3) | QASMTASK-028 tetap Done: butir "regresi lintas modul akhir Fase 3 (B-05)" tidak bisa diuji di Fase 1 (QAFUNC-002-R3: SKIP "belum waktunya") dan dijadwalkan di B-05 (Fase 3). CR-013: NIK 16 digit diterima, nama/email opsional dari data pegawai; CR-019: NIP yang sudah dipakai sebagai username akun lain → 422 `errors.nip` (bukan 1062/500), balapan NIP sama tetap idempoten |
| A-10 Audit trail auth | DONE (✅ CR-013, QA ulang) | T-02 selesai (QASMTASK-029 Pass di R2); CR-013: pelaku = `id_pengguna_actor`, `nip_actor` jejak (null untuk akun tanpa NIP) |
| A-11 Halaman login (FE) | DONE | CR-013: batas username 100, placeholder "username atau NIP" |
| A-12 Halaman manajemen akun (FE) | DONE (✅ CR-013) | CR-013: kolom Nama, NIP "—", isian Nama/Email, NIP read-only / "Tautkan NIP", baris sendiri per `id_pengguna` (menu ⋮ CR-015), pilihan role Admin Satker 2/6/7 |
| A-13 Unit test RBAC & JWT | DONE | terintegrasi `check.sh` |

## DBV-010 / CR-013 — ✅ DISETUJUI, DI MAIN (`6bb29b5`); QA ulang ✅ (QAFUNC-002-R3); menunggu D-11 (DBA) dan deploy Dev

Key review `[DBV-010][CR-013]`, branch `dbv-010/auth-collation-pengguna`, PR #15 (CR+DBV → PR, user merge setelah DB Validator approve). Dokumen skema & keputusan D-1..D-12, runbook server, dan hasil verifikasi MariaDB 10.4: A-01 Bagian 9.

**Approval:** DB Validator (jjoseph48) menyetujui 29-09-2026 lewat komentar PR #15 — D-1..D-10 dan D-12 ✅ sesuai usulan (catatan per butir di A-01 9.7), D-11 ⏳ menunggu DBA. Verifikasi MariaDB 10.4.27: migrate → rollback → migrate bersih, `up()` idempoten, JSON tetap `utf8mb4_bin`, pengaman 130000 menolak, koneksi aplikasi strict, PHPUnit 413/414 (1 gagal = `SKIP LOCKED`, fitur MariaDB 10.6). Review kode CR-013 selesai; di-merge ke `main` oleh reviewer CR 29-09-2026 (`6bb29b5`).

**Sudah di main (`6bb29b5`)**
- Migration `2026-09-25-130000_AlterAuthKeUnicodeCi` (collation 5 tabel auth + `queue_jobs(_failed)`, pra-cek tabrakan UNIQUE & tanggal nol), `2026-09-25-130100_AlterPenggunaAkunNonPegawai`, `2026-09-25-130200_AlterIdentitasAkunIdPengguna` (kosongkan `token`, backfill pelaku audit).
- `Config\Database`: `strictOn = true` grup default, `DBCollat` `utf8mb4_unicode_ci` grup default & tests (ISSUE-008, ISSUE-010).
- Identitas akun `id_pengguna` di JWT (`sub`, claim `nip`, `ver` = 2), `token`, audit, stamp `*_by` master (`MasterModel::currentActorId()`), `AuthContext::idPengguna()`.
- Aturan akun non-pegawai di backend (`UserService`, `AccountProvisioner`, `Role::UL_PEGAWAI`) dan FE Manajemen Akun.
- Test: `AuthCollationMigrationTest`, `AkunNonPegawaiSchemaTest`, `CollationInvariantTest`, `DatabaseConfigTest`, `SqlModeTest`, `AkunTanpaNipTest` + pembaruan test auth lama; Vitest `schemas.spec`, `UserFormDialog.spec`, `UserManagementView.spec`.

**Belum**
- ~~Approval DB Validator + verifikasi MariaDB 10.4 + review kode CR-013~~ ✅ 29-09-2026 (lihat Approval di atas).
- D-11 ⏳ DBA: konfirmasi engine/versi server Dev/Prod dan `sql_mode` global (MariaDB 10.4.27 lokal DB Validator tanpa STRICT_* dan ERROR_FOR_DIVISION_BY_ZERO) — runbook A-01 9.8 langkah 2.
- Deploy ke Dev mengikuti runbook A-01 Bagian 9.8 (ALTER DATABASE oleh DBA, bersihkan data QA Batch 1, luar jam kerja; deploy ulang mengosongkan `token` lagi; jangan andalkan `spark migrate -g`). Belum diuji DB Validator: salinan data tabel auth Dev dan smoke test aplikasi → pra-cek langkah 6 dan smoke langkah 9 saat deploy.
- ~~QA ulang karena identitas akun pindah ke `id_pengguna` (JWT `sub`, `token`, pelaku audit): QASMTASK-024 (A-05), 027 (A-08), 028 (A-09), 029 (A-10)~~ ✅ 29-09-2026, `docs/qa/QAFUNC-002-R3-fase-1-auth-dbv-010.md`: semua DoD PASS; FAIL major 027-S1 (Admin Satker mengambil alih akun Super Admin satu satker) diperbaiki **CR-017** (`f862583`); 2 FAIL minor (isian array di CRUD akun → 500, AccountProvisioner 1062 saat NIP = username akun lain) → ISSUE-023, diperbaiki **CR-019** (di main, merge `b4a9b87`; menunggu QA — lihat bagian CR-019 di bawah).
- Syarat DBV-010 D-6 (sebelum email dipakai sebagai kanal reset, buat/ubah akun wajib menolak email duplikat dalam `utf8mb4_unicode_ci` + test) → ✅ dipenuhi **CR-019** (di main, merge `b4a9b87`; menunggu QA; lihat bagian CR-019). Sisa ISSUE-006: driver email SMTP (**CR-014**, menunggu akun SMTP & port server) + QA UI MTC-007, kewajiban mengisi email (konfirmasi TL), UNIQUE email di DB setelah audit data legacy (DBV baru). Kartu QASMTASK-026 (A-07) **Marked** karena ISSUE-006 (SMTP).
- D-12 wajib tuntas (DBV-009) sebelum FK `*_by` dibuat atau data legacy diimpor. D-7: job purge `token`/`login_attempts`/`forgot_attempts` (A-01 Bagian 8 #12).
- Tindak lanjut 1 baris di G-01 Bagian 8.5 dan G-10 D2 (JOIN `pengguna` × `faq_rate` kini boleh) setelah merge.
- Tindak lanjut 1 baris setelah merge: README MasterData (kolom `nip` tabel `faq_rate`) dan G-10 (kolom `nip` `faq_rate`) masih menyebut "JWT `sub`" → ganti "claim `nip`" (A-01 9.10).
- Di luar cakupan (usul CR/issue terpisah): paksa ganti password dari `expired_at` (FCP legacy; putuskan sebelum impor karena `expired_at` impor sudah terisi); tipe legacy `pengguna.status`/`id_unit`/`id_satker` dan `id_pengguna` signed (D-12) di DBV-009; FK `pengguna.nip` → `pegawai` (B-01); ganti NIP (B-06); `AppShell` menampilkan `name`; pembatasan username ASCII (keputusan B, setelah audit data).

## CR-019 — ISSUE-023 + syarat DBV-010 D-6 (branch `cr-019/akun-input-dan-email-unik`, CR saja tanpa DBV; di main lewat merge `b4a9b87` 29-09-2026, menunggu QA)

- Isian body bukan teks/angka bulat (array/objek/boolean) di `POST/PUT /auth/users`, `PATCH /auth/users/{id}/status`, `POST /auth/login`, dan `POST /auth/forgot-password` → 422 per field "Isian harus berupa teks." sebelum rule CI4 berjalan (`ApiController::validateTextOrFail`); `UserService` menolak ulang untuk pemanggil lain. Sebelumnya `status`/`id_unit`/`id_satker` = `[]`/`{}` → 500 di service dan `captcha_token: []` → 500 di login/lupa password (lolos `permit_empty` lalu di-cast); array berisi sudah 422 lewat StrictRules CI4 (`Config\Validation`) tetapi dengan pesan bawaan per rule, dan `false` lolos diam-diam (mis. `status: false` → akun aktif).
- `AccountProvisioner` (A-09): NIP yang sudah dipakai sebagai username akun lain (termasuk akun terhapus) → 422 `errors.nip` berpesan jelas, akun tidak dibuat/ditautkan (keputusan: tolak, usul kartu ISSUE-023); balapan 1062 untuk NIP yang sama → idempoten. Pencegahan di sumber (username berupa angka 1–18 digit yang bukan NIP akun itu) ditunda sampai audit data legacy.
- Test: `tests/Auth/UserInputShapeTest` (baru), `AccountProvisionerTest` (username = NIP akun aktif/terhapus, balapan).
- Syarat D-6 (ISSUE-006): buat/ubah akun menolak email yang sudah dipakai akun lain yang belum dihapus (aktif/nonaktif), dibandingkan `utf8mb4_unicode_ci` → 422 `errors.email` "Email sudah dipakai akun lain." (pesan generik). Kosong/NULL boleh banyak; email akun terhapus tidak dibandingkan (belum ada fitur pulihkan — fitur itu wajib mengecek ulang); ubah akun hanya menilai email yang berubah, jadi duplikat lama hasil impor tidak mengunci akun. `AccountProvisioner` membuat akun tanpa email duplikat dan mengembalikan `email_skipped: true` (final di B-05). Tanpa UNIQUE DB, balapan dua admin menyimpan email sama bersamaan masih mungkin — didokumentasikan sampai UNIQUE dipasang setelah audit data legacy. Test: `tests/Auth/EmailUnikTest`.
- Catatan verifikasi: dugaan audit bahwa `PATCH /master/{entity}/{id}/status` dan `.../order` ikut 500 untuk array berisi tidak terbukti — rule StrictRules menolak `["1"]` dan `required` menolak `[]` (422, dicek manual saat CR-019). Endpoint lain dengan field `permit_empty` yang di-cast service belum diaudit untuk `[]`.

## CR-018 — ISSUE-021 (branch `cr-018/guard-produksi-captcha-reset`, CR saja tanpa DBV; di main lewat merge `4790b84` 29-09-2026, menunggu QA)

- `ENVIRONMENT = production` menolak `auth.captchaDriver = mock` (`MockCaptchaVerifier` → ConfigException; login, refresh/logout, lupa/reset password gagal 500 sampai `.env` diperbaiki) dan `auth.exposeResetTokenInResponse = true` (`ResetPasswordService::request()` gagal sama untuk semua username, sesudah captcha dan sebelum rate limit/lookup; `reset()` tidak terpengaruh). Pola guard notifier log/mock CR-008.
- Test: `tests/Auth/ProductionConfigGuardTest`. Dokumentasi: README Auth (controller & library), docblock `Config\Auth`, `.env.example`, `README-deploy.md`.
- Kriteria Done ISSUE-021 dari sisi kode terpenuhi; tinggal QA.

## Tindak lanjut A-01 Bagian 8 lainnya

- #5 `down()` `AlterAuditLogsEventAddAuth`, #6 restore akun soft-delete (Fase 3), #7 timezone, #11 tabel legacy tidak dimigrasi, #12 purge log/token, #13 kebijakan lockout, #14 validasi di MySQL 8 — masih terbuka (lihat A-01 Bagian 8).
