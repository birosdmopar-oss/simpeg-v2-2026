# Modul A — Autentikasi & Akun (Fase 1)

Service/business logic modul ini (`App\Libraries\Auth\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

- `PasswordPolicy` — satu sumber kebijakan password baru (K4, ikut legacy): min 8 karakter, huruf besar, huruf kecil, angka. Dipakai `PasswordVerifier::policyErrors()` untuk ganti/reset password, admin buat/ubah akun, dan password awal `AccountProvisioner`. Frontend mencerminkannya di `features/auth/schemas/password.schema.ts`.
- `ResetTokenNotifierInterface` (`App\Interfaces`) — kanal tautan reset password; driver `LogResetTokenNotifier` (development) dan `MockResetTokenNotifier` (test), keduanya ditolak di production. Driver email menyusul (K3).
