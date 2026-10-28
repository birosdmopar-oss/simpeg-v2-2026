/**
 * Route Modul C (Layanan & Approval, Fase 4). Diimpor sekali dari `router/index.ts`; tiap paket M7 menambah route
 * halamannya di berkas ini (PRD §6.11.1). Role mengikuti `roles.ts`; guard FE hanya UX (ADR-024).
 *
 * Menu Fase 4 tetap tersembunyi sampai paket penutup (`ACTIVE_PHASE` dinaikkan hanya di sana, AC-11.8); halaman
 * dapat dibuka lewat URL langsung untuk QA.
 */
import type { RouteRecordRaw } from 'vue-router'

import { CUTI_ADMIN_ROLES, CUTI_ROLES, IB_ROLES, PMK_ROLES, STATUS_LAYANAN_ROLES, TB_ROLES } from './roles'

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
    // Saldo Cuti & Petugas Approval Cuti: role 1, 3 (menu tersembunyi sampai paket penutup).
    path: '/layanan/cuti/saldo',
    name: LAYANAN_ROUTE.CUTI_SALDO,
    component: () => import('./views/CutiSaldoPage.vue'),
    meta: { roles: CUTI_ADMIN_ROLES, title: 'Saldo Cuti' },
  },
  {
    path: '/layanan/cuti/petugas-approval',
    name: LAYANAN_ROUTE.CUTI_PETUGAS_APPROVAL,
    component: () => import('./views/PetugasApprovalCutiPage.vue'),
    meta: { roles: CUTI_ADMIN_ROLES, title: 'Petugas Approval Cuti' },
  },
  {
    // Tugas Belajar (C-07): daftar + detail; role 1, 2, 3, 6 (role 7 → 403). Rute mengikuti backend (keputusan 28-10).
    path: '/layanan/tb',
    name: LAYANAN_ROUTE.TB,
    component: () => import('./views/TbPage.vue'),
    meta: { roles: TB_ROLES, title: 'Tugas Belajar' },
  },
  {
    path: '/layanan/tb/:id(\\d+)',
    name: LAYANAN_ROUTE.TB_DETAIL,
    component: () => import('./views/TbDetailPage.vue'),
    meta: { roles: TB_ROLES, title: 'Detail Tugas Belajar' },
  },
  {
    // Izin Belajar (C-08): daftar + detail; role 1, 2, 3, 7 (role 6 → 403).
    path: '/layanan/ib',
    name: LAYANAN_ROUTE.IB,
    component: () => import('./views/IbPage.vue'),
    meta: { roles: IB_ROLES, title: 'Izin Belajar' },
  },
  {
    path: '/layanan/ib/:id(\\d+)',
    name: LAYANAN_ROUTE.IB_DETAIL,
    component: () => import('./views/IbDetailPage.vue'),
    meta: { roles: IB_ROLES, title: 'Detail Izin Belajar' },
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
  {
    // Status Layanan: monitoring semua pengajuan layanan — role 1–7 (PRD §6.11.1); lingkup data ditegakkan server.
    path: '/layanan/status',
    name: LAYANAN_ROUTE.STATUS,
    component: () => import('./views/LayananStatusPage.vue'),
    meta: { roles: STATUS_LAYANAN_ROLES, title: 'Status Layanan' },
  },
]
