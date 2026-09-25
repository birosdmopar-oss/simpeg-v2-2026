# QAFUNC-002-R1: QA ulang QASMTASK-024, 026 dan 029 (Fase 1 Auth)

| | |
|---|---|
| **Jenis** | QA Functional, uji ulang setelah PR #8 (ISSUE-002) dan PR #9 (ISSUE-004/005) |
| **Tanggal eksekusi** | 25 September 2026, 07:50–08:22 UTC |
| **Commit yang diuji** | `3832c3d` (origin/main), worktree sementara detached |
| **Lingkungan** | PHP 8.4.2, MySQL 8.0.30 Laragon, `php spark serve` port 8093; port 8094–8100 hanya dipakai untuk uji race; DB scratch `simpeg_v2_qa_7a106b`, DB test `simpeg_v2_qat_7a106b` (keduanya sudah di-DROP); captcha `mock`; `auth.exposeResetTokenInResponse=true` |
| **Rujukan** | `docs/qa/QAFUNC-002-fase-1-auth.md`, `01-Auth.md` (A-05, A-07, A-10), ADR-004, ADR-022 |
| **Hasil** | 024 **Belum Pass** (ISSUE-002 sudah beres, ada 1 temuan baru T-01) · 026 bagian backend **Pass** (ISSUE-004 sudah beres) · 029 **Belum Pass** (ISSUE-005 sudah beres, ada 1 temuan baru T-02) |

## Metode

1. **Black-box.** Harness PHP (curl + mysqli) memanggil API di 8093 lalu memeriksa DB scratch. Akun berasal dari `AuthSeeder` (password seed lokal, lihat README_local_seed), captcha `qa-ok`. Setiap skenario memakai akun yang berbeda.
2. **Race lewat HTTP.** Ada 8 instance `spark serve`, masing-masing proses terpisah di port 8093–8100. Request dikirim serentak dengan `curl_multi`, satu request per port.
   - Semua request terkirim dalam ≤4 ms, sedangkan respons pertama baru selesai ≥171 ms. Artinya request benar-benar diproses paralel.
3. **Race lewat CLI.** N proses `php` di-bootstrap CI4 (`util_bootstrap.php`, env development, DB scratch). Setiap proses menunggu barrier waktu lalu memanggil `authService->refresh()` atau `resetPasswordService->reset()` langsung. Selisih waktu mulai antarproses maksimal 14 ms.
4. **Kontrol negatif.** Di worktree scratch, `TokenModel::revoke()` dan `ForgotAttemptModel::markUsed()` sementara dibuat tanpa syarat.
   - Harness mendeteksi semuanya: 3/3 percobaan refresh gagal (3× 200) dan 3/3 percobaan reset gagal (8× 200).
   - File dipulihkan dengan `git checkout`, `git status` bersih, lalu percobaan ulang lolos 3/3.
5. **PHPUnit di DB test unik:**
   - `tests/Auth`: **130 tests / 747 assertions OK**
   - `tests/database/Auth/JwtServiceTest.php`: **25 / 89 OK**
   - `tests/database/Models/BaseAuditableModelTest.php`: **6 / 24 OK**
   - Tidak ada test yang menangkap T-01 maupun T-02.
6. **Insiden lingkungan.** MySQL lokal sempat mati di tengah QA (beberapa `mysqld` di-start bersamaan oleh proses lain). Satu uji pertama (24-01) dapat HTTP 500 karena DB mati; hasilnya dibuang dan seluruh 024 diulang setelah MySQL dinyalakan ulang. Tidak berkaitan dengan kode yang diuji.

## QASMTASK-024: A-05 Refresh Token dan Logout

