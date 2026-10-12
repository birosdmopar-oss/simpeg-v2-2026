/**
 * Route Modul B milik WS-1 (mesin riwayat & halaman usulan riwayat). Diimpor sekali dari `router/index.ts`;
 * menambah route WS-1 cukup di berkas ini. Role mengikuti Matriks v2 (`roles.ts`); guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { KARPEG_KARIS_ROLES } from './roles'

export const riwayatRoutes: RouteRecordRaw[] = [
  {
    // B-17 Usulan Karpeg/Karis/Karsu (hr/rwy/karpeg/*, hr/rwy/kariskarsu/* = role 1, 2, 4, 5, 7) — halaman usulan
    // mandiri; placeholder sampai dibangun WS-1.
    path: '/karpeg-karis',
    name: 'karpeg-karis-usulan',
    component: () => import('./views/UsulanKarpegKarisPage.vue'),
    meta: { roles: KARPEG_KARIS_ROLES, title: 'Usulan Karpeg/Karis' },
  },
  {
    // SEMENTARA sampai MAKE-010 (GET pegawai/{nip} + data.tabs, WS-2): tab riwayat pegawai dari
    // GET pegawai/{nip}/riwayat (MAKE-006). Semua role login; lingkup & izin per jenis ditegakkan backend. Tanpa entri
    // menu. TODO(MAKE-010): alihkan ke Detail Pegawai (tab yang sama) atau hapus.
    path: '/pegawai/:nip/riwayat',
    name: 'pegawai-riwayat',
    component: () => import('./views/RiwayatPegawaiPage.vue'),
    meta: { title: 'Riwayat Pegawai' },
  },
]
