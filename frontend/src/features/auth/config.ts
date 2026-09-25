/**
 * Flag fitur Modul A dari env build (hanya variabel VITE_* yang terekspos ke browser).
 * Dibaca saat dipanggil, bukan saat modul dimuat, supaya bisa di-stub di test (vi.stubEnv).
 */

/**
 * Halaman lupa/reset password (A-07, ISSUE-006). Default false sampai kanal email (K3) aktif di backend:
 * halaman login menampilkan "Hubungi Admin" dan route /lupa-password & /reset-password dialihkan ke login.
 */
export function passwordResetEnabled(): boolean {
  return String(import.meta.env.VITE_PASSWORD_RESET_ENABLED ?? '').trim().toLowerCase() === 'true'
}
