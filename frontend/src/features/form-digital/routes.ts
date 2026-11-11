/**
 * Route F-06 Form Digital (MAKE-083): kelola + rekap respons, role 1. Diimpor sekali dari `router/index.ts`.
 * Guard FE hanya UX (ADR-024); role ditegakkan backend.
 */
import type { RouteRecordRaw } from 'vue-router'

import { FORM_DIGITAL_KELOLA_ROLES } from './roles'

export const FORM_DIGITAL_ROUTE = {
  KELOLA: 'form-digital-kelola',
  RESPONS: 'form-digital-respons',
} as const

export const formDigitalRoutes: RouteRecordRaw[] = [
  {
    path: '/form-digital',
    name: FORM_DIGITAL_ROUTE.KELOLA,
    component: () => import('./views/FormDigitalPage.vue'),
    props: { halaman: 'kelola' },
    meta: { title: 'Form Digital', roles: FORM_DIGITAL_KELOLA_ROLES },
  },
  {
    path: '/form-digital/:id/respons',
    name: FORM_DIGITAL_ROUTE.RESPONS,
    component: () => import('./views/FormDigitalPage.vue'),
    props: (r) => ({ halaman: 'respons', idForm: Number(r.params.id) }),
    meta: { title: 'Respons Form Digital', roles: FORM_DIGITAL_KELOLA_ROLES },
  },
]
