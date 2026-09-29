# Progress Fase 1 — Modul A: Autentikasi & Akun

Catatan progres per task (aturan `IN_PROGRESS` di 00-INDEX.md). Kontrak task lengkap: `01-Auth.md`. Skema & keputusan DB: `backend/docs/db-review/A-01-auth-schema.md`. API: `backend/app/Controllers/Api/Auth/README.md`.

**Terakhir diperbarui:** 29 September 2026 (DBV-010/CR-013 disetujui DB Validator dan di-merge ke main `6bb29b5`; sisa D-11 DBA, deploy Dev, QA ulang)
**Status fase:** sign-off Fase 1 dikonfirmasi user 21-09-2026; DB Validator menyetujui A-01 (21-09-2026) dengan tindak lanjut A-01 Bagian 8. QA ulang QAFUNC-002-R2 (25–26 September 2026) Pass untuk cakupan Gelombang 1 (CR-006 s.d. CR-009).

| Task | Status | Ringkas |
|---|---|---|
| A-01 Migration tabel auth | DONE + ✅ DBV-010 | Skema awal disetujui 21-09-2026. DBV-010 (A-01 Bagian 9, ✅ 29-09-2026, main `6bb29b5`; D-11 ⏳ DBA): collation `utf8mb4_unicode_ci`, `pengguna.nip` VARCHAR(30) NULL, `username` 100, kolom legacy `name`/`email`/`expired_at`, `token.id_pengguna`, `audit_logs.id_pengguna_actor` |
| A-02 Login | DONE (✅ CR-013) | Login lewat username (NIP atau username bebas untuk akun non-pegawai), maks. 100 karakter |
| A-02b Lazy rehash MD5 → Argon2id | DONE | pelaku audit rehash = pemilik akun (T-02, per `id_pengguna` setelah CR-013) |
| A-03 Captcha Turnstile | DONE | |
| A-04 Lockout | DONE | kebijakan per IP masih terbuka (A-01 Bagian 8 #13) |
| A-05 Refresh & logout | DONE (✅ CR-013, QA ulang) | race & T-01 selesai (QASMTASK-024 Pass di R2); CR-013: sesi, pencabutan massal, reuse per `id_pengguna`, token format lama → 401 (login ulang sekali saat deploy) |
| A-06 Ganti password | DONE | |
| A-07 Lupa/reset password | DONE (backend) | QASMTASK-026 backend Pass; kartu penuh menunggu driver email SMTP (K3, CR-014 — wajib menegakkan email unik sebelum email jadi kanal reset, syarat DBV-010 D-6) |
| A-08 CRUD akun scoped | DONE (✅ CR-013, QA ulang) | CR-013: akun tanpa NIP role 1/3/4/5/8 (nama wajib), NIP wajib role 2/6/7, NIP angka maks. 18 digit, email, tautkan NIP, hapus/nonaktifkan diri ditolak per `id_pengguna`; Admin Satker hanya role 2/6/7 dan tidak bisa mengubah role sendiri (legacy); CR-017: Admin Satker hanya mengelola akun 2/6/7 + akunnya sendiri di satkernya (akun 1/3/4/5/8 lain → 403, tidak di daftar); isian non-teks/balapan UNIQUE → 422; rename membatalkan token reset tertunda |
| A-09 Lifecycle akun otomatis | DONE (regresi di B-05; QA ulang CR-013) | CR-013: NIK 16 digit diterima, nama/email opsional dari data pegawai |
| A-10 Audit trail auth | DONE (✅ CR-013, QA ulang) | T-02 selesai (QASMTASK-029 Pass di R2); CR-013: pelaku = `id_pengguna_actor`, `nip_actor` jejak (null untuk akun tanpa NIP) |
| A-11 Halaman login (FE) | DONE | CR-013: batas username 100, placeholder "username atau NIP" |
| A-12 Halaman manajemen akun (FE) | DONE (✅ CR-013) | CR-013: kolom Nama, NIP "—", isian Nama/Email, NIP read-only / "Tautkan NIP", baris sendiri per `id_pengguna` (menu ⋮ CR-015), pilihan role Admin Satker 2/6/7 |
| A-13 Unit test RBAC & JWT | DONE | terintegrasi `check.sh` |

## DBV-010 / CR-013 — ✅ DISETUJUI, DI MAIN (`6bb29b5`); menunggu D-11 (DBA), deploy Dev, dan QA ulang

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
- QA ulang karena identitas akun pindah ke `id_pengguna` (JWT `sub`, `token`, pelaku audit): QASMTASK-024 (A-05), 027 (A-08), 028 (A-09), 029 (A-10); regresi ringan 020, 025, 026, 031; smoke strict + collation di grup default.
- Syarat DBV-010 D-6 → kewajiban **CR-014** (driver email SMTP): sebelum email dipakai sebagai kanal reset, buat/ubah akun wajib menolak email duplikat (dalam `utf8mb4_unicode_ci`) + test. Saat ini keunikan email belum ditegakkan (kanal reset masih driver log/mock berbasis username). Kewajiban mengisi email menunggu konfirmasi TL.
- D-12 wajib tuntas (DBV-009) sebelum FK `*_by` dibuat atau data legacy diimpor. D-7: job purge `token`/`login_attempts`/`forgot_attempts` (A-01 Bagian 8 #12).
- Tindak lanjut 1 baris di G-01 Bagian 8.5 dan G-10 D2 (JOIN `pengguna` × `faq_rate` kini boleh) setelah merge.
- Tindak lanjut 1 baris setelah merge: README MasterData (kolom `nip` tabel `faq_rate`) dan G-10 (kolom `nip` `faq_rate`) masih menyebut "JWT `sub`" → ganti "claim `nip`" (A-01 9.10).
- Di luar cakupan (usul CR/issue terpisah): paksa ganti password dari `expired_at` (FCP legacy; putuskan sebelum impor karena `expired_at` impor sudah terisi); tipe legacy `pengguna.status`/`id_unit`/`id_satker` dan `id_pengguna` signed (D-12) di DBV-009; FK `pengguna.nip` → `pegawai` (B-01); ganti NIP (B-06); `AppShell` menampilkan `name`; pembatasan username ASCII (keputusan B, setelah audit data).

## Tindak lanjut A-01 Bagian 8 lainnya

- #5 `down()` `AlterAuditLogsEventAddAuth`, #6 restore akun soft-delete (Fase 3), #7 timezone, #11 tabel legacy tidak dimigrasi, #12 purge log/token, #13 kebijakan lockout, #14 validasi di MySQL 8 — masih terbuka (lihat A-01 Bagian 8).
