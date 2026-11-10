/**
 * Route F-08 Pusat Notifikasi (MAKE-085). Satu halaman bertab (`?tab=notifikasi|butuh-approval|histori-approval`);
 * tab yang tampil mengikuti role (roles.ts). Guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { NOTIFIKASI_ROLES } from './roles'

export const NOTIFIKASI_ROUTE = 'notifikasi' as const

export const notifikasiRoutes: RouteRecordRaw[] = [
  {
    path: '/notifikasi',
    name: NOTIFIKASI_ROUTE,
    component: () => import('./views/NotifikasiPage.vue'),
    meta: { roles: NOTIFIKASI_ROLES, title: 'Notifikasi' },
  },
]
