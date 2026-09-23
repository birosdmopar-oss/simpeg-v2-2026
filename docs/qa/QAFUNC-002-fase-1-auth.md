# QAFUNC-002 — Fase 1: Functional Test Auth dan Akun

| | |
|---|---|
| **Jenis** | QA Functional |
| **Tanggal eksekusi** | 23 September 2026 |
| **Commit yang diuji** | `7494ce7` (branch `main`) |
| **Lingkungan** | Lokal — PHP 8.4.2, MySQL 8.0.30 (Laragon), Node 24.15, Windows 11; API `http://localhost:8089`, DB dev `simpeg_v2`, DB test `simpeg_v2_testing`; captcha driver `mock` |
| **Rujukan** | `01-Auth.md`, Test Case Manual MTC-001 s.d. MTC-007, Matriks Role x Endpoint Modul A, `backend/docs/db-review/A-01-auth-schema.md` |
| **Hasil cakupan** | **15 / 15 butir lolos** (black-box API + DB) |
| **Hasil per QASMTASK** | **8 Pass, 6 belum Pass** — defect terbuka (024, 025, 026, 029) dan QA Lapis 1 visual belum bisa dijalankan (030, 031) |
| **Exit Criteria** | Belum tercapai — 4 ISSUE terbuka (ISSUE-002, 004, 005, 006), QA Lapis 1 (QAUI-001), Horii sign-off |

## Metode

1. `./check.sh` → exit 0. PHPStan level 5 "No errors", PHP-CS-Fixer 0 file, PHPUnit **177 tests / 2028 assertions**, ESLint + vue-tsc bersih, Vitest **47 passed**, build sukses.
2. Suite Modul A: `vendor/bin/phpunit --no-coverage --testdox tests/Auth` → **109 tests / 550 assertions, semua lolos**.
3. Black-box: skrip curl terhadap API lokal + query DB dev, memakai akun seed README_local_seed (password `Password123!`). Tiap skenario memakai akun berbeda; password yang diubah dikembalikan ke seed di akhir. Hasil: 63 pemeriksaan lolos pada run pertama; 6 yang gagal diinvestigasi — 5 ternyata kesalahan skrip QA (filter waktu memakai `NOW()` MySQL WIB padahal app menulis UTC; format JSON string), 1 defect nyata (lihat butir 15). Butir yang terdampak diulang dengan kueri yang benar dan lolos.

## Checklist cakupan

| # | Cakupan | Hasil | Bukti |
|---|---|---|---|
| 1 | Login sukses kredensial benar; salah = 401 pesan generik | ✅ | Benar → 200 + cookie `refresh_token` HttpOnly; password salah → 401 "Username atau password salah." |
| 2 | NIP tidak terdaftar = error generik | ✅ | 401 dengan pesan **identik** dengan password salah |
| 3 | Captcha invalid/kosong = ditolak sebelum cek kredensial | ✅ | Kosong → 422, invalid (+password salah) → 422; `login_attempts` akun tidak bertambah |
| 4 | N kali gagal = terkunci; counter reset setelah sukses | ✅ | 5× gagal lalu password benar ke-6 → **423** "Akun terkunci sementara … 15 menit"; 4× gagal (N-1) lalu benar → 200; 4× gagal lagi setelah sukses lalu benar → 200 |
| 5 | Login pertama MD5 benar = sukses, `password` Argon2id, `password_legacy` kosong | ✅ | Sebelum: `password` NULL, legacy terisi → login 200 → `password` diawali `$argon2id$`, legacy NULL |
| 6 | Login kedua = jalur Argon2id, tidak sentuh MD5 | ✅ | Audit login: `password_path` login pertama `legacy`, kedua `argon2id` |
| 7 | MD5 salah = ditolak, tetap kena hitungan lockout | ✅ | 401; tidak ada rehash; `login_attempts` +1 dengan `success=0` |
| 8 | Refresh token reuse = ditolak, seluruh sesi invalid | ✅ (lihat Catatan 1) | Refresh A → 200, reuse A → 401, token aktif akun = 0, sesi B ikut tidak bisa refresh (401) |
| 9 | Logout = refresh token dihapus dari DB | ✅ | Row `token` (hash cookie) ada sebelum logout, **0 baris** sesudah; refresh dengan token itu → 401 |
| 10 | Ganti password = seluruh refresh token lama invalid | ✅ | Lama salah → 422; sukses → 200, token aktif 0, dua sesi lama refresh → 401, password baru Argon2id, audit update (actor pemilik, hash `***`) |
| 11 | Token reset expired / dipakai ulang = ditolak | ✅ (lihat Catatan 2) | Dipakai ulang → 422 "Token reset sudah pernah dipakai."; expired → 422 "Token reset sudah kedaluwarsa.", token tidak ditandai terpakai, password tidak berubah |
| 12 | Role 3 akses akun di luar `id_satker` sendiri = 403 | ✅ | Admin Satker S01: GET/PUT/DELETE akun S02 → 403; daftar hanya berisi S01; akun S01 → 200 |
| 13 | Role selain 1 dan 3 di CRUD akun = 403 | ✅ | Role 2, 4, 5, 6, 7, 8: list/show/create/update/status/delete → 403; data tidak berubah |
| 14 | Tiap endpoint diuji 8 role (berhak 200, tidak 403) | ✅ | Matriks black-box 8 role × 8 endpoint sesuai Matriks Role x Endpoint Modul A (role 1 & 3 lolos filter; lainnya 403 di `/auth/users*`; `/me`, `/change-password` UL_ALL); tanpa token → 401. Unit `RbacAuthEndpointsTest` 19/19 |
| 15 | Login/logout/ganti password/ubah akun tercatat di audit_logs | ⚠️ **lolos dengan 1 defect** | login, logout, ganti password, ubah role akun (actor + before/after) tercatat benar. **Defect:** reset password (lupa password) tercatat dengan `nip_actor = NULL` — melanggar DoD A-10 "actor benar di setiap record" |