| # | Kriteria | Langkah | Hasil | Status | Bukti |
|---|---|---|---|---|---|
| 24-01 | Login menerbitkan refresh token HttpOnly; DB hanya menyimpan hash | login U=199002152015022002 | sesuai | PASS | HTTP 200; `Set-Cookie: refresh_token=<64hex>; Max-Age=604800; path=/api/v1/auth; HttpOnly; SameSite=Lax`; `token.id=1 revoked=0 expires_at=2026-10-02 08:00:00` (UTC); plaintext tidak ada di `token_hash` |
| 24-02 | Refresh merotasi token: token lama dicabut, access token baru valid | POST /auth/refresh dengan cookie | sesuai | PASS | HTTP 200; id=1 `revoked=1 revoked_at=08:00:00`; token baru id=2 `revoked=0`; GET /auth/me pakai access baru → 200 |
| 24-03 | Fallback body `{refresh_token}` untuk klien non-browser | refresh lewat body | sesuai | PASS | HTTP 200; id=2 revoked=1; token baru id=3 |
| 24-04 | Reuse berurutan ditolak dan seluruh sesi dicabut (butir 8) | login perangkat 2, lalu refresh dengan token A yang sudah dirotasi | sesuai | PASS | sesi aktif 2 → reuse A → **401 "Refresh token sudah pernah dipakai."** → sesi aktif **0**; refresh token C → 401; perangkat 2 → 401 |
| 24-05 | Logout menghapus row token, bukan hanya cookie (butir 9) | 2 sesi U=198809092013092010, logout sesi 1 | sesuai | PASS | HTTP 200 `logged_out=true`; `SELECT COUNT(*) FROM token WHERE id=5` → **0**; `Set-Cookie access_token=deleted` dan `refresh_token=deleted` (expires 1970); replay → 401 "Refresh token tidak dikenal." |
| 24-06 | Logout satu perangkat tidak mencabut perangkat lain | refresh sesi 2 | sesuai | PASS | sesi 2 tetap `revoked=0`, refresh → 200 |
| 24-07 | Token kedaluwarsa, tidak dikenal, atau tidak ada → 401 | `expires_at = UTC_TIMESTAMP()-1 menit`, token acak, tanpa cookie | sesuai | PASS | 401 "Token sudah kedaluwarsa." (row menjadi revoked=1); 401 "Refresh token tidak dikenal."; 401 "Token tidak ditemukan." |
| 24-08 | Logout tanpa JWT → 401 | POST /auth/logout | sesuai | PASS | 401 "Unauthorized" |
| 24-R | **Race reuse bersamaan (ISSUE-002)** | lihat tabel Race | 48/48 percobaan: tepat 1× 200, sisanya 401 reuse, hanya 1 token baru ter-commit, 0 sesi aktif | **PASS** | `TokenModel.php:52-64`, `JwtService.php:182-217, 320-362` |
| 24-A | Auto-refresh FE | review kode, tidak berubah sejak QAFUNC-002 | reaktif saat 401 dan single-flight, sesuai ADR-022 | PASS | `frontend/src/lib/axios.ts:122-157` |
| 24-09 | Sesi baru setelah reset password tidak ikut dicabut oleh cookie lama | perangkat A login → reset password → login di perangkat B → perangkat A refresh | **sesi B ikut dicabut, berulang setiap kali** | **FAIL (T-01)** | lihat Temuan T-01 |
| info | Access token setelah refresh token dicabut | GET /auth/me pakai access lama | 200 sampai exp (1 jam) | sesuai desain | ADR-004: access token pendek, refresh token bisa dicabut |

**Kesimpulan 024: Belum Pass.** Seluruh DoD dan ISSUE-002 lolos (race 48/48, kontrol negatif terdeteksi). Kartu tertahan oleh T-01. Kartu bisa dinyatakan Pass jika Tech Lead menerima T-01 sebagai perilaku by design. Kalau tidak, T-01 perlu diperbaiki.

## QASMTASK-026: A-07 Lupa dan Reset Password (bagian backend)

