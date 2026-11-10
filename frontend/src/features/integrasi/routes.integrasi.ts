/**
 * Route Modul H — halaman Admin → Integrasi (MAKE-078, ISSUE-058; PRD Fase 6 §6.2). Diimpor sekali dari
 * `router/index.ts`. Guard FE hanya UX (ADR-024); backend tetap menegakkan role 1.
 */
import type { RouteRecordRaw } from 'vue-router'

import { INTEGRASI_ADMIN_ROLES } from './integrasi.types'

export const integrasiRoutes: RouteRecordRaw[] = [
  {
    path: '/integrasi',
    name: 'integrasi-admin',
    component: () => import('./views/IntegrasiAdminPage.vue'),
    meta: { roles: INTEGRASI_ADMIN_ROLES, title: 'Integrasi' },
  },
]
