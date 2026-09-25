# Modul A — Autentikasi & Akun (Fase 1)

Controller REST API modul ini (`App\Controllers\Api\Auth\*`), semua extends `App\Controllers\Api\ApiController`.
Envelope: sukses `{status:'success', data}`, gagal `{status:'error', message, errors?}` (ADR-001).
Role akses mengacu Matriks Role x Endpoint Bagian 2 Modul A. Prefix seluruh path: `/api/v1`.

Error umum di seluruh endpoint (`ApiController` dan handler global `ApiExceptionHandler`, CR-007):
- query string atau body form (form-urlencoded/multipart) yang bukan UTF-8 valid → **422** `message: "Input tidak valid (encoding)."`, `errors: { <field>: ["Isian mengandung karakter yang tidak valid (bukan UTF-8)."] }` (field bersarang bernotasi titik; key ikut dicek). Ditolak sebelum menyentuh DB. Body JSON seperti itu tetap 400 "Body JSON tidak valid.".
- segmen path URL yang bukan UTF-8 valid (mis. `/master/agama/%C3`), atau memuat karakter di luar `Config\App::$permittedURIChars` (huruf/angka ASCII, spasi, `~ % . : _ -`), ditolak Router CI4 sebelum controller → **400** `message: "Permintaan tidak valid."` tanpa `errors`; segmen tidak dipantulkan (detail di log). Cek parameter route di `ApiController::_remap()` (422 "Input tidak valid (encoding)." tanpa `errors`) hanya lapis cadangan bila `permittedURIChars` dilonggarkan.
- exception yang lolos ke handler global pada path `/api/...` (dan `/`) selalu dijawab envelope ini, dengan atau tanpa header `Accept: application/json` (`Config\Exceptions::isApiRequest()` memakai route path, bukan path yang memuat `index.php`); error 4xx dari framework → "Permintaan tidak valid.".
- nilai yang ditolak MySQL strict (1406 terlalu panjang, 1264 di luar rentang, 1366, 1292, 1265, 1364) → **422** `message: "Data tidak dapat diproses karena ada isian yang tidak valid."` tanpa `errors`; pesan MySQL (kolom, nilai) hanya di log. Error DB lain (lock wait, deadlock, 1062 yang tidak diterjemahkan service, koneksi) tetap 500.

Token: access token JWT 1 jam + refresh token 7 hari (rotating, single-use), keduanya cookie httpOnly
(`access_token` path `/`, `refresh_token` path `/api/v1/auth`). Klien non-browser boleh memakai header
`Authorization: Bearer <access_token>` dan body `refresh_token`.

## Endpoint

| Method | Path | Role | Controller | Task |
|---|---|---|---|---|
| POST | `/auth/login` | Publik (Guest) | `LoginController::login` | A-02, A-03, A-04, A-02b |
| POST | `/auth/refresh` | Publik (butuh cookie refresh_token) | `TokenController::refresh` | A-05 |
| POST | `/auth/logout` | UL_ALL | `TokenController::logout` | A-05 |
| GET | `/auth/me` | UL_ALL | `TokenController::me` | (route guard FE, ADR-024) |
| POST | `/auth/change-password` | UL_ALL | `PasswordController::change` | A-06 |
| POST | `/auth/forgot-password` | Publik | `ResetPasswordController::forgot` | A-07 |
| POST | `/auth/reset-password` | Publik | `ResetPasswordController::reset` | A-07 |
| GET | `/auth/users` | 1, 3 | `UserController::index` | A-08 |
| POST | `/auth/users` | 1, 3 | `UserController::create` | A-08 |
| GET | `/auth/users/{id}` | 1, 3 | `UserController::show` | A-08 |
| PUT | `/auth/users/{id}` | 1, 3 | `UserController::update` | A-08 |
| PATCH | `/auth/users/{id}/status` | 1, 3 | `UserController::setStatus` | A-08 |
| DELETE | `/auth/users/{id}` | 1, 3 | `UserController::delete` | A-08 |

Endpoint dummy `/_rbac/*` (Fase 0) tetap ada di non-production untuk regresi RoleFilter.

## Payload & response

### POST /auth/login
Request `{ "username": "198501012010011001", "password": "Password123!", "captcha_token": "<cf-turnstile-response>" }`

| Status | Kapan | Body |
|---|---|---|
| 200 | sukses | `data: { user:{id_pengguna,nip,username,user_level,id_unit,id_satker,status,last_login_at,...}, access_token, access_expires_at, refresh_expires_at }` + Set-Cookie |
| 422 | captcha kosong/invalid (dicek SEBELUM kredensial) / field wajib kosong | `errors: { captcha_token:[...] }` |
| 423 | akun terkunci (5x gagal berturut-turut, `auth.lockoutMaxAttempts`) | `message: "Akun terkunci sementara ... Coba lagi dalam N menit."` |
| 401 | username tidak terdaftar / password salah / akun nonaktif | `message: "Username atau password salah."` (generik) |

