/**
 * Route Modul C (Layanan & Approval, Fase 4). Diimpor sekali dari `router/index.ts`; tiap paket M7 menambah route
 * halamannya di berkas ini (PRD §6.11.1). Role mengikuti `roles.ts`; guard FE hanya UX (ADR-024).
 *
 * Menu Fase 4 tetap tersembunyi sampai paket penutup (`ACTIVE_PHASE` dinaikkan hanya di sana, AC-11.8); halaman
 * dapat dibuka lewat URL langsung untuk QA.
 */
import type { RouteRecordRaw } from 'vue-router'

import { CUTI_ROLES, PMK_ROLES } from './roles'

/** Nama route Modul C (tetap, dipakai tautan antarhalaman dan `nav.config.ts`). */
export const LAYANAN_ROUTE = {
  CUTI: 'layanan-cuti',
  CUTI_DETAIL: 'layanan-cuti-detail',
  CUTI_SALDO: 'layanan-cuti-saldo',
  CUTI_PETUGAS_APPROVAL: 'layanan-cuti-petugas-approval',
  TB: 'layanan-tb',
  TB_DETAIL: 'layanan-tb-detail',
  IB: 'layanan-ib',
  IB_DETAIL: 'layanan-ib-detail',
  PMK: 'layanan-pmk',
  PMK_DETAIL: 'layanan-pmk-detail',
  STATUS: 'layanan-status',
  PETUGAS: 'layanan-petugas',
} as const

export const layananRoutes: RouteRecordRaw[] = [
  {
    // Cuti Online: daftar (3 tab) — role 1, 2, 3, 6, 7 (PRD §6.11.1).
    path: '/layanan/cuti',
    name: LAYANAN_ROUTE.CUTI,
    component: () => import('./views/CutiPage.vue'),
    meta: { roles: CUTI_ROLES, title: 'Cuti' },
  },
  {
    // Detail cuti (:id angka agar tidak bentrok dengan /layanan/cuti/saldo dan /petugas-approval).
    path: '/layanan/cuti/:id(\\d+)',
    name: LAYANAN_ROUTE.CUTI_DETAIL,
    component: () => import('./views/CutiDetailPage.vue'),
    meta: { roles: CUTI_ROLES, title: 'Detail Cuti' },
  },
  {
    // PMK: riwayat final + pengajuan (4 tab) — role 1, 2, 3 (PRD §6.11.1).
    path: '/layanan/pmk',
    name: LAYANAN_ROUTE.PMK,
    component: () => import('./views/PmkPage.vue'),
    meta: { roles: PMK_ROLES, title: 'PMK' },
  },
  {
    path: '/layanan/pmk/pengajuan/:id(\\d+)',
    name: LAYANAN_ROUTE.PMK_DETAIL,
    component: () => import('./views/PmkDetailPage.vue'),
    meta: { roles: PMK_ROLES, title: 'Detail PMK' },
  },
]