| # | Kriteria | Langkah | Hasil | Status | Bukti |
|---|---|---|---|---|---|
| 26-01 | Permintaan lupa password menerbitkan token hash dengan TTL 30 menit | forgot U=197611112010111011 | sesuai | PASS | HTTP 200, pesan generik; `forgot_attempts.id=1 requested_at=08:00:48 expires_at=08:30:48` (1800 detik), `used_at=NULL`; plaintext tidak tersimpan |
| 26-02 | Username tidak dikenal atau akun nonaktif: respons identik, tanpa token | forgot 9999…, forgot akun nonaktif | sesuai | PASS | 200/200, pesan sama; 2 baris dengan `token_hash IS NULL` |
| 26-03 | **Rate limit lewat `forgot_attempts`** (DoD) | 4× forgot per username | sesuai | PASS | terdaftar `200,200,200,429` "Terlalu banyak permintaan… 60 menit.", 3 baris; tidak dikenal `200,200,200,429` |
| 26-04 | Reset sukses: Argon2id, token terpakai, semua refresh token dicabut | 2 sesi aktif, lalu reset | sesuai | PASS | HTTP 200 `reset=true`; `password` diawali `$argon2id$`, `password_legacy=NULL`, `password_changed_at=08:00:51`; `used_at` terisi; sesi aktif 2 → 0; refresh lama → 401; login password lama → 401; login password baru → 200 |
| 26-05 | **Token dipakai ulang → ditolak** (butir 11) | reset kedua dengan token yang sama | sesuai | PASS | 422 `errors.token` "Token reset sudah pernah dipakai."; hash password tidak berubah |
| 26-06 | **Token kedaluwarsa → ditolak** (butir 11) | `expires_at = UTC_TIMESTAMP()-1 menit` | sesuai | PASS | 422 "Token reset sudah kedaluwarsa."; `used_at` tetap NULL; password tidak berubah |
| 26-07 | Token tidak dikenal atau kosong | token acak, token "" | sesuai | PASS | 422 "Token reset tidak valid."; 422 "The token field is required." |
| 26-08 | Password baru melanggar kebijakan: 422 dan token tidak terpakai | "abc", lalu konfirmasi berbeda, lalu reset valid | sesuai | PASS | 422 (min 8, huruf+angka), 422 "Konfirmasi password tidak cocok."; `used_at=NULL`; reset valid → 200; login password baru → 200 |
| 26-09 | Token lain milik akun yang sama dibatalkan setelah reset sukses | T1, T2 → reset pakai T2 → coba T1 | sesuai | PASS | T2 → 200; T1 → 422 "Token reset sudah tidak berlaku lagi."; row T1 `used_at = expires_at = 08:00:55` |
| 26-10 | Akun dinonaktifkan setelah token terbit | forgot, status diset 0, lalu reset | sesuai | PASS | 422 "Token reset tidak valid."; password tidak berubah; `used_at` NULL (status dikembalikan ke 1) |
| 26-R1 | **Race token yang sama (ISSUE-004)** | lihat tabel Race | 30/30: tepat 1× 200, sisanya 422 "sudah pernah dipakai"; 1 baris audit; login pakai password pemenang → 200 | **PASS** | `ResetPasswordService.php:143-183`, `ForgotAttemptModel.php:56-63`, `PenggunaModel.php:96-110` |
| 26-R2 | **Race token berbeda milik akun yang sama** | 3 token berbeda, reset serentak | 23/23: 1× 200, 2× 422 "tidak berlaku lagi"; 0 token tersisa berlaku; tanpa 500 atau deadlock | **PASS** | `ForgotAttemptModel.php:75-88` |

**Kesimpulan 026 (backend): Pass.** Semua DoD A-07 dan ISSUE-004 lolos. Kartu penuh tetap Belum Pass sampai ISSUE-006 selesai, yaitu UI lupa/reset password (MTC-007) dan keputusan kanal pengiriman token.

Catatan untuk keputusan ISSUE-006: log hanya mencatat username dan waktu kedaluwarsa, **bukan token** (`ResetPasswordService.php:97-103`). Artinya, di production (`expose=false`) token tidak sampai ke pengguna sama sekali, sehingga fitur lupa password belum bisa dipakai end-to-end. Kalimat di README "token dicatat di log" juga tidak akurat.

## QASMTASK-029: A-10 Audit Trail Auth