Lazy rehash (A-02b): kalau `password` NULL, verifikasi ke `password_legacy` (MD5); cocok → `password` diisi Argon2id, `password_legacy` NULL.

### POST /auth/refresh
Tanpa body (cookie `refresh_token`) atau `{ "refresh_token": "..." }`. 200 `data: { access_token, access_expires_at, refresh_expires_at }` + cookie baru.
401 kalau token tidak dikenal / kedaluwarsa / **sudah pernah dipakai (reuse) → seluruh sesi akun dicabut**.

### POST /auth/logout
Cookie/body refresh token dicabut di DB (`token.revoked=1`), cookie dihapus, audit `logout`. 200 `data: { logged_out: true }`.

### GET /auth/me
200 `data: { user, claims:{ role, id_unit, id_satker, exp } }`.

### POST /auth/change-password
Request `{ "old_password", "new_password", "new_password_confirmation" }`.
200 `data: { changed:true, sessions_revoked:true }` (seluruh refresh token dicabut, cookie dihapus → login ulang).
422 `errors: { old_password | new_password | new_password_confirmation }`.
Kebijakan password: min `auth.passwordMinLength` (8), mengandung huruf dan angka, beda dari password lama.

### POST /auth/forgot-password
Request `{ "username" }`. 200 selalu generik `data: { accepted:true, message }`; di development (`auth.exposeResetTokenInResponse=true`) ditambah `token`, `expires_at`.
429 kalau > `auth.forgotMaxPerWindow` (3) permintaan per `auth.forgotWindowMinutes` (60) untuk username yang sama.
**Kanal pengiriman token (email/WA) belum ditentukan di dokumen sumber** — token saat ini dicatat di log.

### POST /auth/reset-password
Request `{ "token", "new_password", "new_password_confirmation" }`. 200 `data: { reset:true }`.
Sukses: password baru, token ditandai terpakai, **token reset lain milik akun yang sama dibatalkan**, seluruh refresh token akun dicabut — semuanya dalam satu transaksi (all-or-nothing).
422 `errors.token`: "tidak valid" / "sudah pernah dipakai" / "sudah tidak berlaku lagi" (token dibatalkan karena reset lain sudah sukses) / "sudah kedaluwarsa" (TTL `auth.resetTokenTtl`, default 30 menit).
Dua request paralel untuk akun yang sama (token sama atau berbeda): hanya satu yang 200, sisanya 422.
500 bila penyimpanan gagal (error database: lock wait timeout, deadlock, dll.) — tidak ada yang tersimpan dan token belum terpakai, jadi bisa dicoba lagi.

### /auth/users (A-08)
Query index: `search` (username/nip LIKE), `user_level`, `status`, `id_satker` (role 1 saja), `sort` (username|nip|user_level|status|created_at|last_login_at), `order`, `page`, `per_page` (≤100).
Response index: `data: { items:[user...], total, page, per_page }`.

Create `{ nip (18 digit), username? (default = nip), password, user_level (1-8), id_unit?, id_satker?, status? }` → 201 `data: user`.
Update `{ username?, user_level?, id_unit?, id_satker?, status?, password? }` → 200 `data: user`; perubahan role/status/password/satker mencabut seluruh sesi akun tsb.
Status `{ "status": "0"|"1" }`. Delete → soft delete (`deleted_at`) + sesi dicabut; tidak boleh menghapus akun sendiri.

Scoping: role 3 hanya melihat/mengubah akun dengan `id_satker` = satker di claims JWT-nya; akun lain → **403**. Role 3 tidak dapat membuat/memberi role Super Admin (asumsi keamanan, perlu konfirmasi).
Validasi: 422 `errors` per-field (nip 18 digit, nip/username unik, role valid, kebijakan password).

## Audit trail (A-10)
`audit_logs` — `login` dan `logout` ditulis eksplisit oleh `AuthService` (entity `pengguna`, `nip_actor` = akun ybs); ganti password, reset password, dan seluruh CRUD akun tercatat otomatis lewat `PenggunaModel` (turunan `BaseAuditableModel`) dengan kolom hash password dimasking `***`.
Reset password (tanpa sesi login): `nip_actor` = NIP pemilik akun (`PenggunaModel::withActor()`). Pengecualian fail-open F0-04: bila INSERT audit reset gagal, reset ikut dibatalkan (500), karena di dalam transaksi kegagalannya tidak bisa dibedakan dari transaksi yang sudah di-rollback server.
