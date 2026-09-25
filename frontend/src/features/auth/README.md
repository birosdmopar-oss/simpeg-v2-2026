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
  punya kanal aktif (development: `auth.resetTokenNotifier=log`, tautan dibaca dari log backend; production: menunggu
  driver email). Tautan reset dari backend = `/reset-password#token=…` (token di fragment: tidak dikirim browser ke
  server, jadi tidak masuk access log web server maupun Referer); `?token=` gaya legacy tetap diterima sebagai cadangan.
  Token disimpan di memori lalu fragment/query-nya dihapus dari URL. Sukses →
  `/login?reason=password-reset`.
