# Modul A — Autentikasi & Akun (Fase 1)

Struktur feature-based (ADR-019):

- `views/` — halaman (dipetakan di `src/router`, meta `{ requiresAuth, roles }` — ADR-024)
- `components/` — komponen khusus modul ini
- `composables/` — logic UI; hanya format/decorate data dari backend (ADR-025)
- `services/` — pemanggilan API modul ini lewat `@/lib/axios` (ADR-022)
- `stores/` — Pinia store domain modul ini (ADR-021)
- `schemas/` — skema Zod untuk VeeValidate (ADR-026)

Komponen yang dipakai modul kedua dipindah ke `src/shared/`.

## Password (A-06, A-07, ISSUE-006)

- **Satu sumber aturan:** `schemas/password.schema.ts` → `PASSWORD_RULES` (K4, ikut legacy: min 8 karakter, huruf besar,
  huruf kecil, angka). Dipakai schema Zod (ganti/reset password, form akun admin) dan `PasswordRulesChecklist`
  (checklist real-time). Cermin backend `App\Libraries\Auth\PasswordPolicy` — id, urutan, dan pesan wajib diubah bersamaan
  (vektor uji `__tests__/password.schema.spec.ts` = `backend/tests/unit/Libraries/PasswordPolicyTest.php`).
- **`/ganti-password`** (`ChangePasswordPage`, semua role login; tautan "Ganti Password" di menu pengguna AppShell).
  Sukses → backend mencabut seluruh refresh token dan menghapus cookie; `auth.changePassword()` hanya mengosongkan sesi
  lokal (TANPA `/auth/logout`) lalu halaman pindah ke `/login?reason=password-changed`.
- **Batas pencabutan sesi (ganti & reset):** backend hanya mencabut refresh token; access token yang sudah terbit di
  perangkat lain tetap sah sampai kedaluwarsa (`jwt.accessTtl`, default 60 menit) karena verifikasi access token belum
  memeriksa pencabutan. Teks halaman sengaja berbunyi "berakhir paling lambat 60 menit", bukan "langsung diakhiri".
- **`/lupa-password` & `/reset-password`** (tamu saja) aktif hanya kalau `VITE_PASSWORD_RESET_ENABLED=true`; default
  `false` → route dialihkan ke login dan halaman login tetap menampilkan "Hubungi Admin". Nyalakan hanya kalau backend
  punya kanal aktif (development: `auth.resetTokenNotifier=log`, tautan dibaca dari log backend; production: driver
  email CR-014 — nyalakan setelah kirim nyata lolos di Dev). Halaman reset **tidak** memanggil API saat dibuka (token baru
  dipakai saat submit), sehingga pemindai tautan email (SafeLinks dsb.) tidak menghabiskan token; pertahankan itu.
  Tautan reset dari backend = `/reset-password#token=…` (token di fragment: tidak dikirim browser ke
  server, jadi tidak masuk access log web server maupun Referer); `?token=` gaya legacy tetap diterima sebagai cadangan.
  Token disimpan di memori lalu fragment/query-nya dihapus dari URL. Sukses →
  `/login?reason=password-reset`.

## Manajemen Akun (A-12, DBV-010/CR-013)

- **Identitas akun = `id_pengguna`.** `User.nip` bisa `null` (akun role 1/3/4/5/8 tanpa NIP, K2); baris milik sendiri di
  tabel dikenali lewat `id_pengguna`, bukan NIP.
- **Aturan form** (`schemas/user.schema.ts`, cermin backend `UserService`): NIP angka maks. 18 digit, wajib hanya untuk
  role Pegawai/PTT/PPPK (`UL_PEGAWAI`); akun tanpa NIP wajib nama dan username; email opsional; username ≤ 100
  (`USERNAME_MAX`, juga batas form login). Edit memakai `makeUserUpdateSchema(nipAkun)`: NIP yang sudah ada tampil
  read-only (ganti NIP = fitur B-06), akun tanpa NIP bisa ditautkan ke pegawai lewat isian "Tautkan NIP".
- **Pilihan role:** Super Admin melihat role 1-8; Admin Satker hanya Pegawai/PTT/PPPK (ditambah role akun yang sedang
  diedit agar tetap tampil) dan role akunnya sendiri dikunci — cermin aturan backend (legacy `L_user`), backend tetap
  penentu akhir (403).
- **Aksi baris** lewat menu ⋮ (`RowActionsMenu`, aturan AGENTS.md bagian 1) dengan testid `user-actions-<id_pengguna>` /
  baris `user-row-<id_pengguna>` (NIP bisa `null`, jadi tidak dipakai sebagai id).
