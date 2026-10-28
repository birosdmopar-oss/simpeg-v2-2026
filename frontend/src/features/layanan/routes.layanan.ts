/**
 * Route Modul C (Layanan & Approval, Fase 4). Diimpor sekali dari `router/index.ts`; tiap paket M7 menambah route
 * halamannya di berkas ini (PRD §6.11.1). Role mengikuti `roles.ts`; guard FE hanya UX (ADR-024).
 *
 * Paket 0 hanya menyiapkan fondasi — belum ada halaman, jadi daftar masih kosong dan menu Fase 4 tetap tersembunyi
 * (`ACTIVE_PHASE` dinaikkan hanya di paket penutup, AC-11.8).
 */
import type { RouteRecordRaw } from 'vue-router'

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

export const layananRoutes: RouteRecordRaw[] = []
