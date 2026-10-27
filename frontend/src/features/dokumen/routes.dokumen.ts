/**
 * Route Modul F — generator dokumen F-09 (MAKE-037). Diimpor sekali dari `router/index.ts`. Role mengikuti Matriks v2
 * (`types.ts`); guard FE hanya UX (ADR-024), backend tetap menegakkan role + lingkup.
 */
import type { RouteRecordRaw } from 'vue-router'

import { DOKUMEN_JENIS, DOKUMEN_ROLES } from './types'

export const dokumenRoutes: RouteRecordRaw[] = [
  {
    // hr/document/* = role 1, 3, 4, 5, 8; satu halaman untuk 4 jenis (PRD Fase 7 §7.2.10).
    path: `/dokumen/:jenis(${DOKUMEN_JENIS.join('|')})`,
    name: 'dokumen',
    component: () => import('./views/DokumenPage.vue'),
    meta: { roles: DOKUMEN_ROLES, title: 'Dokumen' },
  },
]