## Status per QASMTASK

| QASMTASK | Task | Status | Catatan |
|---|---|---|---|
| 019 | A-01 Migration Auth | Pass | Disetujui DB Validator 21-09-2026; `AuthSchemaTest` lolos |
| 020 | A-02 Login | Pass | Butir 1-2 |
| 021 | A-02b Lazy rehash | Pass | Butir 5-7 |
| 022 | A-03 Captcha Turnstile | Pass | Butir 3; `CaptchaVerifierTest` 8/8 (Turnstile real diuji dengan transport tiruan, belum dengan secret Cloudflare asli) |
| 023 | A-04 Lockout | Pass | Butir 4 |
| 024 | A-05 Refresh & logout | **Belum Pass** | Fungsi lolos (butir 8-9); race reuse bersamaan terbuka → **ISSUE-002**. Refresh terjadi saat 401 (reaktif), sesuai ADR-022 |
| 025 | A-06 Change password | **Belum Pass** | Backend lolos (butir 10); **UI ganti password (MTC-007) belum ada** → **ISSUE-006** |
| 026 | A-07 Forgot/reset | **Belum Pass** | Backend lolos (butir 11, rate limit 3/jam terbukti); race token reset terbuka → **ISSUE-004** (DB Validator #2); UI lupa/reset password (MTC-007) belum ada → **ISSUE-006** |
| 027 | A-08 CRUD akun scoped | Pass | Butir 12-13 |
| 028 | A-09 Lifecycle akun | Pass | `AccountProvisionerTest` 6/6; regression lintas modul dijadwalkan akhir Fase 3 (B-05) |
| 029 | A-10 Audit trail | **Belum Pass** | Actor NULL pada audit reset password → **ISSUE-005** (DB Validator #4) |
| 030 | A-11 Login FE | **Belum Pass** (fungsional lolos) | Item "QA Lapis 1 vs Figma" belum bisa: layar Login **tidak ada** di file Figma (ranah QAUI-001) |
| 031 | A-12 Manajemen Akun FE | **Belum Pass** (fungsional lolos) | Item "QA Lapis 1" belum bisa: layar Manajemen Akun **tidak ada** di file Figma (ranah QAUI-001) |
| 032 | A-13 Unit test RBAC & JWT | Pass | 109 test Modul A lolos, bagian dari `check.sh` |

## Catatan

1. **Race refresh token (ISSUE-002)** — reuse berurutan ditolak dan seluruh sesi dicabut, tetapi `TokenModel::revoke()` masih `UPDATE` tanpa syarat `revoked = 0`. Belum diperbaiki di `7494ce7`. Tidak bisa direproduksi di lokal karena `php spark serve` menserialkan request.
2. **Race token reset (DB Validator #2)** — `ResetPasswordService::reset()` mengecek `used_at` lalu `update()` tanpa syarat `used_at IS NULL` dan tanpa transaksi; token reset lain milik user juga tidak dibatalkan. Belum diperbaiki.
3. **Zona waktu (DB Validator #8 / #7)** — app menulis `created_at`/`expires_at` dalam UTC, server MySQL WIB. Saat QA, satu uji token expired yang men-set `expires_at` dengan `NOW()` MySQL justru membuat token **masih berlaku** 7 jam. Perilaku app konsisten dengan dirinya sendiri, tetapi proses apa pun yang menulis waktu dari sisi DB (skrip migrasi data legacy, query manual admin) akan salah. Perlu keputusan sebelum migrasi data.
4. **UI MTC-007 belum ada**: frontend belum punya halaman ganti password maupun lupa/reset password (service `auth.service.ts` sudah memanggil `/auth/change-password`, tetapi tidak ada route/view; halaman login hanya menulis "Lupa password? Hubungi Admin"). Ditemukan saat mencocokkan checklist kartu QASMTASK-025/026.
5. Akun seed di DB dev sudah terkena lazy rehash selama QA (kolom `password` terisi Argon2id); password tetap `Password123!`. `login_attempts` dibersihkan setelah QA.
