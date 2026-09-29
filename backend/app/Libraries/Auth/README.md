# Modul A — Autentikasi & Akun (Fase 1)

Service/business logic modul ini (`App\Libraries\Auth\*`). Kalkulasi, business rule, dan scoping data (mis. per satker) ditulis di sini — bukan di Controller/Filter (ADR-005).

- `PasswordPolicy` — satu sumber kebijakan password baru (K4, ikut legacy): min 8 karakter, huruf besar, huruf kecil, angka. Dipakai `PasswordVerifier::policyErrors()` untuk ganti/reset password, admin buat/ubah akun, dan password awal `AccountProvisioner`. Frontend mencerminkannya di `features/auth/schemas/password.schema.ts`.
- Identitas akun (DBV-010/CR-013) = `id_pengguna`: claim `sub` JWT, baris `token`, `audit_logs.id_pengguna_actor`, stamp `*_by` master. NIP hanya atribut akun pegawai (`AuthContext::nip()` dari claim `nip`, null untuk akun role 1/3/4/5/8 tanpa NIP). `JwtService::CLAIMS_VERSION` = 2; token format lama ditolak.
- `Role::UL_PEGAWAI` (2/6/7) wajib NIP (`Role::wajibNip()`); aturan akun lain (nama wajib tanpa NIP, NIP angka maks. 18 digit, username ≤ 100, NIP hanya bisa ditautkan) di `UserService`/`AccountProvisioner`.
- `ResetTokenNotifierInterface` (`App\Interfaces`) — kanal tautan reset password; driver `LogResetTokenNotifier` (development) dan `MockResetTokenNotifier` (test), keduanya ditolak di production. Driver email menyusul (K3).
