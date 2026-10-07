/**
 * Route Modul B milik WS-2 (Kepegawaian: pegawai, struktur, Konket, LKH). Diimpor sekali dari `router/index.ts`;
 * menambah route WS-2 cukup di berkas ini. Role mengikuti Matriks v2 (`roles.ts`); guard FE hanya UX (ADR-024).
 */
import type { RouteRecordRaw } from 'vue-router'

import { KONKET_ROLES, LKH_ROLES, PEGAWAI_LIST_ROLES } from './roles'

export const pegawaiRoutes: RouteRecordRaw[] = [
  {
    // B-20 daftar pegawai (hr/employee/index = role 1, 3, 4, 5, 8).
    path: '/pegawai',
    name: 'pegawai-list',
    component: () => import('./views/DaftarPegawaiPage.vue'),
    meta: { roles: PEGAWAI_LIST_ROLES, title: 'Daftar Pegawai' },
  },
  {
    // B-20 detail pegawai (hr/employee/detail/{nip} = semua role login; lingkup ditegakkan backend).
    path: '/pegawai/:nip',
    name: 'pegawai-detail',
    component: () => import('./views/DetailPegawaiPage.vue'),
    meta: { title: 'Data Pegawai' },
  },
  {
    // B-19 struktur organisasi (hr/so/full = semua role login).
    path: '/struktur-organisasi',
    name: 'org-structure',
    component: () => import('./views/StrukturOrganisasiPage.vue'),
    meta: { title: 'Struktur Organisasi' },
  },
  {
    // B-13 Usulan Konket (hr/rwy/konket/* = role 1, 2, 3, 6, 7) — halaman usulan mandiri; placeholder sampai M5.
    path: '/konket',
    name: 'konket-usulan',
    component: () => import('./views/UsulanKonketPage.vue'),
    meta: { roles: KONKET_ROLES, title: 'Usulan Konket' },
  },
  {
    // B-12b Verifikasi LKH (hr/rwy/lkh/* = role 1, 2, 3, 6, 7; approver = atasan langsung) — placeholder sampai M5.
    path: '/lkh/verifikasi',
    name: 'lkh-verifikasi',
    component: () => import('./views/VerifikasiLkhPage.vue'),
    meta: { roles: LKH_ROLES, title: 'Verifikasi LKH' },
  },
]
