/**
 * Aksi saat backend menjawab 403 `password_change_required` (MAKE-020; sejak MAKE-028 juga untuk password kedaluwarsa
 * di tengah sesi): ambil ulang user dari /auth/me supaya `must_change_password` di store mutakhir, lalu arahkan ke
 * /ganti-password sekali. Dipasang lewat setPasswordChangeRequiredHandler (main.ts); lib/axios menjamin hanya satu
 * eksekusi untuk banyak request 403 bersamaan dan tidak memicunya dari endpoint yang memang terbuka (anti-loop).
 */
import type { Router } from 'vue-router'

import type { useAuthStore } from './stores/auth.store'

export const CHANGE_PASSWORD_ROUTE = 'change-password'

export async function handlePasswordChangeRequired(auth: ReturnType<typeof useAuthStore>, router: Router): Promise<void> {
  await auth.fetchCurrentUser(true)
  if (auth.mustChangePassword && router.currentRoute.value.name !== CHANGE_PASSWORD_ROUTE) {
    await router.push({ name: CHANGE_PASSWORD_ROUTE })
  }
}
