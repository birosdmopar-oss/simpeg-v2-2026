/**
 * Route F-11 Survei Kepuasan Layanan (MAKE-084): rekap role 1. Diimpor sekali dari `router/index.ts`. Guard FE hanya UX.
 */
import type { RouteRecordRaw } from 'vue-router'

import { SKL_REKAP_ROLES } from './roles'

export const SKL_ROUTE = { REKAP: 'survei-layanan-rekap' } as const

export const surveiLayananRoutes: RouteRecordRaw[] = [
  {
    path: '/survei-layanan',
    name: SKL_ROUTE.REKAP,
    component: () => import('./views/SurveiLayananRekapView.vue'),
    meta: { title: 'Survei Kepuasan Layanan', roles: SKL_REKAP_ROLES },
  },
]
