# QAFUNC-001 — Fase 0: Verifikasi Fondasi

| | |
|---|---|
| **Jenis** | QA Functional (QA UI Test tidak ada di fase ini — belum ada UI bisnis) |
| **Tanggal eksekusi** | 21 September 2026 |
| **Commit yang diuji** | `ac0ab51` (branch `main`) |
| **Lingkungan** | Lokal — PHP 8.4.2, MySQL 8.0.30 (Laragon), Node 24.15, Windows 11; DB `simpeg_v2` (dev) + `simpeg_v2_testing` (test) |
| **Rujukan** | `00-Fondasi.md`, SOP Pengembangan Fase 0 (Test Case Checklist), Tech Spec §2.1 |
| **Hasil** | **9 / 9 Hijau/Done** |
| **Exit Criteria** | Seluruh item Hijau ✅ — **Horii sign-off: MENUNGGU** |

## Checklist

| # | Cakupan | Status | Bukti |
|---|---|---|---|
| 1 | `check.sh` jalan, exit code 0 | ✅ **Hijau/Done** | `./check.sh` → exit 0. Backend: PHPStan level 5 "No errors", PHP-CS-Fixer 0 file, PHPUnit OK (163 tests, 821 assertions). Frontend: ESLint bersih, vue-tsc bersih, Vitest 28 passed, vite build sukses |
| 2 | JWT: access token expired 1 jam ditolak; refresh token expired 7 hari ditolak | ✅ **Hijau/Done** | `JwtServiceTest`: *Access token expired after one hour is rejected*, *still valid just before one hour* (boundary), *Refresh token expired after seven days is rejected*. API: `GET /auth/me` tanpa token → 401, token palsu → 401 |
| 3 | Refresh token tersimpan HASH di DB, bukan plaintext | ✅ **Hijau/Done** | Black-box: login via API, cookie `refresh_token` (64 char) → di tabel `token` ditemukan 1 baris dengan `token_hash = sha256(cookie)`, **0 baris** berisi plaintext; format `token_hash` hex 64. Kedua cookie `HttpOnly`. Unit: *Refresh token is stored as hash not plaintext* |
| 4 | Refresh token reuse ditolak/invalidated | ✅ **Hijau/Done** (lihat Catatan 1) | Black-box: refresh #1 → 200, refresh #2 dengan token sama → **401**, token aktif tersisa untuk akun tsb = **0** (seluruh sesi dicabut). Unit: *Reused refresh token is rejected* |
| 5 | BaseSnapshotModel: sync TIDAK otomatis di luar `syncToActiveSnapshot()` | ✅ **Hijau/Done** | `BaseSnapshotModelTest`: *Insert update delete riwayat does not touch snapshot* (tabel snapshot tetap 0 baris), *Explicit sync creates snapshot and writes manual audit*, *Second sync updates existing snapshot row* |
| 6 | BaseAuditableModel: `audit_logs` otomatis terisi saat create/update/delete | ✅ **Hijau/Done** | `BaseAuditableModelTest`: create (after JSON), update (before+after JSON), hard delete, soft delete, *All three events produce exactly three rows*, actor NULL tanpa user login |
| 7 | Queue: job dummy ter-enqueue dan diproses via `php spark queue:work` | ✅ **Hijau/Done** | CLI nyata: `php spark queue:dummy "QAFUNC-001"` → baris `queue_jobs` id=2 status 0 → `php spark queue:work default --max-jobs 1 --stop-when-empty` → "The processing of this job was successful" → marker `writable/queue/dummy_job.log` terisi, `queue_jobs` sisa 0, `queue_jobs_failed` 0 |
| 8 | Cache: set/get/invalidate key dummy | ✅ **Hijau/Done** | `CacheServiceTest`: *Set and get* (tersimpan sebagai file), *Invalidate removes key*, *Remember computes once*, *Invalidate matching pattern* |
| 9 | Mock adapter BSrE/SIASN/FCM return dummy tanpa hit network | ✅ **Hijau/Done** | `MockAdaptersTest` 6 test lolos (signed PDF dummy, data sinkron dummy, payload FCM ter-log lokal). Inspeksi source: tidak ada pemanggilan `curl`/HTTP/socket di ketiga mock adapter |

Perintah bukti per item (36 tests, 143 assertions, semua lolos):

```bash
cd backend
vendor/bin/phpunit --no-coverage --testdox tests/database/Auth/JwtServiceTest.php tests/database/Models tests/database/Queue tests/unit/Libraries/CacheServiceTest.php tests/unit/Integrations
```

## Catatan QA

1. **Reuse refresh token secara bersamaan (race) — tidak bisa direproduksi di lingkungan ini.** DB Validator (DEV-002, `backend/docs/db-review/A-01-auth-schema.md` Bagian 8 #1) melaporkan: satu refresh token dipakai 2x *bersamaan* menghasilkan 2 sesi aktif. Uji 4 request paralel di sini menghasilkan 1×200 + 3×401 dan 0 token aktif, tetapi `php spark serve` adalah server single-thread yang menserialkan request, jadi hasil ini **bukan bukti race sudah aman**. Inspeksi kode membenarkan temuan validator: `TokenModel::revoke()` belum memakai `UPDATE ... WHERE revoked=0` + cek affected rows. Item #4 dinilai Hijau terhadap kriteria Fase 0 (reuse berurutan ditolak + sesi diinvalidasi); perbaikan atomik tetap **wajib sebelum sign-off akhir Fase 1 / Production** sesuai keputusan DB Validator.
2. **"Project kosong"**: saat QA dijalankan repo sudah memuat Modul A (Fase 1). `check.sh` exit 0 pada kondisi ini mencakup seluruh test Fase 0; eksekusi di project kosong tercatat saat Fase 0 diselesaikan (16 Sep 2026).
3. **Portabilitas DB**: DB Validator mencatat 1 test queue gagal di MariaDB 10.4 (`SKIP LOCKED` tidak didukung). Di MySQL 8.0.30 (target ADR) test tersebut lolos. Validasi ulang di MySQL 8 server Dev tetap disarankan (Bagian 8 #14).
4. Fail-fast `check.sh` (di luar 9 item cakupan) sudah diverifikasi saat Fase 0: backend yang sengaja dirusak menghentikan eksekusi sebelum langkah frontend (exit 1).

## Update Trello (21-09-2026)

Sesuai aturan board, kartu QAFUNC-001 (label *Info (Read-only)*) tidak diubah; centang dan catatan QA ditulis di kartu QASMTASK. Tujuh kartu di luar 9 butir ringkasan (F0-01/02/03/07/08/09/18) diverifikasi ulang hari yang sama sebelum ditandai.

| Kartu | Label | Checklist | Keterangan |
|---|---|---|---|
| QASMTASK-001 s.d. 017 | **Done** (hijau), Todo dilepas | semua tercentang | 1 komentar bukti per kartu |
| QASMTASK-018 (F0-18 deploy Dev) | tetap **Todo** | 1/2 (README tercentang) | Push-trigger build baru terbukti lewat simulasi lokal; DoD mensyaratkan server Dev |
| ISSUE-002 (list 🚩 Issues) | baru | — | Race reuse refresh token, terkait QASMTASK-006 / 024 |

## Kesimpulan

Seluruh 9 cakupan ringkasan QAFUNC-001 **Hijau/Done**. Di tingkat QASMTASK, 17 dari 18 Pass; **QASMTASK-018 belum Pass** sampai hook deploy diuji di server Dev. Exit criteria Fase 0 ("seluruh QASMTASK terkait Pass") karenanya **belum penuh**; tersisa QASMTASK-018 dan **Horii sign-off**.
