/**
 * Route Modul F — laporan statistik F-03 (MAKE-036). Diimpor sekali dari `router/index.ts`. Role mengikuti Matriks v2
 * (`types.ts`); guard FE hanya UX (ADR-024), backend tetap menegakkan role + lingkup.
 */
import type { RouteRecordRaw } from 'vue-router'

import { LAPORAN_ROLES, LAPORAN_TIPE } from './types'

export const laporanRoutes: RouteRecordRaw[] = [
  {
    // hr/chart/* = role 1, 3, 4, 5, 8; satu halaman untuk 7 tipe (PRD Fase 7 §7.1.7).
    path: `/laporan/:tipe(${LAPORAN_TIPE.join('|')})`,
    name: 'laporan',
    component: () => import('./views/LaporanPage.vue'),
    meta: { roles: LAPORAN_ROLES, title: 'Laporan' },
  },
]