| # | Kriteria | Langkah | Hasil | Status | Bukti |
|---|---|---|---|---|---|
| 29-01 | Login tercatat dengan actor dan timestamp benar | login U=197212122008121008 | sesuai | PASS | `#38 login pengguna/8 actor=197212122008121008 at=08:03:01` (request 08:03:00–01 UTC); `after_json` berisi ip, `password_path=legacy`, `rehashed=true` |
| 29-02 | **Record lain yang ditulis saat login punya actor benar** | login yang sama | **actor NULL** | **FAIL (T-02)** | `#36 update pengguna/8 actor=NULL` (password→`***`, password_legacy→null); `#37 update pengguna/8 actor=NULL` (last_login_at) |
| 29-03 | Logout tercatat | logout | sesuai | PASS | `#39 logout pengguna/8 actor=197212122008121008`, `refresh_token_deleted=true` |
| 29-04 | Ganti password tercatat, hash dimasking | change-password | sesuai | PASS | `#42 update actor=197212122008121008`, before/after password `"***"`, string `$argon2id$` tidak ada di JSON |
| 29-05 | **Reset password: actor = pemilik akun (ISSUE-005)** | 4 reset dari 026 dan 024b | sesuai | **PASS** | `#20 /10 actor=197611112010111011`, `#23 /7 actor=199308082019082007`, `#26 /11 actor=199112122016122009`, `#31 /9 actor=198809092013092010`; password `***` |
| 29-06 | Reset dalam kondisi race: tepat 1 audit, actor pemilik | uji race 26-R | sesuai | PASS | setiap percobaan menghasilkan 1 baris update dengan actor `196511101990011005`; pihak yang kalah tidak menulis audit |
| 29-07 | Perubahan akun oleh Super Admin | PUT role, PATCH status, POST, DELETE | sesuai | PASS | PUT 200, PATCH 200, POST 201 (id 15), DELETE 200 → `#46/#47 update /4`, `#48 create /15`, `#49 delete /15`, semuanya actor=198501012010011001; `user_level` 4→5 |
| 29-08 | Perubahan akun oleh Admin Satker | PUT /auth/users/2 | sesuai | PASS | `#53 update /2 actor=198703102012031003` |
| 29-09 | Timestamp benar | semua langkah di atas | seluruh baris jatuh di jendela waktu request (UTC) | PASS | konsisten dengan app (UTC); isu zona waktu WIB vs UTC tetap seperti Catatan 3 QAFUNC-002 |

Rekap seluruh `audit_logs` di akhir run:

| Event | Jumlah | Actor NULL |
|---|---|---|
| update | 245 | 160 |
| login | 152 | 0 |
| logout | 2 | 0 |
| create | 1 | 0 |
| delete | 1 | 0 |

Ke-160 baris `update` dengan actor NULL **semuanya berasal dari login**:
- 134 baris `last_login_at`;
- 18 baris tanpa perubahan kolom karena login terjadi pada detik yang sama;
- 8 baris lazy rehash.

Jumlah 134 + 18 = 152, persis satu baris per login.

**Kesimpulan 029: Belum Pass.** ISSUE-005 terverifikasi selesai, tetapi T-02 melanggar DoD "Actor & timestamp benar di setiap record".

## Race: jumlah percobaan

| Skenario | Jalur | Proses paralel | Percobaan | OK |
|---|---|---|---|---|
| Refresh, token sama | HTTP (8 instance) | 8 | 10 + 3 (setelah restore) | 13/13 |
| Refresh, token sama | HTTP | 2 | 10 | 10/10 |
| Refresh, token sama | CLI barrier | 8 | 10 | 10/10 |
| Refresh, token sama | CLI barrier | 2 | 15 | 15/15 |
| Reset, token sama | HTTP | 8 | 10 | 10/10 |
| Reset, token sama | HTTP | 2 | 10 | 10/10 |
| Reset, token sama | CLI barrier | 8 | 10 | 10/10 |
| Reset, 3 token berbeda, akun sama | HTTP | 3 | 10 + 3 | 13/13 |
| Reset, 3 token berbeda, akun sama | CLI barrier | 3 | 10 | 10/10 |
| **Total** | | | **101** | **101/101, 0 gagal, 0 error 500** |
| Kontrol negatif (mutan) | HTTP | 8 | 3 refresh + 3 reset | 6/6 terdeteksi GAGAL |

## Temuan baru

**T-01 (Medium, A-05). Token yang dicabut karena reset/ganti password atau perubahan akun oleh admin diperlakukan sebagai "reuse", sehingga sesi baru pengguna ikut dicabut, dan ini berulang.**

- **Reproduksi (24-09):**
  1. Perangkat A login.
  2. Lupa password, lalu reset → HTTP 200.
  3. Login di perangkat B → 1 sesi aktif.
  4. Perangkat A refresh dengan cookie lama → 401 "Refresh token sudah pernah dipakai." → **sesi aktif menjadi 0**, refresh perangkat B → 401.
  5. Login lagi di B lalu A refresh lagi → B dicabut lagi.
  6. Respons 401 refresh tidak menghapus cookie, jadi cookie lama tetap tersimpan di browser A.
