/**
 * Route Modul D paket 5A (PRD Fase 5A §7.8.1). Diimpor sekali dari `router/index.ts`. Role per halaman di `roles.ts`;
 * guard FE hanya UX (ADR-024), backend tetap menegakkan role + lingkup.
 */
import type { RouteRecordRaw } from 'vue-router'

import { SLIP_GAJI_ADMIN_ROLES, SLIP_GAJI_PEGAWAI_ROLES } from './roles'

export const presensiRoutes: RouteRecordRaw[] = [
  {
    // D-11 slip pegawai (`hr/sl_gaji/list|mark_viewed|cetak` = 2, 6, 7).
    path: '/presensi/slip-gaji',
    name: 'presensi-slip-gaji',
    component: () => import('./views/SlipGajiPage.vue'),
    meta: { roles: SLIP_GAJI_PEGAWAI_ROLES, title: 'Slip Gaji' },
  },
  {
    // D-10/D-11 jalur admin (`hr/sl_gaji/admin|import_*|edit_save|reset_access` = 1, 3; SG-2).
    path: '/presensi/slip-gaji/kelola',
    name: 'presensi-slip-gaji-kelola',
    component: () => import('./views/SlipGajiKelolaPage.vue'),
    meta: { roles: SLIP_GAJI_ADMIN_ROLES, title: 'Kelola Slip Gaji' },
  },
  {
    // D-14 verifikasi QR publik (ADR-032): tanpa login & tanpa `guestOnly` (bisa dibuka saat sudah login), tanpa layout
    // aplikasi; path tetap `/verify/slip/:token` karena dicetak di QR PDF lama dan baru (SG-1).
    path: '/verify/slip/:token',
    name: 'verifikasi-slip',
    component: () => import('./views/VerifikasiSlipPage.vue'),
    meta: { requiresAuth: false, title: 'Verifikasi Slip Gaji' },
  },
]
