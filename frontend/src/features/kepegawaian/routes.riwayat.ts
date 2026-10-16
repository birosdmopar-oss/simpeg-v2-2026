/**
 * Route Modul B milik WS-1 (mesin riwayat & halaman usulan riwayat). Diimpor sekali dari `router/index.ts`;
 * menambah route WS-1 cukup di berkas ini. Role mengikuti Matriks v2 (`roles.ts`); guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { AK_JF_ROLES, KARPEG_KARIS_ROLES } from './roles'

export const riwayatRoutes: RouteRecordRaw[] = [
  {
    // B-17 Usulan Karpeg/Karis/Karsu (hr/rwy/karpeg/*, hr/rwy/kariskarsu/* = role 1, 2, 4, 5, 7) — halaman usulan
    // mandiri (UsulanMandiri, MAKE-007).
    path: '/karpeg-karis',
    name: 'karpeg-karis-usulan',
    component: () => import('./views/UsulanKarpegKarisPage.vue'),
    meta: { roles: KARPEG_KARIS_ROLES, title: 'Usulan Karpeg/Karis' },
  },
  {
    // Alamat lama tab riwayat (halaman sementara MAKE-006, sebelum Detail Pegawai live MAKE-010): dialihkan ke Detail
    // Pegawai — tab yang sama dari descriptor `data.tabs`; query (mis. `?tab=kp`) dipertahankan (MAKE-016).
    path: '/pegawai/:nip/riwayat',
    name: 'pegawai-riwayat',
    redirect: (to) => ({ name: 'pegawai-detail', params: { nip: to.params.nip }, query: to.query }),
  },
  {
    // MAKE-021: master Angka Kredit JF `dm_ak_jf` (legacy Data Master > JABATAN > Angka Kredit JF, role 1).
    path: '/master-ak-jf',
    name: 'master-ak-jf',
    component: () => import('./angka-kredit/MasterAkJfPage.vue'),
    meta: { roles: AK_JF_ROLES, title: 'Angka Kredit JF' },
  },
]