- **Penyebab:**
  - Semua pencabutan massal menulis `revoked=1`: `PasswordService.php:46`, `ResetPasswordService.php:171`, `UserService.php:240, 269`.
  - `JwtService.php:190-192` menganggap setiap token dengan `revoked=1` sebagai reuse lalu memanggil `handleReuse()` (`JwtService.php:370-375`), yang mencabut semua sesi.
  - `TokenController.php:23-42` tidak mengirim cookie kedaluwarsa saat refresh gagal.
  - Di FE, setiap kali route yang butuh login dibuka, guard memanggil `fetchCurrentUser()` (`router/index.ts:74-85`). Jika dapat 401, FE mencoba refresh (`axios.ts:146-157`), sehingga tab lama akan terus mencabut sesi baru.
  - Logout sendiri sudah sengaja dibedakan: row dihapus sehingga replay terbaca "tidak dikenal" (24-06). Jalur pencabutan lain belum dibedakan dengan cara yang sama.
- **Opsi perbaikan (keputusan tim):**
  - pada ganti/reset password dan perubahan akun oleh admin, hapus row token seperti logout, bukan `revoked=1`; atau
  - bedakan alasan pencabutan (rotasi vs dicabut). Opsi ini mengubah skema, jadi perlu DB Validator.
  - Di kedua opsi, respons 401 refresh sebaiknya juga menghapus cookie.

**T-02 (Blocker A-10). Setiap login sukses menulis 1–2 baris audit `update pengguna` dengan `nip_actor = NULL`.**

- **Penyebab:**
  - `AuthService.php:80` meng-update `last_login_at` dan `PasswordVerifier.php:99` menyimpan hasil lazy rehash, keduanya lewat `PenggunaModel` yang auditable.
  - Actor diambil dari `authContext` (`BaseAuditableModel.php:231-238`), yang masih kosong saat login.
  - `PenggunaModel::withActor()` (`PenggunaModel.php:74-84`) baru dipakai di jalur reset (`ResetPasswordService.php:161`).
- **Perbaikan yang disarankan:** bungkus verifikasi+rehash dan update `last_login_at` di `AuthService::login()` dengan `withActor($user['nip'], …)`, lalu tambahkan test regresi.
- QAFUNC-002 butir 15 tidak menangkap temuan ini karena hanya memeriksa baris `event=login`.

## Catatan

1. Pesan 401 untuk token yang dicabut karena reset sama dengan pesan reuse ("sudah pernah dipakai"). Kode HTTP-nya benar, tetapi pesannya menyesatkan. Ini terkait T-01.
2. README (`app/Controllers/Api/Auth/README.md`) menulis logout "dicabut di DB (`token.revoked=1`)", padahal kode menghapus row. Perilaku kode sudah benar sesuai DoD A-05; yang perlu dikoreksi README-nya.
3. Tabel auth masih `utf8mb4_general_ci` (DDL `audit_logs`, `token`, `forgot_attempts`), sesuai ISSUE-008/010 yang masih terbuka.

## Bersih-bersih

- Semua server `spark serve` 8093–8100 sudah dihentikan.
- DB scratch dan DB test unik sudah di-DROP dengan nama persis.
- Worktree QA sudah dihapus; repo utama tetap di `main` `3832c3d`. QA tidak membuat commit kode.
- Skrip harness (PHP curl/mysqli, worker race CLI) bersifat lokal dan tidak di-commit.

## Tindak lanjut (25-09-2026)

| Temuan | Kartu Trello | Perbaikan |
|---|---|---|
| T-01 | ISSUE-017 (Medium, terkait QASMTASK-024) | CR-006, opsi pertama: pencabutan massal menghapus row token seperti logout (tanpa ubah skema), respons 401 refresh menghapus cookie, README Auth dikoreksi (Catatan 2) |
| T-02 | ISSUE-018 (Blocker, terkait QASMTASK-029) | CR-006: `withActor()` di jalur login (rehash + `last_login_at`), test regresi memeriksa semua baris audit saat login |
| Kanal token reset (026) | ISSUE-006 | Keputusan K3: kanal = email (driver SMTP menyusul, sampai aktif production menampilkan "Hubungi Admin"); K4: kebijakan password ikut legacy (min 8, huruf besar, huruf kecil, angka). UI lupa/reset/ganti password di CR-008 |
| Collation tabel auth (Catatan 3) | ISSUE-008/010 | DBV-010 |

Setelah CR-006 masuk `main`, QASMTASK-024 (butir 24-09) dan QASMTASK-029 (butir 29-02) perlu diuji ulang.
